<?php

namespace AldirBlanc\Http\Clients;

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

    public final function get()
    {
        // Utilizado para testes locais
        if ($this->isDevelopmentMode()) {
            return require $this->getFixturePath();
        }

        $fullUrl = $this->prepareUrl();

        try {
            $this->curl->get($fullUrl);
            return $this->parseResponse($this->curl->response);
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
            $this->curl->post($fullUrl, $jsonPayload);
            $rawResponse = $this->curl->response;
            $parsed = $this->parseResponse($rawResponse);
            return $parsed;
        } catch (\Exception $e) {
            $this->handleError('[CultBR] Erro na API ao enviar dados (POST)', $e);
        } finally {
            $this->closeCurl();
        }
    }

    public final function put(array $data)
    {
        if ($this->isDevelopmentMode()) {
            return $data;
        }

        $fullUrl = $this->prepareUrl();
        $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

        try {
            $this->curl->setOpt(CURLOPT_CUSTOMREQUEST, 'PUT');
            $this->curl->post($fullUrl, $jsonPayload);
            $rawResponse = $this->curl->response;
            $parsed = $this->parseResponse($rawResponse);
            return $parsed;
        } catch (\Exception $e) {
            $this->handleError('[CultBR] Erro na API ao atualizar dados (PUT)', $e, true);
        } finally {
            $this->closeCurl();
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
     * @param mixed $response valor de $this->curl->response (string, array, object ou null)
     * @return array|object resultado decodificado ou array vazio quando a API informa ausência de dados
     * @throws \Exception em erro de JSON, 4xx/5xx ou formato não reconhecido
     */
    private function parseResponse(mixed $response): array|object
    {
        // Obtém o código HTTP da resposta (lib curl/curl expõe http_status_code, não getInfo()/httpStatusCode)
        $httpCode = $this->curl->http_status_code ?? 0;

        if ($response === null) {
            throw new \Exception(self::NO_RESPONSE_MESSAGE, $httpCode);
        }

        // Se a resposta é uma string JSON, decodifica para array
        if (is_string($response)) {
            // Se a string está vazia, trata como indisponibilidade/erro de API.
            if (trim($response) === '') {
                $errorMessage = $this->curl->error_message ?? "Erro HTTP {$httpCode}";
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
                if (!empty($decoded) && (isset($decoded['error']) || isset($decoded['message']) || isset($decoded['erro']))) {
                    $errorMsg = $decoded['message'] ?? $decoded['error'] ?? $decoded['erro'] ?? 'Erro na resposta da API';
                    throw new \Exception($errorMsg, $httpCode ?: 0);
                }

                return $decoded;
            }

            // Se decodificou para null, retorna array vazio (caso válido)
            if ($decoded === null) {
                return [];
            }
        }

        // Verifica outros códigos HTTP de erro (500, etc) ANTES de verificar curl->error
        if ($httpCode >= self::HTTP_ERROR_MIN) {
            $errorMessage = $this->curl->error_message ?? "Erro HTTP {$httpCode}";
            throw new \Exception($errorMessage, $httpCode);
        }

        // Verifica se houve erro HTTP.
        if ($this->curl->error) {
            $errorMessage = $this->curl->error_message ?? 'Erro desconhecido na requisição';
            throw new \Exception($errorMessage, $this->curl->error_code ?? 0);
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

        $normalizedDetail = strtolower($detail);
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
