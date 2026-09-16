<?php

namespace AldirBlanc\Integration;

/** Defaults e limites da paginação do catálogo, para consumidor nenhum precisar conhecer client. */
final class ParActionPageLimits
{
    public const DEFAULT_SKIP = 0;

    /** Página única: o teto tem folga sobre o catálogo real, que já passou de mil ações e cresce. */
    public const DEFAULT_LIMIT = 2000;
    public const ALLOWED_LIMITS = [2000];
}
