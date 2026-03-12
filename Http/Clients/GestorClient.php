<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Dtos\GestorDocument;

class GestorClient extends AbstractClient
{
    protected string $document;

    public function __construct(GestorDocument $gestorDocument)
    {
        $this->document = $gestorDocument->document;
        $this->endpoint = $this->getClientConfig()['seficEndpoint'] . '/' . $this->getClientConfig()['gestorEndpoint'];

        parent::__construct();
    }
}
