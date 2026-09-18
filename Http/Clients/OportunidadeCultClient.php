<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\OpportunitySender;

class OportunidadeCultClient extends AbstractClient implements OpportunitySender
{
    protected const PROVIDER = 'gestao';

    /** @var string id da oportunidade (usado como {id} no endpoint de update no AbstractClient) */
    protected string $document;

    private const PARAMETER_DEFAULT = '{id}';

    public function __construct(OpportunityId $opportunityId, ?Transport $transport = null)
    {
        $this->endpoint = '';
        $this->document = (string) $opportunityId->id;
        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    public function update(OpportunityDto $payload)
    {
        $this->endpoint = $this->requiredEndpoint('oportunidadeEndpoint');
        return $this->put($payload->toArray());
    }
}