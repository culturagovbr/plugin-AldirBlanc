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
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse
    {
        $this->requisicoes[] = compact('method', 'url', 'headers', 'body');

        return new TransportResponse(status: $this->status, body: $this->body);
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
