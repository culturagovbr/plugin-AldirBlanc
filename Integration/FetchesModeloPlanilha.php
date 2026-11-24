<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParEditalId;

interface FetchesModeloPlanilha
{
    /** Modelo de planilha do resultado do edital, como a origem o descreve. */
    public function fetchModeloPlanilha(ParEditalId $edital): array;
}
