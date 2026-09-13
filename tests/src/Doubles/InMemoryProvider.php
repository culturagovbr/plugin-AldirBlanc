<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Integration\IntegrationProvider;

/** Satisfaz o contrato sem rede: o que o contrato promete tem que ser implementável assim. */
class InMemoryProvider implements IntegrationProvider
{
    /** @param array<string, ManagerSnapshot> $managers indexado por documento */
    public function __construct(
        private array $managers = [],
        private array $actions = [],
    ) {
    }

    public function provider(): Provider
    {
        return Provider::Gestao;
    }

    public function fetchManager(GestorDocument $document): ?ManagerSnapshot
    {
        return $this->managers[$document->document] ?? null;
    }

    public function listParActions(int $skip, int $limit): ParActionPage
    {
        $itens = array_map(
            fn(array $dados) => ParAction::fromArray($dados),
            array_slice($this->actions, $skip, $limit),
        );

        return new ParActionPage(
            items: array_values($itens),
            skip: $skip,
            limit: $limit,
            total: count($this->actions),
        );
    }

    public function sendOpportunity(OpportunityId $id, OpportunityDto $payload): SendOutcome
    {
        return new SendOutcome(
            provider: $this->provider(),
            result: SendResult::Simulated,
            method: 'PUT',
            endpoint: "oportunidades/{$id->id}",
            payload: $payload->toArray(),
            sentAt: new \DateTimeImmutable('2026-09-13 12:00:00'),
            durationMs: 0,
        );
    }
}
