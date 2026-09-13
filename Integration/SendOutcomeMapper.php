<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;

/** Traduz o que o client registrou de um envio no desfecho que o contrato promete. */
final class SendOutcomeMapper
{
    private const RESULT_BY_LOG_STATUS = [
        CultBrRequestLogAttempt::RESULT_SUCCESS => SendResult::Success,
        CultBrRequestLogAttempt::RESULT_SIMULATED => SendResult::Simulated,
        CultBrRequestLogAttempt::RESULT_ERROR => SendResult::Error,
    ];

    public static function fromExchange(array $exchange, Provider $provider): SendOutcome
    {
        $status = (string) ($exchange['status'] ?? '');

        return new SendOutcome(
            provider: $provider,
            result: self::RESULT_BY_LOG_STATUS[$status] ?? SendResult::Error,
            method: (string) ($exchange['method'] ?? 'PUT'),
            endpoint: (string) ($exchange['endpoint'] ?? ''),
            payload: is_array($exchange['payload'] ?? null) ? $exchange['payload'] : [],
            sentAt: $exchange['sentAt'] ?? new \DateTimeImmutable(),
            durationMs: (int) ($exchange['durationMs'] ?? 0),
            response: is_string($exchange['response'] ?? null) ? $exchange['response'] : null,
            responseHeaders: is_array($exchange['responseHeaders'] ?? null) ? $exchange['responseHeaders'] : null,
            httpStatus: isset($exchange['httpStatus']) ? (int) $exchange['httpStatus'] : null,
        );
    }
}
