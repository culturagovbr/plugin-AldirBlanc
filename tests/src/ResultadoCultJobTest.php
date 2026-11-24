<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\EnvioSituacao;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\BeneficiariosService;
use AldirBlanc\Services\FormularioResultadoService;
use AldirBlanc\Services\ResultadoService;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsAnexos;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** O job de resultado: envia pela capacidade, loga no action próprio e captura o id do 201. */
class ResultadoCultJobTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use LoadsConectaResponses;
    use SendsOpportunityThroughJob;
    use BuildsAnexos;
    use BuildsContempladas;
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

    private function processaComResposta(Opportunity $raiz, ?string $response, ?array $estrutura = null, ?callable $preparo = null): void
    {
        $this->semCacheDoFormulario(
            fn() => $this->comProvedorDuble(
                fn() => $this->desfecho(),
                function () use ($raiz, $response, $estrutura, $preparo) {
                    FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
                    FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura ?? self::ESTRUTURA_SEM_OBRIGATORIAS;
                    FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->desfecho(['response' => $response]);

                    if ($preparo !== null) {
                        $preparo();
                    }

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
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $estrutura, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 777;
        });
        $segundoTipo = FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo;

        // Literais de propósito: o contrato do PAR é 2 = final, 3 = retificado.
        $this->assertSame(2, $primeiroTipo);
        $this->assertSame(3, $segundoTipo, 'publicado é imutável: correção cria um retificado');
        $this->assertSame('888', $this->resultadoId((int) $raiz->id), 'o metadado guarda o resultado vigente');
    }

    public function testPublicadoCriadoPorForaViraRetificado(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', null, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 20127;
        });

        $this->assertSame(3, FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo, 'sem metadado local, quem decide é o CultBR');
    }

    public function testMetadadoLocalSemPublicadoNoCultBrEnviaFinal(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}');

        $this->assertSame(2, FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo);
    }

    public function testLeituraVemAntesDoEnvio(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');

        $this->assertSame(['publicado', 'lista', 'envio'], FakeIntegrationProvider::$passos);
    }

    #[DataProvider('leiturasIndeterminadas')]
    public function testLeituraIndeterminadaNaoEnviaEApareceNoHistorico(string $leitura): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, function () use ($leitura) {
            FakeIntegrationProvider::${$leitura} = fn() => throw IntegrationError::contract('a lista de resultados do edital veio incompleta');
        });

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame(['error'], $this->statusNoHistorico($raiz));
        $this->assertFalse($this->resultadoId((int) $raiz->id));
        $this->assertSame('nao-enviado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertStringContainsString('consultar o CultBR', $this->ultimoEnvio($raiz)['mensagem']);
    }

    public static function leiturasIndeterminadas(): array
    {
        return ['publicado' => ['aoLerPublicado'], 'lista' => ['aoListarPublicados']];
    }

    /** Listas do edital lidas na ordem: antes do POST e, se preciso, depois dele. */
    private function comListas(array ...$listas): callable
    {
        return function () use ($listas) {
            FakeIntegrationProvider::$aoListarPublicados = function () use (&$listas) {
                return array_shift($listas);
            };
        };
    }

    public function testRespostaSemIdRecuperaOIdPelaLista(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // No edital 53809 o retificado tem data anterior, e o publicado continua apontando o final.
        $this->processaComResposta($raiz, '{"detail": "sem o id"}', null, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 20010;
            $this->comListas([20010], [20010, 20011])();
        });

        $this->assertSame('20011', $this->resultadoId((int) $raiz->id));
        $this->assertSame(['publicado', 'lista', 'envio', 'lista'], FakeIntegrationProvider::$passos);
    }

    public function testComMaisDeUmResultadoNovoValeOMaisRecente(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"detail": "sem o id"}', null, $this->comListas([20010], [20010, 20011, 20012]));

        $this->assertSame('20012', $this->resultadoId((int) $raiz->id));
    }

    public function testRespostaComIdNaoReleALista(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, $this->comListas([20010], [20010, 20011]));

        $this->assertSame('777', $this->resultadoId((int) $raiz->id));
    }

    public function testRespostaSemIdEListaIgualNaoGravaENaoFalha(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"detail": "sem o id"}', null, $this->comListas([20010], [20010]));

        $this->assertFalse($this->resultadoId((int) $raiz->id));
        $this->assertSame(['success'], $this->statusNoHistorico($raiz));
        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'a captura ausente não pode falhar o envio nem gerar retry');
    }

    public function testRespostaSemIdEListaForaDoArNaoFalhaOEnvio(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"detail": "sem o id"}', null, function () {
            $leituras = 0;
            FakeIntegrationProvider::$aoListarPublicados = function () use (&$leituras) {
                return ++$leituras === 1 ? [] : throw IntegrationError::transport('Connection timed out', 28);
            };
        });

        $this->assertFalse($this->resultadoId((int) $raiz->id));
        $this->assertSame(['success'], $this->statusNoHistorico($raiz));
        $this->assertCount(0, $this->app->repo(Job::class)->findAll());
    }

    /** Roda o envio com o POST falhando depois de sair. */
    private function processaComFalha(Opportunity $raiz, \Throwable $falha): void
    {
        $this->processaComResposta($raiz, null, null, function () use ($falha) {
            FakeIntegrationProvider::$aoEnviarResultado = fn() => throw $falha;
        });
    }

    private function falhaDoPost(IntegrationError $causa): SendFailed
    {
        return new SendFailed($this->desfecho(['result' => SendResult::Error, 'httpStatus' => $causa->httpStatus()]), $causa);
    }

    private function ultimoEnvio(Opportunity $raiz): array
    {
        return (new ResultadoService())->ultimoEnvio((int) $raiz->id);
    }

    #[DataProvider('falhasSemConfirmacao')]
    public function testFalhaDepoisDoPostFicaSemConfirmacaoESemNovoJob(IntegrationError $causa): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComFalha($raiz, $this->falhaDoPost($causa));

        $this->assertSame('sem-confirmacao', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertNotNull($this->ultimoEnvio($raiz)['novoPedidoEm'], 'o CultBR ainda pode estar processando');
        $this->assertNull($this->ultimoEnvio($raiz)['mensagem'], 'a situação já diz tudo');
        $this->assertNotNull((new ResultadoService())->envioAConferir((int) $raiz->id));
        $this->assertCount(0, $this->app->repo(Job::class)->findAll(), 'o POST nunca se repete sozinho');
        $tentativa = $this->logs((int) $raiz->id)[0]['attempts'][0];
        $this->assertSame([1, 1], [$tentativa['attempt'], $tentativa['maxAttempts']]);
    }

    public static function falhasSemConfirmacao(): array
    {
        return [
            'erro do servidor' => [IntegrationError::http('erro interno', 500)],
            'redirecionamento' => [IntegrationError::http('redirecionado', 302)],
            'aceite vazio' => [IntegrationError::parse('API não retornou resposta', 201)],
            'aceite ilegível' => [IntegrationError::parse('Resposta da API não é um JSON válido', 201, '<html>')],
            'tempo esgotado' => [IntegrationError::transport('Operation timed out', 28)],
        ];
    }

    #[DataProvider('recusas')]
    public function testQuatrocentosERecusaENaoDeixaEnvioAConferir(int $status): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComFalha($raiz, $this->falhaDoPost(IntegrationError::http('recusado', $status)));

        $this->assertSame('recusado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertNull($this->ultimoEnvio($raiz)['novoPedidoEm']);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
        $this->assertFalse($this->hashGravado($raiz));
    }

    public static function recusas(): array
    {
        return ['400' => [400], '422' => [422], '499' => [499]];
    }

    /** Recusa do CultBR ao POST, com o corpo real gravado em homologação. */
    private function recusa(int $status, string $resposta): SendFailed
    {
        return $this->falhaDoPost(IntegrationError::http('recusado', $status, $this->corpoDaConecta($resposta)));
    }

    public function testRecusaDeQuestaoGuardaONomeDaQuestao(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $estrutura = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 60, 'id_questao_etapa' => 13934, 'nome' => 'Declaração', 'tipo_questao' => 'textarea', 'requerida' => false],
        ]]]]]];

        $this->processaComResposta($raiz, null, $estrutura, function () {
            FakeIntegrationProvider::$aoEnviarResultado = fn() => throw $this->recusa(422, 'recusa-422-obrigatoria');
        });

        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame('O CultBR recusou respostas do formulário.', $ultimo['mensagem']);
        $this->assertSame([['tipo' => 'questao', 'nome' => 'Declaração', 'descricao' => 'Esta questão é obrigatória.']], $ultimo['recusas']);
    }

    public function testRecusaEmDetalheGuardaOTextoDoCultBr(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComFalha($raiz, $this->recusa(422, 'recusa-422-sem-modelo'));

        $this->assertStringStartsWith('Edital sem modelo de planilha', $this->ultimoEnvio($raiz)['mensagem']);
    }

    #[DataProvider('recusasECache')]
    public function testSoAQuestaoForaDaVersaoFazRelerAEstrutura(int $tipoAcao, string $resposta, int $leituras): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 300;

                return $config;
            },
            fn() => $this->comProvedorDuble(fn() => $this->desfecho(), function () use ($raiz, $tipoAcao, $resposta, $leituras) {
                FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => $tipoAcao;
                FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_SEM_OBRIGATORIAS;
                FakeIntegrationProvider::$aoEnviarResultado = fn() => throw $this->recusa(400, $resposta);
                $this->enfileira($raiz);
                $this->processJobs(number_of_jobs: 1);
                FakeIntegrationProvider::$formulariosBuscados = [];

                (new FormularioResultadoService())->formStructureForOpportunity((int) $raiz->id);

                $this->assertCount($leituras, FakeIntegrationProvider::$formulariosBuscados);
            }),
        );
    }

    public static function recusasECache(): array
    {
        return [
            'fora da versão' => [9105, 'recusa-400-fora-da-versao', 1],
            'outra recusa' => [9106, 'recusa-400-nao-permite', 0],
        ];
    }

    public function testEstruturaForaDoArNaoImpedeGuardarARecusa(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, null, null, function () {
            FakeIntegrationProvider::$aoEnviarResultado = function () {
                FakeIntegrationProvider::$aoBuscarFormulario = fn() => throw IntegrationError::transport('Connection timed out', 28);

                throw $this->recusa(422, 'recusa-422-obrigatoria');
            };
        });

        $this->assertSame('recusado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertSame('Questão 13934', $this->ultimoEnvio($raiz)['recusas'][0]['nome']);
    }

    public function testFalhaQueOProvedorNaoLancouFicaSemConfirmacao(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, null, null, function () {
            FakeIntegrationProvider::$aoEnviarResultado = fn() => $this->desfecho(['result' => SendResult::Error]);
        });

        $this->assertSame('sem-confirmacao', $this->ultimoEnvio($raiz)['situacao']);
    }

    public function testFaltaDeConfiguracaoDescobertaNoPostNaoFicaSemConfirmacao(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComFalha($raiz, IntegrationError::configuration('PNAB_CULTBR_HOST', 'sem valor'));

        $this->assertSame('nao-enviado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    private function hashGravado(Opportunity $raiz): string|false
    {
        return $this->metaGravado($raiz, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_LAST_HASH);
    }

    private function metaGravado(Opportunity $raiz, string $chave): string|false
    {
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => (int) $raiz->id, 'key' => $chave]
        );

        return $row === false ? false : (string) $row['value'];
    }

    public function testSituacaoEnviandoEGravadaAntesDoPost(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $durante = null;

        $this->processaComResposta($raiz, null, null, function () use ($raiz, &$durante) {
            FakeIntegrationProvider::$aoListarPublicados = fn() => [20010];
            FakeIntegrationProvider::$aoEnviarResultado = function () use ($raiz, &$durante) {
                $service = new ResultadoService();
                $durante = [$service->ultimoEnvio((int) $raiz->id)['situacao'], $service->envioAConferir((int) $raiz->id)['idsAntes']];

                return $this->desfecho(['response' => '{"id_par_edital_resultado": 777}']);
            };
        });

        $this->assertSame(['enviando', [20010]], $durante);
        $depois = $this->ultimoEnvio($raiz);
        $this->assertSame(['publicado', 777, 2, null], [$depois['situacao'], $depois['id'], $depois['tipo'], $depois['novoPedidoEm']]);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    /** Envio anterior que saiu e ficou sem resposta, com o POST há tantos segundos. */
    private function deixaEnvioAConferir(Opportunity $raiz, int $segundos, array $idsAntes, string $hash, EnvioSituacao $situacao = EnvioSituacao::SemConfirmacao): string
    {
        $em = (new \DateTime("-{$segundos} seconds"))->format(\DateTime::ATOM);
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, $situacao, [
            'contadoDe' => $em,
            'tipo' => 2,
            'aConferir' => ['idsAntes' => $idsAntes, 'hash' => $hash, 'tipo' => 2, 'em' => $em],
        ]);

        return $em;
    }

    private const VENCIDO = ResultadoService::PRAZO_DO_ENVIO_EM_ANDAMENTO + 1;

    #[DataProvider('situacoesSemDesfecho')]
    public function testEnvioSemDesfechoDentroDoPrazoEncerraSemLerESemPost(EnvioSituacao $situacao): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, ResultadoService::PRAZO_DO_ENVIO_EM_ANDAMENTO - 60, [], 'x', $situacao);

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');

        $this->assertSame([], FakeIntegrationProvider::$passos);
        $this->assertSame($situacao->value, $this->ultimoEnvio($raiz)['situacao'], 'a situação não muda');
    }

    public static function situacoesSemDesfecho(): array
    {
        return ['enviando' => [EnvioSituacao::Enviando], 'sem confirmação' => [EnvioSituacao::SemConfirmacao]];
    }

    /** Hash que o conteúdo do edital tem agora, com a estrutura sem obrigatórias. */
    private function hashDeAgora(): string
    {
        return $this->hash([]);
    }

    #[DataProvider('situacoesSemDesfecho')]
    public function testEnvioQueChegouComOMesmoConteudoEAdotadoSemPost(EnvioSituacao $situacao): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], $this->hashDeAgora(), $situacao);

        // No edital 53809 o retificado tem data anterior, e o publicado continua apontando o final.
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 20010;
            FakeIntegrationProvider::$aoListarPublicados = fn() => [20010, 20011];
        });

        $this->assertSame(['lista'], FakeIntegrationProvider::$passos);
        $this->assertSame('20011', $this->resultadoId((int) $raiz->id));
        $this->assertSame($this->hashDeAgora(), $this->hashGravado($raiz));
        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame(['publicado', 20011, 2], [$ultimo['situacao'], $ultimo['id'], $ultimo['tipo']]);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    public function testEnvioQueChegouComOutroConteudoEAdotadoESaiORetificado(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [], 'conteudo-anterior');

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 20010;
            FakeIntegrationProvider::$aoListarPublicados = fn() => [20010];
        });

        $this->assertSame(['lista', 'publicado', 'lista', 'envio'], FakeIntegrationProvider::$passos);
        $this->assertSame(3, FakeIntegrationProvider::$resultadosEnviados[0]['payload']->id_tipo);
        $this->assertSame('777', $this->resultadoId((int) $raiz->id));
        $this->assertSame($this->hashDeAgora(), $this->hashGravado($raiz));
    }

    public function testEnvioQueNaoChegouSegueComUmUnicoPost(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], $this->hashDeAgora());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, function () {
            FakeIntegrationProvider::$aoLerPublicado = fn() => 20010;
            FakeIntegrationProvider::$aoListarPublicados = fn() => [20010];
        });

        $this->assertCount(1, FakeIntegrationProvider::$resultadosEnviados, 'o hash de um envio não entregue não dispensa o reenvio');
        $this->assertSame('publicado', $this->ultimoEnvio($raiz)['situacao']);
    }

    public function testEnvioQueNaoChegouDeixaDeSerConferidoMesmoSemNovoPost(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], 'outro');
        (new ResultadoService())->persistResultadoLastPayloadHash((int) $raiz->id, $this->hashDeAgora());

        $this->processaComResposta($raiz, null, null, fn() => FakeIntegrationProvider::$aoListarPublicados = fn() => [20010]);

        $this->assertSame('nada-mudou', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    public function testPrazoContaDoPostENaoDoPedido(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::Aguardando, [
            'contadoDe' => (new \DateTime('-10 minutes'))->format(\DateTime::ATOM),
        ]);

        $this->processaComFalha($raiz, $this->falhaDoPost(IntegrationError::transport('Operation timed out', 28)));

        $this->assertGreaterThan(new \DateTime('+10 minutes'), new \DateTime($this->ultimoEnvio($raiz)['novoPedidoEm']));
    }

    public function testConciliacaoIndeterminadaNaoEnviaENaoRenovaOPrazo(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], $this->hashDeAgora());
        // O novo pedido reinicia a contagem só do pedido, não do POST anterior.
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::Aguardando, ['contadoDe' => (new \DateTime())->format(\DateTime::ATOM)]);

        $this->processaComResposta($raiz, null, null, function () {
            FakeIntegrationProvider::$aoListarPublicados = fn() => throw IntegrationError::transport('Connection timed out', 28);
        });

        $this->assertSame(['lista'], FakeIntegrationProvider::$passos);
        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame('sem-confirmacao', $ultimo['situacao']);
        $this->assertStringContainsString('consultar o CultBR', $ultimo['mensagem']);
        $this->assertNull($ultimo['novoPedidoEm'], 'o gestor pode pedir de novo');
        $this->assertSame([20010], (new ResultadoService())->envioAConferir((int) $raiz->id)['idsAntes'], 'o envio segue a conferir');
        $this->assertSame(['error'], $this->statusNoHistorico($raiz));
    }

    public function testConciliacaoIndeterminadaNaoDispensaPeloHash(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        (new ResultadoService())->persistResultadoLastPayloadHash((int) $raiz->id, $this->hashDeAgora());
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], 'outro');

        $this->processaComResposta($raiz, null, null, function () {
            FakeIntegrationProvider::$aoListarPublicados = fn() => throw IntegrationError::transport('Connection timed out', 28);
        });

        $this->assertSame('sem-confirmacao', $this->ultimoEnvio($raiz)['situacao']);
    }

    public function testPedidoVencidoQueNaoChegouAoPostNaoConcilia(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::Aguardando, [
            'contadoDe' => (new \DateTime('-' . self::VENCIDO . ' seconds'))->format(\DateTime::ATOM),
        ]);

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');

        $this->assertSame(['publicado', 'lista', 'envio'], FakeIntegrationProvider::$passos);
    }

    public function testImpedimentoNaHoraDeExecutarMantemOEnvioAConferir(): void
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, 'Subsite Conferir Despublicado'));
        $this->faseFinal($raiz, publicada: false);
        $this->deixaEnvioAConferir($raiz, self::VENCIDO, [20010], 'x');

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}');

        $this->assertSame('nao-enviado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertSame([20010], (new ResultadoService())->envioAConferir((int) $raiz->id)['idsAntes']);
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
        $this->assertSame('nao-enviado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertStringContainsString('não está publicado', $this->ultimoEnvio($raiz)['mensagem']);
    }

    function testFormularioIncompletoNaExecucaoNaoEnvia()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $estruturaComObrigatoria);

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados, 'sem formulário completo o envio tomaria 422 do validador');
        $this->assertStringContainsString('incompleto', $this->ultimoEnvio($raiz)['mensagem']);
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
        $this->assertSame('simulado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
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
        $this->assertSame('nada-mudou', $this->ultimoEnvio($raiz)['situacao']);
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

    private function hash(array $respostas, ?array $linhas = null, array $arquivos = []): string
    {
        $metodo = new \ReflectionMethod(ResultadoCultJob::class, 'contentHash');

        return $metodo->invoke(
            $this->app->getRegisteredJobType(ResultadoCultJob::SLUG),
            ['respostas' => $respostas, 'linhas' => $linhas, 'arquivos' => $arquivos],
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

    public function testHashIncluiOConteudoDosArquivosSemDependerDaOrdem(): void
    {
        $arquivo = fn(string $nome, string $hash) => ['nome' => $nome, 'caminho' => "/{$nome}", 'tipo' => 'x', 'descricao' => 'd', 'hash' => $hash];
        $com = fn(array ...$arquivos) => $this->hash([], [], $arquivos);

        $this->assertSame($com($arquivo('a', '1'), $arquivo('b', '2')), $com($arquivo('b', '2'), $arquivo('a', '1')));
        $this->assertNotSame($com($arquivo('a', '1')), $com($arquivo('a', '2')), 'o mesmo nome com outro conteúdo é outro envio');
        $this->assertNotSame($com($arquivo('a', '1'), $arquivo('b', '2')), $com($arquivo('a', '2'), $arquivo('b', '1')));
        $this->assertNotSame($com(), $com($arquivo('a', '1')));
        $this->assertSame(hash('sha256', '[[],[]]'), $com(), 'sem arquivo, o hash já gravado continua valendo');
    }

    private function estruturaComDocumento(): array
    {
        return ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [$this->questaoDeDocumento(30)]]]]]];
    }

    /** Edital com um documento anexado; devolve a raiz. */
    private function editalComDocumento(): Opportunity
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser(), 'Subsite Documento ' . uniqid());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, '{}');
        $this->atualizaRespostasSalvas((int) $raiz->id, [30 => [$this->anexado($raiz)->id]]);

        return $raiz;
    }

    public function testDocumentoVaiNoEnvioEOMesmoNaoEReenviado(): void
    {
        $raiz = $this->editalComDocumento();
        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', $this->estruturaComDocumento());

        $payload = FakeIntegrationProvider::$resultadosEnviados[0]['payload'];
        $this->assertSame(['ata.pdf'], array_column($payload->arquivos, 'nome'));
        $this->assertSame([['id_par_questao' => 10030, 'resposta' => ['resposta' => 'ata.pdf']]], $payload->respostas);

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 888}', $this->estruturaComDocumento());
        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame('nada-mudou', $this->ultimoEnvio($raiz)['situacao']);
    }

    public function testEditalComModeloEDocumentoNaoEnvia(): void
    {
        $raiz = $this->editalComDocumento();

        $this->processaComResposta($raiz, '{}', $this->estruturaComDocumento(), function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
        });

        $this->assertSame(['nao-enviado', FormularioResultadoService::motivoPlanilhaComAnexos()], [$this->ultimoEnvio($raiz)['situacao'], $this->ultimoEnvio($raiz)['mensagem']]);
        $this->assertSame([], FakeIntegrationProvider::$passos);
        $this->assertSame([], $this->statusNoHistorico($raiz));
    }

    public function testDocumentoComPlanilhaSoEncerraDepoisDeConferirOEnvioSemResposta(): void
    {
        $raiz = $this->editalComDocumento();
        $this->deixaEnvioAConferir($raiz, 3600, [], 'x');

        $this->processaComResposta($raiz, '{}', $this->estruturaComDocumento(), function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
            FakeIntegrationProvider::$aoListarPublicados = fn() => throw IntegrationError::transport('Connection timed out', 28);
        });

        $this->assertNotSame(FormularioResultadoService::motivoPlanilhaComAnexos(), $this->ultimoEnvio($raiz)['mensagem']);
        $this->assertNotNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    public function testDocumentoQueSumiuDoDiscoNaoEnvia(): void
    {
        $raiz = $this->editalComDocumento();
        unlink($this->app->repo('OpportunityFile')->findOneBy(['owner' => $raiz])->getPath());

        $this->processaComResposta($raiz, '{}', $this->estruturaComDocumento());

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame(['nao-enviado', 'Não foi possível montar o envio do resultado.'], [$this->ultimoEnvio($raiz)['situacao'], $this->ultimoEnvio($raiz)['mensagem']]);
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

    private function processaComModelo(Opportunity $raiz, callable $modelo, ?callable $aoEnviar = null): void
    {
        $this->semCacheDoFormulario(fn() => $this->comProvedorDuble(fn() => $this->desfecho(), function () use ($raiz, $modelo, $aoEnviar) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_SEM_OBRIGATORIAS;
            FakeIntegrationProvider::$aoBuscarModelo = $modelo;
            FakeIntegrationProvider::$aoEnviarResultado = $aoEnviar ?? fn() => $this->desfecho(['response' => '{"id_par_edital_resultado": 777}']);
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

    public function testEditalComModeloEnviaUmaLinhaPorContemplada(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);

        $this->processaComModelo($raiz, fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'));

        $this->assertEquals([self::CELULAS_COMPLETAS + ['cpf' => '52998224725']], FakeIntegrationProvider::$resultadosEnviados[0]['payload']->linhas);
        $this->assertSame('publicado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertSame([53790], FakeIntegrationProvider::$modelosBuscados);
    }

    public function testLinhaQueMudaGeraNovoEnvioEALinhaIgualNao(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $contemplada = $this->contempladaCompleta($raiz, $fase);
        $modelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
        $this->processaComModelo($raiz, $modelo);

        $this->processaComModelo($raiz, $modelo);
        $this->assertSame('nada-mudou', $this->ultimoEnvio($raiz)['situacao']);

        (new BeneficiariosService($this->planilha()))->salvarCelulas($raiz, (int) $contemplada->id, ['raca_etnia' => 'Preta']);
        $this->processaComModelo($raiz, $modelo);

        $this->assertSame('Preta', FakeIntegrationProvider::$resultadosEnviados[0]['payload']->linhas[0]['raca_etnia']);
    }

    public function testContempladaComPendenciaEncerraSemEnvioEGuardaQuais(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);
        $pendente = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->processaComModelo($raiz, fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'));

        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame(['nao-enviado', 1], [$ultimo['situacao'], $ultimo['totalPendentes']]);
        $this->assertStringContainsString('lista de beneficiários', $ultimo['mensagem']);
        $this->assertSame([(string) $pendente->number, 'Tipo de agente responsável'], [$ultimo['recusas'][0]['inscricao'], $ultimo['recusas'][0]['coluna']]);
        $this->assertSame([], FakeIntegrationProvider::$passos, 'nada é lido nem enviado ao CultBR');
        $this->assertSame([], $this->statusNoHistorico($raiz));
        $this->assertNull((new ResultadoService())->resultadoLastPayloadHash((int) $raiz->id));
    }

    public function testPendenciaSoEncerraDepoisDeConferirOEnvioSemResposta(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $antes = (new \DateTime('-1 hour'))->format(\DateTime::ATOM);
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::SemConfirmacao, [
            'contadoDe' => $antes,
            'aConferir' => ['idsAntes' => [], 'hash' => 'x', 'tipo' => 2, 'em' => $antes],
        ]);

        $this->semCacheDoFormulario(fn() => $this->comProvedorDuble(fn() => $this->desfecho(), function () use ($raiz) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => self::ESTRUTURA_SEM_OBRIGATORIAS;
            FakeIntegrationProvider::$aoBuscarModelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
            FakeIntegrationProvider::$aoListarPublicados = fn() => throw IntegrationError::transport('Connection timed out', 28);
            $this->enfileira($raiz);
            $this->processJobs(number_of_jobs: 1);
        }));

        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame([0, []], [$ultimo['totalPendentes'], $ultimo['recusas']], 'sem a conferência o pedido falha por ela');
        $this->assertNotNull((new ResultadoService())->envioAConferir((int) $raiz->id));
    }

    public function testConteudoQueFalhouNaoERemontadoNoMesmoPedido(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $leituras = 0;

        $this->processaComModelo($raiz, function () use (&$leituras) {
            return ++$leituras === 1 ? throw IntegrationError::transport('Connection timed out', 28) : $this->respostaDaConecta('modelo-pontos-de-cultura');
        });

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertSame(1, $leituras);
    }

    public function testEditalComModeloESemContempladaEnviaSemOCampo(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComModelo($raiz, fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'));

        $this->assertArrayNotHasKey('linhas', FakeIntegrationProvider::$resultadosEnviados[0]['payload']->toArray());
    }

    public function testRecusaDeLinhaApontaAInscricaoEAColuna(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $numeros = [
            (string) $this->contempladaCompleta($raiz, $fase)->number,
            (string) $this->contempladaCompleta($raiz, $fase, '111.444.777-35')->number,
        ];
        sort($numeros, SORT_STRING);

        $this->processaComModelo(
            $raiz,
            fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'),
            fn() => throw $this->recusa(422, 'recusa-422-linhas-invalidas'),
        );

        $ultimo = $this->ultimoEnvio($raiz);
        $this->assertSame('recusado', $ultimo['situacao']);
        $this->assertSame([$numeros[1], 'CPF'], [$ultimo['recusas'][0]['inscricao'], $ultimo['recusas'][0]['coluna']]);
    }

    public function testRecusaPelaQuestaoDaPlanilhaNaoFicaComoEnviada(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);

        $this->processaComModelo(
            $raiz,
            fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'),
            fn() => throw $this->recusa(422, 'recusa-422-questao-da-planilha'),
        );

        $this->assertStringContainsString('não tem a questão da planilha', $this->ultimoEnvio($raiz)['mensagem']);
        $this->assertFalse($this->resultadoId((int) $raiz->id));
        $this->assertNull((new ResultadoService())->resultadoLastPayloadHash((int) $raiz->id));
    }

    public function testFalhaAoLerOModeloNaoEnviaEApareceNoHistorico(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComModelo($raiz, fn() => throw IntegrationError::transport('Connection timed out', 28));

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertCount(0, $this->app->repo(Job::class)->findAll());
        $this->assertSame(['error'], $this->statusNoHistorico($raiz));
        $this->assertStringContainsString('consultar o CultBR', $this->ultimoEnvio($raiz)['mensagem']);
    }

    public function testConteudoQueNaoSeMontaNaoEnvia(): void
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->processaComResposta($raiz, '{"id_par_edital_resultado": 777}', null, function () {
            $leituras = 0;
            // A primeira leitura é a da completude; as seguintes, as da montagem.
            FakeIntegrationProvider::$aoBuscarFormulario = function () use (&$leituras) {
                return ++$leituras === 1 ? self::ESTRUTURA_SEM_OBRIGATORIAS : throw new \RuntimeException('montagem');
            };
        });

        $this->assertSame([], FakeIntegrationProvider::$resultadosEnviados);
        $this->assertStringContainsString('montar o envio', $this->ultimoEnvio($raiz)['mensagem']);
        $this->assertNull((new ResultadoService())->envioAConferir((int) $raiz->id));
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
        $this->assertSame('nao-enviado', $this->ultimoEnvio($raiz)['situacao']);
        $this->assertStringContainsString('não está configurado', $this->ultimoEnvio($raiz)['mensagem']);
    }
}
