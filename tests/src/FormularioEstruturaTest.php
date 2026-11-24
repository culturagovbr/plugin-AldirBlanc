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

    public function testItensSaoSoOsDeOpcaoEmLista(): void
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta('estrutura-tipo-1'));

        $this->assertSame('declaracao_integral', FormularioEstrutura::itens($estrutura->questoes()[2081])[0]['id']);
        $this->assertSame([], FormularioEstrutura::itens($estrutura->questoes()[1843]), 'booleano traz a opção como texto');
        $this->assertSame([], FormularioEstrutura::itens($estrutura->questoes()[1845]), 'sem opção não há itens');
    }
}
