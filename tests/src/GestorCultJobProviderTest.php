<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Doubles\InMemoryProvider;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;

/** Trocar a configuração troca a origem dos entes, sem que o job mude. */
class GestorCultJobProviderTest extends TestCase
{
    private function comProviderConfigurado(string $valor, callable $exercicio): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client']['provider'] ?? null;
        $config['client']['provider'] = $valor;
        $ref->setValue($plugin, $config);
        $plugin->resetIntegrationProvider();

        try {
            $exercicio();
        } finally {
            $config = $ref->getValue($plugin);
            $config['client']['provider'] = $original;
            $ref->setValue($plugin, $config);
            $plugin->resetIntegrationProvider();
        }
    }

    private function buscar(): mixed
    {
        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $metodo = new \ReflectionMethod($job, 'fetchGestorData');
        $metodo->setAccessible(true);

        return $metodo->invoke($job);
    }

    function testOJobPedeOsEntesAoProvedorQueAConfiguracaoEscolheu()
    {
        $this->comProviderConfigurado(InMemoryProvider::class, function () {
            $this->assertNull($this->buscar(), 'o provedor em memória não conhece este documento');
        });
    }

    function testTrocarAConfiguracaoTrocaAImplementacaoQueAtende()
    {
        $this->comProviderConfigurado('gestao', function () {
            $this->assertInstanceOf(GestaoProvider::class, Plugin::getInstance()->integrationProvider());
        });

        $this->comProviderConfigurado('conecta', function () {
            $this->assertInstanceOf(ConectaProvider::class, Plugin::getInstance()->integrationProvider());
        });
    }

    private function comModoReal(callable $exercicio): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client']['mode'] ?? null;
        $config['client']['mode'] = 'live';
        $ref->setValue($plugin, $config);

        try {
            $exercicio();
        } finally {
            $config = $ref->getValue($plugin);
            $config['client']['mode'] = $original;
            $ref->setValue($plugin, $config);
        }
    }

    /** O job não conhece client nenhum: o que ele recebe é sempre o contrato. */
    function testOJobRecebeOContratoENaoORetornoCruDaApi()
    {
        $this->comModoReal(function () {
        $transporte = new FakeTransport(200, json_encode([
            'nome' => 'Fulano',
            'entes_federados' => [
                ['name' => 'Arapiraca', 'document' => '12198693000158', 'exercicios' => []],
            ],
        ]));

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->useTransport($transporte);

        $metodo = new \ReflectionMethod($job, 'fetchGestorData');
        $metodo->setAccessible(true);
        $snapshot = $metodo->invoke($job);

        $this->assertSame('Fulano', $snapshot->name());
        $this->assertCount(1, $snapshot->entities());
        $this->assertSame('12198693000158', $snapshot->entities()[0]->document);
        });
    }
}
