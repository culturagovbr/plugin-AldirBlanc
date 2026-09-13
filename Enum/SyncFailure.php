<?php

namespace AldirBlanc\Enum;

/** Por que a sincronização do gestor falhou. O valor vai para a sessão e para o log. */
enum SyncFailure: string
{
    case ApiUnavailable = 'api_unavailable';
    case ConfigurationError = 'configuration_error';
    case UnexpectedResponse = 'unexpected_response';

    /** Só o que pode mudar sozinho justifica a tela esperar e tentar de novo. */
    public function isRetryable(): bool
    {
        return $this === self::ApiUnavailable;
    }
}
