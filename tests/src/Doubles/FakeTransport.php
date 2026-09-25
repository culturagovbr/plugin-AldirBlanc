<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Http\Transport\TransportResponse;

/** Guarda o que o client pediu e devolve uma resposta fixa, sem rede. */
class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $requisicoes = [];

    public function __construct(
        private int $status = 200,
        private mixed $body = '{}',
        private bool $hasError = false,
        private ?string $errorMessage = null,
        private int $errorCode = 0,
    ) {
    }

    /** Timeout, DNS, TLS: a requisição não chegou a ter resposta HTTP. */
    public static function falhaDeTransporte(string $mensagem = 'Connection timed out', int $codigo = 28): self
    {
        return new self(status: 0, body: null, hasError: true, errorMessage: $mensagem, errorCode: $codigo);
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse
    {
        $this->requisicoes[] = compact('method', 'url', 'headers', 'body');

        return new TransportResponse(
            status: $this->status,
            body: $this->body,
            hasError: $this->hasError,
            errorMessage: $this->errorMessage,
            errorCode: $this->errorCode,
        );
    }

    public function metodos(): array
    {
        return array_column($this->requisicoes, 'method');
    }

    public function ultimaUrl(): ?string
    {
        return $this->requisicoes === [] ? null : end($this->requisicoes)['url'];
    }

    public function ultimosCabecalhos(): array
    {
        return $this->requisicoes === [] ? [] : end($this->requisicoes)['headers'];
    }
}
