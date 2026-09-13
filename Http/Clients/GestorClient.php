<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Dtos\GestorDocument;

class GestorClient extends AbstractClient
{
    protected string $document;

    public function __construct(GestorDocument $gestorDocument)
    {
        $config = $this->getClientConfig();

        $this->document = $gestorDocument->document;
        $this->endpoint = $this->requiredConfig($config, 'seficEndpoint', 'PNAB_CULTBR_SEFIC_ENDPOINT')
            . '/' . $this->requiredConfig($config, 'gestorEndpoint', 'PNAB_CULTBR_GESTOR_ENDPOINT');

        parent::__construct();
    }
}
