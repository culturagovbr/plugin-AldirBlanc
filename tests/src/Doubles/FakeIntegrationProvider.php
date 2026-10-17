<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Integration\IntegrationProvider;
use AldirBlanc\Integration\SendsResultado;

/**
 * Provedor resolvido por nome de classe em `provider`: o envio devolve (ou lança) o que o teste
 * mandar, sem client e sem recorder. O resolver instancia sem argumentos, daí o estado estático.
 */
class FakeIntegrationProvider implements IntegrationProvider, SendsResultado
{
    /** @var null|callable(OpportunityId, OpportunityDto): SendOutcome */
    public static $aoEnviar = null;

    /** @var null|callable(ParEditalId, Resultado): SendOutcome */
    public static $aoEnviarResultado = null;

    /** @var list<int> ids recebidos, na ordem */
    public static array $enviados = [];

    /** @var list<array{edital: int, payload: Resultado}> envios de resultado, na ordem */
    public static array $resultadosEnviados = [];

    public static function reset(): void
    {
        self::$aoEnviar = null;
        self::$aoEnviarResultado = null;
        self::$enviados = [];
        self::$resultadosEnviados = [];
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

    public function sendOpportunity(
        OpportunityId $id,
        OpportunityDto $payload,
        SendAction $action = SendAction::Update,
    ): SendOutcome {
        self::$enviados[] = (int) $id->id;

        return (self::$aoEnviar)($id, $payload);
    }

    public function sendResultado(ParEditalId $edital, Resultado $payload): SendOutcome
    {
        self::$resultadosEnviados[] = ['edital' => (int) $edital->id, 'payload' => $payload];

        return (self::$aoEnviarResultado)($edital, $payload);
    }
}
