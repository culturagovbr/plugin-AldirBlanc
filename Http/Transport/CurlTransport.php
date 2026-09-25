<?php

namespace AldirBlanc\Http\Transport;

use Curl\Curl;

class CurlTransport implements Transport
{
    private const CONNECT_TIMEOUT = 30;
    private const TIMEOUT = 60;

    public function send(string $method, string $url, array $headers, ?string $body = null): TransportResponse
    {
        $curl = $this->novoHandle();

        foreach ($headers as $nome => $valor) {
            $curl->setHeader($nome, $valor);
        }

        $curl->setOpt(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOpt(CURLOPT_TIMEOUT, self::TIMEOUT);
        $curl->setOpt(CURLOPT_FAILONERROR, false);

        $this->suppressingDeprecations(fn() => $this->dispatch($curl, $method, $url, $body));

        return new TransportResponse(
            status: (int) ($curl->http_status_code ?? 0),
            body: $curl->response,
            headers: $this->responseHeaders($curl->response_headers ?? null),
            hasError: (bool) $curl->error,
            errorMessage: $curl->error_message,
            errorCode: (int) ($curl->error_code ?? 0),
        );
    }

    /**
     * Um handle por chamada: CURLOPT_CUSTOMREQUEST não tem como ser desfeito, e reusar o handle
     * faria a operação seguinte a um PUT sair com o método do PUT.
     */
    protected function novoHandle(): Curl
    {
        return new Curl();
    }

    /** O put() da lib manda o corpo na query string; trocar o método sobre o post() é o que envia corpo. */
    private function dispatch(Curl $curl, string $method, string $url, ?string $body): void
    {
        if ($method === 'GET') {
            $curl->get($url);

            return;
        }

        if ($method !== 'POST') {
            $curl->setOpt(CURLOPT_CUSTOMREQUEST, $method);
        }

        $curl->post($url, $body ?? '');
    }

    /** A lib entrega os cabeçalhos como string ou array; o log os quer como lista de linhas. */
    private function responseHeaders(mixed $headers): ?array
    {
        if (is_array($headers)) {
            return array_values($headers);
        }

        if (is_string($headers) && $headers !== '') {
            return preg_split('/\r\n|\n/', trim($headers), -1, PREG_SPLIT_NO_EMPTY) ?: null;
        }

        return null;
    }

    /**
     * A lib emite PHP Deprecated (preg_split com $limit nulo) a cada requisição sob PHP 8.1+, e
     * com display_errors=STDOUT o aviso é ecoado antes do corpo, quebrando o parse no front-end.
     */
    private function suppressingDeprecations(callable $fn): void
    {
        $previousLevel = error_reporting();
        error_reporting($previousLevel & ~E_DEPRECATED);

        try {
            $fn();
        } finally {
            error_reporting($previousLevel);
        }
    }
}
