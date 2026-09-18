<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use AldirBlanc\Integration\ParActionPageLimits;
use Laminas\Diactoros\Response;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\Traits\UserDirector;

/**
 * GET /aldirblanc/parAcoes — catálogo de ações do PAR exibido no card de um modelo oficial.
 * É o único caso de uso síncrono dentro da requisição: o que falha aqui chega como tela.
 */
class ControllerParAcoesTest extends TestCase
{
    use UserDirector;
    use CapturesLog;
    use ConfiguresPlugin;

    private const HOST = 'http://cultbr.invalid';

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

    /** Modo real com transporte falso: exercita provedor e client de verdade, sem sair da máquina. */
    private function comCatalogo(FakeTransport $transport, callable $exercicio): void
    {
        $this->comProvedorDoCatalogo(fn() => new GestaoProvider($transport), $transport, $exercicio);
    }

    private function comProvedorDoCatalogo(callable $criaProvedor, FakeTransport $transport, callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['mode'] = 'live';
                $config['client']['providers']['gestao']['host'] = self::HOST;
                $config['client']['providers']['gestao']['token'] = 'token-de-teste';
                $config['client']['providers']['conecta'] = [
                    'mode' => 'live',
                    'host' => self::HOST,
                    'token' => 'token-de-teste',
                    'parAcoesEndpoint' => 'par/acoes',
                ] + ($config['client']['providers']['conecta'] ?? []);

                return $config;
            },
            function () use ($criaProvedor, $exercicio) {
                $controller = new TestableController();
                $controller->setIntegrationProvider($criaProvedor());

                $exercicio($controller);
            }
        );
    }

    private function loginComPermissao(): void
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));
    }

    private static function corpo(array $data, array $pagination = []): string
    {
        return json_encode(['data' => $data, 'pagination' => $pagination]);
    }

    private static function acao(string $nome, int $idCadastro = 1): array
    {
        return ['nome_acao' => $nome, 'id_par_cadastro' => $idCadastro];
    }

    function testCatalogoRespondeComAcoesNormalizadasEPaginacao()
    {
        $this->loginComPermissao();

        $corpo = self::corpo(
            [self::acao('1.1 Fomento Cultural'), self::acao('1.5 Subsídio a espaços culturais')],
            ['skip' => 0, 'limit' => 1000, 'total' => 2, 'next' => null, 'previous' => null]
        );

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertEquals(200, $this->responseStatus());
            $this->assertCount(2, $resposta['data']);
            $this->assertEquals('1.1 Fomento Cultural', $resposta['data'][0]['label']);
            $this->assertEquals('1.1 Fomento Cultural', $resposta['data'][0]['value']);
            $this->assertEquals(2, $resposta['pagination']['total']);
            $this->assertNull($resposta['pagination']['next']);
        });
    }

    /** A API devolve a ação por cadastro: 926 linhas para 28 nomes, e quem reduz somos nós. */
    function testNomesRepetidosViramUmaAcaoSo()
    {
        $this->loginComPermissao();

        $corpo = self::corpo([
            self::acao('1.5 Subsídio a espaços culturais', 11),
            self::acao('1.1 Fomento Cultural', 22),
            self::acao('1.5 Subsídio a espaços culturais', 33),
        ]);

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertSame(
                ['1.1 Fomento Cultural', '1.5 Subsídio a espaços culturais'],
                array_column($resposta['data'], 'label'),
                'Sem repetição e em ordem de rótulo'
            );
        });
    }

    /** Resposta que chegou, mas não serve: 502 diz isso; 504 diria que não houve resposta. */
    function testRespostaSemAChaveDataResponde502()
    {
        $this->loginComPermissao();

        $this->comCatalogo(new FakeTransport(status: 200, body: '{"itens":[]}'), function ($controller) {
            $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertEquals(502, $this->responseStatus());
        });
    }

    /** Catálogo vazio continua sendo 502 na tela; o que muda é o motivo ficar no log. */
    function testCatalogoVazioResponde502ERegistraOMotivo()
    {
        $this->loginComPermissao();

        $this->comCatalogo(new FakeTransport(status: 200, body: self::corpo([])), function ($controller) {
            $capturado = $this->capturandoLog(function () use ($controller) {
                $this->callJson(fn() => $controller->callGetParAcoes());
            });

            $this->assertEquals(502, $this->responseStatus());
            $this->assertTrue($capturado->hasErrorThatContains('sem nenhuma ação'));
        });
    }

    /** Timeout e DNS são indisponibilidade de verdade — e não podem passar em silêncio. */
    function testFalhaDeTransporteResponde504ComLog()
    {
        $this->loginComPermissao();

        $this->comCatalogo(FakeTransport::falhaDeTransporte(), function ($controller) {
            $capturado = $this->capturandoLog(function () use ($controller) {
                $this->callJson(fn() => $controller->callGetParAcoes());
            });

            $this->assertEquals(504, $this->responseStatus());
            $this->assertTrue($capturado->hasErrorThatContains('listar as ações do PAR'));
        });
    }

    /** A API respondeu 500: houve conexão, então a tela não pode dizer que faltou conexão. */
    function testErroHttpDaApiResponde502()
    {
        $this->loginComPermissao();

        $this->comCatalogo(new FakeTransport(status: 500, body: 'Internal Server Error'), function ($controller) {
            $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertEquals(502, $this->responseStatus());
        });
    }

    /** Corpo `null` num 200 é erro de contrato desde que o gate de revogação passou a exigir forma. */
    function testCorpoNuloResponde502()
    {
        $this->loginComPermissao();

        $this->comCatalogo(new FakeTransport(status: 200, body: 'null'), function ($controller) {
            $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertEquals(502, $this->responseStatus());
        });
    }

    /**
     * Registro do comportamento atual, não do desejado: o client troca um limite fora da lista
     * pelo default sem avisar, e a resposta ecoa de volta o que o front pediu quando a API não
     * devolve o campo. Mudar isso é decisão de quem for mexer na paginação.
     */
    function testPaginacaoEcoaOLimitePedidoQuandoAApiNaoDevolveOSeu()
    {
        $this->loginComPermissao();

        $corpo = self::corpo([self::acao('1.1 Fomento Cultural')]);

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $controller->data = ['skip' => 0, 'limit' => 50];

            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertEquals(50, $resposta['pagination']['limit'], 'Ecoa o pedido, não o usado');
        });
    }

    /** O catálogo é o mesmo nos dois contratos: trocar o provedor não pode mudar a tela. */
    function testCatalogoPelaConectaProduzOMesmoEnvelope()
    {
        $this->loginComPermissao();

        $corpo = self::corpo(
            [self::acao('1.1 Fomento Cultural'), self::acao('1.5 Subsídio a espaços culturais')],
            ['skip' => 0, 'limit' => 2000, 'total' => 2, 'next' => null, 'previous' => null]
        );

        $pelaGestao = null;
        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) use (&$pelaGestao) {
            $pelaGestao = $this->callJson(fn() => $controller->callGetParAcoes());
        });

        $transporteConecta = new FakeTransport(status: 200, body: $corpo);
        $pelaConecta = null;
        $this->comProvedorDoCatalogo(
            fn() => new ConectaProvider($transporteConecta),
            $transporteConecta,
            function ($controller) use (&$pelaConecta) {
                $pelaConecta = $this->callJson(fn() => $controller->callGetParAcoes());
            }
        );

        $this->assertSame($pelaGestao, $pelaConecta);
        $this->assertStringContainsString('/par/acoes?', (string) $transporteConecta->ultimaUrl());
    }

    /** Uma ação aparece uma vez por cadastro do PAR; o catálogo mostra o nome, não a repetição. */
    function testNomesRepetidosDoCatalogoViramUmaOpcaoSo()
    {
        $this->loginComPermissao();

        $corpo = json_encode([
            'pagination' => ['skip' => 0, 'limit' => 2000, 'total' => 4, 'next' => null, 'previous' => null],
            'data' => [
                ['id_par_acao_meta_acao' => 1, 'nome_acao' => '1.1 Fomento Cultural', 'excluido' => false],
                ['id_par_acao_meta_acao' => 5, 'nome_acao' => '1.1 Fomento Cultural', 'excluido' => false],
                ['id_par_acao_meta_acao' => 3, 'nome_acao' => '2.1 Fomento a projetos de Pontos de Cultura', 'excluido' => false],
                ['id_par_acao_meta_acao' => 7, 'nome_acao' => '2.1 Fomento a projetos de Pontos de Cultura', 'excluido' => false],
            ],
        ]);

        $transport = new FakeTransport(status: 200, body: $corpo);
        $payload = null;
        $this->comProvedorDoCatalogo(
            fn() => new ConectaProvider($transport),
            $transport,
            function ($controller) use (&$payload) {
                $payload = $this->callJson(fn() => $controller->callGetParAcoes());
            }
        );

        $this->assertCount(2, $payload['data'], 'quatro linhas, dois nomes, duas opções');
        $this->assertEqualsCanonicalizing(
            ['1.1 Fomento Cultural', '2.1 Fomento a projetos de Pontos de Cultura'],
            array_column($payload['data'], 'label'),
            'a ordem é da ordenação, não do dedupe',
        );
        $this->assertSame(4, $payload['pagination']['total'], 'o dedupe encurta a lista, não o total que a API declarou');
    }

    /** O que a API declara na paginação é o que o front recebe, sem recálculo nosso. */
    function testPaginacaoDaApiEhPreservada()
    {
        $this->loginComPermissao();

        $corpo = self::corpo(
            [self::acao('1.1 Fomento Cultural')],
            ['skip' => 40, 'limit' => 20, 'total' => 100, 'next' => 60, 'previous' => 20]
        );

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertSame(
                ['skip' => 40, 'limit' => 20, 'total' => 100, 'next' => 60, 'previous' => 20],
                $resposta['pagination']
            );
        });
    }

    /** Sem os campos no envelope, a página seguinte ainda precisa ser alcançável pela tela. */
    function testPaginacaoEhDerivadaQuandoAApiOmiteOsCampos()
    {
        $this->loginComPermissao();

        $corpo = self::corpo(
            [self::acao('1.1 Fomento Cultural')],
            ['skip' => 0, 'limit' => 1, 'total' => 5]
        );

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertSame(1, $resposta['pagination']['next'], 'Há mais páginas a alcançar');
            $this->assertNull($resposta['pagination']['previous'], 'A primeira página não tem anterior');
        });
    }

    /** Requisição sem paginação usa o default da fonte neutra, o mesmo que o tema entrega ao front. */
    function testRequisicaoSemPaginacaoUsaODefaultDaFonteNeutra()
    {
        $this->loginComPermissao();

        $corpo = self::corpo([self::acao('1.1 Fomento Cultural')]);

        $this->comCatalogo(new FakeTransport(status: 200, body: $corpo), function ($controller) {
            $resposta = $this->callJson(fn() => $controller->callGetParAcoes());

            $this->assertSame(ParActionPageLimits::DEFAULT_SKIP, $resposta['pagination']['skip']);
            $this->assertSame(ParActionPageLimits::DEFAULT_LIMIT, $resposta['pagination']['limit']);
        });
    }

    /** Sem host nem token configurados não há o que pedir, e repetir não resolveria. */
    function testIntegracaoSemEndpointConfiguradoResponde502()
    {
        $this->loginComPermissao();

        $this->comCatalogo(
            new FakeTransport(status: 200, body: self::corpo([])),
            function ($controller) {
                $this->semEndpointDoCatalogo(function () use ($controller) {
                    $this->callJson(fn() => $controller->callGetParAcoes());
                });

                $this->assertEquals(502, $this->responseStatus());
            }
        );
    }

    private function semEndpointDoCatalogo(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['providers']['gestao']['parAcoesEndpoint'] = '';

                return $config;
            },
            $exercicio
        );
    }

    function testUsuarioSemPermissaoRecebe403()
    {
        $this->login($this->userDirector->createUser([Role::GESTOR_CULT_BR]));

        $controller = new TestableController();

        $this->callJson(fn() => $controller->callGetParAcoes());

        $this->assertEquals(403, $this->responseStatus());
    }
}
