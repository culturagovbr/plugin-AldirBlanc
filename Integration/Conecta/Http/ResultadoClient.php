<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\ResultadoSender;

class ResultadoClient extends AbstractClient implements ResultadoSender
{
    protected const PROVIDER = 'conecta';

    private const PARAMETER_DEFAULT = '{id_edital}';

    protected string $document;

    public function __construct(?Transport $transport = null)
    {
        $this->endpoint = '';
        $this->document = '';

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    public function create(ParEditalId $edital, Resultado $payload): mixed
    {
        $this->endpoint = $this->requiredEndpoint('criarResultadoEndpoint');
        $this->document = (string) $edital->id;

        return $this->post($payload->toArray());
    }
}
