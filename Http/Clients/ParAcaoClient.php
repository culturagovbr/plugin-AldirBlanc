<?php

namespace AldirBlanc\Http\Clients;

use AldirBlanc\Http\Transport\Transport;

class ParAcaoClient extends AbstractClient
{
    public const DEFAULT_SKIP = 0;
    public const DEFAULT_LIMIT = 1000;
    public const ALLOWED_LIMITS = [1000];

    protected string $document;

    protected int $skip;
    protected int $limit;

    public function __construct(
        int $skip = self::DEFAULT_SKIP,
        int $limit = self::DEFAULT_LIMIT,
        ?Transport $transport = null,
    )
    {
        $this->document = '';
        $this->skip = max(0, $skip);
        $this->limit = in_array($limit, self::ALLOWED_LIMITS, true) ? $limit : self::DEFAULT_LIMIT;

        $endpoint = $this->requiredConfig($this->getClientConfig(), 'parAcoesEndpoint', 'PNAB_CULTBR_PAR_ACOES_ENDPOINT');
        $this->endpoint = rtrim($endpoint, '?') . '?' . http_build_query([
            'skip' => $this->skip,
            'limit' => $this->limit,
        ]);

        parent::__construct($transport);
    }
}
