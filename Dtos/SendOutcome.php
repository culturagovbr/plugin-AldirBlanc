<?php

namespace AldirBlanc\Dtos;

use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;

/**
 * O que aconteceu num envio, para o log de integração. Contagem de tentativa fica com o job:
 * o provedor não sabe em que tentativa está.
 */
final class SendOutcome
{
    public function __construct(
        public readonly Provider $provider,
        public readonly SendResult $result,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly array $payload,
        public readonly \DateTimeInterface $sentAt,
        public readonly int $durationMs,
        public readonly ?string $response = null,
        public readonly ?array $responseHeaders = null,
        public readonly ?int $httpStatus = null,
    ) {
    }
}
