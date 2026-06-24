<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * auth.successful, auth.logout:before, blockAccessOnError (Theme.php, Pnab).
 *
 * blockAccessOnError não captura $canAccess via closure (diferente de outros hooks do Theme.php) —
 * é totalmente testável via applyHookBoundTo(), desde que $app->request/controller estejam
 * montados (ver dispatchBefore()).
 *
 * Lacuna de testabilidade conhecida, NÃO testada: os branches AJAX de "sync concluído com
 * erro" e "perfil incompleto" chamam `exit;` literal (Theme.php) — invocá-los aqui mataria o
 * processo do PHPUnit inteiro, não só o teste. Só os branches não-AJAX desses dois gates são
 * exercitados (ambos usam Halt via $app->redirect(), seguro de capturar).
 */
class ThemeAuthHooksTest extends TestCase
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
        foreach (self::SYNC_KEYS as $key) {
            unset($_SESSION[$key]);
        }
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);
        unset($_SESSION['auth.asUserId']);
        unset($_SESSION['mapasculturais.auth.redirect_path']);
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        return $entity;
    }

    private function persistRelation($agent, FederativeEntity $entity): void
    {
        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
    }

    private function selectEntityInSession(FederativeEntity $entity): void
    {
        $_SESSION['selectedFederativeEntity'] = json_encode([
            'id' => $entity->id,
            'name' => $entity->name,
            'document' => $entity->document,
        ]);
    }

    private function fillAllRequiredFields($profile): void
    {
        $keys = [
            'nomeCompleto', 'cpf', 'emailPrivado', 'telefonePublico',
            'acessouFomentoCultural', 'anosExperienciaAreaCultural', 'eMestreCulturasTradicionais',
            'En_CEP', 'En_Nome_Logradouro', 'En_Num', 'En_Bairro', 'En_Municipio', 'En_Estado',
            'dataDeNascimento', 'genero', 'orientacaoSexual', 'raca', 'renda', 'escolaridade',
            'pessoaDeficiente', 'comunidadesTradicional',
        ];
        $this->app->disableAccessControl();
        foreach ($keys as $key) {
            $profile->$key = $key === 'dataDeNascimento' ? '1990-01-01' : ($key === 'cpf' ? '52998224725' : 'valor-de-teste');
        }
        $profile->save(true);
        $this->app->enableAccessControl();
    }

    /**
     * Monta $app->request + controller com id/action reais e dispara o hook ":before" exato
     * que o core aplicaria em RoutesManager::callAction() pra essa rota — exercitando
     * blockAccessOnError pelo mesmo caminho de wildcard matching usado em produção.
     */
    private function dispatchBefore(string $method, string $controllerId, string $action, bool $ajax = false, string $path = '/'): void
    {
        $psr7 = new ServerRequest([], [], $path, $method);
        if ($ajax) {
            $psr7 = $psr7->withHeader('X-Requested-With', 'XMLHttpRequest');
        }
        $this->app->request = new Request($psr7, $controllerId, $action, []);
        $_SERVER['REQUEST_URI'] = $path;

        $controller = $this->app->controller($controllerId);
        $controller->action = $action;

        $this->app->response = new Response();
        $this->app->applyHookBoundTo($controller, strtoupper($method) . "({$controllerId}.{$action}):before");
    }

    private function assertLibera(string $method, string $controllerId, string $action, bool $ajax = false, string $path = '/'): void
    {
        try {
            $this->dispatchBefore($method, $controllerId, $action, $ajax, $path);
        } catch (Halt) {
            $this->fail("Esperava que {$method} {$controllerId}.{$action} (ajax=" . ($ajax ? '1' : '0') . ") liberasse, mas bloqueou (Halt)");
        }
        $this->assertSame('', $this->app->response->getHeaderLine('Location'));
    }

    private function assertRedirectPara(string $expectedRouteFragment, string $method, string $controllerId, string $action, bool $ajax = false): void
    {
        try {
            $this->dispatchBefore($method, $controllerId, $action, $ajax);
            $this->fail("Esperava Halt (redirect) pra {$method} {$controllerId}.{$action}");
        } catch (Halt) {
        }
        $this->assertStringContainsString($expectedRouteFragment, $this->app->response->getHeaderLine('Location'));
    }

    // ===== auth.successful =====

    function testAuthSuccessfulUsuarioComumLimpaFlagsESetaRedirectPath()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $_SESSION['gestor_cult_sync_completed'] = true;
        $_SESSION['selectedFederativeEntity'] = '{"id":1}';

        $this->app->applyHookBoundTo($this->app->user, 'auth.successful');

        $this->assertArrayNotHasKey('gestor_cult_sync_completed', $_SESSION);
        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
        $this->assertStringContainsString('consolidatingData', $_SESSION['mapasculturais.auth.redirect_path']);
    }

    function testAuthSuccessfulAdminNaoSetaRedirectPath()
    {
        $user = $this->userDirector->createUser([Role::ADMIN]);
        $this->login($user);

        $this->app->applyHookBoundTo($this->app->user, 'auth.successful');

        $this->assertArrayNotHasKey('mapasculturais.auth.redirect_path', $_SESSION);
    }

    // ===== auth.logout:before =====

    function testAuthLogoutLimpaTodasAsFlagsRelevantes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        foreach (self::SYNC_KEYS as $key) {
            $_SESSION[$key] = 'valor';
        }
        $_SESSION['selectedFederativeEntity'] = '{"id":1}';
        $_SESSION['federative_entity_redirect_uri'] = '/algumacoisa';

        $this->app->applyHookBoundTo($this->app->user, 'auth.logout:before');

        foreach (self::SYNC_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $_SESSION);
        }
        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
    }

    // ===== blockAccessOnError =====

    function testBlockAccessGuestLibera()
    {
        $this->logout();
        $this->assertLibera('GET', 'panel', 'index');
    }

    function testBlockAccessRotaAsUserIdLibera()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertLibera('GET', 'auth', 'asUserId');
    }

    function testBlockAccessImpersonacaoAtivaLibera()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $_SESSION['auth.asUserId'] = 999;
        $this->assertLibera('GET', 'panel', 'index');
    }

    function testBlockAccessAdminLibera()
    {
        $user = $this->userDirector->createUser([Role::ADMIN]);
        $this->login($user);
        $this->assertLibera('GET', 'panel', 'index');
    }

    function testBlockAccessRotaLgpdLibera()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertLibera('GET', 'lgpd', 'index');
    }

    function testBlockAccessTermosECondicoesLibera()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertLibera('GET', 'site', 'termos', false, '/termos-e-condicoes/');
    }

    function testBlockAccessAllowlistAldirblancLibera()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertLibera('POST', 'aldirblanc', 'startSync');
    }

    function testBlockAccessSyncNaoIniciadoRedirecionaParaConsolidatingDataESalvaUri()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertRedirectPara('consolidatingData', 'GET', 'panel', 'index');
        $this->assertNotEmpty($_SESSION['federative_entity_redirect_uri'] ?? null);
    }

    function testBlockAccessSyncNaoIniciadoAjaxNaoSalvaUriMasRedirecionaIgual()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertRedirectPara('consolidatingData', 'GET', 'panel', 'index', true);
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
    }

    function testBlockAccessSyncComErroNaoAjaxRedirecionaParaConsolidatingData()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';

        $this->assertRedirectPara('consolidatingData', 'GET', 'panel', 'index');
    }

    function testBlockAccessSyncEmAndamentoRedirecionaParaConsolidatingData()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;

        $this->assertRedirectPara('consolidatingData', 'GET', 'panel', 'index');
    }

    function testBlockAccessPerfilIncompletoNaoAjaxRedirecionaParaCompleteProfile()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
        // perfil fica incompleto de propósito

        $this->assertRedirectPara('completeProfile', 'GET', 'panel', 'index');
    }

    function testBlockAccessGestorSemEnteRedirecionaParaSelectFederativeEntity()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;

        $this->assertRedirectPara('selectFederativeEntity', 'GET', 'panel', 'index');
    }

    function testBlockAccessTudoOkLibera()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);
        $entity = $this->persistFederativeEntity('11111111111111', 'Ente Um');
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;

        $this->assertLibera('GET', 'panel', 'index');
    }

    /**
     * Confirma a ordem real dos gates de blockAccessOnError — perfil
     * incompleto bloqueia ANTES do gate de "gestor sem ente", mesmo quando ambas condições
     * seriam verdadeiras (gestor, sem ente selecionado, E perfil incompleto).
     */
    function testBlockAccessOrdemDosGatesPerfilIncompletoTemPrioridadeSobreGestorSemEnte()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        // perfil incompleto de propósito, gestor sem ente selecionado também
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;

        $this->assertRedirectPara('completeProfile', 'GET', 'panel', 'index');
    }
}
