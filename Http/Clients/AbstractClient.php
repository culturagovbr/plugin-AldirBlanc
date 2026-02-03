<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Http\Fixtures\GestorEndpoint;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use Curl\Curl;
use ReflectionClass;

abstract class AbstractClient
{
    protected string $endpoint;
    protected string $document;

    private string $mode;

    private string $host;
    private string $token;

    private Curl $curl;

    public function __construct()
    {
        $config = $this->getClientConfig();

        if (empty($config)) {
            throw new \Exception('Configuração do cliente não encontrada');
        }

        $this->mode = $config['mode'];
        $this->host = $config['host'];
        $this->token = $config['token'];

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

            $response = $this->curl->response;

            // Obtém o código HTTP da resposta usando múltiplas formas
            $httpCode = 0;
            if (method_exists($this->curl, 'getInfo')) {
                $httpCode = $this->curl->getInfo(CURLINFO_HTTP_CODE) ?: 0;
            }

            if ($httpCode === 0) {
                $httpCode = $this->curl->httpStatusCode ?? $this->curl->httpErrorCode ?? 0;
            }

            if ($httpCode === 404) {
                return [];
            }

            // Se a resposta é uma string JSON, decodifica para array
            if (is_string($response)) {
                // Se a string está vazia, retorna array vazio (caso válido quando não encontra dados)
                if (trim($response) === '') {
                    return [];
                }

                $decoded = json_decode($response, true);

                // Verifica se houve erro no JSON
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Resposta da API não é um JSON válido', 0);
                }

                // Verifica se a resposta contém o JSON de erro 404 da API
                if (
                    is_array($decoded) && isset($decoded['detail']) &&
                    strpos(strtolower($decoded['detail']), 'não encontrada') !== false
                ) {
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
            if ($httpCode >= 400 && $httpCode !== 404) {
                $errorMessage = $this->curl->errorMessage ?? "Erro HTTP {$httpCode}";
                throw new \Exception($errorMessage, $httpCode);
            }

            // Verifica se houve erro HTTP (exceto 404 que já foi tratado)
            if ($this->curl->error && $httpCode !== 404) {
                $errorMessage = $this->curl->errorMessage ?? 'Erro desconhecido na requisição';
                throw new \Exception($errorMessage, $this->curl->errorCode ?? 0);
            }

            // Se já é um array, retorna como está (incluindo arrays vazios)
            if (is_array($response)) {
                return $response;
            }

            // Se é null, retorna array vazio (caso válido quando não encontra dados)
            if ($response === null) {
                return [];
            }

            // Se é um objeto, retorna como está
            if (is_object($response)) {
                return $response;
            }

            // Se chegou aqui, a resposta não está em um formato esperado
            throw new \Exception('Formato de resposta da API não reconhecido', 0);
        } catch (\Exception $e) {
            // Dispara alerta para Telegram
            $app = App::i();
            $endpoint = $this->endpoint ?? 'N/A';
            $document = $this->document ?? 'N/A';
            $app->log->critical("[Gestores CultBR] Erro na API ao buscar dados | Endpoint: {$endpoint} | Documento: {$document} | URL: {$fullUrl} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());

            // Qualquer erro da API é tratado como indisponibilidade
            throw new \Exception("Não foi possível consolidar seus dados, tente novamente mais tarde", 0);
        } finally {
            // Garante que o curl seja sempre fechado
            if (isset($this->curl)) {
                $this->curl->close();
            }
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

    private function prepareUrl(): string
    {
        return "{$this->host}/{$this->prepareEndpoint()}";
    }

    private function prepareEndpoint(): string
    {
        return str_replace('{document}', $this->document, $this->endpoint);
    }
}
