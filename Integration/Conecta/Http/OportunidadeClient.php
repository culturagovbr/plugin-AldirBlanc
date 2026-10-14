<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\OpportunitySender;

class OportunidadeClient extends AbstractClient implements OpportunitySender
{
    protected const PROVIDER = 'conecta';

    private const PARAMETER_DEFAULT = '{id}';

    private const NAO_ENCONTRADO = 404;

    protected string $document;

    private SendAction $action;

    private string $opportunityId;

    public function __construct(
        OpportunityId $opportunityId,
        ?Transport $transport = null,
        SendAction $action = SendAction::Update,
    ) {
        $this->endpoint = '';
        $this->document = (string) $opportunityId->id;
        $this->opportunityId = (string) $opportunityId->id;
        $this->action = $action;

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    protected function logIdentifier(): string
    {
        return $this->opportunityId;
    }

    public function update(OpportunityDto $payload)
    {
        if ($this->action === SendAction::Create) {
            return $this->create($payload);
        }

        $this->endpoint = $this->requiredEndpoint('oportunidadeEndpoint');

        try {
            return $this->put($payload->toArray());
        } catch (IntegrationError $e) {
            // O PUT daqui não cria: edital que a origem não tem volta 404, e só o POST resolve.
            if ($e->httpStatus() !== self::NAO_ENCONTRADO) {
                throw $e;
            }

            return $this->create($payload);
        }
    }

    private function create(OpportunityDto $payload)
    {
        $this->endpoint = $this->requiredEndpoint('criarOportunidadeEndpoint');
        $this->document = '';

        return $this->post($payload->toArray());
    }
}
