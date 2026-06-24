<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\AgentRelation;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * UserAccessService::canViewFederativeEntityTeam — usado por
 * API.find(agent).params (Theme.php) para validar ownership do federativeEntityId
 * consultado, em vez de confiar no valor vindo da query string.
 */
class UserAccessServiceTest extends TestCase
{
    use UserDirector;

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

    function testAdminPodeVerEquipeDeQualquerEnte()
    {
        $user = $this->userDirector->createUser([Role::ADMIN]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('11111111111111', 'Ente Qualquer');

        $this->assertTrue(UserAccessService::canViewFederativeEntityTeam($entity->id));
        $this->assertTrue(UserAccessService::canViewFederativeEntityTeam(999999), 'Admin pode ver mesmo ID inexistente (sem vazar dado, só não filtra)');
    }

    function testGestorComRelacaoPodeVerEquipeDoProprioEnte()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('22222222222222', 'Ente Próprio');
        $this->persistRelation($user->profile, $entity);

        $this->assertTrue(UserAccessService::canViewFederativeEntityTeam($entity->id));
    }

    function testGestorSemRelacaoNaoPodeVerEquipeDeOutroEnte()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('33333333333333', 'Ente De Outra Organização');
        // sem relation -> sem vínculo

        $this->assertFalse(UserAccessService::canViewFederativeEntityTeam($entity->id));
    }

    function testUsuarioComumNaoPodeVerEquipeDeNenhumEnte()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $entity = $this->persistFederativeEntity('44444444444444', 'Ente X');
        $this->persistRelation($user->profile, $entity);

        $this->assertFalse(UserAccessService::canViewFederativeEntityTeam($entity->id));
    }
}
