<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;

/** Base dos clients da Conecta: bucket próprio de configuração e variáveis próprias. */
abstract class ConectaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected function envName(string $sufixo): string
    {
        return 'PNAB_CULTBR_CONECTA_' . $sufixo;
    }
}
