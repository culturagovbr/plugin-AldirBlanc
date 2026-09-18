<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Enum\Mode;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Transport\CurlTransport;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Http\Transport\TransportResponse;
use AldirBlanc\Plugin;
use MapasCulturais\App;

abstract class AbstractClient
{
    protected string $endpoint;
    protected string $document;

    /** @var string placeholder para substituir no endpoint */
    protected string $parameter;

    private Mode $mode;

    private string $host;
    private string $token;

    private Transport $transport;

    private ?TransportResponse $lastResponse = null;

    /**
     * Callback opcional que recebe request + resposta de cada PUT (payload, status HTTP, corpo,
     * duração). Usado pelo OportunidadeCultJob para gravar o histórico da aba "Logs CultBr".
     *
     * @var callable|null
     */
    private $exchangeRecorder = null;

    /** Caminho da fixture, relativo a Http/Fixtures. Declarado por client para não colidir entre provedores. */
    protected const FIXTURE = '';

    /** A qual provedor o client pertence; é o que decide de qual bucket ele lê a configuração. */
    protected const PROVIDER = '';

    private const PARAMETER_DEFAULT = '{document}';
    private const HTTP_REDIRECT_MIN = 300;
    private const HTTP_CLIENT_ERROR_MIN = 400;
    private const NO_RESPONSE_MESSAGE = 'API não retornou resposta';

    public function __construct(?Transport $transport = null)
    {
        $config = $this->getClientConfig();

        if (empty($config)) {
            throw $this->configurationError($this->envName('*'), 'nenhuma variável do provedor está definida');
        }

        $this->mode = Plugin::modoDaIntegracao();
        $this->host = rtrim($this->requiredConfig($config, 'host', $this->envName('HOST')), '/');
        $this->token = $this->requiredConfig($config, 'token', $this->envName('TOKEN'));
        $this->parameter = self::PARAMETER_DEFAULT;
        $this->transport = $transport ?? new CurlTransport();
    }

    private function send(string $method, string $url, ?string $body = null): TransportResponse
    {
        $this->lastResponse = null;

        $resposta = $this->transport->send($method, $url, $this->headers(), $body);
        $this->lastResponse = $resposta;

        return $resposta;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function isDevelopmentMode(): bool
    {
        return $this->mode === Mode::Development;
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
            return $this->loadFixture();
        }

        $fullUrl = $this->prepareUrl();
        $app->log->info("[Gestores CultBR] GET requisição | Cliente: " . static::class . " | URL: {$fullUrl}");

        try {
            $resposta = $this->send('GET', $fullUrl);
            $app->log->info("[Gestores CultBR] GET resposta recebida | Cliente: " . static::class . " | HTTP: {$resposta->status}");

            return $this->parseResponse(
                $resposta->body,
                $resposta->status,
                $resposta->hasError,
                $resposta->errorMessage,
                $resposta->errorCode,
            );
        } catch (\Exception $e) {
            $this->handleError('[Gestores CultBR] Erro na API ao buscar dados', $e);
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
            $resposta = $this->send('POST', $fullUrl, $jsonPayload);

            return $this->parseResponse(
                $resposta->body,
                $resposta->status,
                $resposta->hasError,
                $resposta->errorMessage,
                $resposta->errorCode,
            );
        } catch (\Exception $e) {
            $this->handleError('[CultBR] Erro na API ao enviar dados (POST)', $e);
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
            $resposta = $this->send('PUT', $fullUrl, $jsonPayload);
            $rawResponse = $resposta->body;
            $app->log->info("[CultBR] PUT response | HTTP: {$resposta->status} | Body: " . (is_string($rawResponse) ? $rawResponse : json_encode($rawResponse)));
            $parsed = $this->parseResponse(
                $rawResponse,
                $resposta->status,
                $resposta->hasError,
                $resposta->errorMessage,
                $resposta->errorCode,
            );

            $this->recordExchange([
                'method' => 'PUT',
                'endpoint' => $fullUrl,
                'payload' => $data,
                'response' => is_string($rawResponse) ? $rawResponse : json_encode($rawResponse),
                'responseHeaders' => $resposta->headers,
                'httpStatus' => $resposta->status,
                'status' => CultBrRequestLogAttempt::RESULT_SUCCESS,
                'sentAt' => $sentAt,
                'durationMs' => $this->elapsedMs($startedAt),
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            $rawResponse = $this->lastResponse?->body;

            $this->recordExchange([
                'method' => 'PUT',
                'endpoint' => $fullUrl,
                'payload' => $data,
                'response' => is_string($rawResponse) ? $rawResponse : json_encode($rawResponse),
                'responseHeaders' => $this->lastResponse?->headers,
                'httpStatus' => $this->lastResponse?->status,
                'error' => $this->exchangeErrorMessage($e),
                'status' => CultBrRequestLogAttempt::RESULT_ERROR,
                'sentAt' => $sentAt,
                'durationMs' => $this->elapsedMs($startedAt),
            ]);

            $this->handleError('[CultBR] Erro na API ao atualizar dados (PUT)', $e, true);
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Mensagem do erro de transporte (timeout, DNS, TLS) quando houver; senão, a da exceção.
     * Sem isso, uma falha de transporte chegaria ao log como exceção genérica.
     */
    private function exchangeErrorMessage(\Throwable $e): string
    {
        if ($e instanceof IntegrationError && $e->kind() !== IntegrationError::KIND_TRANSPORT) {
            return $e->getMessage();
        }

        $erroDeTransporte = trim((string) ($this->lastResponse?->errorMessage ?? ''));

        return $erroDeTransporte !== '' ? $erroDeTransporte : $e->getMessage();
    }

    protected function envName(string $sufixo): string
    {
        return 'PNAB_CULTBR_' . $sufixo;
    }

    protected function getClientConfig(): array
    {
        return $this->clientConfig()['providers'][static::PROVIDER] ?? [];
    }

    /** O modo vale para a integração inteira: ou os dois provedores simulam, ou nenhum simula. */
    private function clientConfig(): array
    {
        return Plugin::getInstance()->config['client'] ?? [];
    }

    /** Fixture ausente precisa dizer qual arquivo falta, não derrubar o processo com erro fatal. */
    private function loadFixture(): mixed
    {
        if (static::FIXTURE === '') {
            throw $this->configurationError('fixture de ' . static::class, 'a classe não declara arquivo de simulação');
        }

        $caminho = $this->getFixturePath();

        if (!is_file($caminho)) {
            throw $this->configurationError('fixture de ' . static::class, "arquivo não encontrado em {$caminho}");
        }

        return require $caminho;
    }

    private function getFixturePath(): string
    {
        return __DIR__ . '/../Fixtures/' . static::FIXTURE;
    }

    /**
     * Prepara a URL para a requisição
     * @return string a URL preparada para a requisição
     */
    private function prepareUrl(): string
    {
        return $this->host . '/' . ltrim($this->prepareEndpoint(), '/');
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
    /** Endpoint é fato sobre a API, não configuração de ambiente: faltar é erro de programação. */
    protected function requiredEndpoint(string $chave): string
    {
        $valor = trim((string) ($this->getClientConfig()[$chave] ?? ''));

        if ($valor === '') {
            throw $this->configurationError(static::PROVIDER . '.' . $chave, 'endpoint não declarado para este provedor');
        }

        return $valor;
    }

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
     * Interpreta a resposta da API (código HTTP, body JSON, erros) e retorna o resultado ou lança exceção.
     * Recebe o estado da resposta como parâmetros explícitos, para ser testável de forma pura.
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

        // Devolver [] aqui faria a resposta sem conteúdo passar por "a API respondeu e não
        // trouxe nada", que é o que revoga o papel do gestor.
        if ($decoded === null) {
            throw IntegrationError::contract('corpo da resposta é null', [], $httpCode, $rawBody);
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
        if ($this->isRedirect($httpCode)) {
            return "Erro HTTP {$httpCode} — redirecionamento não seguido;"
                . ' confira o esquema do host e a barra final do endpoint';
        }

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

    protected function handleError(string $criticalMessageBase, \Throwable $e, bool $isIntegration = false): void
    {
        $app = App::i();
        $endpoint = $this->endpoint ?? 'N/A';
        $document = $this->document ?? 'N/A';

        $documentPlaceholder = $isIntegration ? 'ID da oportunidade' : 'Documento';
        $mensagem = "{$criticalMessageBase} | Endpoint: {$endpoint} | {$documentPlaceholder}: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode();

        // No envio quem alerta é o job, único a saber se ainda há tentativa pela frente.
        if (!$isIntegration && $this->deservesAlert($e)) {
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

        return $status === null || $status >= 500 || $this->isRedirect($status);
    }

    /** Redirect não é instabilidade da API: é o endereço configurado apontando para outro lugar. */
    private function isRedirect(?int $status): bool
    {
        return $status !== null
            && $status >= self::HTTP_REDIRECT_MIN
            && $status < self::HTTP_CLIENT_ERROR_MIN;
    }
}
