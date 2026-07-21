<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Jobs\OportunidadeCultJob;

/**
 * Expõe como público o critério de recusa da API, só para teste. Não altera comportamento.
 */
class TestableOportunidadeCultJob extends OportunidadeCultJob
{
    public function callApiRejectedSend(?string $lastAttemptResult): bool
    {
        return $this->apiRejectedSend($lastAttemptResult);
    }
}
