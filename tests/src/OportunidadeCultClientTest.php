<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\OportunidadeCultClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Http\Transport\TransportResponse;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Traits\CapturesLog;

class OportunidadeCultClientTest extends TestCase
{
    use ConfiguresPlugin;

    use CapturesLog;

    private function setPluginClientConfig(string $key, mixed $value): void
    {
        $config = $this->leConfigDoPlugin();
        $config['client'][$key] = $value;
        $this->escreveConfigDoPlugin($config);
    }

    private function makePayload(): OpportunityDto
    {
        return new OpportunityDto(id: 1);
    }

    function testUpdateFalhaComoErroDeConfiguracaoQuandoEndpointNaoConfigurado()
    {
        $original = Plugin::getInstance()->config['client']['updateOportunidadeEndpoint'];
        $client = new OportunidadeCultClient(new OpportunityId(1));
        $this->setPluginClientConfig('updateOportunidadeEndpoint', null);

        try {
            $client->update($this->makePayload());
            $this->fail('Esperava falha ao chamar update() sem endpoint configurado');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
            $this->assertStringContainsString('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT', $e->getMessage());
        } finally {
            $this->setPluginClientConfig('updateOportunidadeEndpoint', $original);
        }
    }

    /** Modo real com transporte falso: exercita o PUT sem sair da máquina. */
    private function comEnvioReal(Transport $transport, callable $exercicio): void
    {
        $config = Plugin::getInstance()->config['client'];
        $originais = [
            'mode' => $config['mode'] ?? null,
            'host' => $config['host'] ?? null,
            'token' => $config['token'] ?? null,
        ];

        $this->setPluginClientConfig('mode', 'live');
        $this->setPluginClientConfig('host', 'http://cultbr.invalid');
        $this->setPluginClientConfig('token', 'token-de-teste');

        try {
            $exercicio(new OportunidadeCultClient(new OpportunityId(1), $transport));
        } finally {
            foreach ($originais as $chave => $valor) {
                $this->setPluginClientConfig($chave, $valor);
            }
        }
    }

    /** Quem alerta sobre o envio é o job, único a saber se ainda resta tentativa. */
    function testFalhaDoEnvioRegistraSemDispararAlerta()
    {
        $capturado = $this->capturandoLog(function () {
            $this->comEnvioReal(
                new FakeTransport(status: 500, body: 'Internal Server Error'),
                function (OportunidadeCultClient $client) {
                    try {
                        $client->update($this->makePayload());
                        $this->fail('Esperava falha com HTTP 500');
                    } catch (IntegrationError $e) {
                        $this->assertSame(500, $e->httpStatus());
                    }
                }
            );
        });

        $this->assertFalse($capturado->hasCriticalRecords(), 'O alerta do envio é do job');
        $this->assertTrue($capturado->hasErrorRecords(), 'A falha continua registrada');
    }

    /** Um \Error escapava do catch e levava embora a tentativa que a aba de logs mostraria. */
    function testErroFatalNoTransporteAindaRegistraATentativa()
    {
        $transporteQuebrado = new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse
            {
                throw new \TypeError('transporte quebrado');
            }
        };

        $registrado = null;

        $this->comEnvioReal($transporteQuebrado, function (OportunidadeCultClient $client) use (&$registrado) {
            $client->setExchangeRecorder(function (array $exchange) use (&$registrado) {
                $registrado = $exchange;
            });

            try {
                $client->update($this->makePayload());
                $this->fail('Esperava falha com o transporte quebrado');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_PARSE, $e->kind());
            }
        });

        $this->assertNotNull($registrado, 'A tentativa precisa sobreviver a um \Error');
        $this->assertSame('PUT', $registrado['method']);
    }
}
