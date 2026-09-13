<?php

namespace AldirBlanc\Integration\Gestao;

use AldirBlanc\Dtos\FederativeEntitySnapshot;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Http\Clients\OportunidadeCultClient;
use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\IntegrationProvider;

/** A API de Gestão por trás do contrato, sobre os clients que já existem. */
final class GestaoProvider implements IntegrationProvider
{
    /** Chave na resposta da API => campo do snapshot. */
    private const MANAGER_FIELDS = [
        'nome' => 'name',
        'rg' => 'rg',
        'cep' => 'cep',
        'celular' => 'cellphone',
        'numero' => 'number',
        'complemento' => 'complement',
    ];

    private const RESULT_BY_LOG_STATUS = [
        CultBrRequestLogAttempt::RESULT_SUCCESS => SendResult::Success,
        CultBrRequestLogAttempt::RESULT_SIMULATED => SendResult::Simulated,
        CultBrRequestLogAttempt::RESULT_ERROR => SendResult::Error,
    ];

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

        if (!is_array($resposta)) {
            throw IntegrationError::contract('resposta do gestor não é um objeto');
        }

        $campos = [];

        foreach (self::MANAGER_FIELDS as $naApi => $noContrato) {
            if (array_key_exists($naApi, $resposta)) {
                $campos[$noContrato] = $resposta[$naApi] === null ? null : (string) $resposta[$naApi];
            }
        }

        return new ManagerSnapshot($campos, $this->entities($resposta));
    }

    public function listParActions(int $skip, int $limit): ParActionPage
    {
        $resposta = (new ParAcaoClient($skip, $limit, $this->transport))->get();

        if (!is_array($resposta) || !array_key_exists('data', $resposta) || !is_array($resposta['data'])) {
            throw IntegrationError::contract('catálogo do PAR sem a chave data');
        }

        $paginacao = is_array($resposta['pagination'] ?? null) ? $resposta['pagination'] : [];
        $itens = array_values(array_map(
            fn(array $acao) => ParAction::fromArray($acao),
            array_filter($resposta['data'], 'is_array'),
        ));

        return new ParActionPage(
            items: $itens,
            skip: (int) ($paginacao['skip'] ?? $skip),
            limit: (int) ($paginacao['limit'] ?? $limit),
            total: (int) ($paginacao['total'] ?? count($itens)),
        );
    }

    public function sendOpportunity(OpportunityId $id, OpportunityDto $payload): SendOutcome
    {
        $client = new OportunidadeCultClient($id, $this->transport);
        $trocado = null;
        $client->setExchangeRecorder(function (array $exchange) use (&$trocado) {
            $trocado = $exchange;
        });

        $client->update($payload);

        if ($trocado === null) {
            throw IntegrationError::contract('envio não registrou o que aconteceu');
        }

        return $this->outcome($trocado);
    }

    /** Os dois formatos de retorno da Gestão: envelope com a chave, ou a lista nua. */
    private function entities(array $resposta): array
    {
        if (array_key_exists('entes_federados', $resposta)) {
            if (!is_array($resposta['entes_federados'])) {
                throw IntegrationError::contract('entes_federados deve ser array');
            }

            $lista = $resposta['entes_federados'];
        } elseif (array_is_list($resposta)) {
            $lista = $resposta;
        } else {
            throw IntegrationError::contract('chave entes_federados ausente');
        }

        return array_values(array_map(
            fn(array $ente) => new FederativeEntitySnapshot(
                name: (string) ($ente['name'] ?? ''),
                document: (string) ($ente['document'] ?? ''),
                // A API escreve "exercicios"; a coluna se chama "exercices", e a troca é da fronteira.
                exercices: is_array($ente['exercicios'] ?? null) ? $ente['exercicios'] : [],
            ),
            array_filter($lista, 'is_array'),
        ));
    }

    private function outcome(array $exchange): SendOutcome
    {
        $status = (string) ($exchange['status'] ?? '');

        return new SendOutcome(
            provider: $this->provider(),
            result: self::RESULT_BY_LOG_STATUS[$status] ?? SendResult::Error,
            method: (string) ($exchange['method'] ?? 'PUT'),
            endpoint: (string) ($exchange['endpoint'] ?? ''),
            payload: is_array($exchange['payload'] ?? null) ? $exchange['payload'] : [],
            sentAt: $exchange['sentAt'] ?? new \DateTimeImmutable(),
            durationMs: (int) ($exchange['durationMs'] ?? 0),
            response: is_string($exchange['response'] ?? null) ? $exchange['response'] : null,
            responseHeaders: is_array($exchange['responseHeaders'] ?? null) ? $exchange['responseHeaders'] : null,
            httpStatus: isset($exchange['httpStatus']) ? (int) $exchange['httpStatus'] : null,
        );
    }
}
