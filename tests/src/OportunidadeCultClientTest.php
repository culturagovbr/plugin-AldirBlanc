<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\OportunidadeCultClient;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;

class OportunidadeCultClientTest extends TestCase
{
    private function setPluginClientConfig(string $key, mixed $value): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);
        $config = $ref->getValue($plugin);
        $config['client'][$key] = $value;
        $ref->setValue($plugin, $config);
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
}
