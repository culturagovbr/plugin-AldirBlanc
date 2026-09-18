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
        $config = $this->getClientConfig();

        $this->document = $gestorDocument->document;
        $this->endpoint = $this->requiredConfig($config, 'seficEndpoint', 'PNAB_CULTBR_SEFIC_ENDPOINT')
            . '/' . $this->requiredConfig($config, 'gestorEndpoint', 'PNAB_CULTBR_GESTOR_ENDPOINT');

        parent::__construct($transport);
    }
}
