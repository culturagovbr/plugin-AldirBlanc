<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Integration\FetchesParSituation;

class CountingParSituationProvider extends InMemoryProvider implements FetchesParSituation
{
    public static int $chamadas = 0;

    public function fetchParSituation(string $cnpj): array
    {
        self::$chamadas++;

        return [];
    }
}
