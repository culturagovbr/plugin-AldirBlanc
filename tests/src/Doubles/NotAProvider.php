<?php

namespace Tests\AldirBlanc\Doubles;

/** Existe, carrega, e não serve: a resolução precisa recusar antes de construir. */
class NotAProvider
{
    public static int $construcoes = 0;

    public function __construct()
    {
        self::$construcoes++;
    }
}
