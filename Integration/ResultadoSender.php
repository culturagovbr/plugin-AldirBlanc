<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;

/** O que um provedor precisa de um client de resultado: criar o resultado no edital e contar o que houve. */
interface ResultadoSender
{
    public function setExchangeRecorder(?callable $recorder): void;

    /** Cria o resultado no edital do PAR; devolve a resposta já interpretada. */
    public function create(ParEditalId $edital, Resultado $payload): mixed;
}
