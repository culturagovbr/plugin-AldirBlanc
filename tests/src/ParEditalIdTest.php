<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParEditalId;
use Tests\Abstract\TestCase;

/** Contrato do value object do id externo: só existe com um id válido do PAR. */
class ParEditalIdTest extends TestCase
{
    function testArmazenaIdPositivo()
    {
        $dto = new ParEditalId(53790);

        $this->assertSame(53790, $dto->id);
    }

    function testRejeitaZero()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ParEditalId(0);
    }

    function testRejeitaNegativo()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ParEditalId(-9);
    }

    function testNaoAceitaNull()
    {
        $this->expectException(\TypeError::class);

        new ParEditalId(null);
    }
}
