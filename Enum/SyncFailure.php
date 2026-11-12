<?php

namespace AldirBlanc\Enum;

/** Por que a sincronização do gestor falhou. O valor vai para o log. */
enum SyncFailure: string
{
    case ApiUnavailable = 'api_unavailable';
    case ConfigurationError = 'configuration_error';
    case CredentialRefused = 'credential_refused';
    case UnexpectedResponse = 'unexpected_response';
    case InternalError = 'internal_error';
}
