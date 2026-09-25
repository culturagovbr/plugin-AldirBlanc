<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;

/** O que um provedor precisa de um client de envio: mandar a oportunidade e contar o que houve. */
interface OpportunitySender
{
    public function setExchangeRecorder(?callable $recorder): void;

    /** Envia a oportunidade; devolve a resposta já interpretada. */
    public function update(OpportunityDto $payload);
}
