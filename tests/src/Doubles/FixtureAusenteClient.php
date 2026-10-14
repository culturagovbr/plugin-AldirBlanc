<?php

namespace Tests\AldirBlanc\Doubles;

/** Aponta para um arquivo que não existe de propósito, para exercitar a falha de fixture. */
class FixtureAusenteClient extends TestableAbstractClient
{
    protected const FIXTURE = 'conecta/nao-existe.php';
}
