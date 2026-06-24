<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Jobs\GestorCultJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

class ControllerSyncStatusTest extends TestCase
{
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

    function testStartSyncComErroRelancadoPeloJobPreservaMensagemDaSessao()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = 'Mensagem segura do job';
            throw new \RuntimeException('Detalhe interno');
        });

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
        $this->assertSame('Mensagem segura do job', $payload['errorMessage']);
    }

    function testStartSyncComLockExistenteNaoDeixaSessaoEmAndamento()
    {
        $controller = $this->controller();
        $controller->setSyncCallback(fn() => false);

        $payload = $this->callJson(fn() => $controller->callStartSync());

        $this->assertSame([
            'started' => false,
            'error' => true,
            'errorMessage' => GestorCultJob::API_UNAVAILABLE_MESSAGE,
        ], $payload);
        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error']);
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
