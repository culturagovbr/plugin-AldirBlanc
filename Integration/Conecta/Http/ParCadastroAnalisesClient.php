<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ParCadastroAnalisesClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/par-cadastro-analises.php';

    protected string $document;

    public function __construct(string $cnpj, ?Transport $transport = null)
    {
        $this->document = $cnpj;
        $this->endpoint = $this->requiredEndpoint('parCadastroAnalisesEndpoint');

        parent::__construct($transport);
    }
}
