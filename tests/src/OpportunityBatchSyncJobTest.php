<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Jobs\OportunidadeCultJob;
use AldirBlanc\Jobs\OpportunityBatchSyncJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

/**
 * Testes do OpportunityBatchSyncJob e do trigger em POST_startSync.
 *
 * Cobre: enfileiramento do batch job após sync bem-sucedido, ausência do job
 * em caso de falha no sync, execução do job (enfileira OportunidadeCultJob
 * update por oportunidade elegível) e não-enfileiramento de inelegíveis.
 */
class OpportunityBatchSyncJobTest extends TestCase
{
    use UserDirector;

    private function createSubsite(User $user): Subsite
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Batch Sync Test Subsite';
        $subsite->url = 'batch-sync-' . uniqid();
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
        $opp->name = 'Batch Sync Opp';
        $opp->shortDescription = 'desc';
        $opp->status = $overrides['status'] ?? Opportunity::STATUS_ENABLED;
        $opp->subsite = $subsite;
        $opp->save(true);

        $opp->setMetadata('isGeneratedFromModel', '1');
        $opp->save(true);

        $this->app->enableAccessControl();
        return $opp;
    }

    private function findBatchSyncJob(int $agentId, int $subsiteId): ?Job
    {
        $internalId = "opportunity-batch-sync:{$agentId}:{$subsiteId}";
        $hashedId = md5("opportunity-batch-sync:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    private function findUpdateJob(int $opportunityId): ?Job
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    // ===== Testes do trigger em POST_startSync =====

    function testStartSyncBemSucedidoEnfileiraOpportunityBatchSyncJob()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $subsite = $this->createSubsite($user);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $ctrl = new TestableController();
        $ctrl->setSyncCallback(fn() => true);

        $this->app->response = new Response();
        try {
            $ctrl->callStartSync();
        } catch (\MapasCulturais\Exceptions\Halt) {
        }

        $this->assertNotNull(
            $this->findBatchSyncJob($user->profile->id, $subsite->id),
            'OpportunityBatchSyncJob deve ser enfileirado após sync bem-sucedido'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    function testStartSyncFalhoNaoEnfileiraOpportunityBatchSyncJob()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $subsite = $this->createSubsite($user);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $ctrl = new TestableController();
        $ctrl->setSyncCallback(fn() => false);

        $this->app->response = new Response();
        try {
            $ctrl->callStartSync();
        } catch (\MapasCulturais\Exceptions\Halt) {
        }

        $this->assertNull(
            $this->findBatchSyncJob($user->profile->id, $subsite->id),
            'OpportunityBatchSyncJob não deve ser enfileirado após sync com falha'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    // ===== Testes de execução do OpportunityBatchSyncJob =====

    function testBatchSyncJobEnfileiraUpdateJobParaCadaOportunidadeElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);

        $opp1 = $this->createOpportunity($owner, $subsite);
        $opp2 = $this->createOpportunity($owner, $subsite);

        $this->app->enqueueOrReplaceJob(OpportunityBatchSyncJob::SLUG, [
            'agentId'   => $owner->profile->id,
            'subsiteId' => $subsite->id,
        ]);

        $this->processJobs(number_of_jobs: 1);

        $this->assertNotNull($this->findUpdateJob($opp1->id), 'Deve enfileirar update job para opp1');
        $this->assertNotNull($this->findUpdateJob($opp2->id), 'Deve enfileirar update job para opp2');
    }

    function testBatchSyncJobNaoEnfileiraUpdateJobParaOportunidadeInelegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->createSubsite($owner);

        $inelegivel = $this->createOpportunity($owner, $subsite, ['status' => Opportunity::STATUS_DRAFT]);

        $this->app->enqueueOrReplaceJob(OpportunityBatchSyncJob::SLUG, [
            'agentId'   => $owner->profile->id,
            'subsiteId' => $subsite->id,
        ]);

        $this->processJobs(number_of_jobs: 1);

        $this->assertNull(
            $this->findUpdateJob($inelegivel->id),
            'Não deve enfileirar update job para oportunidade inelegível (DRAFT)'
        );
    }
}
