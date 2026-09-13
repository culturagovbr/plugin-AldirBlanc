<?php

namespace AldirBlanc\Integration;

/** Defaults e limites da paginação do catálogo, para consumidor nenhum precisar conhecer client. */
final class ParActionPageLimits
{
    public const DEFAULT_SKIP = 0;
    public const DEFAULT_LIMIT = 1000;
    public const ALLOWED_LIMITS = [1000];
}
