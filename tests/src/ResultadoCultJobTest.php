<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\FormularioResultadoService;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private const RESPOSTAS_DA_VERSAO_SIMULADA = [
        2186 => 'fe_tec',
        1840 => 15000,
        1842 => ['nenhum'],
        1843 => true,
        2280 => '2026-12-15',
        1845 => 'Cronograma cumprido.',
        2654 => [['nome' => 'Integrante', 'cpf' => '000.000.000-00', 'vinculo' => 'voluntário', 'categoria_grupo' => 'Todas']],
        2081 => ['declaracao_integral'],
    ];

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

    private function semCacheDoFormulario(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            $exercicio,
        );
    }

    private function processaComResposta(Opportunity $raiz, ?string $response, ?array $estrutura = null): void
    {
        $this->semCacheDoFormulario(
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

    function testFormularioIncompletoNaExecucaoNaoEnvia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $estruturaComObrigatoria);

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados, 'sem formulário completo o envio tomaria 422 do validador');
    }

    function testEnvioSimuladoFechaOLogComoSimuladoESemMetadado()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 109, 'id_formulario_versao' => 541, 'versao' => 12, 'respostas' => self::RESPOSTAS_DA_VERSAO_SIMULADA,
        ]));

        $this->semCacheDoFormulario(fn() => $this->comProviderConfigurado('conecta', function () use ($raiz) {
            $this->enfileira($raiz);
            $this->processJobs(number_of_jobs: 1);
        }));

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

    private function estruturaComRegra(): array
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => true, 'fonteIdQuestaoEtapa' => 961]];

        return ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 60, 'id_questao_etapa' => 960, 'nome' => 'Cotas', 'tipo_questao' => 'checkbox', 'opcao' => ['itens' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]]],
            ['id_questao' => 61, 'id_questao_etapa' => 961, 'nome' => 'Houve?', 'tipo_questao' => 'booleano'],
            ['id_questao' => 62, 'id_questao_etapa' => 962, 'nome' => 'Comentário', 'tipo_questao' => 'textarea', 'config' => ['regras' => ['itens' => [$regra]]]],
            ['id_questao' => 63, 'id_questao_etapa' => 963, 'nome' => 'Valor', 'tipo_questao' => 'moeda'],
        ]]]]]];
    }

    #[DataProvider('mesmoConteudo')]
    public function testMesmoConteudoEmOutraFormaDispensaOReenvio(array $primeiras, array $segundas): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, '{}');

        $this->atualizaRespostasSalvas((int) $raiz->id, $primeiras);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $this->estruturaComRegra());
        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados);

        $this->atualizaRespostasSalvas((int) $raiz->id, $segundas);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $this->estruturaComRegra());
        $this->assertCount(0, FakeIntegrationProvider::$resultadosEnviados);
    }

    public static function mesmoConteudo(): array
    {
        return [
            'marcações em outra ordem' => [[60 => ['a', 'b']], [60 => ['b', 'a']]],
            'com e sem resposta em questão invisível' => [[60 => ['a'], 61 => false], [60 => ['a'], 61 => false, 62 => 'Guardado.']],
            'número com e sem casa decimal' => [[63 => 15000.0], [63 => '15000']],
        ];
    }

    public function testMesmasRespostasEmOutraOrdemDaEstruturaDispensamOReenvio(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, '{}');
        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => ['a'], 61 => true]);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $this->estruturaComRegra());

        $reordenada = $this->estruturaComRegra();
        $reordenada['secoes'][0]['etapas'][0]['questoes'] = array_reverse($reordenada['secoes'][0]['etapas'][0]['questoes']);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $reordenada);

        $this->assertCount(0, FakeIntegrationProvider::$resultadosEnviados);
    }

    private function hash(array $respostas, ?array $linhas = null): string
    {
        $metodo = new \ReflectionMethod(ResultadoCultJob::class, 'contentHash');

        return $metodo->invoke(
            $this->app->getRegisteredJobType(ResultadoCultJob::SLUG),
            new Resultado(id_tipo: 2, id_status: 3, respostas: $respostas, linhas: $linhas),
        );
    }

    public function testHashNaoDependeDaOrdemDasLinhasNemDasColunas(): void
    {
        $tabela = fn(array $linhas) => [['id_par_questao' => 1, 'resposta' => ['resposta' => $linhas]]];

        $this->assertSame(
            $this->hash($tabela([['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]])),
            $this->hash($tabela([['b' => 4, 'a' => 3], ['b' => 2, 'a' => 1]])),
        );
        $this->assertSame($this->hash([], [['x' => 1], ['x' => 2]]), $this->hash([], [['x' => 2], ['x' => 1]]));
        $this->assertNotSame($this->hash([], [['x' => 1]]), $this->hash([], [['x' => 2]]), 'a planilha faz parte do conteúdo');
    }

    public function testRespostaDiferenteReenvia(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, '{}');
        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => ['a']]);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $this->estruturaComRegra());

        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => ['b']]);
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $this->estruturaComRegra());

        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados);
    }

    private function processaComModelo(Opportunity $raiz, callable $modelo): void
    {
        $this->semCacheDoFormulario(fn() => $this->comProvedorDuble(fn() => $this->desfecho(), function () use ($raiz, $modelo) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_SEM_OBRIGATORIAS;
            FakeIntegrationProvider::$aoBuscarModelo = $modelo;
            FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->desfecho(['response' => '{"id_par_edital_resultado": 777}']);
            $this->enfileira($raiz);
            $this->processJobs(number_of_jobs: 1);
        }));
    }

    private function statusNoHistorico(Opportunity $raiz): array
    {
        return $this->app->em->getConnection()->fetchFirstColumn(
            'SELECT status FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => (int) $raiz->id]
        );
    }

    public function testEditalComModeloDePlanilhaNaoEnviaEFechaOHistoricoEmErro(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComModelo($raiz, fn() => ['possui_planilha' => true]);

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame(['error'], $this->statusNoHistorico($raiz));
        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'repetir não muda o modelo do edital');
    }

    public function testFalhaAoLerOModeloNaoEnviaEReenfileira(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComModelo($raiz, fn() => throw IntegrationError::transport('Connection timed out', 28));

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertCount(1, $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]), 'a origem pode voltar');
        $this->assertCount(1, $this->statusNoHistorico($raiz), 'a tentativa aparece no histórico');
    }

    public function testEditalSemModeloDePlanilhaEnvia(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComModelo($raiz, fn() => ['possui_planilha' => false]);

        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame([53790], FakeIntegrationProvider::$modelosBuscados);
    }

    public function testEstruturaQueMudaEntreOSalvamentoEOEnvioNaoEnvia(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $questoes = [['id_questao' => 60, 'id_questao_etapa' => 960, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true]];
        $vigente = fn(int $versao) => ['id_formulario' => 110, 'id_formulario_versao' => $versao, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => $questoes]]]]];
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, '{}');
        $this->atualizaRespostasSalvas((int) $raiz->id, [60 => 'Respondido na versão 1']);

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 300;

                return $config;
            },
            fn() => $this->comProvedorDuble(fn() => $this->desfecho(), function () use ($raiz, $vigente) {
                FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 9102;
                FakeIntegrationProvider::$aoBuscarFormulario = fn() => $vigente(1);
                FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->fail('respostas de outra versão não podem sair');
                (new FormularioResultadoService())->formStateForOpportunity($raiz);

                FakeIntegrationProvider::$aoBuscarFormulario = fn() => $vigente(2);
                $this->enfileira($raiz);
                $this->processJobs(number_of_jobs: 1);

                $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
            }),
        );
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
