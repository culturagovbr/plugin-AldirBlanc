<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\ResultadoCultJob;
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

    private function processaComResposta(Opportunity $raiz, ?string $response, ?array $estrutura = null): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProvedorDuble(
                fn() => $this->desfecho(),
                function () use ($raiz, $response, $estrutura) {
                    FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
                    FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura ?? self::ESTRUTURA_SEM_OBRIGATORIAS;
                    FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->desfecho(['response' => $response]);
                    $this->enfileira($raiz);
                    $this->processJobs(number_of_jobs: 1);
                }
            ),
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
        $this->assertSame(2, FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo);
    }

    function testSegundoEnvioViraRetificado()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $estrutura = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 60, 'id_questao_etapa' => 960, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        // O dublê é resetado a cada rodada: o tipo de cada envio é capturado na sua vez.
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'respostas' => [60 => 'Primeira versão'],
        ]));
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $estrutura);
        $primeiroTipo = FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo;

        // O conteúdo muda, senão o reenvio idêntico seria dispensado (dedup do envio).
        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => 'Segunda versão']);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $estrutura);
        $segundoTipo = FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo;

        // Literais de propósito: o contrato do PAR é 2 = final, 3 = retificado.
        $this->assertSame(2, $primeiroTipo);
        $this->assertSame(3, $segundoTipo, 'publicado é imutável: correção cria um retificado');
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
                FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
                FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_SEM_OBRIGATORIAS;
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

    function testFormularioIncompletoNaExecucaoNaoEnviaEMantemOCarimbo()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $fase->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE, '2026-10-16 20:00:00');

        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $estruturaComObrigatoria);

        $this->assertCount(0, FakeIntegrationProvider::$resultadosEnviados, 'entre enfileirar e rodar o formulário manda: incompleto não envia');
        $carimbo = $this->app->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => (int) $fase->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE]
        );
        $this->assertNotFalse($carimbo, 'a janela de alterações fica aberta esperando o formulário');
    }

    /** O caminho de dev: provider conecta real em modo development — simula sem rede. */
    function testEnvioSimuladoFechaOLogComoSimuladoESemMetadado()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comProviderConfigurado('conecta', function () use ($raiz) {
            $this->enfileira($raiz);
            $this->processJobs(number_of_jobs: 1);
        });

        $this->assertFalse($this->resultadoId((int) $raiz->id), 'a resposta simulada ecoa o payload, sem id a capturar');
        $status = $this->app->em->getConnection()->fetchFirstColumn(
            'SELECT status FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => (int) $raiz->id]
        );
        $this->assertSame(['simulated'], $status, 'simulado não pode se passar por envio aceito pela API');
        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'simulado conclui sem retry');
    }

    function testReenvioComConteudoIdenticoNaoViraRetificado()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');
        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados, 'o primeiro envio sempre sai');

        // Nada mudou no conteúdo entre os dois: o segundo é dispensado.
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}');

        $this->assertCount(0, FakeIntegrationProvider::$resultadosEnviados, 'payload idêntico ao último não vira retificado novo');
        $this->assertSame('777', $this->resultadoId((int) $raiz->id), 'sem reenvio, o id vigente permanece o do primeiro');
    }

    function testAlteracaoRealDeConteudoReenvia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $estrutura = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 60, 'id_questao_etapa' => 960, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'respostas' => [60 => 'Primeira versão'],
        ]));
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $estrutura);
        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados);

        // O gestor muda o conteúdo da resposta: o hash difere e o reenvio sai.
        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => 'Segunda versão']);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $estrutura);

        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados, 'conteúdo diferente gera novo envio');
        $this->assertSame('888', $this->resultadoId((int) $raiz->id));
    }

    private function atualizaRespostasSalvas(int $raizId, array $respostas): void
    {
        $this->app->em->getConnection()->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            [
                'value' => json_encode(['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'respostas' => $respostas]),
                'id' => $raizId,
                'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM,
            ]
        );
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
