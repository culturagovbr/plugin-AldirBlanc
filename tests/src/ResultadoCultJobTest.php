<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\ResultadoService;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** O job de resultado: envia pela capacidade, loga no action próprio e captura o id do 201. */
class ResultadoCultJobTest extends TestCase
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

    /** O save do cenário enfileira OportunidadeCultJob pelo hook do tema: a fila nasce limpa aqui. */
    private function enfileira(Opportunity $raiz): void
    {
        $this->clearJobQueue();
        $this->app->enqueueOrReplaceJob(ResultadoCultJob::SLUG, ['opportunity' => $raiz]);
    }

    private function processaComResposta(Opportunity $raiz, ?string $response): void
    {
        $this->comProvedorDuble(
            fn() => $this->desfecho(),
            function () use ($raiz, $response) {
                FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->desfecho(['response' => $response]);
                $this->enfileira($raiz);
                $this->processJobs(number_of_jobs: 1);
            }
        );
    }

    private function resultadoId(int $raizId): string|false
    {
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID]
        );

        return $row === false ? false : (string) $row['value'];
    }

    function testEnvioFelizLogaNoActionResultadoECapturaOId()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');

        $this->assertSame('777', $this->resultadoId((int) $raiz->id));
        $acoes = $this->app->em->getConnection()->fetchFirstColumn(
            'SELECT action FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => (int) $raiz->id]
        );
        $this->assertSame([ResultadoCultJob::ACTION], $acoes, 'o log do resultado vive no action próprio, sob a RAIZ');
        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame(53790, FakeIntegrationProvider::$resultadosEnviados[0]['edital']);
        $this->assertSame(ResultadoService::TIPO_FINAL, FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo);
    }

    function testSegundoEnvioViraRetificado()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // O dublê é resetado a cada rodada: o tipo de cada envio é capturado na sua vez.
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');
        $primeiroTipo = FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo;

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}');
        $segundoTipo = FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo;

        $this->assertSame(ResultadoService::TIPO_FINAL, $primeiroTipo);
        $this->assertSame(ResultadoService::TIPO_RETIFICADO, $segundoTipo, 'publicado é imutável: correção cria um retificado');
        $this->assertSame('888', $this->resultadoId((int) $raiz->id), 'o metadado guarda o resultado vigente');
    }

    function testRespostaSemIdNaoGravaENaoFalha()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"detail": "sem o id"}');

        $this->assertFalse($this->resultadoId((int) $raiz->id));
        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'a captura ausente não pode falhar o envio nem gerar retry');
    }

    function testFalhaDeServidorReenfileira()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comProvedorDuble(
            fn() => $this->desfecho(),
            function () use ($raiz) {
                FakeIntegrationProvider::$aoEnviarResultado = function () {
                    throw new SendFailed(
                        $this->desfecho(['result' => SendResult::Error, 'httpStatus' => 500]),
                        IntegrationError::http('erro interno', 500)
                    );
                };
                $this->enfileira($raiz);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        $jobs = $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
        $this->assertCount(1, $jobs, 'falha de servidor merece retentativa');
    }

    function testIneligivelNaExecucaoNaoEnviaNada()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Despublicado');
        $raiz = $this->raizElegivel($owner, $subsite);
        $this->faseFinal($raiz, publicada: false);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->comProvedorDuble(
            fn() => $this->desfecho(),
            function () use ($raiz) {
                FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->fail('edital inelegível não pode gerar envio');
                $this->enfileira($raiz);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        $logs = $this->app->em->getConnection()->fetchFirstColumn(
            'SELECT action FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => (int) $raiz->id]
        );
        $this->assertSame([], $logs, 'sem envio não há nem registro de tentativa');
    }

    function testProvedorSemACapacidadeEncerraSemRetry()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // O provedor da Gestão não implementa SendsResultado: o envio é erro de configuração.
        $this->comProviderConfigurado('gestao', function () use ($raiz) {
            $this->enfileira($raiz);
            $this->processJobs(number_of_jobs: 1);
        });

        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'configuração errada não melhora com retentativa');
        $resultados = $this->app->em->getConnection()->fetchFirstColumn(
            'SELECT status FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => (int) $raiz->id]
        );
        $this->assertSame(['error'], $resultados, 'o envio frustrado fica visível na aba de logs');
    }
}
