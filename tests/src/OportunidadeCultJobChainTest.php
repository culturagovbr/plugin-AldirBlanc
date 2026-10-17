<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Jobs\ResultadoCultJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** Sucesso no envio da oportunidade dispara o de resultado — guarda: nunca enviado OU alteração pendente. */
class OportunidadeCultJobChainTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use SendsOpportunityThroughJob;
    use BuildsPublishedFinalPhase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Envia a OPORTUNIDADE com o desfecho dado, a partir de fila limpa. */
    private function enviaOportunidade(Opportunity $raiz, array $trocas = []): void
    {
        $this->comProvedorDuble(
            fn() => $this->desfecho($trocas),
            function () use ($raiz) {
                $this->clearJobQueue();
                $this->enqueueUpdateJob($raiz);
                // executeJob, não processJobs: hash iniciado por letra zera o cast no while do core.
                $subsiteAnterior = $this->app->currentSubsiteId;
                try {
                    $this->app->executeJob('2100-01-01 00:00');
                } finally {
                    // O job seta o subsite atual; sem restaurar, o próximo teste roda sob um subsite desfeito pelo rollback.
                    $this->app->setCurrentSubsiteId($subsiteAnterior);
                }
            }
        );
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testSucessoComResultadoNuncaEnviadoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->enviaOportunidade($raiz);

        $this->assertCount(1, $this->jobsDeResultado(), 'primeiro envio do resultado entra pelo encadeamento');
    }

    function testJaEnviadoSemAlteracaoPendenteNaoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->enviaOportunidade($raiz);

        $this->assertCount(0, $this->jobsDeResultado(), 'reenvio sem mudança criaria retificado idêntico no PAR a cada login');
    }

    function testJaEnviadoComAlteracaoPendenteEncadeia()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');
        $this->gravaMeta((int) $fase->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE, '2026-10-16 20:00:00');

        $this->enviaOportunidade($raiz);

        $this->assertCount(1, $this->jobsDeResultado(), 'alteração pendente é exatamente o que o retificado existe para levar');
    }

    function testSemResultadoPublicadoNaoEncadeia()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Chain Sem Publicacao');
        $raiz = $this->raizElegivel($owner, $subsite);
        $this->faseFinal($raiz, publicada: false);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->enviaOportunidade($raiz);

        $this->assertCount(0, $this->jobsDeResultado());
    }

    function testEnvioComErroNaoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->enviaOportunidade($raiz, ['result' => SendResult::Error, 'httpStatus' => 502]);

        $this->assertCount(0, $this->jobsDeResultado(), 'sem oportunidade aceita não há resultado a enviar');
    }

    /** Hook de publicação e encadeamento próximos não podem duplicar o envio. */
    function testGatilhoDuplicadoResultaEmUmJobSo()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // Gatilho 1: o sucesso do envio de oportunidade encadeia (nunca enviado).
        $this->enviaOportunidade($raiz);

        // Gatilho 2: a republicação dispara o hook do tema — sem limpar a fila entre os dois.
        $this->app->disableAccessControl();
        $fase->publishRegistrations();
        $this->app->enableAccessControl();

        $this->assertCount(1, $this->jobsDeResultado(), 'dois gatilhos próximos, um job: enqueueOrReplaceJob deduplica pelo id');
    }
}
