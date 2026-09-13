<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Transport\Transport;

class EntesClient extends ConectaClient
{
    protected const FIXTURE = 'conecta/entes.php';

    protected string $document;

    public function __construct(GestorDocument $gestorDocument, ?Transport $transport = null)
    {
        $this->document = $gestorDocument->document;
        $this->endpoint = $this->requiredConfig(
            $this->getClientConfig(),
            'entesEndpoint',
            $this->envName('ENTES_ENDPOINT'),
        );

        parent::__construct($transport);
    }
}
