<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Integration\IntegrationProvider;

/**
 * Provedor resolvido por nome de classe em `provider`: o envio devolve (ou lança) o que o teste
 * mandar, sem client e sem recorder. O resolver instancia sem argumentos, daí o estado estático.
 */
class FakeIntegrationProvider implements IntegrationProvider
{
    /** @var null|callable(OpportunityId, OpportunityDto): SendOutcome */
    public static $aoEnviar = null;

    /** @var list<int> ids recebidos, na ordem */
    public static array $enviados = [];

    public static function reset(): void
    {
        self::$aoEnviar = null;
        self::$enviados = [];
    }

    public function provider(): Provider
    {
        return Provider::Conecta;
    }

    public function fetchManager(GestorDocument $document): ?ManagerSnapshot
    {
        throw new \LogicException('dublê só cobre o envio');
    }

    public function listParActions(int $skip, int $limit): ParActionPage
    {
        throw new \LogicException('dublê só cobre o envio');
    }

    public function sendOpportunity(OpportunityId $id, OpportunityDto $payload): SendOutcome
    {
        self::$enviados[] = (int) $id->id;

        return (self::$aoEnviar)($id, $payload);
    }
}
