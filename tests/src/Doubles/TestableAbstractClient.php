<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

/**
 * Subclasse concreta mínima de AbstractClient, para exercitar em teste o que é protegido:
 * o parse da resposta, o preparo do endpoint e a resolução da fixture.
 */
class TestableAbstractClient extends AbstractClient
{
    protected const PROVIDER = 'gestao';

    public function __construct(?Transport $transport = null)
    {
        parent::__construct($transport);
    }

    /** Define o alvo da requisição, que em produção cada client monta no próprio construtor. */
    public function apontarPara(string $endpoint, string $document = '', string $parameter = '{document}'): static
    {
        $this->endpoint = $endpoint;
        $this->document = $document;
        $this->parameter = $parameter;

        return $this;
    }

    public function callPrepareEndpoint(string $endpoint, string $document, string $parameter): string
    {
        $this->apontarPara($endpoint, $document, $parameter);

        return $this->invocar('prepareEndpoint');
    }

    public function callGetFixturePath(): string
    {
        return $this->invocar('getFixturePath');
    }

    public function callHandleError(\Exception $e): void
    {
        $this->handleError('[Teste] Erro na API', $e);
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

    private function invocar(string $metodo): mixed
    {
        $reflexao = new \ReflectionMethod(AbstractClient::class, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($this);
    }
}
