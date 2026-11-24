<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\FormularioEstrutura;
use AldirBlanc\Services\FormularioRegras;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Visibilidade das questões do formulário de resultado, pelas regras publicadas. */
class FormularioRegrasTest extends TestCase
{
    use LoadsConectaResponses;

    private function isVisivel(int $tipoAcao, int $idQuestao, array $respostas): bool
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta("estrutura-tipo-{$tipoAcao}"));

        return (new FormularioRegras($estrutura))->isVisivel($estrutura->questoes()[$idQuestao], $respostas);
    }

    #[DataProvider('regrasReais')]
    public function testVisibilidadeNasEstruturasReais(int $tipoAcao, int $idQuestao, array $respostas, bool $visivel): void
    {
        $this->assertSame($visivel, $this->isVisivel($tipoAcao, $idQuestao, $respostas));
    }

    public static function regrasReais(): array
    {
        return [
            'sem regra' => [1, 1838, [], true],
            'comentário do sim, execução sim' => [1, 2965, [1843 => true], true],
            'comentário do sim, execução não' => [1, 2965, [1843 => false], false],
            'comentário do não, execução não' => [1, 1845, [1843 => false], true],
            'comentário do não, execução sim' => [1, 1845, [1843 => true], false],
            'comentário do não, execução sem resposta' => [1, 1845, [], false],
            'booleano em texto não casa' => [1, 1845, [1843 => ''], false],
            'planilha da forma escolhida' => [1, 2077, [2186 => 'fe_tec'], true],
            'planilha de outra forma' => [1, 2966, [2186 => 'fe_tec'], false],
            'forma com complemento' => [1, 2077, [2186 => ['id' => 'fe_outros', 'complemento' => 'x']], false],
            'includes com o valor marcado' => [9, 2838, [2837 => ['enq_i', 'enq_ii']], true],
            'includes sem o valor marcado' => [9, 2838, [2837 => ['enq_iii']], false],
            'includes com a fonte sem resposta' => [9, 2838, [], false],
            'includes com texto no lugar da lista' => [9, 2838, [2837 => 'enq_ii'], false],
        ];
    }

    public function testOpcaoComComplementoCasaPeloId(): void
    {
        [$estrutura, $questao] = $this->comRegras([$this->regra('a')]);

        $this->assertTrue((new FormularioRegras($estrutura))->isVisivel($questao, [1 => ['id' => 'a', 'complemento' => 'x']]));
        $this->assertTrue(
            (new FormularioRegras($estrutura))->isVisivel(
                ['config' => ['regras' => ['itens' => [$this->regra('a', 'includes')]]]],
                [1 => [['id' => 'a', 'complemento' => 'x']]],
            ),
        );
    }

    public function testComDuasRegrasAsDuasPrecisamCasar(): void
    {
        [$estrutura, $questao] = $this->comRegras([$this->regra('a'), $this->regra('b', 'eq', 200)]);
        $regras = new FormularioRegras($estrutura);

        $this->assertTrue($regras->isVisivel($questao, [1 => 'a', 2 => 'b']));
        $this->assertFalse($regras->isVisivel($questao, [1 => 'a', 2 => 'c']));
        $this->assertFalse($regras->isVisivel($questao, [1 => 'c', 2 => 'b']));
    }

    #[DataProvider('regrasNaoSuportadas')]
    public function testRegraNaoSuportadaEApontadaENaoEscondeAQuestao(array $trocas): void
    {
        [$estrutura, $questao] = $this->comRegras([array_replace_recursive($this->regra('a'), $trocas)]);
        $regras = new FormularioRegras($estrutura);

        $this->assertFalse($regras->isTodaSuportada($questao));
        $this->assertTrue($regras->isVisivel($questao, []), 'sem entender a regra, a questão não some');
    }

    public static function regrasNaoSuportadas(): array
    {
        return [
            'operador inventado' => [['quando' => ['operador' => 'gt']]],
            'tipo inventado' => [['tipo' => 'obrigatoriedade']],
            'efeito inventado' => [['efeito' => ['mostrar' => false]]],
            'efeito a mais' => [['efeito' => ['limpar' => true]]],
            'fonte ausente da estrutura' => [['quando' => ['fonteIdQuestaoEtapa' => 999]]],
        ];
    }

    #[DataProvider('estruturasPublicadas')]
    public function testTodaRegraPublicadaESuportada(int $tipoAcao): void
    {
        $estrutura = new FormularioEstrutura($this->respostaDaConecta("estrutura-tipo-{$tipoAcao}"));

        foreach ($estrutura->questoes() as $questao) {
            $this->assertTrue((new FormularioRegras($estrutura))->isTodaSuportada($questao), $questao['nome']);
        }
    }

    public static function estruturasPublicadas(): array
    {
        return array_map(fn(int $tipo) => [$tipo], [1, 2, 3, 4, 5, 6, 8, 9, 10, 12, 13, 14, 15, 16, 17, 20]);
    }

    private function regra(string $valor, string $operador = 'eq', int $fonte = 100): array
    {
        return [
            'tipo' => 'visibilidade',
            'efeito' => ['mostrar' => true],
            'quando' => ['valor' => $valor, 'operador' => $operador, 'fonteIdQuestaoEtapa' => $fonte],
        ];
    }

    /** @return array{FormularioEstrutura, array} a estrutura com duas fontes e a questão com as regras */
    private function comRegras(array $regras): array
    {
        $questao = ['id_questao' => 3, 'id_questao_etapa' => 300, 'config' => ['regras' => ['itens' => $regras]]];
        $fontes = [['id_questao' => 1, 'id_questao_etapa' => 100], ['id_questao' => 2, 'id_questao_etapa' => 200]];

        return [new FormularioEstrutura(['secoes' => [['etapas' => [['questoes' => [...$fontes, $questao]]]]]]), $questao];
    }
}
