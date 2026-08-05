<?php

namespace Tests\AldirBlanc\Traits;

use MapasCulturais\App;

/**
 * A fila de jobs é global e escapa do rollback: o bootstrap do core enfileira jobs recorrentes
 * fora da transação do teste, e App::executeJob() sempre executa o mais antigo da tabela inteira.
 */
trait IsolatesJobQueue
{
    /** Roda dentro da transação do teste, então o rollback devolve a fila. */
    protected function clearJobQueue(): void
    {
        App::i()->em->getConnection()->executeStatement('DELETE FROM job');
    }
}
