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

    private string $kind;
    private ?int $httpStatus;
    private ?string $rawBody;

    public function __construct(
        string $message,
        string $kind,
        ?int $httpStatus = null,
        ?string $rawBody = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->kind = $kind;
        $this->httpStatus = $httpStatus;
        $this->rawBody = $rawBody;
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
}
