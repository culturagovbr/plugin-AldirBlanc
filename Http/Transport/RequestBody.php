<?php

namespace AldirBlanc\Http\Transport;

/** Corpo de um envio e o tipo que o descreve. */
final readonly class RequestBody
{
    public const JSON = 'application/json';

    private function __construct(public string $content, public string $contentType)
    {
    }

    public static function json(array $dados): self
    {
        return new self((string) json_encode($dados, JSON_UNESCAPED_UNICODE), self::JSON);
    }
}
