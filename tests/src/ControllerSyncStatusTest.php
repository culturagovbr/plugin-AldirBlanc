<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\SyncFailure;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Jobs\GestorCultJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\Traits\UserDirector;

class ControllerSyncStatusTest extends TestCase
{
    use CapturesLog;
    use UserDirector;

    private const SYNC_KEYS = [
        'gestor_cult_sync_started',
        'gestor_cult_sync_completed',
        'gestor_cult_sync_started_at',
        'gestor_cult_sync_error',
        'gestor_cult_sync_error_message',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->login($this->userDirector->createUser());
        $this->clearSyncSession();
    }

    private function clearSyncSession(): void
    {
        foreach (self::SYNC_KEYS as $key) {
            unset($_SESSION[$key]);
        }
    }

    private function controller(): TestableController
    {
        return new TestableController();
    }

    private function callJson(callable $callback): array
    {
        $this->app->response = new Response();

        try {
            $callback();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    function testStartSyncPrimeiraChamadaDisparaJobERetornaStarted()
    {
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame(['started' => true], $payload);
        $this->assertSame(1, $controller->getSyncCalls());
        $this->assertTrue($_SESSION['gestor_cult_sync_started']);
        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertArrayHasKey('gestor_cult_sync_started_at', $_SESSION);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
    }

    function testStartSyncEmAndamentoNaoRechamaJob()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time();
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame(['started' => true], $payload);
        $this->assertSame(0, $controller->getSyncCalls());
        $this->assertFalse($_SESSION['gestor_cult_sync_completed']);
    }

    function testStartSyncConcluidoPermiteNovaExecucao()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame(['started' => true], $payload);
        $this->assertSame(1, $controller->getSyncCalls());
    }

    function testStartSyncComErroMarcadoPeloJobSemExcecaoPreservaErro()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            $_SESSION['gestor_cult_sync_completed'] = true;
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = 'Falha preservada';
            return true;
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame([
            'started' => false,
            'error' => true,
            'retryable' => true,
            'errorMessage' => 'Falha preservada',
        ], $payload);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
        $this->assertSame('Falha preservada', $_SESSION['gestor_cult_sync_error_message']);
    }

    function testStartSyncComFalhaAoObterCpfRespondeErroSeguro()
    {
        $controller = $this->controller();
        $controller->setCpfException(new \RuntimeException('CPF interno quebrado'));

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $payload['errorMessage']);
        $this->assertSame(0, $controller->getSyncCalls());
    }

    /** Classificação parcial deixada na sessão não pode vencer a do controller, que vê a exceção. */
    function testStartSyncComErroRelancadoIgnoraClassificacaoJaGravadaNaSessao()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = 'Mensagem antiga';
            throw IntegrationError::http('Credencial recusada', 401);
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertSame('credential_refused', $_SESSION['gestor_cult_sync_error']);
        $this->assertSame(SyncFailure::CredentialRefused->message(), $payload['errorMessage']);
        $this->assertFalse($payload['retryable']);
    }

    /** 401 na Conecta e 403 na Gestão são a mesma causa: credencial que nenhuma espera conserta. */
    function testCredencialRecusadaNaoEhRetentavelNosDoisStatus()
    {
        foreach ([401, 403] as $status) {
            $controller = $this->controller();
            $controller->setSyncCallback(function () use ($status) {
                throw IntegrationError::http('Credencial recusada', $status);
            });

            $payload = $this->callJson(fn() => $controller->callStartSync());

            $this->assertSame('credential_refused', $_SESSION['gestor_cult_sync_error'], "status {$status}");
            $this->assertFalse($payload['retryable'], "status {$status}");
            $this->assertStringNotContainsString('Tente novamente', $payload['errorMessage'], "status {$status}");
        }
    }

    /** O job falha antes do próprio catch quando o cache cai; nenhuma falha pode passar calada. */
    function testQualquerFalhaDoSyncAlertaUmaVezComACausaClassificada()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::http('Credencial recusada', 401);
        });

        $capturado = $this->capturandoLog(function () use ($controller) {
            $this->callJson(fn() => $controller->callStartSync());
        });

        $criticos = array_filter(
            $capturado->getRecords(),
            fn($registro) => $registro['level_name'] === 'CRITICAL',
        );

        $this->assertCount(1, $criticos, 'a falha precisa alertar, e uma vez só');
        $this->assertStringContainsString('credential_refused', reset($criticos)['message']);
    }

    /** A tela desiste na hora diante de falha permanente; o texto não pode pedir o contrário. */
    function testNenhumaFalhaPermanenteConvidaARepetir()
    {
        $permanentes = array_filter(SyncFailure::cases(), fn(SyncFailure $falha) => !$falha->isRetryable());

        $this->assertNotEmpty($permanentes);

        foreach ($permanentes as $falha) {
            $this->assertStringNotContainsString('Tente novamente', $falha->message(), $falha->value);
            $this->assertStringContainsString('repetir não resolve', $falha->message(), $falha->value);
        }
    }

    /** 404 não é credencial: continua no ramo genérico, para a mudança não endurecer demais. */
    function testOutroErroHttpNaoViraCredencialRecusada()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::http('Não encontrado', 404);
        });

        $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
    }

    /** Nenhuma dessas causas depende do gestor, mas cada uma diz a sua, e nenhuma pede repetição. */
    function testFalhaDeConfiguracaoNaoEhRetentavelETemMensagemPropria()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::configuration('PNAB_CULTBR_PROVIDER', 'sem valor');
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertFalse($payload['retryable']);
        $this->assertSame(SyncFailure::ConfigurationError->message(), $payload['errorMessage']);
        $this->assertStringContainsString('mal configurada', $payload['errorMessage']);
        $this->assertSame('configuration_error', $_SESSION['gestor_cult_sync_error']);
    }

    function testRespostaForaDoContratoNaoEhRetentavel()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw new \UnexpectedValueException('Resposta da API CultBr fora do contrato esperado');
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertFalse($payload['retryable']);
        $this->assertSame(SyncFailure::UnexpectedResponse->message(), $payload['errorMessage']);
        $this->assertStringContainsString('fora do esperado', $payload['errorMessage']);
        $this->assertSame('unexpected_response', $_SESSION['gestor_cult_sync_error']);
    }

    /** Timeout e erro do servidor melhoram na tentativa seguinte, e seguem sendo retentados. */
    function testFalhaDeTransporteContinuaRetentavelComAMensagemDeSempre()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::transport('Connection timed out', 28);
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertTrue($payload['retryable']);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $payload['errorMessage']);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
    }

    function testErroDoServidorContinuaRetentavel()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::http('Erro HTTP 500', 500);
        });

        $this->assertTrue($this->callJson(fn() => $controller->callStartSync())['retryable']);
    }

    /**
     * Lock ativo é concorrência local — o sync está em curso em outra requisição ou acabou de
     * terminar. Dizer "sem conexão" com a API no ar faria o gestor esperar por nada.
     */
    function testStartSyncComLockExistenteDelegaAoStatusEmVezDeAcusarFalhaDeApi()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(fn() => false);

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame(['started' => true], $payload);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
    }

    function testStartSyncComSessaoStalePermiteReexecutar()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time() - 301;
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame(['started' => true], $payload);
        $this->assertSame(1, $controller->getSyncCalls());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
    }

    function testCheckSyncStatusNaoIniciadoRetornaNaoPronto()
    {
        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame(['ready' => false], $payload);
    }

    function testCheckSyncStatusEmAndamentoRetornaNaoPronto()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time();

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame(['ready' => false], $payload);
    }

    function testCheckSyncStatusConcluidoSemErroRetornaProntoELimpaMensagemAntiga()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        $_SESSION['gestor_cult_sync_error'] = '';
        $_SESSION['gestor_cult_sync_error_message'] = 'Mensagem velha';

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame([
            'ready' => true,
            'error' => false,
            'errorMessage' => null,
        ], $payload);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertArrayNotHasKey('gestor_cult_sync_error_message', $_SESSION);
    }

    function testCheckSyncStatusConcluidoComErroRetornaMensagem()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
        $_SESSION['gestor_cult_sync_error_message'] = 'Falha esperada';

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame([
            'ready' => true,
            'error' => true,
            'errorMessage' => 'Falha esperada',
        ], $payload);
    }

    function testCheckSyncStatusConcluidoSemStartedNaoFicaEmPolling()
    {
        $_SESSION['gestor_cult_sync_completed'] = true;

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame([
            'ready' => true,
            'error' => false,
            'errorMessage' => null,
        ], $payload);
    }

    function testCheckSyncStatusErroSemMensagemUsaPadraoSeguro()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $payload['errorMessage']);
    }

    function testCheckSyncStatusStaleMarcaErroControlado()
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time() - 301;

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame([
            'ready' => true,
            'error' => true,
            'errorMessage' => GestorCultJob::API_UNAVAILABLE_MESSAGE,
        ], $payload);
        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
    }

    function testLogoutOnErrorLimpaFlagsERetornaRedirect()
    {
        foreach (self::SYNC_KEYS as $key) {
            $_SESSION[$key] = 'valor';
        }
        $_SESSION['selectedFederativeEntity'] = 123;
        $_SESSION['federative_entity_redirect_uri'] = '/painel';

        $payload = $this->callJson(fn() => $this->controller()->callLogoutOnError());

        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['redirectTo']);
        foreach (self::SYNC_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $_SESSION);
        }
        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
    }
}
