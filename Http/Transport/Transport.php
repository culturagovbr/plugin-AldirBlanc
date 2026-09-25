<?php

namespace AldirBlanc\Http\Transport;

interface Transport
{
    /**
     * Executa a requisição e devolve status, corpo e erro de transporte.
     * @param array<string,string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse;
}
