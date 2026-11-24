<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ModeloPlanilhaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/modelo-planilha.php';

    private const PARAMETER_DEFAULT = '{id_edital}';

    protected string $document;

    public function __construct(ParEditalId $edital, ?Transport $transport = null)
    {
        $this->document = (string) $edital->id;
        $this->endpoint = $this->requiredEndpoint('modeloPlanilhaEndpoint');

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }
}
