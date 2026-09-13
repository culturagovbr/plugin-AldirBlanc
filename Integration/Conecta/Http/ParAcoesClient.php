<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Transport\Transport;

class ParAcoesClient extends ConectaClient
{
    protected const FIXTURE = 'conecta/par-acoes.php';

    protected string $document;

    protected int $skip;
    protected int $limit;

    public function __construct(int $skip, int $limit, ?Transport $transport = null)
    {
        $this->document = '';
        $this->skip = $skip;
        $this->limit = $limit;

        $endpoint = $this->requiredConfig(
            $this->getClientConfig(),
            'parAcoesEndpoint',
            $this->envName('PAR_ACOES_ENDPOINT'),
        );
        $this->endpoint = rtrim($endpoint, '?') . '?' . http_build_query([
            'skip' => $skip,
            'limit' => $limit,
        ]);

        parent::__construct($transport);
    }
}
