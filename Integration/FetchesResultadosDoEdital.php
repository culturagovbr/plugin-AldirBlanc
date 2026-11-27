<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParEditalId;

/** O que o edital já tem publicado; leitura que não dá certeza segue como erro. */
interface FetchesResultadosDoEdital
{
    public function fetchIdPublicado(ParEditalId $edital): ?int;

    /** @return list<int> ids de todos os resultados publicados */
    public function fetchIdsPublicados(ParEditalId $edital): array;
}
