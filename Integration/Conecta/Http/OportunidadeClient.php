<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\OpportunitySender;

class OportunidadeClient extends ConectaClient implements OpportunitySender
{
    private const PARAMETER_DEFAULT = '{id}';

    protected string $document;

    public function __construct(OpportunityId $opportunityId, ?Transport $transport = null)
    {
        $this->endpoint = '';
        $this->document = (string) $opportunityId->id;

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    public function update(OpportunityDto $payload)
    {
        $this->endpoint = $this->requiredConfig(
            $this->getClientConfig(),
            'oportunidadeEndpoint',
            $this->envName('OPORTUNIDADE_ENDPOINT'),
        );

        return $this->put($payload->toArray());
    }
}
