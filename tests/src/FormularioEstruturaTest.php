<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\FormularioEstrutura;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** A estrutura publicada pelo PAR, lida por um ponto só. */
class FormularioEstruturaTest extends TestCase
{
    use LoadsConectaResponses;

    #[DataProvider('estruturasReais')]
    public function testReuneAsQuestoesDeTodasAsEtapas(string $nome, int $versao, int $total): void
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta($nome));

        $this->assertSame($versao, $estrutura->versao());
        $this->assertCount($total, $estrutura->questoes());
    }

    public static function estruturasReais(): array
    {
        return [
            'ação 1.1' => ['estrutura-tipo-1', 541, 20],
            'ação 2.3' => ['estrutura-tipo-14', 551, 19],
        ];
    }

    public function testEncontraAQuestaoPelosDoisIds(): void
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta('estrutura-tipo-1'));

        $this->assertSame('execucao_conforme_planejado_1_1', $estrutura->questoes()[1843]['tag']);
        $this->assertSame(1843, $estrutura->questaoPorEtapa(13923)['id_questao']);
        $this->assertNull($estrutura->questaoPorEtapa(7914), 'id de outra versão não existe nesta');
    }

    public function testQuestaoSemIdDeEtapaContinuaAcessivel(): void
    {
        $estrutura = new FormularioEstrutura(['secoes' => [['etapas' => [['questoes' => [['id_questao' => 10]]]]]]]);

        $this->assertSame([10], array_keys($estrutura->questoes()));
        $this->assertNull($estrutura->questaoPorEtapa(0), 'id ausente não vira índice zero');
        $this->assertNull($estrutura->versao());
    }

    public function testSemEstruturaNaoHaQuestoes(): void
    {
        $this->assertSame([], (new FormularioEstrutura(null))->questoes());
    }

    public function testPlanilhaEReconhecidaPelaTagEPeloNome(): void
    {
        $planilhas = [];
        $anexos = 0;

        foreach ([1, 2, 3, 4, 5, 6, 8, 9, 10, 12, 13, 14, 15, 16, 17, 20] as $tipo) {
            foreach ((new FormularioEstrutura($this->respostaDaConecta("estrutura-tipo-{$tipo}")))->questoes() as $id => $questao) {
                $isPlanilha = FormularioEstrutura::isPlanilha($questao);
                $isDocumento = FormularioEstrutura::isDocumento($questao);
                $isAnexo = in_array($questao['tipo_questao'], ['anexo', 'múltiplos anexos'], true);

                $this->assertSame($isAnexo, $isPlanilha xor $isDocumento, $questao['nome']);
                $anexos += (int) $isAnexo;
                $planilhas = $isPlanilha ? [...$planilhas, $id] : $planilhas;
            }
        }

        $this->assertSame(52, $anexos);
        $this->assertSame([2077, 2966, 2967, 1790, 1935, 2850, 1979, 2001, 2549, 2742, 2757, 1811, 2786], $planilhas);
    }

    public function testPlanilhaDaVersaoAntigaEntraEADaComissaoNao(): void
    {
        $questoes = (new FormularioEstrutura($this->respostaDaConecta('estrutura-tipo-1-versao-296')))->questoes();

        $this->assertTrue(FormularioEstrutura::isPlanilha($questoes[2077]), 'o nome não diferencia maiúsculas');
        $this->assertFalse(FormularioEstrutura::isPlanilha($questoes[2078]), 'planilha da comissão é documento');
    }

    public function testSoAnexoComONomeNoInicioEPlanilha(): void
    {
        $questao = ['tipo_questao' => 'anexo', 'tag' => 'planilha', 'nome' => 'Anexo da planilha de resultados'];

        $this->assertTrue(FormularioEstrutura::isDocumento($questao));
        $this->assertFalse(FormularioEstrutura::isPlanilha(['tipo_questao' => 'texto', 'tag' => 'planilha', 'nome' => 'Planilha de resultados']));
    }

    public function testNomeDePlanilhaSemATagEDocumento(): void
    {
        $questao = ['tipo_questao' => 'anexo', 'tag' => 'outros', 'nome' => 'Planilha de resultados'];

        $this->assertFalse(FormularioEstrutura::isPlanilha($questao));
        $this->assertTrue(FormularioEstrutura::isDocumento($questao));
    }

    public function testItensSaoSoOsDeOpcaoEmLista(): void
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta('estrutura-tipo-1'));

        $this->assertSame('declaracao_integral', FormularioEstrutura::itens($estrutura->questoes()[2081])[0]['id']);
        $this->assertSame([], FormularioEstrutura::itens($estrutura->questoes()[1843]), 'booleano traz a opção como texto');
        $this->assertSame([], FormularioEstrutura::itens($estrutura->questoes()[1845]), 'sem opção não há itens');
    }
}
