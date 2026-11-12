<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ParCadastroClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/par-cadastro.php';

    private const PARAMETER_DEFAULT = '{id}';

    protected string $document;

    public function __construct(int $idParCadastro, ?Transport $transport = null)
    {
        $this->document = (string) $idParCadastro;
        $this->endpoint = $this->requiredEndpoint('parCadastroEndpoint');

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }
}
