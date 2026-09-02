<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Services\OpportunityService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * OpportunityService::findFederativeEntityIdsWithOpportunities — quais entes o gestor já usou.
 *
 * É o dado que decide se um ente sem dados do PAR continua listado na tela de seleção.
 */
class OpportunityServiceFederativeEntityIdsTest extends TestCase
{
    use UserDirector;

    private OpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpportunityService();
    }

    private function subsite(User $owner): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Pnab ' . uniqid();
        $subsite->url = 'subsite-pnab-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function federativeEntity(string $document, string $name): FederativeEntity
    {
        $this->app->disableAccessControl();
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $this->app->enableAccessControl();
        return $entity;
    }

    private function opportunity(
        User $user,
        ?Subsite $subsite,
        ?FederativeEntity $entity,
        int $status = Opportunity::STATUS_ENABLED,
        ?Opportunity $parent = null,
    ): Opportunity {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;
        $opportunity->name = 'Oportunidade ' . uniqid();
        $opportunity->shortDescription = 'Oportunidade de teste';
        $opportunity->subsite = $subsite;
        $opportunity->status = $status;
        if ($parent) {
            $opportunity->parent = $parent;
        }
        $opportunity->save(true);
        if ($entity) {
            $opportunity->setMetadata('federativeEntityId', (string) $entity->id);
            $opportunity->save(true);
        }
        $this->app->enableAccessControl();
        return $opportunity;
    }

    private function idsFor(User $user, Subsite $subsite): array
    {
        return $this->service->findFederativeEntityIdsWithOpportunities($user->profile, $subsite->id);
    }

    function testSemOportunidadeRetornaVazio()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeRaizContaOEnte()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('11111111111111', 'Ente Um');
        $this->opportunity($user, $subsite, $entity);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }

    function testEntesRepetidosAparecemUmaVezSo()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('88888888888888', 'Ente Oito');
        $this->opportunity($user, $subsite, $entity);
        $this->opportunity($user, $subsite, $entity);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }
}
