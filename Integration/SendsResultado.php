<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Dtos\SendOutcome;

/** Segregada no molde de ValidatesCredential: só quem sabe enviar resultado a implementa. */
interface SendsResultado
{
    public function sendResultado(ParEditalId $edital, Resultado $payload): SendOutcome;
}
