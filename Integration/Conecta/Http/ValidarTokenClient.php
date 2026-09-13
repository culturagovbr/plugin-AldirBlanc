<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Transport\Transport;

class ValidarTokenClient extends ConectaClient
{
    protected const FIXTURE = 'conecta/validar-token.php';

    protected string $document;

    public function __construct(?Transport $transport = null)
    {
        $this->document = '';
        $this->endpoint = $this->requiredConfig(
            $this->getClientConfig(),
            'validarTokenEndpoint',
            $this->envName('VALIDAR_TOKEN_ENDPOINT'),
        );

        parent::__construct($transport);
    }
}
