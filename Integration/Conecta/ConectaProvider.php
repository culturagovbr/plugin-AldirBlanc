<?php

namespace AldirBlanc\Integration\Conecta;

use AldirBlanc\Dtos\CredentialCheck;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Mode;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\Conecta\Http\EntesClient;
use AldirBlanc\Integration\Conecta\Http\OportunidadeClient;
use AldirBlanc\Integration\Conecta\Http\ParAcoesClient;
use AldirBlanc\Integration\Conecta\Http\ValidarTokenClient;
use AldirBlanc\Integration\IntegrationProvider;
use AldirBlanc\Integration\ManagerSnapshotMapper;
use AldirBlanc\Integration\ParActionPageMapper;
use AldirBlanc\Integration\RecordedSend;
use AldirBlanc\Integration\ValidatesCredential;
use AldirBlanc\Plugin;

/** A API Conecta MinC por trás do contrato. */
final class ConectaProvider implements IntegrationProvider, ValidatesCredential
{
    /** A Gestão usa 403 para credencial; a Conecta usa 401. Os dois significam recusa. */
    private const REFUSED_STATUSES = [401, 403];

    public function __construct(private readonly ?Transport $transport = null)
    {
    }

    public function provider(): Provider
    {
        return Provider::Conecta;
    }

    /**
     * A Gestão tem uma rota de caminho idêntico a esta que devolve a lista nua, só com nome e
     * documento. Aceitá-la entregaria ente sem árvore do PAR e zeraria a cascata em silêncio.
     */
    public function fetchManager(GestorDocument $document): ?ManagerSnapshot
    {
        $resposta = (new EntesClient($document, $this->transport))->get();

        return ManagerSnapshotMapper::fromResponse($resposta, acceptFlatList: false, documento: $document->document);
    }

    public function listParActions(int $skip, int $limit): ParActionPage
    {
        $resposta = (new ParAcoesClient($skip, $limit, $this->transport))->get();

        return ParActionPageMapper::fromResponse($resposta, $skip, $limit);
    }

    public function sendOpportunity(
        OpportunityId $id,
        OpportunityDto $payload,
        SendAction $action = SendAction::Update,
    ): SendOutcome {
        $client = new OportunidadeClient($id, $this->transport, $action);

        return RecordedSend::perform($client, $payload, $this->provider());
    }

    public function validateCredential(): CredentialCheck
    {
        // Sem o curto-circuito, cada processo do phpunit esperaria o connect timeout do host fictício.
        if ($this->isSimulated()) {
            return CredentialCheck::notVerified('modo simulado');
        }

        try {
            $resposta = (new ValidarTokenClient($this->transport))->get();
        } catch (IntegrationError $e) {
            // Só a API recusando a credencial é veredito sobre ela: timeout ou rota fora do ar
            // diriam "token inválido" para quem só não conseguiu perguntar.
            return in_array($e->httpStatus(), self::REFUSED_STATUSES, true)
                ? CredentialCheck::invalid($e->getMessage())
                : CredentialCheck::notVerified($e->getMessage());
        }

        if (!is_array($resposta) || !array_key_exists('valido', $resposta)) {
            return CredentialCheck::notVerified('resposta de validação sem a chave valido');
        }

        return $resposta['valido'] === true
            ? CredentialCheck::valid()
            : CredentialCheck::invalid('token recusado pela API');
    }

    private function isSimulated(): bool
    {
        return Plugin::modoDaIntegracao() === Mode::Development;
    }
}
