<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\ProviderResolver;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\InMemoryProvider;
use Tests\AldirBlanc\Doubles\NotAProvider;

/** Quem atende a integração é decidido por configuração, e valor ruim falha alto. */
class ProviderResolverTest extends TestCase
{
    private function resolverCom(mixed $configurado): ProviderResolver
    {
        return new ProviderResolver($configurado);
    }

    private function esperaFalhaDeConfiguracao(callable $exercicio, string $trechoEsperado): void
    {
        try {
            $exercicio();
            $this->fail('Esperava falha de configuração');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
            $this->assertStringContainsString('PNAB_CULTBR_PROVIDER', $e->getMessage());
            $this->assertStringContainsString($trechoEsperado, $e->getMessage());
        }
    }

    function testNomeDeClasseCompletoEhUsadoComoVeio()
    {
        $provider = $this->resolverCom(InMemoryProvider::class)->resolve();

        $this->assertInstanceOf(InMemoryProvider::class, $provider);
    }

    /** A convenção de nome é travada aqui, e não pela ausência da classe — ela vai existir. */
    function testValorCurtoViraAClasseDoProvedorCorrespondente()
    {
        $montar = new \ReflectionMethod(ProviderResolver::class, 'className');
        $montar->setAccessible(true);
        $resolver = $this->resolverCom('gestao');

        $this->assertSame(
            'AldirBlanc\\Integration\\Gestao\\GestaoProvider',
            $montar->invoke($resolver, 'gestao'),
        );
        $this->assertSame(
            'AldirBlanc\\Integration\\Conecta\\ConectaProvider',
            $montar->invoke($resolver, 'conecta'),
        );
    }

    function testClasseInexistenteFalhaNomeandoAClasse()
    {
        $this->esperaFalhaDeConfiguracao(
            fn() => $this->resolverCom('Nao\\Existe\\Provider')->resolve(),
            'classe Nao\\Existe\\Provider não existe',
        );
    }

    function testValorDesconhecidoFalhaNomeandoOsAceitos()
    {
        $this->esperaFalhaDeConfiguracao(
            fn() => $this->resolverCom('sefic')->resolve(),
            'use gestao ou conecta',
        );
    }

    function testAusenciaDeConfiguracaoFalhaEmVezDeEscolherSozinha()
    {
        $this->esperaFalhaDeConfiguracao(
            fn() => $this->resolverCom(null)->resolve(),
            'sem valor',
        );
    }

    function testValorVazioFalhaComoAusente()
    {
        $this->esperaFalhaDeConfiguracao(
            fn() => $this->resolverCom('   ')->resolve(),
            'sem valor',
        );
    }

    /** Classe que existe mas não cumpre o contrato não pode ser devolvida como se cumprisse. */
    function testClasseQueNaoImplementaOContratoEhRecusada()
    {
        $this->esperaFalhaDeConfiguracao(
            fn() => $this->resolverCom(NotAProvider::class)->resolve(),
            'não implementa',
        );
    }

    /** Configuração errada num lote de envios não pode virar um alerta por item. */
    function testFalhaEhMemoizadaEAlertaUmaVezSo()
    {
        $resolver = $this->resolverCom('sefic');
        $registrados = 0;

        for ($i = 0; $i < 3; $i++) {
            try {
                $resolver->resolve();
            } catch (IntegrationError $e) {
                $registrados++;
                $primeira ??= $e;
            }
        }

        $this->assertSame(3, $registrados);
        $this->assertSame($primeira, $e, 'a mesma exceção é relançada, sem refazer a resolução');
    }

    function testResetLimpaTambemAFalhaMemoizada()
    {
        $resolver = $this->resolverCom('sefic');

        try {
            $resolver->resolve();
        } catch (IntegrationError) {
        }

        $resolver->reset();
        $this->expectException(IntegrationError::class);
        $resolver->resolve();
    }

    function testResolucaoEhMemoizadaDentroDoMesmoResolver()
    {
        $resolver = $this->resolverCom(InMemoryProvider::class);

        $this->assertSame($resolver->resolve(), $resolver->resolve());
    }

    function testResetFazAResolucaoAcontecerDeNovo()
    {
        $resolver = $this->resolverCom(InMemoryProvider::class);
        $primeiro = $resolver->resolve();

        $resolver->reset();

        $this->assertNotSame($primeiro, $resolver->resolve());
    }

    function testOPluginEntregaOProvedorQueAConfiguracaoDiz()
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client']['provider'] ?? null;
        $config['client']['provider'] = InMemoryProvider::class;
        $ref->setValue($plugin, $config);
        $plugin->resetIntegrationProvider();

        try {
            $this->assertInstanceOf(InMemoryProvider::class, $plugin->integrationProvider());
        } finally {
            $config = $ref->getValue($plugin);
            $config['client']['provider'] = $original;
            $ref->setValue($plugin, $config);
            $plugin->resetIntegrationProvider();
        }
    }
}
