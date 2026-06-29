<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
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

    function testCreateLancaRuntimeExceptionQuandoEndpointNaoConfigurado()
    {
        $original = Plugin::getInstance()->config['client']['createOportunidadeEndpoint'];
        $client = new OportunidadeCultClient(new OpportunityId(1));
        $this->setPluginClientConfig('createOportunidadeEndpoint', null);

        try {
            $client->create($this->makePayload());
            $this->fail('Esperava RuntimeException ao chamar create() sem endpoint configurado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PNAB_CULTBR_CREATE_OPORTUNIDADE_ENDPOINT', $e->getMessage());
        } finally {
            $this->setPluginClientConfig('createOportunidadeEndpoint', $original);
        }
    }

    function testUpdateLancaRuntimeExceptionQuandoEndpointNaoConfigurado()
    {
        $original = Plugin::getInstance()->config['client']['updateOportunidadeEndpoint'];
        $client = new OportunidadeCultClient(new OpportunityId(1));
        $this->setPluginClientConfig('updateOportunidadeEndpoint', null);

        try {
            $client->update($this->makePayload());
            $this->fail('Esperava RuntimeException ao chamar update() sem endpoint configurado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT', $e->getMessage());
        } finally {
            $this->setPluginClientConfig('updateOportunidadeEndpoint', $original);
        }
    }
}
