<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class OportunidadeConsultaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/oportunidade-consulta.php';

    private const PARAMETER_DEFAULT = '{id}';

    private const NAO_SINCRONIZADA = 404;

    protected string $document;

    public function __construct(OpportunityId $opportunityId, ?Transport $transport = null)
    {
        $this->document = (string) $opportunityId->id;
        $this->endpoint = $this->requiredEndpoint('oportunidadeEndpoint');

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    /** Eco da oportunidade na origem, ou null quando ela não existe lá. */
    public function fetch(): ?array
    {
        try {
            return (array) $this->get();
        } catch (IntegrationError $e) {
            if ($e->httpStatus() === self::NAO_SINCRONIZADA) {
                return null;
            }

            throw $e;
        }
    }
}
