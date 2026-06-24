<?php

namespace Tests\AldirBlanc;

use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;

/**
 * Testes da normalização da listagem de ações do PAR: extração da chave de
 * deduplicação, remoção de duplicatas e ordenação natural por label.
 */
class ParActionsListHelpersTest extends TestCase
{
    private function controller(): TestableController
    {
        return new TestableController();
    }

    // ===== getParActionLabelKey =====

    function testLabelKeyComCodigoSimplesExtraiSoONumero()
    {
        $this->assertSame('1.1', $this->controller()->callGetParActionLabelKey('1.1 Fomento Cultural'));
    }

    function testLabelKeyComCodigoMultinivelExtraiTudo()
    {
        $this->assertSame('3.2.1', $this->controller()->callGetParActionLabelKey('3.2.1 Sub-ação qualquer'));
    }

    function testLabelKeySemCodigoUsaTextoEmMinusculas()
    {
        $this->assertSame('conformidade legal', $this->controller()->callGetParActionLabelKey('Conformidade Legal'));
    }

    /**
     * Achado: dígito seguido de letra sem separador (sem espaço/ponto) não tem fronteira de
     * palavra entre eles — a regex exige \b logo após os dígitos, então o match falha e cai
     * para o fallback de texto em minúsculas.
     */
    function testLabelKeyComDigitosColadosEmLetrasNaoExtraiCodigo()
    {
        $this->assertSame('123abc', $this->controller()->callGetParActionLabelKey('123abc'));
    }

    function testLabelKeyIgnoraEspacosNoInicio()
    {
        $this->assertSame('1.1', $this->controller()->callGetParActionLabelKey('   1.1 Fomento Cultural'));
    }

    function testLabelKeyVazioRetornaVazio()
    {
        $this->assertSame('', $this->controller()->callGetParActionLabelKey(''));
    }

    // ===== removeDuplicatedParActions =====

    function testRemoveDuplicadosComMesmoLabelExatoMantemSoOPrimeiro()
    {
        $actions = [
            ['value' => 'a', 'label' => '1.1 Fomento Cultural'],
            ['value' => 'b', 'label' => '1.1 Fomento Cultural'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['value']);
    }

    /**
     * A deduplicação é pelo CÓDIGO numérico, não pelo texto completo — duas ações com o mesmo
     * código e descrições diferentes são tratadas como duplicata, mantendo só a primeira.
     */
    function testRemoveDuplicadosComMesmoCodigoETextoDiferenteMantemSoOPrimeiro()
    {
        $actions = [
            ['value' => 'a', 'label' => '1.2 Algo'],
            ['value' => 'b', 'label' => '1.2 Outro Texto Completamente Diferente'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['value']);
    }

    function testRemoveDuplicadosSemCodigoEhCaseInsensitive()
    {
        $actions = [
            ['value' => 'a', 'label' => 'Conformidade Legal'],
            ['value' => 'b', 'label' => 'CONFORMIDADE LEGAL'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['value']);
    }

    function testRemoveDuplicadosMantemAcoesComCodigosDiferentes()
    {
        $actions = [
            ['value' => 'a', 'label' => '1.1 Fomento Cultural'],
            ['value' => 'b', 'label' => '1.2 Outra Ação'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(2, $result);
    }

    /**
     * Ação com label vazio (após trim) é DESCARTADA, não só deduplicada — nem a primeira
     * ocorrência é mantida.
     */
    function testRemoveDuplicadosDescartaLabelVazio()
    {
        $actions = [
            ['value' => 'a', 'label' => ''],
            ['value' => 'b', 'label' => '   '],
            ['value' => 'c', 'label' => '1.1 Fomento Cultural'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(1, $result);
        $this->assertSame('c', $result[0]['value']);
    }

    function testRemoveDuplicadosComActionSemChaveLabelTrataComoVazio()
    {
        $actions = [
            ['value' => 'a'],
            ['value' => 'b', 'label' => '1.1 Fomento Cultural'],
        ];

        $result = $this->controller()->callRemoveDuplicatedParActions($actions);

        $this->assertCount(1, $result);
        $this->assertSame('b', $result[0]['value']);
    }

    // ===== sortParActionsByLabel =====

    function testSortOrdenaNumericamenteNaoLexicograficamente()
    {
        $actions = [
            ['label' => '1.10 Décima ação'],
            ['label' => '1.2 Segunda ação'],
        ];

        $result = $this->controller()->callSortParActionsByLabel($actions);

        $this->assertSame('1.2 Segunda ação', $result[0]['label']);
        $this->assertSame('1.10 Décima ação', $result[1]['label']);
    }

    function testSortEhCaseInsensitive()
    {
        $actions = [
            ['label' => 'zebra'],
            ['label' => 'Abacaxi'],
        ];

        $result = $this->controller()->callSortParActionsByLabel($actions);

        $this->assertSame('Abacaxi', $result[0]['label']);
        $this->assertSame('zebra', $result[1]['label']);
    }

    function testSortComActionSemLabelVaiParaOInicio()
    {
        $actions = [
            ['label' => 'Zebra'],
            ['value' => 'sem-label'],
        ];

        $result = $this->controller()->callSortParActionsByLabel($actions);

        $this->assertArrayNotHasKey('label', $result[0]);
        $this->assertSame('Zebra', $result[1]['label']);
    }
}
