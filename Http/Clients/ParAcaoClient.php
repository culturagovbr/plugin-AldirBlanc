<?php

namespace AldirBlanc\Http\Clients;

class ParAcaoClient extends AbstractClient
{
    public const DEFAULT_SKIP = 0;
    public const DEFAULT_LIMIT = 1000;
    public const ALLOWED_LIMITS = [1000];
    public const DEFAULT_ENDPOINT = 'par/sefic/acoes';

    protected string $document;

    protected int $skip;
    protected int $limit;

    public function __construct(int $skip = self::DEFAULT_SKIP, int $limit = self::DEFAULT_LIMIT)
    {
        $this->document = '';
        $this->skip = max(0, $skip);
        $this->limit = in_array($limit, self::ALLOWED_LIMITS, true) ? $limit : self::DEFAULT_LIMIT;

        $endpoint = $this->getClientConfig()['parAcoesEndpoint'] ?? self::DEFAULT_ENDPOINT;
        $this->endpoint = rtrim($endpoint, '?') . '?' . http_build_query([
            'skip' => $this->skip,
            'limit' => $this->limit,
        ]);

        parent::__construct();
    }
}
