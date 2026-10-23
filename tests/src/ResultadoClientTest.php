<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use AldirBlanc\Integration\SendsResultado;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;

/** Envio de resultado pela Conecta: só-POST num edital do PAR, sobre a fundação da fase 1. */
class ResultadoClientTest extends TestCase
{
    use ConfiguresPlugin;

    private const HOST = 'http://cultbr.invalid';

    private function comConecta(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live'],
                'conecta',
            ),
            $exercicio,
        );
    }

    private function payload(): Resultado
    {
        return new Resultado(id_tipo: 2, id_status: 3, data_publicacao: '2026-10-16T18:00:00-03:00');
    }

    private function envia(FakeTransport $transporte)
    {
        return (new ConectaProvider($transporte))->sendResultado(new ParEditalId(53790), $this->payload());
    }

    function testEnvioCriaPeloPostNoEndpointDoEdital()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201, body: '{"id_par_edital_resultado": 10}');

            $outcome = $this->envia($transporte);

            $this->assertSame(['POST'], $transporte->metodos(), 'resultado publicado é imutável: nunca há PUT');
            $this->assertSame(self::HOST . '/editais/53790/resultado', $transporte->ultimaUrl());
            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame('POST', $outcome->method);
        });
    }

    function testEnvioHerdaBearerEContentTypeDaFundacao()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201);

            $this->envia($transporte);

            $cabecalhos = implode('|', $transporte->ultimosCabecalhos());
            $this->assertStringContainsString('Bearer token-de-teste', $cabecalhos);
            $this->assertStringContainsString('application/json', $cabecalhos);
        });
    }

    function testEndpointAusenteNaConfigFalhaComoConfiguracao()
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live', 'criarResultadoEndpoint' => null],
                'conecta',
            ),
            function () {
                try {
                    $this->envia(new FakeTransport(status: 201));
                    $this->fail('Esperava falha de configuração sem o endpoint declarado');
                } catch (IntegrationError $e) {
                    $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                    $this->assertStringContainsString('conecta.criarResultadoEndpoint', $e->getMessage());
                }
            },
        );
    }

    function testEnvioQueFalhaRegistraATentativaComoErro()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 422, body: '{"detail":[{"msg":"campo inválido"}]}');

            try {
                $this->envia($transporte);
                $this->fail('Esperava SendFailed com a tentativa registrada');
            } catch (SendFailed $e) {
                $this->assertSame(SendResult::Error, $e->outcome()->result);
                $this->assertSame('POST', $e->outcome()->method);
                $this->assertSame(422, $e->outcome()->httpStatus);
            }
        });
    }

    function testEnvioEmModoSimuladoNaoSaiPelaRede()
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'development'],
                'conecta',
            ),
            function () {
                $transporte = new FakeTransport(status: 201);

                $outcome = $this->envia($transporte);

                $this->assertSame([], $transporte->metodos(), 'modo simulado não sai pela rede');
                $this->assertSame(SendResult::Simulated, $outcome->result);
                $this->assertSame('POST', $outcome->method);
            },
        );
    }

    function testSoAConectaDeclaraACapacidadeDeEnviarResultado()
    {
        $this->assertTrue(is_a(ConectaProvider::class, SendsResultado::class, true));
        $this->assertFalse(is_a(GestaoProvider::class, SendsResultado::class, true));
    }
}
