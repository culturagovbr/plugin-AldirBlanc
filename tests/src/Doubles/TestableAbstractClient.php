<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Http\Clients\AbstractClient;

/**
 * Subclasse concreta mínima de AbstractClient, só para expor parseResponse()
 * como público em teste. Não altera nenhum comportamento.
 */
class TestableAbstractClient extends AbstractClient
{
    /**
     * Não chama o construtor real: parseResponse() não depende de mode/host/token/curl,
     * e o construtor real falha em ambientes onde PNAB_CULTBR_HOST/TOKEN não estão setados.
     */
    public function __construct()
    {
    }

    public function callParseResponse(
        mixed $response,
        int $httpCode = 0,
        bool $curlError = false,
        ?string $curlErrorMessage = null,
        int $curlErrorCode = 0,
    ): array|object {
        return $this->parseResponse($response, $httpCode, $curlError, $curlErrorMessage, $curlErrorCode);
    }
}
