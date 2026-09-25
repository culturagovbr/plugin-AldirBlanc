<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\ParActionPageLimits;

class ParAcoesClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/par-acoes.php';

    protected string $document;

    protected int $skip;
    protected int $limit;

    public function __construct(int $skip, int $limit, ?Transport $transport = null)
    {
        $this->document = '';
        $this->skip = max(0, $skip);
        $this->limit = in_array($limit, ParActionPageLimits::ALLOWED_LIMITS, true)
            ? $limit
            : ParActionPageLimits::DEFAULT_LIMIT;

        $endpoint = $this->requiredEndpoint('parAcoesEndpoint');
        $this->endpoint = rtrim($endpoint, '?') . '?' . http_build_query([
            'skip' => $this->skip,
            'limit' => $this->limit,
        ]);

        parent::__construct($transport);
    }
}
