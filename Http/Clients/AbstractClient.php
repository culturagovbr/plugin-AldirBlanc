<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Exceptions\IntegrationError;
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
     * duração). Usado pelo OportunidadeCultJob para gravar o histórico da aba "Logs CultBr".
     *
     * @var callable|null
     */
    private $exchangeRecorder = null;

    private const PARAMETER_DEFAULT = '{document}';
    private const HTTP_REDIRECT_MIN = 300;
    private const NO_RESPONSE_MESSAGE = 'API não retornou resposta';

    public function __construct()
    {
        $config = $this->getClientConfig();

        if (empty($config)) {
            throw new \Exception('Configuração do cliente não encontrada');
        }

        $this->mode = (string) ($config['mode'] ?? '');
        $this->host = $this->requiredConfig($config, 'host', 'PNAB_CULTBR_HOST');
        $this->token = $this->requiredConfig($config, 'token', 'PNAB_CULTBR_TOKEN');
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
                // isset e não prepareUrl() direto: as propriedades são tipadas e podem não estar
                // inicializadas numa subclasse, e o erro aqui derrubaria o envio, não só o log.
                'endpoint' => isset($this->endpoint, $this->document) ? $this->prepareUrl() : ($this->endpoint ?? ''),
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
                'status' => CultBrRequestLogAttempt::RESULT_SUCCESS,
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
     * Mensagem do erro do curl (timeout, DNS, TLS) quando houver; senão, a da exceção.
     * Sem isso, uma falha de transporte chegaria ao log como exceção genérica.
     */
    private function exchangeErrorMessage(\Throwable $e): string
    {
        if ($e instanceof IntegrationError && $e->kind() !== IntegrationError::KIND_TRANSPORT) {
            return $e->getMessage();
        }

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
        // Client sem documento a substituir (o catálogo, por exemplo) não tem marcador a exigir.
        if ($this->document !== '' && !str_contains($this->endpoint, $this->parameter)) {
            throw $this->configurationError(
                'endpoint de ' . static::class,
                "não contém o marcador {$this->parameter}, e a URL sairia sem o identificador",
            );
        }

        return str_replace($this->parameter, $this->document, $this->endpoint);
    }

    /** Configuração ausente precisa dizer qual variável falta, não estourar TypeError. */
    protected function requiredConfig(array $config, string $chave, string $variavel): string
    {
        $valor = trim((string) ($config[$chave] ?? ''));

        if ($valor === '') {
            throw $this->configurationError($variavel, 'sem valor na configuração do plugin');
        }

        return $valor;
    }

    /** Loga na detecção: os três call sites engolem a exceção, e um deles sem deixar rastro. */
    private function configurationError(string $variavel, string $detalhe): IntegrationError
    {
        $erro = IntegrationError::configuration($variavel, $detalhe);

        App::i()->log->critical('[CultBR] ' . $erro->getMessage());

        return $erro;
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
        $rawBody = is_string($response) ? $response : null;

        $decoded = $response;
        $bodyIsUsable = $response !== null;

        if (is_string($response)) {
            $bodyIsUsable = trim($response) !== '';
            $decoded = $bodyIsUsable ? json_decode($response, true) : null;
            $bodyIsUsable = $bodyIsUsable && json_last_error() === JSON_ERROR_NONE;
        }

        // O status decide antes da forma do corpo: 3xx sem FOLLOWLOCATION também é falha,
        // e um corpo ilegível não pode apagar o status que veio com ele.
        if ($httpCode >= self::HTTP_REDIRECT_MIN) {
            throw IntegrationError::http(
                $this->httpErrorMessage($bodyIsUsable ? $decoded : null, $httpCode, $curlErrorMessage),
                $httpCode,
                $rawBody,
            );
        }

        // `error` da lib também é ligado por status de erro, então só aqui — sem status de
        // erro — ele significa falha de transporte de verdade.
        if ($curlError) {
            throw IntegrationError::transport(
                $this->firstFilled($curlErrorMessage, 'Erro desconhecido na requisição'),
                $curlErrorCode,
            );
        }

        if ($response === null) {
            throw IntegrationError::parse(self::NO_RESPONSE_MESSAGE, $httpCode);
        }

        if (!$bodyIsUsable) {
            $message = $rawBody !== null && trim($rawBody) === ''
                ? self::NO_RESPONSE_MESSAGE
                : 'Resposta da API não é um JSON válido';

            throw IntegrationError::parse($message, $httpCode, $rawBody);
        }

        if (is_array($decoded) || is_object($decoded)) {
            return $decoded;
        }

        // JSON `null` é resposta sem conteúdo, não erro.
        if ($decoded === null) {
            return [];
        }

        throw IntegrationError::parse('Formato de resposta da API não reconhecido', $httpCode, $rawBody);
    }

    /**
     * Mensagem de um status de erro, na ordem em que as APIs a entregam: `detail` da Conecta
     * (string na exceção HTTP, lista de campos no 422), depois as chaves da Gestão, e por fim
     * a linha de status que a lib de curl deixa em error_message.
     */
    private function httpErrorMessage(mixed $decoded, int $httpCode, ?string $curlErrorMessage): string
    {
        if (is_array($decoded)) {
            $detail = $decoded['detail'] ?? null;

            if (is_string($detail) && trim($detail) !== '') {
                return $detail;
            }

            if (is_array($detail) && $detail !== []) {
                return $this->validationDetailMessage($detail);
            }

            foreach (['message', 'error', 'erro'] as $chave) {
                if (isset($decoded[$chave]) && is_string($decoded[$chave]) && trim($decoded[$chave]) !== '') {
                    return $decoded[$chave];
                }
            }
        }

        return $this->firstFilled($curlErrorMessage, "Erro HTTP {$httpCode}");
    }

    /** Achata a lista de `{loc, msg}` do 422 do FastAPI em "campo: motivo". */
    private function validationDetailMessage(array $detail): string
    {
        $itens = [];

        foreach ($detail as $erro) {
            if (!is_array($erro)) {
                continue;
            }

            $campo = is_array($erro['loc'] ?? null) ? implode('.', $erro['loc']) : null;
            $motivo = is_string($erro['msg'] ?? null) ? $erro['msg'] : null;

            if ($motivo !== null) {
                $itens[] = $campo !== null ? "{$campo}: {$motivo}" : $motivo;
            }
        }

        return $itens === [] ? 'Payload recusado pela API' : implode('; ', $itens);
    }

    /** `error_message` do curl vem como string vazia, não null — `??` não a trata. */
    private function firstFilled(?string $valor, string $alternativa): string
    {
        return trim((string) $valor) !== '' ? $valor : $alternativa;
    }

    protected function handleError(string $criticalMessageBase, \Exception $e, bool $isIntegration = false): void
    {
        $app = App::i();
        $endpoint = $this->endpoint ?? 'N/A';
        $document = $this->document ?? 'N/A';

        $documentPlaceholder = $isIntegration ? 'ID da oportunidade' : 'Documento';
        $mensagem = "{$criticalMessageBase} | Endpoint: {$endpoint} | {$documentPlaceholder}: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode();

        // `critical` dispara alerta: um 4xx repetido por oportunidade viraria tempestade.
        if ($this->deservesAlert($e)) {
            $app->log->critical($mensagem);
        } else {
            $app->log->error($mensagem);
        }

        if ($e instanceof IntegrationError) {
            throw $e;
        }

        throw IntegrationError::parse($e->getMessage(), null, null, $e);
    }

    /** Só o que não melhora sozinho merece alerta: erro de transporte, 5xx e falha não classificada. */
    private function deservesAlert(\Throwable $e): bool
    {
        if (!$e instanceof IntegrationError) {
            return true;
        }

        if ($e->kind() === IntegrationError::KIND_TRANSPORT) {
            return true;
        }

        $status = $e->httpStatus();

        return $status === null || $status >= 500;
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
