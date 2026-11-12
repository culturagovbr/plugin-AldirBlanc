<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Jobs\GestorCultJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\AgentRelation;
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
        'gestor_cult_sync_degraded',
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

    private function loginGestorComVinculo(): void
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $ente = new FederativeEntity();
        $ente->name = 'Ente Gravado';
        $ente->document = '55555555000155';
        $ente->exercices = [];
        $ente->createTimestamp = new \DateTime();
        $this->app->em->persist($ente);

        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $user->profile;
        $relation->owner = $ente;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
    }

    private function falhaDeTransporte(): TestableController
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            throw IntegrationError::transport('Connection timed out', 28);
        });

        return $controller;
    }

    private function assertEntrouDegradado(array $payload): void
    {
        $this->assertSame(['started' => true, 'degraded' => true], $payload);
        $this->assertSessaoDegradada();
    }

    private function alertaDaFalha(callable $falha): string
    {
        $controller = $this->controller();
        $controller->setSyncCallback($falha);

        $capturado = $this->capturandoLog(fn() => $this->callJson(fn() => $controller->callStartSync()));
        $criticos = array_values(array_filter(
            $capturado->getRecords(),
            fn($registro) => $registro['level_name'] === 'CRITICAL',
        ));

        $this->assertCount(1, $criticos);
        $this->assertEntrouDegradado(json_decode((string) $this->app->response->getBody(), true));

        return $criticos[0]['message'];
    }

    private function assertSessaoDegradada(): void
    {
        $this->assertTrue($_SESSION['gestor_cult_sync_completed']);
        $this->assertTrue($_SESSION['gestor_cult_sync_degraded']);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertArrayNotHasKey('gestor_cult_sync_error_message', $_SESSION);
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

    public function testErroMarcadoPeloJobSemExcecaoEntraMesmoAssim(): void
    {
        $controller = $this->controller();
        $controller->setSyncCallback(function () {
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = 'Falha antiga';
            return true;
        });

        $this->assertEntrouDegradado($this->callJson(fn() => $controller->callStartSync()));
    }

    public function testFalhaAoObterCpfEntraMesmoAssimSemChamarAApi(): void
    {
        $controller = $this->controller();
        $controller->setCpfException(new \RuntimeException('CPF interno quebrado'));

        $this->assertEntrouDegradado($this->callJson(fn() => $controller->callStartSync()));
        $this->assertSame(0, $controller->getSyncCalls());
    }

    /** Classificação parcial deixada na sessão não pode vencer a do controller, que vê a exceção. */
    public function testErroRelancadoEhClassificadoPelaExcecaoENaoPelaSessao(): void
    {
        $alerta = $this->alertaDaFalha(function () {
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            throw IntegrationError::http('Credencial recusada', 401);
        });

        $this->assertStringContainsString('(credential_refused)', $alerta);
    }

    /** 401 na Conecta e 403 na Gestão são a mesma causa. */
    public function testCredencialRecusadaEhClassificadaNosDoisStatus(): void
    {
        foreach ([401, 403] as $status) {
            $alerta = $this->alertaDaFalha(fn() => throw IntegrationError::http('Credencial recusada', $status));

            $this->assertStringContainsString('(credential_refused)', $alerta, "status {$status}");
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

    public function testCpfAusenteEhErroInternoSemChamarAApi(): void
    {
        $controller = $this->controller();
        $controller->setGestorCpf('');

        $capturado = $this->capturandoLog(fn() => $this->assertEntrouDegradado($this->callJson(fn() => $controller->callStartSync())));

        $this->assertTrue($capturado->hasCriticalThatContains('Sincronização falhou (internal_error) | Usuário ID: '));
        $this->assertTrue($capturado->hasCriticalThatContains('Erro: CPF do gestor ausente no perfil'));
        $this->assertSame(0, $controller->getSyncCalls());
    }

    public function testCpfNuloNoPerfilEhErroInterno(): void
    {
        $controller = $this->controller();
        $controller->setCpfException(new \TypeError('documentOnlyNumbers(): Argument #1 must be of type string, null given'));

        $capturado = $this->capturandoLog(fn() => $this->callJson(fn() => $controller->callStartSync()));

        $this->assertTrue($capturado->hasCriticalThatContains('Sincronização falhou (internal_error)'));
    }

    public function testFalhaDeBancoEhErroInterno(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw new \PDOException('SQLSTATE[08006] could not connect to server'));

        $this->assertStringContainsString('(internal_error)', $alerta);
    }

    /** 404 não é credencial: continua no ramo genérico. */
    public function testOutroErroHttpNaoViraCredencialRecusada(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw IntegrationError::http('Não encontrado', 404));

        $this->assertStringContainsString('(api_unavailable)', $alerta);
    }

    public function testFalhaDeConfiguracaoEhClassificadaComoTal(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw IntegrationError::configuration('PNAB_CULTBR_PROVIDER', 'sem valor'));

        $this->assertStringContainsString('(configuration_error)', $alerta);
    }

    public function testExcecaoDeValorInesperadoDeForaDaIntegracaoEhErroInterno(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw new \UnexpectedValueException('The stream or file "/var/log/app.log" could not be opened'));

        $this->assertStringContainsString('(internal_error)', $alerta);
    }

    public function testContratoOuCorpoIlegivelDaIntegracaoEhRespostaInesperada(): void
    {
        foreach ([IntegrationError::contract('entes_federados deve ser array'), IntegrationError::parse('JSON inválido', 200)] as $erro) {
            $alerta = $this->alertaDaFalha(fn() => throw $erro);

            $this->assertStringContainsString('(unexpected_response)', $alerta, $erro->kind());
        }
    }

    public function testFalhaDeTransporteEhClassificadaComoApiIndisponivel(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw IntegrationError::transport('Connection timed out', 28));

        $this->assertStringContainsString('(api_unavailable)', $alerta);
    }

    public function testErroDoServidorEhClassificadoComoApiIndisponivel(): void
    {
        $alerta = $this->alertaDaFalha(fn() => throw IntegrationError::http('Erro HTTP 500', 500));

        $this->assertStringContainsString('(api_unavailable)', $alerta);
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

    public function testCheckSyncStatusSessaoVencidaEntraMesmoAssim(): void
    {
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time() - 301;

        $payload = $this->callJson(fn() => $this->controller()->callCheckSyncStatus());

        $this->assertSame(['ready' => true, 'error' => false, 'errorMessage' => null], $payload);
        $this->assertSessaoDegradada();
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

    public function testFalhaDaSyncDeGestorComVinculoEntraComOsDadosGravados(): void
    {
        $this->loginGestorComVinculo();

        $capturado = $this->capturandoLog(fn() => $this->assertEntrouDegradado($this->callJson(fn() => $this->falhaDeTransporte()->callStartSync())));

        $this->assertTrue($capturado->hasWarningThatContains('Entrando apesar da falha da sincronização (modo degradado)'));
    }

    public function testFalhaDaSyncDeUsuarioSemPapelEntraComoUsuarioComum(): void
    {
        $this->assertEntrouDegradado($this->callJson(fn() => $this->falhaDeTransporte()->callStartSync()));
        $this->assertFalse($this->app->user->is(Role::GESTOR_CULT_BR));
    }

    public function testModoDegradadoMantemPapelEVinculo(): void
    {
        $this->loginGestorComVinculo();

        $this->callJson(fn() => $this->falhaDeTransporte()->callStartSync());

        $this->assertTrue($this->app->user->is(Role::GESTOR_CULT_BR));
        $this->assertCount(1, $this->app->repo(FederativeEntityAgentRelation::class)->findBy([
            'agent' => $this->app->user->profile,
        ]));
    }

    public function testNovaSincronizacaoLimpaMarcaDeModoDegradado(): void
    {
        $this->loginGestorComVinculo();
        $_SESSION['gestor_cult_sync_degraded'] = true;

        $this->callJson(fn() => $this->controller()->callStartSync());

        $this->assertArrayNotHasKey('gestor_cult_sync_degraded', $_SESSION);
    }
}
