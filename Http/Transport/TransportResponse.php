<?php

namespace AldirBlanc\Http\Transport;

/** O que uma requisição devolve, no formato que AbstractClient::parseResponse() consome. */
readonly class TransportResponse
{
    /**
     * @param mixed $body corpo cru da resposta, como o transporte o entregou
     * @param ?array $headers cabeçalhos da resposta como lista de linhas
     */
    public function __construct(
        public int $status = 0,
        public mixed $body = null,
        public ?array $headers = null,
        public bool $hasError = false,
        public ?string $errorMessage = null,
        public int $errorCode = 0,
    ) {
    }
}
