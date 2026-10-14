<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Mode;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Plugin;
use AldirBlanc\Http\Clients\ParAcaoClient;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableAbstractClient;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;

/**
 * Configuração ausente precisa falhar nomeando a variável, em vez de bater na API antiga,
 * devolver fixture ou estourar TypeError.
 */
class ClientConfigGuardTest extends TestCase
{
    use ConfiguresPlugin;

    private function comConfig(string $chave, mixed $valor, callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) use ($chave, $valor) {
                return $this->comValoresDoCliente($config, [$chave => $valor]);
            },
            $exercicio
        );
    }

    /** Endpoint não vem do ambiente: faltar a chave é erro de programação, e a mensagem diz onde. */
    function testEndpointDoCatalogoAusenteFalhaNomeandoOProvedorEAChave()
    {
        $this->comConfig('parAcoesEndpoint', null, function () {
            try {
                new ParAcaoClient();
                $this->fail('Esperava falha por endpoint não declarado');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                $this->assertStringContainsString('gestao.parAcoesEndpoint', $e->getMessage());
            }
        });
    }

    /** O caminho de cada operação é fato sobre a API, e trocá-lo por engano muda a URL em silêncio. */
    function testCadaProvedorDeclaraOCaminhoDaPropriaApi()
    {
        $providers = Plugin::getInstance()->config['client']['providers'];

        $this->assertSame('par/sefic/pessoa/{document}', $providers['gestao']['entesEndpoint']);
        $this->assertSame('par/sefic/acoes', $providers['gestao']['parAcoesEndpoint']);
        $this->assertSame('integracao/oportunidades/{id}', $providers['gestao']['oportunidadeEndpoint']);

        $this->assertSame('auth/pessoa/{document}/entes', $providers['conecta']['entesEndpoint']);
        $this->assertSame('par/acoes', $providers['conecta']['parAcoesEndpoint']);
        $this->assertSame('oportunidades/{id}', $providers['conecta']['oportunidadeEndpoint']);
        $this->assertSame('validar-token', $providers['conecta']['validarTokenEndpoint']);
    }

    function testEndpointDoCatalogoVazioTambemFalha()
    {
        $this->comConfig('parAcoesEndpoint', '', function () {
            $this->expectException(IntegrationError::class);

            new ParAcaoClient();
        });
    }

    function testHostAusenteFalhaNomeandoAVariavel()
    {
        $this->comConfig('host', null, function () {
            try {
                new ParAcaoClient();
                $this->fail('Esperava falha por host ausente');
            } catch (IntegrationError $e) {
                $this->assertStringContainsString('PNAB_CULTBR_HOST', $e->getMessage());
            }
        });
    }

    function testTokenAusenteFalhaNomeandoAVariavel()
    {
        $this->comConfig('token', null, function () {
            try {
                new ParAcaoClient();
                $this->fail('Esperava falha por token ausente');
            } catch (IntegrationError $e) {
                $this->assertStringContainsString('PNAB_CULTBR_TOKEN', $e->getMessage());
            }
        });
    }

    function testEndpointDoGestorAusenteFalhaNomeandoOProvedorEAChave()
    {
        $this->comConfig('entesEndpoint', null, function () {
            try {
                new GestorClient(new GestorDocument('12345678901'));
                $this->fail('Esperava falha por endpoint do gestor não declarado');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                $this->assertStringContainsString('gestao.entesEndpoint', $e->getMessage());
            }
        });
    }

    /** O catálogo não tem documento a substituir: exigir marcador ali quebraria o client. */
    function testClientSemDocumentoNaoExigeMarcadorNoEndpoint()
    {
        $client = new ParAcaoClient();

        $this->assertInstanceOf(ParAcaoClient::class, $client);
    }

    function testEndpointSemOMarcadorFalhaEmVezDeMontarUrlSemIdentificador()
    {
        try {
            (new TestableAbstractClient())->callPrepareEndpoint('pessoa/entes', '12345678901', '{document}');
            $this->fail('Esperava falha por endpoint sem o marcador');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
            $this->assertStringContainsString('{document}', $e->getMessage());
        }
    }

    function testEndpointComOMarcadorSubstituiODocumento()
    {
        $endpoint = (new TestableAbstractClient())
            ->callPrepareEndpoint('pessoa/{document}/entes', '12345678901', '{document}');

        $this->assertSame('pessoa/12345678901/entes', $endpoint);
    }

    /** Bucket inteiro ausente é erro de configuração, não exceção genérica que o job retentaria. */
    function testBucketDeConfiguracaoAusenteFalhaComoErroDeConfiguracao()
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client'] = [];

                return $config;
            },
            function () {
                try {
                    new TestableAbstractClient();
                    $this->fail('Esperava falha por configuração ausente');
                } catch (IntegrationError $e) {
                    $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                    $this->assertFalse($e->isRetryable(), 'Configuração ausente não muda com retentativa');
                    $this->assertStringContainsString('PNAB_CULTBR_*', $e->getMessage());
                }
            }
        );
    }

    /** Sem lista fechada, "!development" e qualquer erro de digitação virariam modo real em silêncio. */
    function testModoForaDaListaFalhaNomeandoOsValoresAceitos()
    {
        $this->comConfig('mode', '!development', function () {
            try {
                new TestableAbstractClient();
                $this->fail('Esperava falha por modo fora da lista');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                $this->assertStringContainsString('PNAB_CULTBR_MODE', $e->getMessage());
                $this->assertStringContainsString('live', $e->getMessage());
                $this->assertStringContainsString('development', $e->getMessage());
            }
        });
    }

    function testModoVazioNaoViraModoRealPorOmissao()
    {
        $this->comConfig('mode', '', function () {
            $this->expectException(IntegrationError::class);

            new TestableAbstractClient();
        });
    }

    function testOsDoisModosDeclaradosSaoAceitos()
    {
        foreach (Mode::valores() as $valor) {
            $this->comConfig('mode', $valor, function () use ($valor) {
                $this->assertInstanceOf(
                    TestableAbstractClient::class,
                    new TestableAbstractClient(),
                    "O modo {$valor} precisa ser aceito"
                );
            });
        }
    }
}
