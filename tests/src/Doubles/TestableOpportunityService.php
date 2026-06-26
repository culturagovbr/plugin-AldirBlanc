<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Enum\MultiselectField;
use AldirBlanc\Services\OpportunityService;

/**
 * Expõe os métodos protected de OpportunityService como públicos, apenas para teste.
 * Não altera nenhum comportamento — apenas wrappers finos.
 */
class TestableOpportunityService extends OpportunityService
{
    public function publicNormalizeDecimalValue(mixed $value): ?string
    {
        return $this->normalizeDecimalValue($value);
    }

    public function publicNormalizeDateValue(mixed $value): ?string
    {
        return $this->normalizeDateValue($value);
    }

    public function publicMapRecursosOutrasFontes(mixed $raw): ?array
    {
        return $this->mapRecursosOutrasFontes($raw);
    }

    public function publicMapReservaVagasCotas(mixed $raw): ?array
    {
        return $this->mapReservaVagasCotas($raw);
    }

    public function publicMapTiposFormasInscricao(mixed $raw): ?array
    {
        return $this->mapTiposFormasInscricao($raw);
    }

    public function publicMapTiposProponentes(mixed $raw): ?array
    {
        return $this->mapTiposProponentes($raw);
    }

    public function publicMapOutrasModalidadesAcoesAfirmativas(mixed $raw): ?array
    {
        return $this->mapOutrasModalidadesAcoesAfirmativas($raw);
    }

    public function publicGetOpportunityStatus(mixed $opportunity): ?array
    {
        return $this->getOpportunityStatus($opportunity);
    }

    public function publicMapMultiselectToString(MultiselectField $field, mixed $value): ?string
    {
        return $this->mapMultiselectToString($field, $value);
    }

    public function publicGetEnteFederadoByOpportunity(mixed $opportunity): ?array
    {
        return $this->getEnteFederadoByOpportunity($opportunity);
    }
}
