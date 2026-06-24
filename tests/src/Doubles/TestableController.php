<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Controller;

/**
 * Expõe os métodos protected de Controller usados na normalização de ações do PAR
 * como públicos, só para teste. Não altera nenhum comportamento.
 */
class TestableController extends Controller
{
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
