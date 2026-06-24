<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Jobs\GestorCultJob;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;
use Tests\Traits\UserDirector;

class GestorCultJobSyncErrorTest extends TestCase
{
    use UserDirector;

    private function jobWithResponse(mixed $response): TestableGestorCultJob
    {
        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorResponse($response);
        return $job;
    }

    private function grantGestorRole(): void
    {
        $this->app->disableAccessControl();
        $this->app->user->addRole(Role::GESTOR_CULT_BR);
        $this->app->enableAccessControl();
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [['id' => 1, 'ano' => 2025, 'metas' => []]];
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

    private function createUserWithoutProfile(): User
    {
        $this->app->disableAccessControl();

        $user = new User();
        $user->setAuthProvider('test');
        $user->authUid = uniqid('test-no-profile-');
        $user->email = uniqid('no-profile-') . '@example.com';
        $user->save(true);

        $this->app->enableAccessControl();

        return $user;
    }

    function testRespostaSemEntesRevogaRoleERemoveRelations()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->grantGestorRole();

        $entity = $this->persistFederativeEntity('77111111111111', 'Ente Removido');
        $this->persistRelation($user->profile, $entity);
        $this->assertTrue(UserAccessService::isGestorCultBr());

        $this->jobWithResponse([])->sync();
        $this->app->em->clear();

        $this->assertFalse(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }

    function testRespostaSemEntesSemRoleEhIdempotente()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertFalse(UserAccessService::isGestorCultBr());

        $this->jobWithResponse([])->sync();

        $this->assertFalse(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }

    function testErroAoBuscarDadosMarcaSessaoERelancaExcecao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorException(new \Exception('Timeout de conexão', 28));

        try {
            $job->sync();
            $this->fail('Esperava exceção do client');
        } catch (\Exception $e) {
            $this->assertSame('Timeout de conexão', $e->getMessage());
        }

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $_SESSION['gestor_cult_sync_error_message'] ?? null);
    }

    function testLockEhRemovidoAposSyncComSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $lockKey = "gestor_cult_sync_lock:{$user->id}:12345678901";

        $job = $this->jobWithResponse([]);

        $this->assertTrue($job->sync());
        $this->assertFalse($this->app->cache->contains($lockKey));
    }

    function testLockEhRemovidoAposErroRelancado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $lockKey = "gestor_cult_sync_lock:{$user->id}:12345678901";

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorException(new \RuntimeException('Falha no client'));

        try {
            $job->sync();
            $this->fail('Esperava exceção do client');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha no client', $exception->getMessage());
        }

        $this->assertFalse($this->app->cache->contains($lockKey));
    }

    function testErroAoAssociarDadosMarcaSessaoSemRelancarExcecao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Teste',
            'entes_federados' => [
                [
                    'document' => '77222222222222',
                    'name' => 'Ente Com Falha',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setAssociateException(new \RuntimeException('Falha controlada na associação'));

        $job->sync();
        $this->app->em->clear();

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $_SESSION['gestor_cult_sync_error_message'] ?? null);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77222222222222']));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testErroAoAtualizarAgenteNaoPersisteAssociacoesNemConcedeRole()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Com Falha',
            'entes_federados' => [
                [
                    'document' => '77233333333333',
                    'name' => 'Ente Sem Persistencia Parcial',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setUpdateAgentException(new \RuntimeException('Falha controlada no agente'));

        $job->sync();
        $this->app->em->clear();

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $_SESSION['gestor_cult_sync_error_message'] ?? null);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77233333333333']));
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testErroAoConcederRoleNaoPersisteAssociacoes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Sem Role',
            'entes_federados' => [
                [
                    'document' => '77244444444444',
                    'name' => 'Ente Sem Role',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setGrantRoleException(new \RuntimeException('Falha controlada na role'));

        $job->sync();
        $this->app->em->clear();

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
        $this->assertSame(GestorCultJob::API_UNAVAILABLE_MESSAGE, $_SESSION['gestor_cult_sync_error_message'] ?? null);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77244444444444']));
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testUsuarioSemProfileAbortaSyncSemErro()
    {
        $user = $this->createUserWithoutProfile();
        $this->login($user);

        $this->jobWithResponse([
            'entes_federados' => [
                [
                    'document' => '77333333333333',
                    'name' => 'Nao Deve Persistir',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ])->sync();

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77333333333333']));
    }

    function testErroDuranteAssociacaoFazRollbackDoConjuntoAnterior()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $previousEntity = $this->persistFederativeEntity('77444444444444', 'Ente Anterior');
        $this->persistRelation($user->profile, $previousEntity);

        $job = $this->jobWithResponse([
            'entes_federados' => [
                [
                    'document' => '77555555555555',
                    'name' => 'Novo Ente Antes Do Flush',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setBeforeFlushException(new \RuntimeException('Falha antes do flush'));
        $job->sync();

        $this->app->em->clear();

        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77444444444444']));
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77555555555555']));
        $this->assertCount(1, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }
}
