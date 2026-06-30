<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\OpportunityService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de OpportunityService::findEligibleOpportunitiesForSync.
 *
 * Garante que apenas oportunidades que passam todos os guards de elegibilidade
 * são retornadas: ENABLED, raiz (sem parent), isGeneratedFromModel=1,
 * agente correto e subsite correto.
 */
class OpportunityServiceEligibleSyncTest extends TestCase
{
    use UserDirector;

    private function service(): OpportunityService
    {
        return new OpportunityService();
    }

    private function createSubsite(User $user): Subsite
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Sync Test';
        $subsite->url = 'sync-test-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function createOpportunity(User $owner, Subsite $subsite, array $overrides = []): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $opp = new ($owner->profile->opportunityClassName)();
        $opp->owner = $owner->profile;
        $opp->ownerEntity = $owner->profile;
        $opp->name = 'Sync Test Opp';
        $opp->shortDescription = 'desc';
        $opp->status = $overrides['status'] ?? Opportunity::STATUS_ENABLED;
        $opp->subsite = $overrides['subsite'] ?? $subsite;

        if (isset($overrides['parent'])) {
            $opp->parent = $overrides['parent'];
        }

        $opp->save(true);

        $isGeneratedFromModel = array_key_exists('isGeneratedFromModel', $overrides)
            ? $overrides['isGeneratedFromModel']
            : '1';

        if ($isGeneratedFromModel !== null) {
            $opp->setMetadata('isGeneratedFromModel', $isGeneratedFromModel);
            $opp->save(true);
        }

        $this->app->enableAccessControl();
        return $opp;
    }

    private function eligibleIds(User $owner, Subsite $subsite): array
    {
        return array_map(
            fn($opp) => $opp->id,
            $this->service()->findEligibleOpportunitiesForSync($owner->profile->id, $subsite->id)
        );
    }

    function testRetornaOportunidadeElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $opp = $this->createOpportunity($owner, $subsite);

        $this->assertContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testNaoRetornaOportunidadeComStatusDraft()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $opp = $this->createOpportunity($owner, $subsite, ['status' => Opportunity::STATUS_DRAFT]);

        $this->assertNotContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testNaoRetornaOportunidadeComSubsiteDiferente()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $outroSubsite = $this->createSubsite($owner);
        $opp = $this->createOpportunity($owner, $subsite, ['subsite' => $outroSubsite]);

        $this->assertNotContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testNaoRetornaOportunidadeSemIsGeneratedFromModel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $opp = $this->createOpportunity($owner, $subsite, ['isGeneratedFromModel' => null]);

        $this->assertNotContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testNaoRetornaOportunidadeComParent()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $parent = $this->createOpportunity($owner, $subsite);
        $opp = $this->createOpportunity($owner, $subsite, ['parent' => $parent]);

        $this->assertNotContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testNaoRetornaOportunidadeDeOutroAgente()
    {
        $owner = $this->userDirector->createUser();
        $outroOwner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $opp = $this->createOpportunity($outroOwner, $subsite);

        $this->assertNotContains($opp->id, $this->eligibleIds($owner, $subsite));
    }

    function testRetornaMultiplasOportunidadesElegiveis()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);
        $opp1 = $this->createOpportunity($owner, $subsite);
        $opp2 = $this->createOpportunity($owner, $subsite);

        $ids = $this->eligibleIds($owner, $subsite);
        $this->assertContains($opp1->id, $ids);
        $this->assertContains($opp2->id, $ids);
    }
}
