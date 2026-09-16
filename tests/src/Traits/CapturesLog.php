<?php

namespace Tests\AldirBlanc\Traits;

use Monolog\Handler\TestHandler;

/** Troca os handlers do logger por um de teste, para afirmar em que nível a linha saiu. */
trait CapturesLog
{
    protected function capturandoLog(callable $exercicio): TestHandler
    {
        $capturado = new TestHandler();
        $originais = $this->app->log->getHandlers();
        $this->app->log->setHandlers([$capturado]);

        try {
            $exercicio();
        } finally {
            $this->app->log->setHandlers($originais);
        }

        return $capturado;
    }
}
