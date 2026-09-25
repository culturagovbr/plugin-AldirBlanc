<?php

namespace AldirBlanc\Exceptions;

/**
 * Falha ao falar com a API do CultBR, com o que aconteceu preservado: a família do erro,
 * o status HTTP e o corpo cru da resposta.
 */
class IntegrationError extends \Exception
{
    /** Timeout, DNS, TLS — a requisição não chegou a ter resposta HTTP. */
    public const KIND_TRANSPORT = 'transport';

    /** A API respondeu, com status fora da faixa de sucesso. */
    public const KIND_HTTP = 'http';

    /** A API respondeu, mas o corpo não é utilizável. */
    public const KIND_PARSE = 'parse';

    /** Falta configuração para a chamada acontecer — não adianta repetir. */
    public const KIND_CONFIGURATION = 'configuration';

    /** O corpo é legível, mas a forma não é a que o contrato exige. */
    public const KIND_CONTRACT = 'contract';

    private const HTTP_SERVER_ERROR_MIN = 500;

    private string $kind;
    private ?int $httpStatus;
    private ?string $rawBody;
    private array $details;

    public function __construct(
        string $message,
        string $kind,
        ?int $httpStatus = null,
        ?string $rawBody = null,
        int $code = 0,
        ?\Throwable $previous = null,
        array $details = [],
    ) {
        parent::__construct($message, $code, $previous);

        $this->kind = $kind;
        $this->httpStatus = $httpStatus;
        $this->rawBody = $rawBody;
        $this->details = $details;
    }

    public static function transport(string $message, int $curlErrorCode, ?\Throwable $previous = null): self
    {
        return new self($message, self::KIND_TRANSPORT, null, null, $curlErrorCode, $previous);
    }

    public static function http(string $message, int $httpStatus, ?string $rawBody = null, ?\Throwable $previous = null): self
    {
        return new self($message, self::KIND_HTTP, $httpStatus, $rawBody, $httpStatus, $previous);
    }

    public static function parse(string $message, ?int $httpStatus = null, ?string $rawBody = null, ?\Throwable $previous = null): self
    {
        return new self($message, self::KIND_PARSE, $httpStatus, $rawBody, $httpStatus ?? 0, $previous);
    }

    public static function configuration(string $variavel, string $detalhe): self
    {
        return new self("Configuração ausente ou inválida: {$variavel} — {$detalhe}", self::KIND_CONFIGURATION);
    }

    public static function contract(
        string $message,
        array $details = [],
        ?int $httpStatus = null,
        ?string $rawBody = null,
    ): self {
        return new self($message, self::KIND_CONTRACT, $httpStatus, $rawBody, 0, null, $details);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    public function details(): array
    {
        return $this->details;
    }

    /** Só repetir o que pode mudar de resultado: transporte e erro do servidor. */
    public function isRetryable(): bool
    {
        if ($this->kind === self::KIND_TRANSPORT) {
            return true;
        }

        return $this->kind === self::KIND_HTTP && $this->httpStatus >= self::HTTP_SERVER_ERROR_MIN;
    }
}
