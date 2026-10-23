<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Jobs\ResultadoCultJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** O envio da oportunidade não encadeia mais o do resultado: nada sai sem o gestor apertar o botão. */
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

    /** Envia a OPORTUNIDADE com o desfecho dado, a partir de fila limpa; o formulário de resultado resolve completo. */
    private function enviaOportunidade(Opportunity $raiz, array $trocas = [], ?array $estrutura = null): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProvedorDuble(
                fn() => $this->desfecho($trocas),
                function () use ($raiz, $estrutura) {
                    FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
                    FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura ?? self::ESTRUTURA_SEM_OBRIGATORIAS;

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
            ),
        );
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testSucessoComResultadoNuncaEnviadoNaoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->enviaOportunidade($raiz);

        $this->assertCount(0, $this->jobsDeResultado(), 'o primeiro envio é do botão: nenhum caminho automático o produz');
    }

    function testJaEnviadoComAlteracaoPendenteTambemNaoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->enviaOportunidade($raiz);

        $this->assertCount(0, $this->jobsDeResultado(), 'o retificado é o botão Retificar, não um efeito do envio da oportunidade');
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

    function testSucessoComFormularioIncompletoNaoEncadeia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->enviaOportunidade($raiz, estrutura: $estruturaComObrigatoria);

        $this->assertCount(0, $this->jobsDeResultado(), 'a oportunidade foi aceita, mas sem formulário completo o resultado não segue');
    }
}
