<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\AssertsHooks;
use Tests\Traits\UserDirector;

/**
 * Tier A6: GET_federativeEntities, POST_selectFederativeEntity, GET_selectFederativeEntity,
 * GET_changeFederativeEntity, GET_parExercicios. [DB] [HOOK]
 */
class ControllerFederativeEntityTest extends TestCase
{
    use UserDirector;
    use AssertsHooks;

    protected function setUp(): void
    {
        parent::setUp();
        // $_SESSION não é limpa pelo rollback de transação (não é estado de banco) — sem isso,
        // uma seleção feita num teste vaza pro próximo dentro do mesmo processo PHPUnit.
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);
    }

    private function controller(): Controller
    {
        return new Controller();
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

    private function callRedirect(callable $callback): Response
    {
        $this->app->response = new Response();

        try {
            $callback();
            $this->fail('Esperava redirect via Halt');
        } catch (Halt) {
        }

        return $this->app->response;
    }

    private function persistFederativeEntity(string $document, string $name, array $exercices = []): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = $exercices;
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        return $entity;
    }

    private function persistRelation($agent, FederativeEntity $entity): FederativeEntityAgentRelation
    {
        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
        return $relation;
    }

    private function selectEntityInSession(FederativeEntity $entity): void
    {
        $_SESSION['selectedFederativeEntity'] = json_encode([
            'id' => $entity->id,
            'name' => $entity->name,
            'document' => $entity->document,
        ]);
    }

    // ===== GET_federativeEntities =====

    function testFederativeEntitiesGestorComRelacoesListaCorretamente()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('11111111111111', 'Ente Um');
        $this->persistRelation($user->profile, $entity);

        $payload = $this->callJson(fn() => $this->controller()->GET_federativeEntities());

        $this->assertSame([[
            'id' => $entity->id,
            'name' => 'Ente Um',
            'document' => '11111111111111',
        ]], $payload);
    }

    function testFederativeEntitiesGestorSemRelacoesRetornaVazio()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $payload = $this->callJson(fn() => $this->controller()->GET_federativeEntities());

        $this->assertSame([], $payload);
    }

    function testFederativeEntitiesNaoGestorRetornaVazioComoJson()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $payload = $this->callJson(fn() => $this->controller()->GET_federativeEntities());

        $this->assertSame([], $payload);
    }

    private function createUserWithoutProfile(): \MapasCulturais\Entities\User
    {
        $this->app->disableAccessControl();

        $user = new \MapasCulturais\Entities\User();
        $user->setAuthProvider('test');
        $user->authUid = uniqid('test-no-profile-');
        $user->email = uniqid('no-profile-') . '@example.com';
        $user->save(true);
        $user->addRole(Role::GESTOR_CULT_BR);

        $this->app->enableAccessControl();

        return $user;
    }

    function testFederativeEntitiesSemAgenteRetornaVazioComoJson()
    {
        $user = $this->createUserWithoutProfile();
        $this->login($user);

        $payload = $this->callJson(fn() => $this->controller()->GET_federativeEntities());

        $this->assertSame([], $payload);
    }

    // ===== POST_selectFederativeEntity =====

    function testSelectFederativeEntitySemEntityIdRetorna400()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $controller = $this->controller();
        $controller->data = [];

        $this->app->response = new Response();
        try {
            $controller->POST_selectFederativeEntity();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(400, $this->app->response->getStatusCode());
        $body = json_decode((string) $this->app->response->getBody(), true);
        $this->assertTrue($body['error']);
    }

    function testSelectFederativeEntityIdNaoNumericoRetorna400()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $controller = $this->controller();
        $controller->data = ['entityId' => 'abc'];

        $this->app->response = new Response();
        try {
            $controller->POST_selectFederativeEntity();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(400, $this->app->response->getStatusCode());
    }

    function testSelectFederativeEntityNaoGestorRetorna403()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $entity = $this->persistFederativeEntity('22222222222222', 'Ente Dois');

        $controller = $this->controller();
        $controller->data = ['entityId' => $entity->id];

        $this->app->response = new Response();
        try {
            $controller->POST_selectFederativeEntity();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(403, $this->app->response->getStatusCode());
        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
    }

    function testSelectFederativeEntityGestorSemRelacaoRetorna403()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('33333333333333', 'Ente Três');
        // NÃO cria relation -> sem vínculo

        $controller = $this->controller();
        $controller->data = [
            'entityId' => $entity->id,
            'entityName' => 'Forjado',
            'entityDocument' => '00000000000000',
        ];

        $this->app->response = new Response();
        try {
            $controller->POST_selectFederativeEntity();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(403, $this->app->response->getStatusCode());
        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
    }

    function testSelectFederativeEntityGestorComRelacaoGravaDadosReaisDoBanco()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('44444444444444', 'Nome Real');
        $this->persistRelation($user->profile, $entity);

        $payload = $this->callJson(function () use ($entity) {
            $controller = $this->controller();
            $controller->data = [
                'entityId' => $entity->id,
                'entityName' => 'Nome Forjado',
                'entityDocument' => '99999999999999',
            ];
            $controller->POST_selectFederativeEntity();
        });

        $this->assertTrue($payload['success']);
        $this->assertSame($entity->id, $payload['entityId']);

        $session = json_decode($_SESSION['selectedFederativeEntity'], true);
        $this->assertSame('Nome Real', $session['name'], 'Deve usar o nome do banco, não o forjado pelo client');
        $this->assertSame('44444444444444', $session['document'], 'Deve usar o documento do banco, não o forjado pelo client');
    }

    function testSelectFederativeEntityLimpaRedirectUriEDevolveNaResposta()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('55555555555555', 'Ente Cinco');
        $this->persistRelation($user->profile, $entity);
        $_SESSION['federative_entity_redirect_uri'] = '/painel/algumacoisa';

        $payload = $this->callJson(function () use ($entity) {
            $controller = $this->controller();
            $controller->data = ['entityId' => $entity->id];
            $controller->POST_selectFederativeEntity();
        });

        $this->assertSame('/painel/algumacoisa', $payload['redirectUri']);
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
    }

    function testSelectFederativeEntityDisparaHookAfter()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('66666666666666', 'Ente Seis');
        $this->persistRelation($user->profile, $entity);

        $this->assertHookFired('aldirblanc.selectFederativeEntity:after', function () use ($entity) {
            $this->callJson(function () use ($entity) {
                $controller = $this->controller();
                $controller->data = ['entityId' => $entity->id];
                $controller->POST_selectFederativeEntity();
            });
        });
    }

    // ===== GET_selectFederativeEntity =====

    function testGetSelectFederativeEntitySemSelecaoNaoRedireciona()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->app->response = new Response();

        try {
            $this->controller()->GET_selectFederativeEntity();
        } catch (Halt $e) {
            $this->fail('não deveria redirecionar (Halt) sem seleção');
        } catch (\Throwable $e) {
            // Ambiente de teste não monta o pipeline completo de assets/templates do tema;
            // o que importa aqui é confirmar que NÃO houve redirect antes de chegar no render().
        }

        $this->assertSame('', $this->app->response->getHeaderLine('Location'));
    }

    function testGetSelectFederativeEntityComSelecaoERedirectUriRedirecionaERemoveFlag()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('77777777777777', 'Ente Sete');
        $this->selectEntityInSession($entity);
        $_SESSION['federative_entity_redirect_uri'] = '/destino-original';

        $response = $this->callRedirect(fn() => $this->controller()->GET_selectFederativeEntity());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/destino-original', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
    }

    function testGetSelectFederativeEntityComSelecaoSemRedirectUriVaiParaPainel()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('88888888888888', 'Ente Oito');
        $this->selectEntityInSession($entity);

        $response = $this->callRedirect(fn() => $this->controller()->GET_selectFederativeEntity());

        $this->assertStringContainsString('painel', $response->getHeaderLine('Location'));
    }

    // ===== GET_changeFederativeEntity =====

    function testChangeFederativeEntityLimpaSelecaoERedirecionaParaSelecao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('10101010101010', 'Ente Dez');
        $this->selectEntityInSession($entity);
        $_SESSION['federative_entity_redirect_uri'] = '/onde-eu-estava';

        $response = $this->callRedirect(fn() => $this->controller()->GET_changeFederativeEntity());

        $this->assertArrayNotHasKey('selectedFederativeEntity', $_SESSION);
        $this->assertArrayNotHasKey('federative_entity_redirect_uri', $_SESSION);
        $this->assertStringContainsString('selectFederativeEntity', $response->getHeaderLine('Location'));
    }

    function testChangeFederativeEntitySemSelecaoPreviaNaoQuebra()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $response = $this->callRedirect(fn() => $this->controller()->GET_changeFederativeEntity());

        $this->assertSame(302, $response->getStatusCode());
    }

    // ===== GET_parExercicios =====

    function testParExerciciosNaoGestorRetornaListaVazia()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $payload = $this->callJson(fn() => $this->controller()->GET_parExercicios());

        $this->assertSame(['exercicios' => []], $payload);
    }

    function testParExerciciosGestorSemSelecaoRetornaIdNuloEListaVazia()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $payload = $this->callJson(fn() => $this->controller()->GET_parExercicios());

        $this->assertNull($payload['federativeEntityId']);
        $this->assertSame([], $payload['exercicios']);
    }

    function testParExerciciosGestorComSelecaoRetornaExerciciosReais()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $exercicios = [['id' => 1, 'ano' => 2025, 'metas' => []]];
        $entity = $this->persistFederativeEntity('12121212121212', 'Ente Doze', $exercicios);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $payload = $this->callJson(fn() => $this->controller()->GET_parExercicios());

        $this->assertSame($entity->id, $payload['federativeEntityId']);
        $this->assertSame($exercicios, $payload['exercicios']);
    }
}
