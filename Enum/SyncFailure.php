<?php

namespace AldirBlanc\Enum;

/** Por que a sincronização do gestor falhou. O valor vai para a sessão e para o log. */
enum SyncFailure: string
{
    public const MENSAGEM_API_INDISPONIVEL = 'Não conseguimos estabelecer conexão com a API CultBr. Tente novamente mais tarde.';

    private const AVISO_DE_FALHA_PERMANENTE = 'Avise o suporte: repetir não resolve.';

    case ApiUnavailable = 'api_unavailable';
    case ConfigurationError = 'configuration_error';
    case CredentialRefused = 'credential_refused';
    case UnexpectedResponse = 'unexpected_response';

    /** Só o que pode mudar sozinho justifica a tela esperar e tentar de novo. */
    public function isRetryable(): bool
    {
        return $this === self::ApiUnavailable;
    }

    /** O texto que o gestor lê quando a sincronização falha. */
    public function message(): string
    {
        return match ($this) {
            self::ApiUnavailable => self::MENSAGEM_API_INDISPONIVEL,
            self::CredentialRefused => 'O acesso à API CultBr foi recusado. ' . self::AVISO_DE_FALHA_PERMANENTE,
            self::ConfigurationError => 'A integração com a API CultBr está mal configurada. ' . self::AVISO_DE_FALHA_PERMANENTE,
            self::UnexpectedResponse => 'A API CultBr respondeu fora do esperado. ' . self::AVISO_DE_FALHA_PERMANENTE,
        };
    }
}
