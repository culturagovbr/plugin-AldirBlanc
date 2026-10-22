<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\BeneficiariosService;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** Nada envia resultado sozinho: nem publicar, nem mudar status, nem remover inscrição, nem gravar célula. */
class ThemeResultadoHooksTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use BuildsPublishedFinalPhase;
    use ConfiguresPlugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Edital integrado e elegível; `jaEnviado` acrescenta o id do resultado vigente no PAR. */
    private function cenario(string $nome, bool $publicada = true, bool $jaEnviado = false): array
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, $nome));
        $fase = $this->faseFinal($raiz, publicada: $publicada);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        if ($jaEnviado) {
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');
        }

        $this->clearJobQueue();

        return [$raiz, $fase];
    }

    private function contempladaNa(Opportunity $fase, Opportunity $owner = null): Registration
    {
        $this->app->disableAccessControl();
        $inscricao = new Registration();
        $inscricao->opportunity = $fase;
        $inscricao->owner = $this->userDirector->createUser()->profile;
        $inscricao->save(true);
        $this->app->em->getConnection()->executeStatement(
            'UPDATE registration SET status = 10 WHERE id = :id',
            ['id' => (int) $inscricao->id]
        );
        $this->app->enableAccessControl();

        return $inscricao;
    }

    private function publica(Opportunity $fase): void
    {
        $this->clearJobQueue();
        $this->app->disableAccessControl();
        $fase->publishRegistrations();
        $this->app->enableAccessControl();
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testPublicarNaoEnfileiraNada()
    {
        $this->comFormularioResultado(function () {
            [, $fase] = $this->cenario('Subsite Hook Publica', publicada: false);

            $this->publica($fase);

            $this->assertCount(0, $this->jobsDeResultado(), 'publicar libera o formulário; quem envia é o gestor, pelo botão');
        });
    }

    function testPublicarEditalJaEnviadoNaoEnfileiraNada()
    {
        $this->comFormularioResultado(function () {
            [, $fase] = $this->cenario('Subsite Hook Republica', publicada: false, jaEnviado: true);

            $this->publica($fase);

            $this->assertCount(0, $this->jobsDeResultado(), 'nem o retificado sai sozinho: ele é o botão Retificar');
        });
    }

    function testMudarStatusDeInscricaoNaoEnfileiraNada()
    {
        $this->comFormularioResultado(function () {
            [, $fase] = $this->cenario('Subsite Hook Status', jaEnviado: true);
            $inscricao = $this->contempladaNa($fase);
            $this->clearJobQueue();

            $this->app->disableAccessControl();
            $inscricao->setStatusToWaitlist();
            $this->app->enableAccessControl();

            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    function testRemoverInscricaoNaoEnfileiraNada()
    {
        $this->comFormularioResultado(function () {
            [, $fase] = $this->cenario('Subsite Hook Remocao', jaEnviado: true);
            $inscricao = $this->contempladaNa($fase);
            $this->clearJobQueue();

            $this->app->disableAccessControl();
            $inscricao->delete(true);
            $this->app->enableAccessControl();

            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    function testGravarCelulaDaGradeNaoEnfileiraNada()
    {
        $this->comFormularioResultado(function () {
            [$raiz, $fase] = $this->cenario('Subsite Hook Celula', jaEnviado: true);
            $inscricao = $this->contempladaNa($fase);
            $this->clearJobQueue();

            (new BeneficiariosService())->salvarCelulas($raiz, (int) $inscricao->id, ['data_recebimento_recurso' => '10/01/2026']);

            $this->assertCount(0, $this->jobsDeResultado(), 'completar a grade não dispara envio: o gestor retifica quando quiser');
        });
    }

    function testDespublicarNaoEnfileiraNada()
    {
        [, $fase] = $this->cenario('Subsite Hook Despublica');

        $this->app->disableAccessControl();
        $fase->unPublishRegistrations();
        $this->app->enableAccessControl();

        $this->assertCount(0, $this->jobsDeResultado());
    }
}
