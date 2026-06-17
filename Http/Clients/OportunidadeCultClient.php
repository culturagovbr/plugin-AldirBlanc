<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;

class OportunidadeCultClient extends AbstractClient
{
    /** @var string id da oportunidade (usado como {id} no endpoint de update no AbstractClient) */
    protected string $document;

    private const PARAMETER_DEFAULT = '{id}';

    public function __construct(OpportunityId $opportunityId)
    {
        $this->endpoint = '';
        $this->document = (string) $opportunityId->id;
        parent::__construct();

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    public function create(OpportunityDto $payload)
    {
        $endpoint = $this->getClientConfig()['createOportunidadeEndpoint'] ?? null;
        if (empty($endpoint)) {
            throw new \RuntimeException('PNAB_CULTBR_CREATE_OPORTUNIDADE_ENDPOINT não configurado.');
        }
        $this->endpoint = $endpoint;
        return $this->post($payload->toArray());
    }

    public function update(OpportunityDto $payload)
    {
        $endpoint = $this->getClientConfig()['updateOportunidadeEndpoint'] ?? null;
        if (empty($endpoint)) {
            throw new \RuntimeException('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT não configurado.');
        }
        $this->endpoint = $endpoint;
        return $this->put($payload->toArray());
    }
}