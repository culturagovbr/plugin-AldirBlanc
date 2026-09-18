<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\ParActionPageLimits;

class ParAcaoClient extends AbstractClient
{
    protected const FIXTURE = 'gestao/par-acoes.php';
    protected const PROVIDER = 'gestao';

    protected string $document;

    protected int $skip;
    protected int $limit;

    public function __construct(
        int $skip = ParActionPageLimits::DEFAULT_SKIP,
        int $limit = ParActionPageLimits::DEFAULT_LIMIT,
        ?Transport $transport = null,
    )
    {
        $this->document = '';
        $this->skip = max(0, $skip);
        $this->limit = in_array($limit, ParActionPageLimits::ALLOWED_LIMITS, true)
            ? $limit
            : ParActionPageLimits::DEFAULT_LIMIT;

        $endpoint = $this->requiredConfig($this->getClientConfig(), 'parAcoesEndpoint', 'PNAB_CULTBR_PAR_ACOES_ENDPOINT');
        $this->endpoint = rtrim($endpoint, '?') . '?' . http_build_query([
            'skip' => $this->skip,
            'limit' => $this->limit,
        ]);

        parent::__construct($transport);
    }
}
