<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Plugin;

/** Base dos clients da Conecta: bucket próprio de configuração e variáveis próprias. */
abstract class ConectaClient extends AbstractClient
{
    protected function getClientConfig(): array
    {
        return Plugin::getInstance()->config['client']['conecta'] ?? [];
    }

    protected function envName(string $sufixo): string
    {
        return 'PNAB_CULTBR_CONECTA_' . $sufixo;
    }
}
