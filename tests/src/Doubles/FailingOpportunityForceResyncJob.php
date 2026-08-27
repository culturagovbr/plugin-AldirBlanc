<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Jobs\OpportunityForceResyncJob;
use AldirBlanc\Services\OpportunityService;
use MapasCulturais\Entities\Opportunity;

/** Faz falhar a carga do lote ou o envio de uma oportunidade escolhida, para exercitar o isolamento. */
class FailingOpportunityForceResyncJob extends OpportunityForceResyncJob
{
    public function __construct(
        string $slug,
        private int $failingOpportunityId = 0,
        private bool $failOnLoad = false
    ) {
        parent::__construct($slug);
    }

    protected function opportunityService(): OpportunityService
    {
        if (!$this->failOnLoad) {
            return parent::opportunityService();
        }

        return new class extends OpportunityService {
            public function findOpportunitiesForEligibilityCheck(array $ids): array
            {
                throw new \RuntimeException('falha simulada na carga do lote');
            }
        };
    }

    protected function enqueueOpportunitySync(Opportunity $opportunity): void
    {
        if ((int) $opportunity->id === $this->failingOpportunityId) {
            throw new \RuntimeException('falha simulada no enfileiramento');
        }

        parent::enqueueOpportunitySync($opportunity);
    }
}
