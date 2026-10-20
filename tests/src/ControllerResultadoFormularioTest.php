<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\FormularioResultadoService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/**
 * GET/POST /aldirblanc/resultadoFormulario — o estado e o preenchimento do formulário de
 * resultado do CultBR, com o travamento do servidor espelhando o da tela.
 */
class ControllerResultadoFormularioTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use SendsOpportunityThroughJob;

    /** Estrutura mínima: duas editáveis obrigatórias + anexo e data de publicação fora do gate. */
    private const ESTRUTURA = ['id_formulario' => 109, 'id_formulario_versao' => 296, 'versao' => 5, 'secoes' => [['etapas' => [['questoes' => [
        ['id_questao' => 10, 'tipo_questao' => 'número', 'tag' => 'media_das_notas', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 11, 'tipo_questao' => 'texto', 'tag' => 'comentarios', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 20, 'tipo_questao' => 'anexo', 'tag' => 'planilha_de_resultados', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 30, 'tipo_questao' => 'data', 'tag' => 'data_publicacao_resultado_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
    ]]]]]];

    private function comFormulario(callable $exercicio, bool $semFormulario = false): void
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => $semFormulario ? null : 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA;

        $this->comConfigDoPlugin(
            function (array $config) {
                // Cache desligado: a chave por tipo de ação atravessaria os cenários do processo.
                $config['integration']['cacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, $exercicio),
        );
    }

    private function callJson(callable $callback): array
    {
        $this->app->response = new Response();

        try {
            $callback();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function cenario(bool $publicada = true): array
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, 'Subsite Formulário ' . uniqid()));
        $fase = $this->faseFinal($raiz, publicada: $publicada);
        $this->login($owner);

        return [$owner, $raiz, $fase];
    }

    private function getFormulario(Opportunity $raiz): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id];

        return $this->callJson(fn() => $controller->callGetResultadoFormulario());
    }

    private function postRespostas(Opportunity $raiz, array $respostas): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id, 'respostas' => $respostas];

        return $this->callJson(fn() => $controller->callPostResultadoFormulario());
    }

    function testUsuarioSemControleDoEditalRecebe403()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $outro = $this->userDirector->createUser();
            $this->login($outro);

            $this->getFormulario($raiz);

            $this->assertEquals(403, $this->responseStatus());
        });
    }

    function testEditalSemFormularioNoParFicaNoEstadoProprio()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_SEM_FORMULARIO, $payload['estado']);
            $this->assertNull($payload['estrutura']);
            $this->assertFalse($payload['envioHabilitado']);
        }, semFormulario: true);
    }

    /** Estrutura com as tags que o cálculo automático reconhece. */
    private const ESTRUTURA_AUTO = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
        ['id_questao' => 50, 'tipo_questao' => 'número', 'tag' => 'total_inscritos_edital_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 51, 'tipo_questao' => 'número', 'tag' => 'total_contemplados_edital_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 52, 'tipo_questao' => 'moeda', 'tag' => 'valor_total_repassado_edital_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 53, 'tipo_questao' => 'Tabela Dinâmica', 'tag' => 'dados_por_categoria_resultado_1_1', 'requerida' => true, 'opcao' => null, 'config' => ['minRows' => 1, 'columns' => [
            ['key' => 'categoria', 'label' => 'Categoria', 'readOnly' => true, 'required' => true],
            ['key' => 'valor_individual', 'label' => 'Valor individual', 'required' => true],
            ['key' => 'quantidade_contemplados', 'label' => 'Quantidade', 'required' => true],
            ['key' => 'valor_total_categoria', 'label' => 'Total', 'readOnly' => true, 'required' => false],
        ]], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
    ]]]]]];

    private function inscricao(Opportunity $fase, int $status, ?string $categoria = null): void
    {
        $this->app->disableAccessControl();
        $inscricao = new \MapasCulturais\Entities\Registration();
        $inscricao->opportunity = $fase;
        $inscricao->owner = $fase->owner;
        $inscricao->save(true);
        $conn = $this->app->em->getConnection();
        $conn->executeStatement('UPDATE registration SET status = :s WHERE id = :id', ['s' => $status, 'id' => $inscricao->id]);
        if ($categoria !== null) {
            $conn->executeStatement('UPDATE registration SET range = :r WHERE id = :id', ['r' => $categoria, 'id' => $inscricao->id]);
        }
        $this->app->enableAccessControl();
    }

    function testCamposConhecidosDoEditalSaoCalculadosENaoSalvaveis()
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_AUTO;

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['cacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, function () {
                [$owner, $raiz, $fase] = $this->cenario();

                $this->app->disableAccessControl();
                $raiz->registrationRanges = [
                    ['label' => 'Música', 'limit' => 5, 'value' => 1000],
                    ['label' => 'Teatro', 'limit' => 2, 'value' => 500],
                ];
                $raiz->save(true);
                $this->app->enableAccessControl();

                $this->inscricao($raiz, \MapasCulturais\Entities\Registration::STATUS_SENT);
                $this->inscricao($raiz, \MapasCulturais\Entities\Registration::STATUS_NOTAPPROVED);
                $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
                $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
                // Os UPDATEs por SQL não aparecem nas entidades da identity map — leitura fria.
                $this->app->em->clear();
                $this->login($owner);

                $payload = $this->getFormulario($raiz);
                $autos = $payload['respostasAutomaticas'];

                $this->assertSame(2, $autos['50'] ?? $autos[50], 'inscritas reais da primeira fase');
                $this->assertSame(2, $autos['51'] ?? $autos[51], 'contempladas reais da fase final');
                $this->assertArrayNotHasKey('52', $autos, 'valor repassado não é travado');
                $this->assertSame(2000, $payload['respostas']['52'] ?? $payload['respostas'][52], 'valor repassado vem pré-preenchido do cálculo');
                $linhas = $autos['53'] ?? $autos[53];
                $this->assertSame(['categoria' => 'Música', 'valor_individual' => 1000, 'quantidade_contemplados' => 2, 'valor_total_categoria' => 2000], $linhas[0]);
                $this->assertSame(['categoria' => 'Teatro', 'valor_individual' => 500, 'quantidade_contemplados' => 0, 'valor_total_categoria' => 0], $linhas[1]);

                $this->postRespostas($raiz, [50 => 99, 52 => 1234]);
                $relido = $this->getFormulario($raiz);
                $this->assertArrayNotHasKey('50', (array) $relido['respostas'], 'questão automática não aceita valor do cliente');
                $this->assertSame(1234, $relido['respostas']['52'] ?? $relido['respostas'][52], 'o gestor pode ajustar o valor sugerido');
            }),
        );
    }

    function testAutomaticasSozinhasCompletamOFormulario()
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 111, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 60, 'tipo_questao' => 'número', 'tag' => 'total_inscritos_edital_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
            ['id_questao' => 61, 'tipo_questao' => 'número', 'tag' => 'total_contemplados_edital_1_1', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ]]]]]];

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['cacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, function () {
                [, $raiz] = $this->cenario();

                $payload = $this->getFormulario($raiz);

                $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $payload['estado'], 'obrigatórias satisfeitas pelo cálculo contam para a completude');
                $this->assertTrue($payload['envioHabilitado']);
            }),
        );
    }

    function testSemCategoriasValorETabelaFicamSemFonte()
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_AUTO;

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['cacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, function () {
                [, $raiz] = $this->cenario();

                $payload = $this->getFormulario($raiz);
                $autos = $payload['respostasAutomaticas'];

                $this->assertArrayHasKey('50', $autos, 'contagem existe mesmo zerada');
                $this->assertArrayNotHasKey('52', $autos, 'sem categorias não há valor calculado');
                $this->assertArrayNotHasKey('52', (array) $payload['respostas'], 'sem categorias o campo de valor vem vazio');
                $this->assertSame([53], $payload['questoesSemFonte'], 'só a tabela é fonte única; o valor é campo');
                $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado'], 'sem fonte não completa — força a configuração na origem');

                $this->postRespostas($raiz, [53 => [['categoria' => 'x']]]);
                $relido = $this->getFormulario($raiz);
                $this->assertSame([], (array) $relido['respostas'], 'questão sem fonte não aceita valor do cliente');

                $this->postRespostas($raiz, [52 => 123]);
                $completo = $this->getFormulario($raiz);
                $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $completo['estado'], 'sem fonte fica fora do gate — não trava a completude invisivelmente');
            }),
        );
    }

    function testRespostasVaziasSerializamComoObjetoJson()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $controller = new TestableController();
            $controller->data = ['opportunityId' => $raiz->id];
            $this->app->response = new Response();

            try {
                $controller->callGetResultadoFormulario();
                $this->fail('Esperava Halt');
            } catch (Halt) {
            }

            $corpo = (string) $this->app->response->getBody();
            $this->assertStringContainsString('"respostas":{}', $corpo, 'vazio como [] faria o cliente tratar respostas como lista');
        });
    }

    function testEstadoCarregaADataDePublicacaoAutomatica()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->getFormulario($raiz);

            $this->assertStringStartsWith('2026-10-10T12:00:00', (string) $payload['dataPublicacao'], 'a data automática é o publishTimestamp da fase final');
        });
    }

    function testFaseFinalNaoPublicadaTravaLeituraEESave()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario(publicada: false);

            $payload = $this->getFormulario($raiz);
            $this->assertSame(FormularioResultadoService::ESTADO_AGUARDANDO_PUBLICACAO, $payload['estado']);

            $this->postRespostas($raiz, [10 => 5]);
            $this->assertEquals(400, $this->responseStatus());
            $this->assertSame([], (new FormularioResultadoService())->savedRespostas((int) $raiz->id), 'o gate do servidor não deixa salvar');
        });
    }

    function testSaveParcialSalvaEDevolvePendente()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 25]);

            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado']);
            $this->assertFalse($payload['envioHabilitado']);
            $this->assertSame([10 => 25], $payload['respostas']);

            $relido = $this->getFormulario($raiz);
            $this->assertSame([10 => 25], $relido['respostas'], 'a resposta parcial persiste');
        });
    }

    function testObrigatoriasEditaveisCompletasHabilitamOEnvio()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 25, 11 => 'executado conforme o plano']);

            $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $payload['estado'], 'anexo e data de publicação não entram no gate');
            $this->assertTrue($payload['envioHabilitado']);
        });
    }

    function testRespostaInvalidaRetorna400SemSalvarNada()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 'não sou número']);

            $this->assertEquals(400, $this->responseStatus());
            $this->assertArrayHasKey('10', $payload['data']['respostas']);
            $this->assertSame([], (new FormularioResultadoService())->savedRespostas((int) $raiz->id));
        });
    }

    function testDataDePublicacaoNuncaVemDoCliente()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [30 => '2020-01-01']);

            $this->assertEquals(400, $this->responseStatus());
            $this->assertArrayHasKey('30', $payload['data']['respostas']);
        });
    }

    function testEditalComResultadoEnviadoFicaNoEstadoEnviado()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '20010');

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_ENVIADO, $payload['estado']);
            $this->assertFalse($payload['envioHabilitado']);
        });
    }

    function testApiIndisponivelResponde502SemEstourar()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            FakeIntegrationProvider::$aoBuscarFormulario = function () {
                throw \AldirBlanc\Exceptions\IntegrationError::transport('Connection timed out', 28);
            };

            $this->getFormulario($raiz);
            $this->assertEquals(502, $this->responseStatus());

            $this->postRespostas($raiz, [10 => 5]);
            $this->assertEquals(502, $this->responseStatus());
        });
    }

    function testRespostaNulaLimpaOQueEstavaSalvo()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $this->postRespostas($raiz, [10 => 25, 11 => 'texto']);
            $payload = $this->postRespostas($raiz, [11 => null]);

            $this->assertSame([10 => 25], $payload['respostas']);
            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado']);
        });
    }
}
