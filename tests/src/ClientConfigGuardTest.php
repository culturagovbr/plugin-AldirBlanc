<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableAbstractClient;

/**
 * Configuração ausente precisa falhar nomeando a variável, em vez de bater na API antiga,
 * devolver fixture ou estourar TypeError.
 */
class ClientConfigGuardTest extends TestCase
{
    private function comConfig(string $chave, mixed $valor, callable $exercicio): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client'][$chave] ?? null;
        $config['client'][$chave] = $valor;
        $ref->setValue($plugin, $config);

        try {
            $exercicio();
        } finally {
            $config = $ref->getValue($plugin);
            $config['client'][$chave] = $original;
            $ref->setValue($plugin, $config);
        }
    }

    function testEndpointDoCatalogoAusenteFalhaNomeandoAVariavel()
    {
        $this->comConfig('parAcoesEndpoint', null, function () {
            try {
                new ParAcaoClient();
                $this->fail('Esperava falha por configuração ausente');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                $this->assertStringContainsString('PNAB_CULTBR_PAR_ACOES_ENDPOINT', $e->getMessage());
            }
        });
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

    function testEndpointDoGestorAusenteFalhaNomeandoAVariavel()
    {
        $this->comConfig('gestorEndpoint', null, function () {
            try {
                new GestorClient(new GestorDocument('12345678901'));
                $this->fail('Esperava falha por endpoint do gestor ausente');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                $this->assertStringContainsString('PNAB_CULTBR_GESTOR_ENDPOINT', $e->getMessage());
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
}
