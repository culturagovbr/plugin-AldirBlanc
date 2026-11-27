<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParEditalId;

interface FetchesResultadoPermitido
{
    /** A ação do edital recebe resultado? Null quando a origem não diz. */
    public function fetchResultadoPermitido(ParEditalId $edital): ?bool;
}
