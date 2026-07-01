<?php

namespace AldirBlanc\Jobs;

use AldirBlanc\Services\OpportunityService;
use MapasCulturais\App;
use MapasCulturais\Entities\Job;
use MapasCulturais\Definitions\JobType;

class OpportunityBatchSyncJob extends JobType
{
    const SLUG = 'opportunity-batch-sync';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return "opportunity-batch-sync:{$data['agentId']}:{$data['subsiteId']}";
    }

    public function _execute(Job $job)
    {
        $app = App::i();
        $agentId   = (int) $job->agentId;
        $subsiteId = (int) $job->subsiteId;

        $opportunities = (new OpportunityService())->findEligibleOpportunitiesForSync($agentId, $subsiteId);

        foreach ($opportunities as $opp) {
            $app->enqueueOrReplaceJob(
                OportunidadeCultJob::SLUG,
                ['opportunity' => $opp, 'action' => 'update'],
                'now',
            );
        }

        $app->log->info(sprintf(
            'OpportunityBatchSyncJob: agentId=%d subsiteId=%d → %d oportunidades enfileiradas',
            $agentId,
            $subsiteId,
            count($opportunities)
        ));

        return true;
    }
}
