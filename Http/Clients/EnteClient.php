<?php

namespace AldirBlanc\Http\Clients;

class EnteClient extends AbstractClient
{
    protected string $document;

    public function __construct(string $document)
    {
        $this->document = $document;
        $this->endpoint = $this->getClientConfig()['enteFederadoEndpoint'];

        parent::__construct();
    }
}
