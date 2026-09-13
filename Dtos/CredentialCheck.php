<?php

namespace AldirBlanc\Dtos;

final class CredentialCheck
{
    private function __construct(
        public readonly bool $verified,
        public readonly bool $valid,
        public readonly ?string $reason = null,
    ) {
    }

    public static function valid(): self
    {
        return new self(verified: true, valid: true);
    }

    public static function invalid(string $reason): self
    {
        return new self(verified: true, valid: false, reason: $reason);
    }

    /** Provedor sem a capacidade de validar: nunca "válida", sempre "não verificada". */
    public static function notVerified(string $reason): self
    {
        return new self(verified: false, valid: false, reason: $reason);
    }
}
