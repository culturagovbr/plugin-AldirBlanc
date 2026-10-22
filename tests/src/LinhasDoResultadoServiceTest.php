<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\LinhasDoResultadoService;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\Traits\UserDirector;

/** Planilha de ação cultural do envio: só vai quando o conjunto inteiro passa no que o validador exige. */
class LinhasDoResultadoServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;

    private function service(): LinhasDoResultadoService
    {
        return new LinhasDoResultadoService();
    }

    function testMontaUmaLinhaPorContempladaComOsCamposDoContrato()
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'Faixa A', 'value' => '15000']]);
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', 'Faixa A');

        $linhas = $this->service()->montar($raiz);

        $this->assertCount(1, $linhas);
        $this->assertSame('15000.00', $linhas[0]['valor_recebido_pnab']);
        $this->assertSame('11144477735', $linhas[0]['cpf_responsavel_execucao']);
        $this->assertSame((string) $raiz->name, $linhas[0]['identificador_edital']);
        $this->assertArrayNotHasKey('tipo_agente', $linhas[0], 'coluna que o validador não conhece derruba a planilha inteira');
    }

    function testUmaContempladaIncompletaSeguraAPlanilhaInteira()
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'Faixa A', 'value' => '15000']]);
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', 'Faixa A');
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']], 'Pessoa Física');

        $this->assertNull($this->service()->montar($raiz), 'o validador é atômico: linha incompleta derrubaria o resultado');
    }

    function testEditalSemContempladaNaoGeraPlanilha()
    {
        [$raiz] = $this->editalComCategorias();

        $this->assertNull($this->service()->montar($raiz));
    }

    function testLinhasSaemNaOrdemDoNumeroDaInscricao()
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'Faixa A', 'value' => '15000']]);
        $uma = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', 'Faixa A');
        $outra = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']], 'Pessoa Física', 'Faixa A');

        $cpfPorNumero = [(string) $uma->number => '11144477735', (string) $outra->number => '52998224725'];
        ksort($cpfPorNumero, SORT_STRING);

        $cpfs = array_map(fn(array $linha) => $linha['cpf_responsavel_execucao'], $this->service()->montar($raiz));

        $this->assertSame(array_values($cpfPorNumero), $cpfs, 'ordem instável geraria retificado sem nada ter mudado');
    }
}
