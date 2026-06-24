<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Jobs\GestorCultJob;

/**
 * Expõe os métodos protected de Controller usados na normalização de ações do PAR
 * como públicos, só para teste. Não altera nenhum comportamento.
 */
class TestableController extends Controller
{
    private string $gestorCpf = '12345678901';
    private ?\Throwable $cpfException = null;
    private $syncCallback = null;
    private int $syncCalls = 0;

    public function setGestorCpf(string $cpf): void
    {
        $this->gestorCpf = $cpf;
    }

    public function setCpfException(\Throwable $exception): void
    {
        $this->cpfException = $exception;
    }

    public function setSyncCallback(callable $callback): void
    {
        $this->syncCallback = $callback;
    }

    public function getSyncCalls(): int
    {
        return $this->syncCalls;
    }

    public function callStartSync(): void
    {
        $this->POST_startSync();
    }

    public function callCheckSyncStatus(): void
    {
        $this->GET_checkSyncStatus();
    }

    public function callLogoutOnError(): void
    {
        $this->POST_logoutOnError();
    }

    protected function getGestorCpf(): string
    {
        if ($this->cpfException) {
            throw $this->cpfException;
        }

        return $this->gestorCpf;
    }

    protected function createGestorCultJob(GestorDocument $gestorDocument): GestorCultJob
    {
        $this->syncCalls++;
        $callback = $this->syncCallback;

        return new class($gestorDocument, $callback) extends GestorCultJob {
            private $callback;

            public function __construct(GestorDocument $gestorDocument, ?callable $callback)
            {
                parent::__construct($gestorDocument);
                $this->callback = $callback;
            }

            public function sync(): bool
            {
                if ($this->callback) {
                    return (bool) ($this->callback)();
                }

                return true;
            }
        };
    }

    public function callRemoveDuplicatedParActions(array $actions): array
    {
        return $this->removeDuplicatedParActions($actions);
    }

    public function callGetParActionLabelKey(string $label): string
    {
        return $this->getParActionLabelKey($label);
    }

    public function callSortParActionsByLabel(array $actions): array
    {
        return $this->sortParActionsByLabel($actions);
    }
}
