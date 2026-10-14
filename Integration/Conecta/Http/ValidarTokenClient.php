<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ValidarTokenClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/validar-token.php';

    protected string $document;

    public function __construct(?Transport $transport = null)
    {
        $this->document = '';
        $this->endpoint = $this->requiredEndpoint('validarTokenEndpoint');

        parent::__construct($transport);
    }
}
