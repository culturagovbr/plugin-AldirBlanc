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
            $this->curl->close();
            $response =  $this->curl->response;

            return $response;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao buscar dados: {$e->getMessage()}");
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
