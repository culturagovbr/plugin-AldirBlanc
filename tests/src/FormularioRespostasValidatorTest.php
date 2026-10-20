<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\FormularioRespostasValidator;
use Tests\Abstract\TestCase;

/** Validação pura das respostas contra a estrutura do formulário do PAR. */
class FormularioRespostasValidatorTest extends TestCase
{
    private function estrutura(array $questoes): array
    {
        return ['id_formulario' => 1, 'secoes' => [['etapas' => [['questoes' => $questoes]]]]];
    }

    private function questao(array $trocas = []): array
    {
        return $trocas + [
            'id_questao' => 10,
            'tipo_questao' => 'número',
            'tag' => 'total_inscritos',
            'requerida' => true,
            'opcao' => null,
            'config' => [],
            'valor_minimo' => null,
            'valor_maximo' => null,
            'somente_leitura' => false,
        ];
    }

    private function validator(array $questoes): FormularioRespostasValidator
    {
        return new FormularioRespostasValidator($this->estrutura($questoes));
    }

    function testRespostasValidasNaoGeramErro()
    {
        $validator = $this->validator([
            $this->questao(),
            $this->questao(['id_questao' => 11, 'tipo_questao' => 'texto', 'tag' => 'detalhe']),
        ]);

        $this->assertSame([], $validator->validate([10 => 25, 11 => 'tudo certo']));
    }

    function testQuestaoDesconhecidaERejeitada()
    {
        $erros = $this->validator([$this->questao()])->validate([99 => 'x']);

        $this->assertArrayHasKey(99, $erros);
    }

    function testQuestaoDeAnexoNaoRecebeResposta()
    {
        $validator = $this->validator([
            $this->questao(['id_questao' => 20, 'tipo_questao' => 'anexo', 'tag' => 'planilha_de_resultados']),
        ]);

        $this->assertArrayHasKey(20, $validator->validate([20 => 'arquivo.pdf']));
    }

    function testDataDePublicacaoNaoRecebeResposta()
    {
        $validator = $this->validator([
            $this->questao(['id_questao' => 30, 'tipo_questao' => 'data', 'tag' => 'data_publicacao_resultado_1_1']),
        ]);

        $this->assertArrayHasKey(30, $validator->validate([30 => '2026-10-19']));
    }

    function testNumeroInvalidoEForaDoMinimoSaoRejeitados()
    {
        $validator = $this->validator([$this->questao(['valor_minimo' => 1.0])]);

        $this->assertArrayHasKey(10, $validator->validate([10 => 'abc']));
        $this->assertArrayHasKey(10, $validator->validate([10 => 0]));
        $this->assertSame([], $validator->validate([10 => '3']));
    }

    function testDataForaDoFormatoERejeitada()
    {
        $validator = $this->validator([
            $this->questao(['id_questao' => 12, 'tipo_questao' => 'data', 'tag' => 'data_inicio']),
        ]);

        $this->assertArrayHasKey(12, $validator->validate([12 => '19/10/2026']));
        $this->assertArrayHasKey(12, $validator->validate([12 => '2026-02-30']), 'data que o createFromFormat rola para março é inválida');
        $this->assertSame([], $validator->validate([12 => '2026-10-19']));
    }

    function testBooleanoSoAceitaVerdadeiroOuFalso()
    {
        $validator = $this->validator([
            $this->questao(['id_questao' => 13, 'tipo_questao' => 'booleano', 'tag' => 'executado']),
        ]);

        $this->assertArrayHasKey(13, $validator->validate([13 => 'sim']));
        $this->assertSame([], $validator->validate([13 => true]));
    }

    function testMultiplaEscolhaForaDoVocabularioERejeitada()
    {
        $opcao = ['tipo' => 'multipla', 'itens' => [['valor' => 'libras'], ['valor' => 'rampa']]];
        $validator = $this->validator([
            $this->questao(['id_questao' => 14, 'tipo_questao' => 'múltipla escolha', 'tag' => 'medidas', 'opcao' => $opcao]),
        ]);

        $this->assertArrayHasKey(14, $validator->validate([14 => ['libras', 'audiodescricao']]));
        $this->assertSame([], $validator->validate([14 => ['libras', 'rampa']]));
    }

    function testMultiplaEscolhaComOutrosAceitaValorLivre()
    {
        $opcao = ['tipo' => 'multipla', 'itens' => [['valor' => 'libras']]];
        $validator = $this->validator([
            $this->questao([
                'id_questao' => 14,
                'tipo_questao' => 'múltipla escolha',
                'tag' => 'medidas',
                'opcao' => $opcao,
                'permite_inclusao_outros' => true,
            ]),
        ]);

        $this->assertSame([], $validator->validate([14 => ['qualquer outra medida']]));
    }

    function testTabelaDinamicaExigeListaDeLinhasComColunasObrigatorias()
    {
        $config = ['minRows' => 1, 'columns' => [
            ['key' => 'categoria', 'label' => 'Categoria', 'required' => true],
            ['key' => 'valor_total', 'label' => 'Valor total', 'required' => true, 'readOnly' => true],
        ]];
        $validator = $this->validator([
            $this->questao(['id_questao' => 15, 'tipo_questao' => 'Tabela Dinâmica', 'tag' => 'categorias', 'config' => $config]),
        ]);

        $this->assertArrayHasKey(15, $validator->validate([15 => ['não é linha']]));
        $this->assertArrayHasKey(15, $validator->validate([15 => [['categoria' => '']]]));
        $this->assertSame([], $validator->validate([15 => [['categoria' => 'Música']]]), 'coluna readOnly calculada não é cobrada');
    }

    function testMissingRequiredIgnoraAnexoEDataDePublicacao()
    {
        $validator = $this->validator([
            $this->questao(),
            $this->questao(['id_questao' => 20, 'tipo_questao' => 'anexo', 'tag' => 'planilha']),
            $this->questao(['id_questao' => 30, 'tipo_questao' => 'data', 'tag' => 'data_publicacao_resultado_1_1']),
            $this->questao(['id_questao' => 16, 'tipo_questao' => 'texto', 'tag' => 'opcional', 'requerida' => false]),
        ]);

        $this->assertSame([10], $validator->missingRequired([]));
        $this->assertSame([], $validator->missingRequired([10 => 5]));
    }

    function testMissingRequiredCobraOMinimoDeLinhasDaTabela()
    {
        $config = ['minRows' => 2, 'columns' => []];
        $validator = $this->validator([
            $this->questao(['id_questao' => 15, 'tipo_questao' => 'Tabela Dinâmica', 'tag' => 'categorias', 'config' => $config]),
        ]);

        $this->assertSame([15], $validator->missingRequired([15 => [['a' => 1]]]));
        $this->assertSame([], $validator->missingRequired([15 => [['a' => 1], ['a' => 2]]]));
    }
}
