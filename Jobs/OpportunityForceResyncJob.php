<?php

namespace AldirBlanc\Jobs;

use AldirBlanc\Services\OpportunityService;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;

/**
 * Reenvia ao CultBR as oportunidades escolhidas na tela de sincronização, enfileirando o mesmo
 * job que o save dispara. Nenhuma falha isolada pode escapar: App::executeJob marca o job como
 * processando antes de rodar e só busca os que estão esperando, então um job que falha fica preso.
 */
class OpportunityForceResyncJob extends JobType
{
    const SLUG = 'opportunity-force-resync';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        $ids = $this->normalizeIds($data['opportunityIds'] ?? []);
        sort($ids);

        return sprintf('%s:%d:%s', self::SLUG, (int) App::i()->user->id, implode(',', $ids));
    }

    public function _execute(Job $job)
    {
        $app = App::i();
        $ids = $this->normalizeIds($job->opportunityIds ?? []);
        $service = $this->opportunityService();

        try {
            $opportunities = $service->findOpportunitiesForEligibilityCheck($ids);
        } catch (\Throwable $e) {
            $app->log->error(sprintf('%s: falha ao carregar o lote — %s', self::SLUG, $e->getMessage()));

            return true;
        }

        $enqueued = 0;
        foreach ($opportunities as $opportunity) {
            try {
                if ($service->isEligibleForSync($opportunity)) {
                    $this->enqueueOpportunitySync($opportunity);
                    $enqueued++;
                }
            } catch (\Throwable $e) {
                $app->log->error(sprintf('%s: oportunidade %d não enfileirada — %s', self::SLUG, $opportunity->id, $e->getMessage()));
            }
        }

        $app->log->info(sprintf(
            '%s: %d ids recebidos, %d enfileiradas, %d descartadas',
            self::SLUG,
            count($ids),
            $enqueued,
            count($ids) - $enqueued
        ));

        return true;
    }

    protected function opportunityService(): OpportunityService
    {
        return new OpportunityService();
    }

    protected function enqueueOpportunitySync(Opportunity $opportunity): void
    {
        App::i()->enqueueOrReplaceJob(
            OportunidadeCultJob::SLUG,
            ['opportunity' => $opportunity, 'action' => 'update'],
            'now',
        );
    }

    /** @return int[] */
    private function normalizeIds(mixed $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $ids), fn($id) => $id > 0)));
    }
}
