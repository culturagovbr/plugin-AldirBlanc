<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Http\Transport\TransportResponse;

/** Uma resposta por chamada, na ordem dada: é o que permite exercitar o 404 seguido do POST. */
class SequencedTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $requisicoes = [];

    /** @param list<array{0: int, 1: string}> $respostas pares de status e corpo */
    public function __construct(private array $respostas)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse
    {
        $this->requisicoes[] = compact('method', 'url', 'headers', 'body');

        [$status, $corpo] = array_shift($this->respostas) ?? [200, '{}'];

        return new TransportResponse(status: $status, body: $corpo, hasError: false, errorMessage: null, errorCode: 0);
    }

    /** @return list<string> */
    public function metodos(): array
    {
        return array_column($this->requisicoes, 'method');
    }

    public function ultimaUrl(): string
    {
        return $this->requisicoes ? (string) end($this->requisicoes)['url'] : '';
    }
}
