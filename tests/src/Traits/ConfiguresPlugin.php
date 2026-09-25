<?php

namespace Tests\AldirBlanc\Traits;

use AldirBlanc\Plugin;

/**
 * Acesso à configuração do plugin nos testes: `getConfig()` devolve o array por valor, então
 * trocar qualquer chave exige reflexão sobre a propriedade.
 */
trait ConfiguresPlugin
{
    protected function leConfigDoPlugin(): array
    {
        return self::propriedadeDeConfig()->getValue(Plugin::getInstance());
    }

    protected function escreveConfigDoPlugin(array $config): void
    {
        self::propriedadeDeConfig()->setValue(Plugin::getInstance(), $config);
    }

    /** Roda o exercício com a configuração trocada e devolve a original, inclusive se ele falhar. */
    protected function comConfigDoPlugin(callable $troca, callable $exercicio): void
    {
        $original = $this->leConfigDoPlugin();

        $this->escreveConfigDoPlugin($troca($original));

        try {
            $exercicio();
        } finally {
            $this->escreveConfigDoPlugin($original);
        }
    }

    /** `provider` e `mode` valem para a integração inteira; o resto pertence ao bucket de um provedor. */
    protected function comValoresDoCliente(array $config, array $valores, string $provedor = 'gestao'): array
    {
        foreach ($valores as $chave => $valor) {
            if (in_array($chave, ['provider', 'mode'], true)) {
                $config['client'][$chave] = $valor;
            } else {
                $config['client']['providers'][$provedor][$chave] = $valor;
            }
        }

        return $config;
    }

    private static function propriedadeDeConfig(): \ReflectionProperty
    {
        $propriedade = new \ReflectionProperty(Plugin::getInstance(), '_config');
        $propriedade->setAccessible(true);

        return $propriedade;
    }
}
