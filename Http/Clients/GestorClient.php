<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Transport\Transport;

class GestorClient extends AbstractClient
{
    protected const FIXTURE = 'gestao/gestor.php';
    protected const PROVIDER = 'gestao';

    protected string $document;

    public function __construct(GestorDocument $gestorDocument, ?Transport $transport = null)
    {
        $this->document = $gestorDocument->document;
        $this->endpoint = $this->requiredEndpoint('entesEndpoint');

        parent::__construct($transport);
    }
}
