<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use Curl\Curl;
use ReflectionClass;

abstract class AbstractClient
{
    protected string $endpoint;
    protected string $document;

    /** @var string placeholder para substituir no endpoint */
    protected string $parameter;

    private string $mode;

    private string $host;
    private string $token;

    private Curl $curl;

    /**
     * Callback opcional que recebe request + resposta de cada PUT (payload, status HTTP, corpo,
     * duração). Usado pelo OportunidadeCultJob para gravar o histórico da aba "Logs CultBr" —
     * é aqui, e não no job, porque handleError() relança uma exceção genérica e o chamador
     * perde status e corpo do erro.
     *
     * @var callable|null
     */
    private $exchangeRecorder = null;

    private const PARAMETER_DEFAULT = '{document}';
    private const HTTP_ERROR_MIN = 400;
    private const HTTP_NOT_FOUND = 404;
    private const NO_RESPONSE_MESSAGE = 'API não retornou resposta';
    private const NOT_FOUND_DETAILS = [
        'não encontrada',
        'não encontrado',
        'not found',
    ];

    public function __construct()
    {
        $config = $this->getClientConfig();

        if (empty($config)) {
            throw new \Exception('Configuração do cliente não encontrada');
        }

        $this->mode = $config['mode'];
        $this->host = $config['host'];
        $this->token = $config['token'];
        $this->parameter = self::PARAMETER_DEFAULT;

        // Carregando configurações do curl
        $this->setCurl();
    }

    private function isDevelopmentMode(): bool
    {
        return $this->mode === 'development';
    }

    /**
     * Registra quem deve receber o par request/resposta dos PUTs deste client.
     * Sem recorder definido, o comportamento é exatamente o de antes.
     */
    public function setExchangeRecorder(?callable $recorder): void
    {
        $this->exchangeRecorder = $recorder;
    }

    private function recordExchange(array $exchange): void
    {
        if ($this->exchangeRecorder === null) {
            return;
        }

        try {
            ($this->exchangeRecorder)($exchange);
        } catch (\Throwable $e) {
            // Log é acessório: falha ao gravar não pode derrubar o envio ao CultBR.
            App::i()->log->error('[CultBR] Falha ao registrar log de envio: ' . $e->getMessage());
        }
    }

    public final function get()
    {
        $app = App::i();

        // Utilizado para testes locais
        if ($this->isDevelopmentMode()) {
            $app->log->info("[Gestores CultBR] Modo development: usando fixture | Cliente: " . static::class);
            return require $this->getFixturePath();
        }

        $fullUrl = $this->prepareUrl();
        $app->log->info("[Gestores CultBR] GET requisição | Cliente: " . static::class . " | URL: {$fullUrl}");

        try {
            $this->callCurlSuppressingDeprecations(fn() => $this->curl->get($fullUrl));
            $app->log->info("[Gestores CultBR] GET resposta recebida | Cliente: " . static::class . " | HTTP: {$this->curl->http_status_code}");
            return $this->parseResponse(
                $this->curl->response,
                $this->curl->http_status_code ?? 0,
                $this->curl->error,
                $this->curl->error_message,
                $this->curl->error_code ?? 0,
            );
        } catch (\Exception $e) {
            $this->handleError('[Gestores CultBR] Erro na API ao buscar dados', $e);
        } finally {
            $this->closeCurl();
        }
    }

    public final function post(array $data)
    {
        if ($this->isDevelopmentMode()) {
            return $data;
        }

        $fullUrl = $this->prepareUrl();
        $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

        try {
            $this->callCurlSuppressingDeprecations(fn() => $this->curl->post($fullUrl, $jsonPayload));
            $rawResponse = $this->curl->response;
            $parsed = $this->parseResponse(
                $rawResponse,
                $this->curl->http_status_code ?? 0,
                $this->curl->error,
                $this->curl->error_message,
                $this->curl->error_code ?? 0,
            );
            return $parsed;
        } catch (\Exception $e) {
            $this->handleError('[CultBR] Erro na API ao enviar dados (POST)', $e);
        } finally {
            $this->closeCurl();
        }
    }

    public final function put(array $data)
    {
        $sentAt = new \DateTime();
        $startedAt = microtime(true);

        if ($this->isDevelopmentMode()) {
            $this->recordExchange([
                'method' => 'PUT',
                'endpoint' => $this->endpoint ?? '',
                'payload' => $data,
                'status' => CultBrRequestLogAttempt::RESULT_SIMULATED,
                'sentAt' => $sentAt,
                'durationMs' => $this->elapsedMs($startedAt),
            ]);
            return $data;
        }

        $fullUrl = $this->prepareUrl();
        $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

        $app = App::i();
        $app->log->info("[CultBR] PUT payload | URL: {$fullUrl} | Body: {$jsonPayload}");

        try {
            $this->curl->setOpt(CURLOPT_CUSTOMREQUEST, 'PUT');
            $this->callCurlSuppressingDeprecations(fn() => $this->curl->post($fullUrl, $jsonPayload));
            $rawResponse = $this->curl->response;
            $app->log->info("[CultBR] PUT response | HTTP: {$this->curl->http_status_code} | Body: " . (is_string($rawResponse) ? $rawResponse : json_encode($rawResponse)));
            $parsed = $this->parseResponse(
                $rawResponse,
                $this->curl->http_status_code ?? 0,
                $this->curl->error,
                $this->curl->error_message,
                $this->curl->error_code ?? 0,
            );

            $this->recordExchange([
                'method' => 'PUT',
                'endpoint' => $fullUrl,
                'payload' => $data,
                'response' => is_string($rawResponse) ? $rawResponse : json_encode($rawResponse),
                'responseHeaders' => $this->responseHeaders(),
                'httpStatus' => $this->curl->http_status_code ?? null,
                'status' => $this->exchangeResultForAcceptedResponse((int) ($this->curl->http_status_code ?? 0)),
                'sentAt' => $sentAt,
                'durationMs' => $this->elapsedMs($startedAt),
            ]);

            return $parsed;
        } catch (\Exception $e) {
            $rawResponse = $this->curl->response ?? null;

            $this->recordExchange([
                'method' => 'PUT',
                'endpoint' => $fullUrl,
                'payload' => $data,
                'response' => is_string($rawResponse) ? $rawResponse : json_encode($rawResponse),
                'responseHeaders' => $this->responseHeaders(),
                'httpStatus' => $this->curl->http_status_code ?? null,
                'error' => $this->exchangeErrorMessage($e),
                'status' => CultBrRequestLogAttempt::RESULT_ERROR,
                'sentAt' => $sentAt,
                'durationMs' => $this->elapsedMs($startedAt),
            ]);

            $this->handleError('[CultBR] Erro na API ao atualizar dados (PUT)', $e, true);
        } finally {
            $this->closeCurl();
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Cabeçalhos da resposta como lista de linhas (a lib entrega string ou array).
     * Registrados no log para que a resposta continue auditável quando o corpo não é JSON,
     * está vazio ou vem em formato inesperado.
     */
    private function responseHeaders(): ?array
    {
        $headers = $this->curl->response_headers ?? null;

        if (is_array($headers)) {
            return array_values($headers);
        }
        if (is_string($headers) && $headers !== '') {
            return preg_split('/\r\n|\n/', trim($headers), -1, PREG_SPLIT_NO_EMPTY) ?: null;
        }

        return null;
    }

    /**
     * Desfecho de uma resposta que o parseResponse aceitou sem lançar. O ramo de 404 existe
     * para os GETs (ausência de dados); num PUT de upsert ele é recusa de acesso. Nos dois
     * casos, registrar como sucesso criaria um log contraditório (sucesso com HTTP 404).
     */
    protected function exchangeResultForAcceptedResponse(int $httpStatus): string
    {
        // Estritamente 404: é o único status de erro que o parseResponse aceita sem exceção.
        // Um `>= 400` genérico rotularia como recusa qualquer erro que passasse a ser aceito.
        return $httpStatus === self::HTTP_NOT_FOUND
            ? CultBrRequestLogAttempt::RESULT_REJECTED
            : CultBrRequestLogAttempt::RESULT_SUCCESS;
    }

    /**
     * Mensagem do erro do curl (timeout, DNS, TLS) quando houver; senão, a da exceção.
     * Sem isso, uma falha de transporte chegaria ao log como exceção genérica.
     */
    private function exchangeErrorMessage(\Throwable $e): string
    {
        $curlError = trim((string) ($this->curl->error_message ?? ''));

        return $curlError !== '' ? $curlError : $e->getMessage();
    }

    /**
     * vendor/curl/curl (lib de terceiros) emite PHP Deprecated (preg_split com $limit nulo) a cada
     * requisição real sob PHP 8.1+. Com display_errors=STDOUT, esse aviso é ecoado antes do corpo
     * da resposta e quebra o parse de JSON no front-end. Suprime só E_DEPRECATED, só durante a
     * chamada à lib, sem mexer em vendor/ nem esconder outros erros.
     */
    private function callCurlSuppressingDeprecations(callable $fn): void
    {
        $previousLevel = error_reporting();
        error_reporting($previousLevel & ~E_DEPRECATED);

        try {
            $fn();
        } finally {
            error_reporting($previousLevel);
        }
    }

    protected final function getClientConfig(): array
    {
        return Plugin::getInstance()->config['client'] ?? [];
    }

    private function getFixturePath(): string
    {
        return __DIR__ . "/../Fixtures/{$this->getFixtureClassName()}.php";
    }

    private function getFixtureClassName(): string
    {
        $reflectionClass = new ReflectionClass(get_class($this));
        $className = $reflectionClass->getShortName();
        return "{$className}Fixture";
    }

    private function setCurl(): void
    {
        $this->curl = new Curl();
        $this->curl->setHeader('Content-Type', 'application/json');
        $this->curl->setHeader('Authorization', 'Bearer ' . $this->token);

        // Configura timeout: 30 segundos para conexão e 60 segundos total
        $this->curl->setOpt(CURLOPT_CONNECTTIMEOUT, 30);
        $this->curl->setOpt(CURLOPT_TIMEOUT, 60);

        $this->curl->setOpt(CURLOPT_FAILONERROR, false);
    }

    /**
     * Prepara a URL para a requisição
     * @return string a URL preparada para a requisição
     */
    private function prepareUrl(): string
    {
        return "{$this->host}/{$this->prepareEndpoint()}";
    }

    /**
     * Prepara o endpoint para a requisição (substitui $this->parameter por $this->document no endpoint)
     * @return string
     */
    private function prepareEndpoint(): string
    {
        return str_replace($this->parameter, $this->document, $this->endpoint);
    }

    /**
     * Interpreta a resposta do curl (código HTTP, body JSON, erros) e retorna o resultado ou lança exceção.
     * Recebe o estado do curl como parâmetros explícitos (em vez de ler $this->curl) para ser testável de forma pura.
     * @param mixed $response corpo da resposta (string, array, object ou null)
     * @param int $httpCode código HTTP da resposta
     * @param bool $curlError se o curl reportou erro de transporte
     * @param ?string $curlErrorMessage mensagem de erro do curl, se houver
     * @param int $curlErrorCode código de erro do curl, se houver
     * @return array|object resultado decodificado ou array vazio quando a API informa ausência de dados
     * @throws \Exception em erro de JSON, 4xx/5xx ou formato não reconhecido
     */
    protected function parseResponse(
        mixed $response,
        int $httpCode = 0,
        bool $curlError = false,
        ?string $curlErrorMessage = null,
        int $curlErrorCode = 0,
    ): array|object
    {
        if ($response === null) {
            throw new \Exception(self::NO_RESPONSE_MESSAGE, $httpCode);
        }

        // Se a resposta é uma string JSON, decodifica para array
        if (is_string($response)) {
            // Se a string está vazia, trata como indisponibilidade/erro de API.
            if (trim($response) === '') {
                $errorMessage = $curlErrorMessage ?? "Erro HTTP {$httpCode}";
                throw new \Exception($errorMessage ?: self::NO_RESPONSE_MESSAGE, $httpCode);
            }

            $decoded = json_decode($response, true);

            // Verifica se houve erro no JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Resposta da API não é um JSON válido', 0);
            }

            // Verifica se a resposta contém o JSON de ausência de dados da API.
            if ($httpCode === self::HTTP_NOT_FOUND && is_array($decoded) && $this->hasNotFoundDetail($decoded)) {
                return [];
            }

            if (is_array($decoded)) {
                if (!empty($decoded) && (array_key_exists('error', $decoded) || array_key_exists('message', $decoded) || array_key_exists('erro', $decoded))) {
                    $errorMsg = $decoded['message'] ?? $decoded['error'] ?? $decoded['erro'] ?? 'Erro na resposta da API';
                    throw new \Exception($errorMsg, $httpCode ?: 0);
                }

                if ($httpCode >= self::HTTP_ERROR_MIN) {
                    $errorMessage = $curlErrorMessage ?? "Erro HTTP {$httpCode}";
                    throw new \Exception($errorMessage, $httpCode);
                }

                return $decoded;
            }

            // Se decodificou para null, retorna array vazio (caso válido)
            if ($decoded === null) {
                if ($httpCode >= self::HTTP_ERROR_MIN) {
                    $errorMessage = $curlErrorMessage ?? "Erro HTTP {$httpCode}";
                    throw new \Exception($errorMessage, $httpCode);
                }

                return [];
            }
        }

        // Verifica outros códigos HTTP de erro (500, etc) ANTES de verificar curl->error
        if ($httpCode >= self::HTTP_ERROR_MIN) {
            $errorMessage = $curlErrorMessage ?? "Erro HTTP {$httpCode}";
            throw new \Exception($errorMessage, $httpCode);
        }

        // Verifica se houve erro HTTP.
        if ($curlError) {
            $errorMessage = $curlErrorMessage ?? 'Erro desconhecido na requisição';
            throw new \Exception($errorMessage, $curlErrorCode);
        }

        // Se já é um array, retorna como está (incluindo arrays vazios)
        if (is_array($response)) {
            return $response;
        }

        // Se é um objeto, retorna como está
        if (is_object($response)) {
            return $response;
        }

        // Se chegou aqui, a resposta não está em um formato esperado
        throw new \Exception('Formato de resposta da API não reconhecido', 0);
    }

    private function hasNotFoundDetail(array $response): bool
    {
        $detail = $response['detail'] ?? null;
        if (!is_string($detail)) {
            return false;
        }

        $normalizedDetail = function_exists('mb_strtolower') ? mb_strtolower($detail, 'UTF-8') : strtolower($detail);
        foreach (self::NOT_FOUND_DETAILS as $expectedDetail) {
            if (str_contains($normalizedDetail, $expectedDetail)) {
                return true;
            }
        }

        return false;
    }

    private function handleError(string $criticalMessageBase, \Exception $e, bool $isIntegration = false): void
    {
        // Dispara alerta para Telegram
        $app = App::i();
        $endpoint = $this->endpoint ?? 'N/A';
        $document = $this->document ?? 'N/A';

        $documentPlaceholder = $isIntegration ? 'ID da oportunidade' : 'Documento';
        $app->log->critical("{$criticalMessageBase} | Endpoint: {$endpoint} | {$documentPlaceholder}: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());

        // Qualquer erro da API é tratado como indisponibilidade
        throw new \Exception("Não foi possível consolidar seus dados, tente novamente mais tarde", 0);
    }

    /**
     * Fecha o curl
     * @return void
     */
    protected function closeCurl(): void
    {
        if (isset($this->curl)) {
            $this->curl->close();
        }
    }
}
