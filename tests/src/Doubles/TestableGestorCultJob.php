<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Jobs\GestorCultJob;
use MapasCulturais\Entities\Agent;

/**
 * Expõe os métodos protected de GestorCultJob como públicos, só para teste.
 * Não altera nenhum comportamento — apenas wrappers finos.
 */
class TestableGestorCultJob extends GestorCultJob
{
    private mixed $gestorResponse = null;
    private bool $hasGestorResponse = false;

    public function setGestorResponse(mixed $response): void
    {
        $this->gestorResponse = $response;
        $this->hasGestorResponse = true;
    }

    protected function fetchGestorData()
    {
        if ($this->hasGestorResponse) {
            return $this->gestorResponse;
        }

        return parent::fetchGestorData();
    }

    public function callExtractFederativeEntitiesFromResponse($response): array
    {
        return $this->extractFederativeEntitiesFromResponse($response);
    }

    public function callNormalizeFederativeEntities($federativeEntities): array
    {
        return $this->normalizeFederativeEntities($federativeEntities);
    }

    public function callNormalizeStringForComparison($value): string
    {
        return $this->normalizeStringForComparison($value);
    }

    public function callAssociateFederativeEntities(Agent $agent, array $federativeEntities): void
    {
        $this->associateFederativeEntities($agent, $federativeEntities);
    }

    public function callUpdateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
    {
        $this->updateAgentFromGestorResponse($agent, $apiResponse);
    }
}
