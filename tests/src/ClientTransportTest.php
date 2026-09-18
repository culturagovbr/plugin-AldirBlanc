<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Transport\CurlTransport;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Doubles\Conecta\CatalogoClient as CatalogoConecta;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Doubles\FixtureAusenteClient;
use Tests\AldirBlanc\Doubles\Gestao\CatalogoClient as CatalogoGestao;
use Tests\AldirBlanc\Doubles\SpyCurlTransport;
use Tests\AldirBlanc\Doubles\TestableAbstractClient;

/**
 * O que o client entrega ao transporte — método, URL e cabeçalhos — e como a fixture é resolvida.
 * Antes disto, trocar host, rota ou token não deixava nenhum teste vermelho.
 */
class ClientTransportTest extends TestCase
{
    use ConfiguresPlugin;

    private const HOST = 'http://cultbr.invalid';

    private function comConfigDoCliente(array $valores, callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) use ($valores) {
                return $this->comValoresDoCliente($config, $valores);
            },
            $exercicio
        );
    }

    /** Modo real com transporte falso: exercita o caminho de rede sem sair da máquina. */
    private function emModoReal(array $valores, callable $exercicio): void
    {
        $this->comConfigDoCliente($valores + ['mode' => 'live', 'host' => self::HOST], $exercicio);
    }

    function testUrlJuntaHostEEndpointComUmaBarraSo()
    {
        $transporte = new FakeTransport();

        $this->emModoReal(['host' => self::HOST . '/'], function () use ($transporte) {
            (new TestableAbstractClient($transporte))->apontarPara('/par/sefic/acoes')->get();
        });

        $this->assertSame(self::HOST . '/par/sefic/acoes', $transporte->ultimaUrl());
    }

    function testUrlSubstituiOMarcadorPeloDocumento()
    {
        $transporte = new FakeTransport();

        $this->emModoReal([], function () use ($transporte) {
            (new TestableAbstractClient($transporte))
                ->apontarPara('par/sefic/pessoa/{document}', '12345678901')
                ->get();
        });

        $this->assertSame(self::HOST . '/par/sefic/pessoa/12345678901', $transporte->ultimaUrl());
    }

    function testCabecalhosLevamOTokenComoBearerEJson()
    {
        $transporte = new FakeTransport();

        $this->emModoReal(['token' => 'segredo-de-teste'], function () use ($transporte) {
            (new TestableAbstractClient($transporte))->apontarPara('par/sefic/acoes')->get();
        });

        $cabecalhos = $transporte->ultimosCabecalhos();

        $this->assertSame('Bearer segredo-de-teste', $cabecalhos['Authorization'] ?? null);
        $this->assertSame('application/json', $cabecalhos['Content-Type'] ?? null);
    }

    function testGetSeguinteAUmPutPedeGetAoTransporte()
    {
        $transporte = new FakeTransport();

        $this->emModoReal([], function () use ($transporte) {
            $client = (new TestableAbstractClient($transporte))->apontarPara('integracao/oportunidades/7');
            $client->put(['id' => 7]);
            $client->get();
        });

        $this->assertSame(['PUT', 'GET'], $transporte->metodos());
    }

    /** O handle é onde o método vaza: reusá-lo faria a operação seguinte a um PUT sair como PUT. */
    function testCadaChamadaAoTransporteCriaUmHandleProprio()
    {
        $transporte = new CurlTransport();
        $novoHandle = new \ReflectionMethod(CurlTransport::class, 'novoHandle');
        $novoHandle->setAccessible(true);

        $this->assertNotSame($novoHandle->invoke($transporte), $novoHandle->invoke($transporte));
    }

    /** O put() da lib manda o corpo na query string, então o PUT sai como post com o método trocado. */
    function testPutTrocaOMetodoSobreOPostEGetVaiComoGet()
    {
        $transporte = new SpyCurlTransport();

        $transporte->send('PUT', self::HOST . '/oportunidades/7', [], '{}');
        $transporte->send('GET', self::HOST . '/par/sefic/acoes', []);

        $this->assertCount(2, $transporte->handles);
        $this->assertSame(['CUSTOMREQUEST:PUT', 'POST'], $transporte->handles[0]->chamadas);
        $this->assertSame(['GET'], $transporte->handles[1]->chamadas);
    }

    function testFixtureDeclaradaPelaClasseEhCarregadaEmModoSimulado()
    {
        $catalogo = (new CatalogoGestao())->get();

        $this->assertArrayHasKey('data', $catalogo);
        $this->assertNotEmpty($catalogo['data']);
    }

    function testFixtureAusenteFalhaNomeandoOArquivo()
    {
        try {
            (new FixtureAusenteClient())->get();
            $this->fail('Esperava falha por fixture inexistente');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
            $this->assertStringContainsString('conecta/nao-existe.php', $e->getMessage());
        }
    }

    /** Com as duas fixtures existindo, a prova passa a ser que cada uma carrega a sua. */
    function testCadaClientHomonimoCarregaAPropriaFixture()
    {
        $this->assertArrayHasKey('data', (new CatalogoGestao())->get());
        $this->assertArrayHasKey('data', (new CatalogoConecta())->get());
    }

    function testClientSemFixtureDeclaradaFalhaEmVezDeIncluirODiretorio()
    {
        try {
            (new TestableAbstractClient())->get();
            $this->fail('Esperava falha por fixture não declarada');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
            $this->assertStringContainsString('não declara arquivo de simulação', $e->getMessage());
        }
    }

    /** Duas implementações de mesmo nome curto em provedores distintos apontam para fixtures distintas. */
    function testClientsHomonimosNaoCompartilhamFixture()
    {
        $gestao = (new CatalogoGestao())->callGetFixturePath();
        $conecta = (new CatalogoConecta())->callGetFixturePath();

        $this->assertNotSame($gestao, $conecta);
        $this->assertStringEndsWith('gestao/par-acoes.php', $gestao);
        $this->assertStringEndsWith('conecta/par-acoes.php', $conecta);
    }
}
