<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Exceptions\IntegrationError;

/** As operações da integração, sem que o consumidor saiba qual API as atende. */
interface IntegrationProvider
{
    public function provider(): Provider;

    /**
     * Dados do gestor e os entes federados a que ele responde.
     * @return ?ManagerSnapshot null quando o documento não existe na origem
     * @throws IntegrationError
     */
    public function fetchManager(GestorDocument $document): ?ManagerSnapshot;

    /**
     * Uma página do catálogo de ações do PAR.
     * @throws IntegrationError
     */
    public function listParActions(int $skip, int $limit): ParActionPage;

    /**
     * Envia a oportunidade e devolve o que aconteceu, para o log de integração.
     * @throws IntegrationError
     */
    public function sendOpportunity(OpportunityId $id, OpportunityDto $payload): SendOutcome;
}
