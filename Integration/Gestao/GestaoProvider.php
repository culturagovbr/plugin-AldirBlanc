<?php

namespace AldirBlanc\Integration\Gestao;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Http\Clients\OportunidadeCultClient;
use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\IntegrationProvider;
use AldirBlanc\Integration\ManagerSnapshotMapper;
use AldirBlanc\Integration\ParActionPageMapper;
use AldirBlanc\Integration\RecordedSend;

/** A API de Gestão por trás do contrato, sobre os clients que já existem. */
final class GestaoProvider implements IntegrationProvider
{
    public function __construct(private readonly ?Transport $transport = null)
    {
    }

    public function provider(): Provider
    {
        return Provider::Gestao;
    }

    /**
     * A Gestão não distingue documento inexistente de gestor sem ente: resposta bem-formada
     * sempre vira snapshot, e o `null` do contrato fica para quem sabe fazer a distinção.
     */
    public function fetchManager(GestorDocument $document): ?ManagerSnapshot
    {
        $resposta = (new GestorClient($document, $this->transport))->get();

        // A lista nua é forma antiga e legítima da Gestão.
        return ManagerSnapshotMapper::fromResponse($resposta, acceptFlatList: true, documento: $document->document);
    }

    public function listParActions(int $skip, int $limit): ParActionPage
    {
        $resposta = (new ParAcaoClient($skip, $limit, $this->transport))->get();

        return ParActionPageMapper::fromResponse($resposta, $skip, $limit);
    }

    /** A ação não muda nada aqui: o PUT desta API cria o que não existe. */
    public function sendOpportunity(
        OpportunityId $id,
        OpportunityDto $payload,
        SendAction $action = SendAction::Update,
    ): SendOutcome {
        return RecordedSend::perform(new OportunidadeCultClient($id, $this->transport), $payload, $this->provider());
    }
}
