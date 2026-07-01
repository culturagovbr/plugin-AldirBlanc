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
 * OpportunityService::findOpportunitiesByFederativeEntity — filtros de elegibilidade.
 *
 * Cobre: retorno de elegíveis, array vazio sem elegíveis, exclusão por ente diferente,
 * subsite diferente, isGeneratedFromModel ausente, status != ENABLED, parent != NULL,
 * status = STATUS_PHASE.
 */
class OpportunityServiceFederativeEntityTest extends TestCase
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

    private function eligibleOpportunity(User $user, Subsite $subsite, FederativeEntity $entity, string $name = 'Oportunidade'): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opp = new $opportunityClassName();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = $name;
        $opp->shortDescription = $name;
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $opp->setMetadata('federativeEntityId', (string) $entity->id);
        $opp->setMetadata('isGeneratedFromModel', '1');
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    // ===== Retorno de elegíveis =====

    function testRetornaOportunidadeElegivel()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Um');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity);

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $ids = array_map(fn($o) => $o->id, $result);
        $this->assertContains($opp->id, $ids);
    }

    function testRetornaMultiplasOportunidadesElegiveis()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Multi');
        $opp1 = $this->eligibleOpportunity($user, $subsite, $entity, 'Oportunidade A');
        $opp2 = $this->eligibleOpportunity($user, $subsite, $entity, 'Oportunidade B');

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $ids = array_map(fn($o) => $o->id, $result);
        $this->assertContains($opp1->id, $ids);
        $this->assertContains($opp2->id, $ids);
    }

    function testRetornaArrayVazioQuandoNenhumaOportunidadeElegivel()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Vazio');

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $this->assertSame([], $result);
    }

    // ===== Exclusão de inelegíveis =====

    function testNaoRetornaOportunidadeDeOutroEnte()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity1 = $this->federativeEntity('11111111111111', 'Ente Alvo');
        $entity2 = $this->federativeEntity('22222222222222', 'Ente Outro');
        $this->eligibleOpportunity($user, $subsite, $entity2, 'Opp do Ente Dois');

        $result = $this->service->findOpportunitiesByFederativeEntity($entity1->document, $subsite->id);

        $this->assertSame([], $result);
    }

    function testNaoRetornaOportunidadeDeOutroSubsite()
    {
        $user = $this->userDirector->createUser();
        $subsite1 = $this->subsite($user);
        $subsite2 = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Subsite');
        $this->eligibleOpportunity($user, $subsite2, $entity);

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite1->id);

        $this->assertSame([], $result);
    }

    function testNaoRetornaOportunidadeSemIsGeneratedFromModel()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Sem Model');

        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opp = new $opportunityClassName();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Sem isGeneratedFromModel';
        $opp->shortDescription = 'Sem isGeneratedFromModel';
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $opp->setMetadata('federativeEntityId', (string) $entity->id);
        // isGeneratedFromModel deliberadamente ausente
        $opp->save(true);
        $this->app->enableAccessControl();

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $this->assertSame([], $result);
    }

    function testRetornaOportunidadeComStatusDraft()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Draft');

        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opp = new $opportunityClassName();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Rascunho';
        $opp->shortDescription = 'Rascunho';
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $opp->setMetadata('federativeEntityId', (string) $entity->id);
        $opp->setMetadata('isGeneratedFromModel', '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $this->assertCount(1, $result);
        $this->assertSame($opp->id, $result[0]->id);
    }

    function testNaoRetornaOportunidadeComStatusPhase()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Phase');

        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opp = new $opportunityClassName();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Status Phase';
        $opp->shortDescription = 'Status Phase';
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_PHASE;
        $opp->save(true);
        $opp->setMetadata('federativeEntityId', (string) $entity->id);
        $opp->setMetadata('isGeneratedFromModel', '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $this->assertSame([], $result);
    }

    function testNaoRetornaOportunidadeFilha()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Fase');

        $parent = $this->eligibleOpportunity($user, $subsite, $entity, 'Oportunidade Pai');

        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $child = new $opportunityClassName();
        $child->owner = $user->profile;
        $child->ownerEntity = $user->profile;
        $child->name = 'Fase';
        $child->shortDescription = 'Fase';
        $child->subsite = $subsite;
        $child->status = Opportunity::STATUS_ENABLED;
        $child->parent = $parent;
        $child->save(true);
        $child->setMetadata('federativeEntityId', (string) $entity->id);
        $child->setMetadata('isGeneratedFromModel', '1');
        $child->save(true);
        $this->app->enableAccessControl();

        $result = $this->service->findOpportunitiesByFederativeEntity($entity->document, $subsite->id);

        $ids = array_map(fn($o) => $o->id, $result);
        $this->assertNotContains($child->id, $ids);
        $this->assertContains($parent->id, $ids);
    }
}
