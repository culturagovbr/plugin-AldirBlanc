<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Jobs\GestorCultJob;

/**
 * Expõe os métodos protected de GestorCultJob como públicos, só para teste.
 * Não altera nenhum comportamento — apenas wrappers finos.
 */
class TestableGestorCultJob extends GestorCultJob
{
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
}
