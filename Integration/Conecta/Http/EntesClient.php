<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class EntesClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/entes.php';

    protected string $document;

    public function __construct(GestorDocument $gestorDocument, ?Transport $transport = null)
    {
        $this->document = $gestorDocument->document;
        $this->endpoint = $this->requiredEndpoint('entesEndpoint');

        parent::__construct($transport);
    }
}
