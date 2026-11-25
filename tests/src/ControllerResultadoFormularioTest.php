<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\EnvioSituacao;
use AldirBlanc\Services\FormularioEstrutura;
use AldirBlanc\Services\FormularioResultadoService;
use AldirBlanc\Services\FormularioTabela;
use AldirBlanc\Services\ResultadoRecusas;
use AldirBlanc\Services\ResultadoService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;
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
    use LoadsConectaResponses;
    use SendsOpportunityThroughJob;

    /** Estrutura mínima: duas editáveis obrigatórias e a planilha, fora do gate. */
    private const ESTRUTURA = ['id_formulario' => 109, 'id_formulario_versao' => 296, 'versao' => 5, 'secoes' => [['etapas' => [['questoes' => [
        ['id_questao' => 10, 'nome' => 'Média das notas', 'tipo_questao' => 'número', 'tag' => 'media_das_notas', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 11, 'nome' => 'Comentários', 'tipo_questao' => 'texto', 'tag' => 'comentarios', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
        ['id_questao' => 20, 'nome' => 'Planilha de resultados', 'tipo_questao' => 'anexo', 'tag' => 'planilha_de_resultados', 'requerida' => true, 'opcao' => null, 'config' => [], 'valor_minimo' => null, 'valor_maximo' => null, 'somente_leitura' => false],
    ]]]]]];

    private function comFormulario(callable $exercicio, bool $semFormulario = false): void
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => $semFormulario ? null : 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA;

        $this->comConfigDoPlugin(
            function (array $config) {
                // Cache desligado: a estrutura cacheada por tipo de ação atravessaria os cenários do processo.
                $config['integration']['formularioCacheTTL'] = 0;

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

    /** Cenário com o edital já integrado, que é quando a permissão da ação é consultada. */
    private function cenarioIntegrado(): Opportunity
    {
        [, $raiz] = $this->cenario();
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        return $raiz;
    }

    public function testAcaoQueNaoRecebeResultadoTrazOMotivoERecusaOSave(): void
    {
        $this->comFormulario(function () {
            FakeIntegrationProvider::$aoBuscarPermissao = fn() => false;
            $raiz = $this->cenarioIntegrado();

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_ACAO_SEM_RESULTADO, $payload['estado']);
            $this->assertSame(FormularioResultadoService::motivoAcaoSemResultado(), $payload['motivo']);
            $this->assertFalse($payload['envioHabilitado']);
            $this->assertSame([53790], array_unique(FakeIntegrationProvider::$permissoesBuscadas));

            $recusa = $this->postRespostas($raiz, [10 => 25]);

            $this->assertEquals(400, $this->responseStatus());
            $this->assertSame(FormularioResultadoService::motivoAcaoSemResultado(), $recusa['data']);
            $this->assertSame([], (new FormularioResultadoService())->savedRespostas((int) $raiz->id, self::ESTRUTURA));
        });
    }

    #[DataProvider('permissoesQueNaoImpedem')]
    public function testSemNegativaDaOrigemOFormularioAbreESalva(callable $permissao): void
    {
        $this->comFormulario(function () use ($permissao) {
            FakeIntegrationProvider::$aoBuscarPermissao = $permissao;
            $raiz = $this->cenarioIntegrado();

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado']);
            $this->assertNull($payload['motivo']);
            $this->assertSame([10 => 25], $this->postRespostas($raiz, [10 => 25])['respostas']);
        });
    }

    public static function permissoesQueNaoImpedem(): array
    {
        return [
            'permitido' => [fn() => true],
            'desconhecido' => [fn() => null],
            'leitura com falha' => [fn() => throw \AldirBlanc\Exceptions\IntegrationError::transport('Connection timed out', 28)],
        ];
    }

    public function testEditalSemIdNoParNaoConsultaAPermissao(): void
    {
        $this->comFormulario(function () {
            FakeIntegrationProvider::$aoBuscarPermissao = fn() => false;
            [, $raiz] = $this->cenario();

            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $this->getFormulario($raiz)['estado']);
            $this->assertSame([], FakeIntegrationProvider::$permissoesBuscadas);
        });
    }

    /** Estrutura com as tags que o cálculo automático reconhece, com a tabela de categorias na forma pedida. */
    private function estruturaAuto(string $forma = 'Tabela Dinâmica', bool $somenteLeitura = false): array
    {
        $tabela = $forma === 'tabela'
            ? ['tabela' => ['minLinhas' => 1, 'colunas' => [
                ['id' => 'categoria', 'nome' => 'Categoria', 'tipo' => 'text', 'somenteLeitura' => true],
                ['id' => 'valor_individual', 'nome' => 'Valor', 'tipo' => 'currency', 'obrigatoria' => true],
                ['id' => 'quantidade_contemplados', 'nome' => 'Quantidade', 'tipo' => 'number', 'obrigatoria' => true],
            ]]]
            : ['minRows' => 1, 'columns' => [
                ['key' => 'categoria', 'label' => 'Categoria', 'type' => 'text', 'readOnly' => true, 'required' => true],
                ['key' => 'valor_individual', 'label' => 'Valor', 'type' => 'currency', 'required' => true],
                ['key' => 'quantidade_contemplados', 'label' => 'Quantidade', 'type' => 'number', 'required' => true],
            ]];

        return $this->estruturaCom([
            ['id_questao' => 50, 'tipo_questao' => 'número', 'tag' => 'total_inscritos_edital_1_1', 'requerida' => true],
            ['id_questao' => 51, 'tipo_questao' => 'número', 'tag' => 'total_contemplados_edital_1_1', 'requerida' => true],
            ['id_questao' => 52, 'tipo_questao' => 'moeda', 'tag' => 'valor_total_repassado_edital_1_1', 'requerida' => true],
            ['id_questao' => 53, 'tipo_questao' => $forma, 'tag' => 'dados_por_categoria_resultado_1_1', 'requerida' => true, 'config' => $tabela, 'somente_leitura' => $somenteLeitura],
        ]);
    }

    private function estruturaCom(array $questoes): array
    {
        return ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => $questoes]]]]];
    }

    public static function formasDeTabela(): array
    {
        return ['dinâmica' => ['Tabela Dinâmica'], 'simples' => ['tabela']];
    }

    private function comEstrutura(array $estrutura, callable $exercicio): void
    {
        $this->comFormulario(function () use ($estrutura, $exercicio) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura;
            $exercicio();
        });
    }

    private function gravaFaixas(Opportunity $raiz, array $faixas): void
    {
        $this->app->disableAccessControl();
        $raiz->registrationRanges = $faixas;
        $raiz->save(true);
        $this->app->enableAccessControl();
    }

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

    #[DataProvider('formasDeTabela')]
    public function testCamposConhecidosDoEditalSaoCalculadosENaoSalvaveis(string $forma): void
    {
        $this->comEstrutura($this->estruturaAuto($forma), function () {
            [$owner, $raiz, $fase] = $this->cenario();

            $this->gravaFaixas($raiz, [
                ['label' => 'Música', 'limit' => 5, 'value' => 1000],
                ['label' => 'Teatro', 'limit' => 2, 'value' => 500],
            ]);

            $this->inscricao($raiz, \MapasCulturais\Entities\Registration::STATUS_SENT);
            $this->inscricao($raiz, \MapasCulturais\Entities\Registration::STATUS_NOTAPPROVED);
            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_NOTAPPROVED, 'Música');
            // Os UPDATEs por SQL não aparecem nas entidades da identity map — leitura fria.
            $this->app->em->clear();
            $this->login($owner);

            $payload = $this->getFormulario($raiz);
            $autos = $payload['respostasAutomaticas'];

            $this->assertSame(2, $autos['50'] ?? $autos[50], 'inscritas reais da primeira fase');
            $this->assertSame(2, $autos['51'] ?? $autos[51], 'contempladas reais da fase final');
            $this->assertArrayNotHasKey('52', $autos, 'valor repassado não é travado');
            $this->assertSame(400, $payload['respostas']['52'] ?? $payload['respostas'][52], 'valor repassado vem pré-preenchido do cálculo');
            $this->assertSame([], $payload['pendencias'], 'a sugestão conta como resposta');
            $linhas = $autos['53'] ?? $autos[53];
            $this->assertSame(['categoria' => 'Música', 'valor_individual' => 200, 'quantidade_contemplados' => 2], $linhas[0]);
            $this->assertSame(['categoria' => 'Teatro', 'valor_individual' => 250, 'quantidade_contemplados' => 0], $linhas[1]);

            $this->postRespostas($raiz, [50 => 99, 52 => 1234]);
            $relido = $this->getFormulario($raiz);
            $this->assertArrayNotHasKey('50', (array) $relido['respostas'], 'questão automática não aceita valor do cliente');
            $this->assertSame(1234, $relido['respostas']['52'] ?? $relido['respostas'][52], 'o gestor pode ajustar o valor sugerido');
        });
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo('Job')->findBy(['type' => \AldirBlanc\Jobs\ResultadoCultJob::SLUG]);
    }

    function testSaveQueCompletaOFormularioNaoProduzOPrimeiroEnvio()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 7, 11 => 'Processo concluído sem intercorrências.']);

            $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $payload['estado']);
            $this->assertCount(0, $this->jobsDeResultado(), 'completar o formulário habilita o botão; não envia por conta própria');
        });
    }

    function testSaveDeRespostaEmEditalJaEnviadoNaoEnvia()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

            $this->postRespostas($raiz, [10 => 7, 11 => 'Processo concluído sem intercorrências.']);

            $this->assertCount(0, $this->jobsDeResultado(), 'mudar resposta não retifica sozinho: o gestor decide quando');
        });
    }

    function testSaveQueMantemPendenteNaoEnfileira()
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [11 => 'Só o comentário, sem a média.']);

            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado']);
            $this->assertCount(0, $this->jobsDeResultado(), 'formulário pendente não dispara envio');
        });
    }

    #[DataProvider('formasDeTabela')]
    public function testCategoriasSemValorExibemTabelaSemTotais(string $forma): void
    {
        $this->comEstrutura($this->estruturaAuto($forma), function () {
            [$owner, $raiz, $fase] = $this->cenario();

            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 5, 'value' => null]]);

            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
            $this->app->em->clear();
            $this->login($owner);

            $payload = $this->getFormulario($raiz);
            $autos = $payload['respostasAutomaticas'];

            $linha = ($autos['53'] ?? $autos[53])[0];
            $this->assertSame('Música', $linha['categoria']);
            $this->assertNull($linha['valor_individual'], 'categoria sem valor não inventa número');
            $this->assertSame(1, $linha['quantidade_contemplados'], 'a contagem real segue valendo');
            $this->assertNotContains(53, array_column($payload['pendencias'], 'id_questao'), 'o gestor não preenche a tabela calculada');
            $this->assertArrayNotHasKey('52', (array) $payload['respostas'], 'sem valores não há sugestão de total');
        });
    }

    #[DataProvider('formasDeTabela')]
    public function testInscricoesSemCategoriaContamZeroESugeremTotalZero(string $forma): void
    {
        $this->comEstrutura($this->estruturaAuto($forma), function () {
            [$owner, $raiz, $fase] = $this->cenario();

            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 5, 'value' => 1000]]);

            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED);
            $this->app->em->clear();
            $this->login($owner);

            $payload = $this->getFormulario($raiz);
            $autos = $payload['respostasAutomaticas'];

            $linha = ($autos['53'] ?? $autos[53])[0];
            $this->assertSame(0, $linha['quantidade_contemplados'], 'selecionada sem categoria não entra na linha');
            $this->assertSame(0, $payload['respostas']['52'] ?? $payload['respostas'][52], 'sugestão de total zera — borda documentada: 0 conta como preenchido');
        });
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
                $config['integration']['formularioCacheTTL'] = 0;

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

    #[DataProvider('formasDeTabela')]
    public function testSemCategoriasValorETabelaFicamSemFonte(string $forma): void
    {
        $this->comEstrutura($this->estruturaAuto($forma), function () {
            [, $raiz] = $this->cenario();

            $payload = $this->getFormulario($raiz);
            $autos = $payload['respostasAutomaticas'];

            $this->assertArrayHasKey('50', $autos, 'contagem existe mesmo zerada');
            $this->assertArrayNotHasKey('52', $autos, 'sem categorias não há valor calculado');
            $this->assertArrayNotHasKey('52', (array) $payload['respostas'], 'sem categorias o campo de valor vem vazio');
            $this->assertSame([53], $payload['questoesSemFonte'], 'só a tabela é fonte única; o valor é campo');
            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado'], 'sem fonte não completa — força a configuração na origem');

            $this->postRespostas($raiz, [53 => [['categoria' => 'x', 'valor_individual' => 1, 'quantidade_contemplados' => 1]]]);
            $relido = $this->getFormulario($raiz);
            $this->assertSame([], (array) $relido['respostas'], 'questão sem fonte não aceita valor do cliente');

            $this->postRespostas($raiz, [52 => 123]);
            $completo = $this->getFormulario($raiz);
            $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $completo['estado'], 'sem fonte fica fora do gate — não trava a completude invisivelmente');
        });
    }

    #[DataProvider('tabelasDeCategoriaPublicadas')]
    public function testTabelaDeCategoriaPublicadaECalculadaSemObrigatoriaVazia(int $tipoAcao, int $idQuestao): void
    {
        $estrutura = $this->respostaDaConecta("estrutura-tipo-{$tipoAcao}");

        $this->comEstrutura($estrutura, function () use ($estrutura, $idQuestao) {
            [$owner, $raiz, $fase] = $this->cenario();
            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 4, 'value' => 1000]]);
            $this->inscricao($fase, \MapasCulturais\Entities\Registration::STATUS_APPROVED, 'Música');
            $this->app->em->clear();
            $this->login($owner);

            $linhas = $this->getFormulario($raiz)['respostasAutomaticas'][$idQuestao];
            $colunas = FormularioTabela::daQuestao((new FormularioEstrutura($estrutura))->questoes()[$idQuestao])->colunas;

            $this->assertCount(1, $linhas);
            $this->assertSame(array_column($colunas, 'id'), array_keys($linhas[0]));
            $this->assertEqualsCanonicalizing(['Música', 1, 250], array_values($linhas[0]));
        });
    }

    public static function tabelasDeCategoriaPublicadas(): array
    {
        return [
            '1.1' => [1, 2189], '1.5' => [5, 2216], '1.7' => [2, 2229], '1.8' => [17, 2235], '2.1' => [12, 2241],
            '2.2' => [13, 2247], '2.3' => [14, 2017], '2.4' => [15, 2038], '2.5' => [16, 2752],
        ];
    }

    #[DataProvider('colunasSemFonte')]
    public function testTabelaQueOEditalNaoRespondeVoltaParaOGestor(array $colunaAMais): void
    {
        $estrutura = $this->estruturaAuto();
        $estrutura['secoes'][0]['etapas'][0]['questoes'][3]['config']['columns'][] = $colunaAMais;

        $this->comEstrutura($estrutura, function () {
            [, $raiz] = $this->cenario();
            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 4, 'value' => 1000]]);

            $payload = $this->getFormulario($raiz);

            $this->assertArrayNotHasKey(53, $payload['respostasAutomaticas']);
            $this->assertSame([], $payload['questoesSemFonte']);
            $this->assertContains(53, array_column($payload['pendencias'], 'id_questao'));
        });
    }

    public static function colunasSemFonte(): array
    {
        return [
            'obrigatória sem fonte' => [['key' => 'cep', 'label' => 'CEP', 'type' => 'text', 'required' => true]],
            'lista obrigatória' => [['key' => 'tipo', 'label' => 'Tipo', 'type' => 'dropdown', 'required' => true]],
            'duas do mesmo tipo' => [['key' => 'total', 'label' => 'Total', 'type' => 'currency', 'required' => false]],
        ];
    }

    public function testColunaOpcionalSemFonteFicaForaDaLinhaCalculada(): void
    {
        $estrutura = $this->estruturaAuto();
        $estrutura['secoes'][0]['etapas'][0]['questoes'][3]['config']['columns'][] = ['key' => 'obs', 'label' => 'Obs.', 'type' => 'text'];

        $this->comEstrutura($estrutura, function () {
            [, $raiz] = $this->cenario();
            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 4, 'value' => 1000]]);

            $this->assertSame(
                ['categoria', 'valor_individual', 'quantidade_contemplados'],
                array_keys($this->getFormulario($raiz)['respostasAutomaticas'][53][0]),
            );
        });
    }

    public function testTabelaSomenteLeituraQueOEditalNaoRespondeFicaFora(): void
    {
        $estrutura = $this->estruturaAuto('tabela', somenteLeitura: true);
        $estrutura['secoes'][0]['etapas'][0]['questoes'][3]['config']['tabela']['colunas'][] = ['id' => 'cep', 'nome' => 'CEP', 'tipo' => 'text', 'obrigatoria' => true];

        $this->comEstrutura($estrutura, function () {
            [, $raiz] = $this->cenario();
            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 4, 'value' => 1000]]);

            $payload = $this->getFormulario($raiz);

            $this->assertSame([53], $payload['questoesSemFonte']);
            $this->assertNotContains(53, array_column($payload['pendencias'], 'id_questao'));
        });
    }

    public function testTabelaSemColunasNaoECalculada(): void
    {
        $estrutura = $this->estruturaAuto('tabela', somenteLeitura: true);
        $estrutura['secoes'][0]['etapas'][0]['questoes'][3]['config']['tabela']['colunas'] = [];

        $this->comEstrutura($estrutura, function () {
            [, $raiz] = $this->cenario();
            $this->gravaFaixas($raiz, [['label' => 'Música', 'limit' => 4, 'value' => 1000]]);

            $this->assertArrayNotHasKey(53, $this->getFormulario($raiz)['respostasAutomaticas']);
        });
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
            $this->assertSame([], (new FormularioResultadoService())->savedRespostas((int) $raiz->id, self::ESTRUTURA), 'o gate do servidor não deixa salvar');
        });
    }

    public static function statusSemResultado(): array
    {
        return [
            'rascunho' => [Opportunity::STATUS_DRAFT],
            'lixeira' => [Opportunity::STATUS_TRASH],
        ];
    }

    #[DataProvider('statusSemResultado')]
    public function testEditalForaDePublicacaoFicaNoEstadoProprio(int $status): void
    {
        $this->comFormulario(function () use ($status) {
            [, $raiz] = $this->cenario();
            $raiz->status = $status;
            $raiz->save(true);

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_EDITAL_NAO_PUBLICADO, $payload['estado']);
            $this->assertSame(FormularioResultadoService::motivoEditalNaoPublicado(), $payload['motivo']);
            $this->assertFalse($payload['envioHabilitado']);
        });
    }

    public function testEditalArquivadoSegueComOFormulario(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $raiz->status = Opportunity::STATUS_ARCHIVED;
            $raiz->save(true);

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado'], 'o edital arquivado continua enviando resultado');
            $this->assertNull($payload['motivo']);
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

            $this->assertSame(FormularioResultadoService::ESTADO_COMPLETO, $payload['estado'], 'a planilha não entra no gate');
            $this->assertSame([], $payload['pendencias']);
            $this->assertSame([], $payload['bloqueios']);
            $this->assertTrue($payload['envioHabilitado']);
        });
    }

    public function testRespostaInvalidaVoltaComErroSemDerrubarAValida(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 'não sou número', 11 => 'Cronograma cumprido.']);

            $this->assertEquals(200, $this->responseStatus());
            $this->assertSame([10], array_keys($payload['erros']));
            $this->assertSame([11 => 'Cronograma cumprido.'], $payload['respostas'], 'a válida grava mesmo com outra recusada');
        });
    }

    public function testRespostaRecusadaNaoApagaAQueEstavaGravada(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $this->postRespostas($raiz, [10 => 25]);
            $payload = $this->postRespostas($raiz, [10 => 'não sou número']);

            $this->assertSame([10 => 25], $payload['respostas']);
            $this->assertArrayHasKey(10, $payload['erros']);
        });
    }

    public function testSemErroODicionarioDeErrosVaiVazio(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $this->postRespostas($raiz, [10 => 25]);

            $this->assertStringContainsString('"erros":{}', (string) $this->app->response->getBody(), 'vazio como [] viraria lista no cliente');
        });
    }

    public function testRespostaForaDoAlcanceDoGestorEDescartadaSemErro(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [999 => 'questão de outra versão', 10 => 25]);

            $this->assertEquals(200, $this->responseStatus());
            $this->assertSame([], $payload['erros']);
            $this->assertSame([10 => 25], $payload['respostas']);
        });
    }

    private function gravaRespostasNaVersao(Opportunity $raiz, int|string $versao, array $respostas): void
    {
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 109, 'id_formulario_versao' => $versao, 'versao' => 1, 'respostas' => $respostas,
        ]));
    }

    public function testRespostasDeOutraVersaoNaoVoltamParaATela(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaRespostasNaVersao($raiz, 100, [10 => 25, 999 => 'questão que saiu do formulário']);

            $payload = $this->getFormulario($raiz);

            $this->assertTrue($payload['versaoAlterada']);
            $this->assertSame([], $payload['respostas']);
            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado'], 'o que foi respondido em outra versão não completa esta');
        });
    }

    public function testSalvarDepoisDaTrocaDeVersaoComecaDoZero(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaRespostasNaVersao($raiz, 100, [10 => 25, 999 => 'questão que saiu do formulário']);

            $payload = $this->postRespostas($raiz, [11 => 'Cronograma cumprido.']);

            $this->assertSame([11 => 'Cronograma cumprido.'], $payload['respostas']);
            $this->assertFalse($payload['versaoAlterada']);
        });
    }

    public function testRespostaSoRecusadaNaoTocaNoQueEstavaGravado(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaRespostasNaVersao($raiz, 100, [10 => 25]);

            $payload = $this->postRespostas($raiz, [10 => 'não sou número']);

            $this->assertTrue($payload['versaoAlterada'], 'sem resposta válida nada é regravado');
        });
    }

    #[DataProvider('formasDaVersaoVigente')]
    public function testRespostasDaVersaoVigenteNaoAvisamTroca(int|string $versao): void
    {
        $this->comFormulario(function () use ($versao) {
            [, $raiz] = $this->cenario();
            $this->gravaRespostasNaVersao($raiz, $versao, [10 => 25]);

            $payload = $this->getFormulario($raiz);

            $this->assertFalse($payload['versaoAlterada']);
            $this->assertSame([10 => 25], $payload['respostas']);
        });
    }

    public static function formasDaVersaoVigente(): array
    {
        $vigente = self::ESTRUTURA['id_formulario_versao'];

        return ['número' => [$vigente], 'texto' => [(string) $vigente]];
    }

    public function testEditalSemRespostaGravadaNaoAvisaTroca(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $this->assertFalse($this->getFormulario($raiz)['versaoAlterada']);
        });
    }

    public function testEditalEnviadoEmOutraVersaoSegueEnviado(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaRespostasNaVersao($raiz, 100, [10 => 25]);
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '20010');

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_ENVIADO, $payload['estado']);
            $this->assertTrue($payload['versaoAlterada']);
        });
    }

    public function testAbaAbertaEmVersaoAnteriorNaoGrava(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $controller = new TestableController();
            $controller->data = ['opportunityId' => $raiz->id, 'id_formulario_versao' => 100, 'respostas' => [10 => 25]];

            $payload = $this->callJson(fn() => $controller->callPostResultadoFormulario());

            $this->assertEquals(200, $this->responseStatus());
            $this->assertTrue($payload['versaoAlterada']);
            $this->assertSame([], $payload['respostas']);
            $this->assertSame(self::ESTRUTURA['id_formulario_versao'], $payload['estrutura']['id_formulario_versao'], 'a tela recebe o formulário vigente');
        });
    }

    public function testAbaNaVersaoVigenteGrava(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $controller = new TestableController();
            $controller->data = [
                'opportunityId' => $raiz->id,
                'id_formulario_versao' => (string) self::ESTRUTURA['id_formulario_versao'],
                'respostas' => [10 => 25],
            ];

            $payload = $this->callJson(fn() => $controller->callPostResultadoFormulario());

            $this->assertSame([10 => 25], $payload['respostas']);
        });
    }

    public function testRegraPodeDependerDeQuestaoCalculada(): void
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => 0, 'fonteIdQuestaoEtapa' => 950]];
        $estrutura = self::ESTRUTURA;
        $estrutura['secoes'][0]['etapas'][0]['questoes'] = [
            ['id_questao' => 50, 'id_questao_etapa' => 950, 'tipo_questao' => 'número', 'tag' => 'total_inscritos_edital_1_1', 'requerida' => true],
            ['id_questao' => 51, 'nome' => 'Por que não houve inscritos?', 'tipo_questao' => 'textarea', 'requerida' => true, 'config' => ['regras' => ['itens' => [$regra]]]],
        ];

        $this->comFormulario(function () use ($estrutura) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura;
            [, $raiz] = $this->cenario();

            $this->assertSame([51], array_column($this->getFormulario($raiz)['pendencias'], 'id_questao'));
        });
    }

    public function testEstadoTrazAPlanilhaDoEdital(): void
    {
        $this->comFormulario(function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => false, 'motivo' => 'A ação 1.2 não possui modelo de planilha'];
            $raiz = $this->cenarioIntegrado();

            $payload = $this->postRespostas($raiz, [10 => 25, 11 => 'executado conforme o plano']);

            $this->assertSame(
                ['possui' => false, 'motivo' => 'A ação 1.2 não possui modelo de planilha', 'modelo' => null, 'nomeModelo' => null],
                $payload['planilha'],
            );
            $this->assertTrue($payload['envioHabilitado'], 'sem modelo, envia só as respostas');
        });
    }

    public function testEditalSemIdNoParNaoConsultaAPlanilha(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $this->assertNull($this->getFormulario($raiz)['planilha']);
            $this->assertSame([], FakeIntegrationProvider::$modelosBuscados);
        });
    }

    public function testAbrirESalvarNaoConferemAPlanilha(): void
    {
        $this->comFormulario(function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
            $raiz = $this->cenarioIntegrado();
            $this->inscricao($raiz->lastPhase, Registration::STATUS_APPROVED);

            foreach ($this->app->em->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [] as $inscricao) {
                $this->app->em->detach($inscricao);
            }

            $salvo = $this->postRespostas($raiz, [10 => 25, 11 => 'executado conforme o plano']);

            $this->assertSame([[], [], true], [$salvo['pendencias'], $salvo['bloqueios'], $salvo['envioHabilitado']]);
            $this->assertTrue($this->getFormulario($raiz)['envioHabilitado']);
            $this->assertSame([], $this->app->em->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [], 'nenhuma linha foi montada');
        });
    }

    public function testModeloIndisponivelBloqueiaOEnvioComOMotivo(): void
    {
        $this->comFormulario(function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => throw \AldirBlanc\Exceptions\IntegrationError::transport('Connection timed out', 28);
            $raiz = $this->cenarioIntegrado();

            $payload = $this->getFormulario($raiz);

            $this->assertNull($payload['planilha']['possui']);
            $this->assertStringContainsString('Não foi possível consultar', $payload['planilha']['motivo']);
            $this->assertSame([$payload['planilha']['motivo']], array_column($payload['bloqueios'], 'motivo'));
        });
    }

    #[DataProvider('planilhaEDocumentos')]
    public function testPlanilhaEDocumentosNoMesmoEditalDependemDoCultBr(int $tipoAcao, bool $possui, bool $dependente, array $documentos): void
    {
        $this->comEstrutura($this->respostaDaConecta("estrutura-tipo-{$tipoAcao}"), function () use ($possui, $dependente, $documentos) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => $possui];

            $payload = $this->getFormulario($this->cenarioIntegrado());
            $motivos = implode(' ', array_column($payload['bloqueios'], 'motivo'));

            $this->assertSame($dependente, str_contains($motivos, 'planilha e documentos'));
            $this->assertSame($documentos, array_values(array_intersect($documentos ?: [2547, 2548], array_column($payload['pendencias'], 'id_questao'))));
        });
    }

    public static function planilhaEDocumentos(): array
    {
        return [
            '2.3 com modelo' => [14, true, true, []],
            '2.3 sem modelo' => [14, false, false, [2547, 2548]],
            '2.4 com modelo' => [15, true, true, []],
            '2.1 com modelo' => [12, true, false, []],
            '2.5 sem modelo' => [16, false, false, []],
        ];
    }

    public function testDocumentoInvisivelNaoTornaOEditalDependenteDoCultBr(): void
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => true, 'fonteIdQuestaoEtapa' => 900]];
        $estrutura = $this->estruturaCom([
            ['id_questao' => 70, 'id_questao_etapa' => 900, 'nome' => 'Houve?', 'tipo_questao' => 'booleano'],
            ['id_questao' => 71, 'nome' => 'Documento', 'tipo_questao' => 'anexo', 'tag' => 'x', 'requerida' => true, 'config' => ['regras' => ['itens' => [$regra]]]],
        ]);

        $this->comEstrutura($estrutura, function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true];

            $this->assertSame([], $this->getFormulario($this->cenarioIntegrado())['bloqueios']);
        });
    }

    public function testEstadoTrazOUltimoEnvioParaQuemControlaOEdital(): void
    {
        $this->comFormulario(function () {
            [$owner, $raiz] = $this->cenario();
            $this->assertFalse($owner->is('admin'));
            $this->assertNull($this->getFormulario($raiz)['ultimoEnvio']);

            $recusas = ResultadoRecusas::daResposta(422, $this->corpoDaConecta('recusa-422-linha-valor'), null);
            (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::Recusado, [
                'tipo' => 2, 'mensagem' => $recusas->resumo, 'recusas' => $recusas->itens,
            ]);

            $payload = $this->getFormulario($raiz);

            $this->assertSame('recusado', $payload['ultimoEnvio']['situacao']);
            $this->assertSame($recusas->resumo, $payload['ultimoEnvio']['mensagem']);
            $this->assertSame('valor_total_subsidio', $payload['ultimoEnvio']['recusas'][0]['coluna']);
            $this->assertStringNotContainsString('15.000,00', (string) $this->app->response->getBody());
            $this->assertArrayNotHasKey('aConferir', $payload['ultimoEnvio'], 'o que serve à conferência não vai para a tela');
        });
    }

    public function testPendenteDizOQueFalta(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 25]);

            $this->assertSame(
                [['id_questao' => 11, 'nome' => 'Comentários', 'motivo' => 'resposta obrigatória']],
                $payload['pendencias'],
            );
        });
    }

    public function testEditalEnviadoComFormularioCompletoHabilitaARetificacao(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '20010');

            $payload = $this->postRespostas($raiz, [10 => 25, 11 => 'executado conforme o plano']);

            $this->assertSame(FormularioResultadoService::ESTADO_ENVIADO, $payload['estado']);
            $this->assertTrue($payload['envioHabilitado']);
        });
    }

    public function testEditalEnviadoComFormularioIncompletoNaoHabilitaARetificacao(): void
    {
        $this->comFormulario(function () {
            [, $raiz] = $this->cenario();
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '20010');

            $payload = $this->getFormulario($raiz);

            $this->assertSame(FormularioResultadoService::ESTADO_ENVIADO, $payload['estado']);
            $this->assertFalse($payload['envioHabilitado']);
            $this->assertSame([10, 11], array_column($payload['pendencias'], 'id_questao'));
        });
    }

    public function testQuestaoQueOSistemaNaoTrataBloqueiaOEnvio(): void
    {
        $estrutura = self::ESTRUTURA;
        $estrutura['secoes'][0]['etapas'][0]['questoes'][] = ['id_questao' => 12, 'nome' => 'Assinatura', 'tipo_questao' => 'assinatura', 'requerida' => false];

        $this->comFormulario(function () use ($estrutura) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura;
            [, $raiz] = $this->cenario();

            $payload = $this->postRespostas($raiz, [10 => 25, 11 => 'executado conforme o plano']);

            $this->assertSame([], $payload['pendencias']);
            $this->assertSame(
                [['id_questao' => 12, 'nome' => 'Assinatura', 'motivo' => 'tipo de questão não suportado']],
                $payload['bloqueios'],
            );
            $this->assertSame(FormularioResultadoService::ESTADO_PENDENTE, $payload['estado']);
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
