<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\FormularioEstrutura;
use AldirBlanc\Services\FormularioRespostasValidator;
use AldirBlanc\Services\FormularioTabela;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Validação pura das respostas contra a estrutura do formulário do PAR. */
class FormularioRespostasValidatorTest extends TestCase
{
    use LoadsConectaResponses;

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

    private function real(int $tipoAcao): FormularioRespostasValidator
    {
        return new FormularioRespostasValidator($this->respostaDaConecta("estrutura-tipo-{$tipoAcao}"));
    }

    #[DataProvider('respostasCompletas')]
    public function testRespostaCompletaDaEstruturaRealEAceita(int $tipoAcao, int $idQuestao, mixed $valor): void
    {
        $this->assertSame([], $this->real($tipoAcao)->validate([$idQuestao => $valor]));
        $this->assertArrayNotHasKey($idQuestao, $this->real($tipoAcao)->pendencias([$idQuestao => $valor]));
    }

    public static function respostasCompletas(): array
    {
        $integrante = ['nome' => 'Ana', 'cpf' => '111', 'vinculo' => 'voluntário', 'categoria_grupo' => 'Música'];

        return [
            'radio pelo id' => [1, 2186, 'fe_tec'],
            'radio com complemento' => [1, 2186, ['id' => 'fe_outros', 'complemento' => 'Convênio']],
            'declaração da 1.1' => [1, 2081, ['declaracao_integral']],
            'declaração da 2.1 com as duas' => [12, 2242, ['declaracao_integral', 'declaracao_portaria_206']],
            'declaração da 2.3 com as duas' => [14, 2551, ['declaracao_veracidade_2_3', 'declaracao_portaria_206_2025_2_3']],
            'declaração da 1.2 em checkbox' => [3, 2349, ['declaracao_responsabilidade_veracidade_1_2']],
            'exclusiva sozinha' => [1, 1842, ['nenhum']],
            'lista com complemento' => [12, 2476, ['heteroidentificacao', ['id' => 'outras_estrategias', 'complemento' => 'Banca local']]],
            'comissão da 1.1 em tabela' => [1, 2654, [$integrante]],
            'contratados da 1.2 em tabela dinâmica' => [3, 1764, [['tipo_documento' => 'CNPJ', 'numero_documento' => '11222333000181', 'nome_razao_social' => 'Casa', 'valor_contrato' => 10.5]]],
            'contratados da 3.1 sem linha, com mínimo zero' => [9, 2843, []],
            'CEP' => [5, 2374, '70068-900'],
            'CNPJ com pontuação' => [20, 2765, '11.222.333/0001-81'],
            'CNPJ alfanumérico' => [20, 2765, '12.ABC.345/01DE-35'],
            'CNPJ com dígito zero' => [20, 2765, '11222347000103'],
            'CNPJ alfanumérico em minúsculas' => [20, 2765, '12abc34501de35'],
            'moeda com centavos' => [1, 1840, 10.55],
            'moeda no limite inferior' => [20, 2767, 600000],
            'moeda no limite superior' => [20, 2767, 2000000],
            'texto no limite de caracteres' => [1, 1845, str_repeat('ã', 700)],
            'data' => [1, 2280, '2026-12-31'],
            'booleano' => [1, 1843, false],
        ];
    }

    public function testRespostaLimpaOuVaziaValeComoNaoRespondida(): void
    {
        foreach ([null, ''] as $vazia) {
            $this->assertSame([], $this->real(1)->validate([1838 => $vazia, 2186 => $vazia]));
            $this->assertSame('resposta obrigatória', $this->real(1)->pendencias([2186 => $vazia])[2186]);
        }
    }

    #[DataProvider('respostasInvalidas')]
    public function testRespostaInvalidaDaEstruturaRealERecusada(int $tipoAcao, int $idQuestao, mixed $valor, string $motivo): void
    {
        $erros = $this->real($tipoAcao)->validate([$idQuestao => $valor]);

        $this->assertStringContainsString($motivo, $erros[$idQuestao] ?? 'aceita');
    }

    public static function respostasInvalidas(): array
    {
        $integrante = ['nome' => 'Ana', 'cpf' => '1', 'vinculo' => 'voluntário', 'categoria_grupo' => 'Música'];

        return [
            'radio pelo rótulo' => [1, 2186, 'Termo de Execução Cultural (Lei nº 14.903/2024)', 'opção fora da lista'],
            'radio em lista' => [1, 2186, ['fe_tec'], 'opção fora da lista'],
            'complemento em opção simples' => [1, 2186, ['id' => 'fe_tec', 'complemento' => 'x'], 'não aceita complemento'],
            'complemento além do limite' => [1, 2186, ['id' => 'fe_outros', 'complemento' => str_repeat('a', 121)], '120 caracteres'],
            'complemento que não é texto' => [1, 2186, ['id' => 'fe_outros', 'complemento' => ['x']], 'deve ser texto'],
            'lista em texto' => [1, 2081, 'declaracao_integral', 'lista de opções'],
            'lista indexada por nome' => [1, 2081, ['a' => 'declaracao_integral'], 'lista de opções'],
            'opção desconhecida na lista' => [1, 1842, ['heteroidentificacao', 'libras'], 'opção fora da lista: libras'],
            'exclusiva com outra' => [1, 1842, ['nenhum', 'heteroidentificacao'], 'exclusiva'],
            'a mesma opção duas vezes' => [1, 1842, ['heteroidentificacao', 'heteroidentificacao'], 'mais de uma vez'],
            'tabela em texto' => [1, 2654, 'Ana', 'lista de linhas'],
            'tabela indexada por nome' => [1, 2654, ['a' => $integrante], 'lista de linhas'],
            'linha que não é conjunto' => [1, 2654, [$integrante, 'Ana'], 'lista de linhas'],
            'escolha da coluna fora da lista' => [1, 2654, [['vinculo' => 'parente'] + $integrante], 'linha 1: Vínculo: opção fora da lista'],
            'dropdown da coluna fora da lista' => [3, 1764, [['tipo_documento' => 'RG']], 'opção fora da lista'],
            'coluna de moeda com texto' => [3, 1764, [['valor_contrato' => 'dez']], 'numérico'],
            'coluna de texto além do máximo' => [5, 2372, [['outro_tipo' => str_repeat('a', 121)]], '120 caracteres'],
            'CEP com letra' => [5, 2374, '7006A-900', 'CEP inválido'],
            'CEP com dígito a mais' => [5, 2374, '700689001', 'CEP inválido'],
            'CNPJ com dígito errado' => [20, 2765, '11.222.333/0001-80', 'CNPJ inválido'],
            'CNPJ alfanumérico com dígito errado' => [20, 2765, '12ABC34501DE34', 'CNPJ inválido'],
            'CNPJ zerado' => [20, 2765, '00000000000000', 'CNPJ inválido'],
            'CNPJ com letra no dígito' => [20, 2765, '11222333000A81', 'CNPJ inválido'],
            'moeda abaixo do mínimo' => [20, 2767, 599999.99, 'valor mínimo'],
            'moeda acima do máximo' => [20, 2767, 2000000.01, 'valor máximo'],
            'número com casa decimal' => [1, 1838, 10.5, 'número inteiro'],
            'número negativo em questão com mínimo' => [9, 2838, -1, 'valor mínimo'],
            'texto além do limite' => [1, 1845, str_repeat('a', 701), '700 caracteres'],
            'texto que não é texto' => [1, 1845, 12, 'deve ser texto'],
            'data com hora em questão sem hora' => [1, 2280, '2026-12-31T10:00', 'data deve ser válida'],
        ];
    }

    #[DataProvider('respostasIncompletas')]
    public function testRespostaComecadaGravaEViraPendencia(int $tipoAcao, int $idQuestao, mixed $valor, string $motivo): void
    {
        $this->assertSame([], $this->real($tipoAcao)->validate([$idQuestao => $valor]), 'a incompleta pode ser gravada');
        $this->assertStringContainsString($motivo, $this->real($tipoAcao)->pendencias([$idQuestao => $valor])[$idQuestao] ?? 'completa');
    }

    public static function respostasIncompletas(): array
    {
        $integrante = ['nome' => 'Ana', 'cpf' => '1', 'vinculo' => 'voluntário', 'categoria_grupo' => 'Música'];

        return [
            'uma das duas declarações da 2.1' => [12, 2242, ['declaracao_integral'], 'pelo menos 2'],
            'uma das duas declarações da 2.3' => [14, 2551, ['declaracao_veracidade_2_3'], 'pelo menos 2'],
            'complemento ainda não escrito' => [1, 2186, 'fe_outros', 'falta o complemento'],
            'complemento vazio' => [1, 2186, ['id' => 'fe_outros', 'complemento' => ' '], 'falta o complemento'],
            'célula obrigatória vazia' => [1, 2654, [['nome' => ''] + $integrante], 'linha 1: '],
            'linha sem a coluna obrigatória' => [1, 2654, [$integrante, ['nome' => 'Beto']], 'linha 2: '],
            'tabela abaixo do mínimo de linhas' => [1, 2654, [], 'pelo menos 1 linha'],
            'CEP pela metade' => [5, 2374, '70068', 'CEP incompleto'],
            'CNPJ pela metade' => [20, 2765, '12.ABC', 'CNPJ incompleto'],
            'CNPJ sem o último dígito' => [20, 2765, '1122233300018', 'CNPJ incompleto'],
        ];
    }

    #[DataProvider('estruturasPublicadas')]
    public function testNenhumTipoOuMascaraPublicadoFicaSemTratamento(string $nome): void
    {
        foreach ((new FormularioEstrutura($this->respostaDaConecta($nome)))->questoes() as $id => $questao) {
            $validator = $this->validator([['somente_leitura' => false] + $questao]);

            foreach (['texto', [array_fill_keys($this->colunas($questao), 'texto')]] as $valor) {
                $motivo = $validator->validate([$id => $valor])[$id] ?? '';

                $this->assertDoesNotMatchRegularExpression('/não suportad|não é reconhecida/', $motivo, "{$nome}: {$questao['nome']}");
            }
        }
    }

    public static function estruturasPublicadas(): array
    {
        $tipos = [1, 2, 3, 4, 5, 6, 8, 9, 10, 12, 13, 14, 15, 16, 17, 20];

        return array_combine(
            array_map(fn(int $tipo) => "tipo {$tipo}", $tipos),
            array_map(fn(int $tipo) => ["estrutura-tipo-{$tipo}"], $tipos),
        );
    }

    /** @return list<string> */
    private function colunas(array $questao): array
    {
        return array_column(FormularioTabela::daQuestao($questao)->colunas, 'id');
    }

    public function testTipoDeQuestaoDesconhecidoERecusado(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'assinatura'])]);

        $this->assertSame([10 => 'tipo de questão não suportado'], $validator->validate([10 => 'x']));
    }

    #[DataProvider('mascarasInventadas')]
    public function testMascaraDesconhecidaERecusadaComONomeDaQuestao(array $trocas): void
    {
        $validator = $this->validator([$this->questao($trocas + ['tipo_questao' => 'texto', 'nome' => 'Telefone'])]);

        $this->assertSame([10 => 'Telefone: a máscara "telefone" não é reconhecida'], $validator->validate([10 => '61']));
    }

    public static function mascarasInventadas(): array
    {
        return ['na questão' => [['mascara' => 'telefone']], 'na configuração' => [['config' => ['padraoValidacao' => 'telefone']]]];
    }

    public function testMascaraNenhumaNaoConfereNada(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'texto', 'mascara' => 'nenhum', 'config' => ['padraoValidacao' => 'nenhum']])]);

        $this->assertSame([], $validator->validate([10 => 'qualquer texto']));
    }

    public function testTextoVazioNaoEConferidoPelaMascara(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'texto', 'mascara' => 'cep'])]);

        $this->assertSame([10 => 'resposta obrigatória'], $validator->pendencias([10 => '']));
    }

    private function tabelaComColuna(array $coluna): FormularioRespostasValidator
    {
        return $this->validator([$this->questao([
            'tipo_questao' => 'Tabela Dinâmica',
            'config' => ['minRows' => 0, 'columns' => [$coluna + ['key' => 'c', 'label' => 'Coluna', 'type' => 'text']]],
        ])]);
    }

    #[DataProvider('documentosDeColuna')]
    public function testColunaSoConfereDocumentoOndeHaMascara(?string $mascara, string $documento, string $erro, string $pendencia): void
    {
        $validator = $this->tabelaComColuna(['mask' => $mascara]);

        $this->assertSame($erro, $validator->validate([10 => [['c' => $documento]]])[10] ?? '');
        $this->assertSame($erro ?: $pendencia, $validator->pendencias([10 => [['c' => $documento]]])[10] ?? '');
    }

    public static function documentosDeColuna(): array
    {
        return [
            'sem máscara' => [null, '111', '', ''],
            'cpf certo' => ['cpf', '529.982.247-25', '', ''],
            'cpf errado' => ['cpf', '529.982.247-24', 'linha 1: Coluna: CPF inválido', ''],
            'cpf com letra' => ['cpf', '529A', 'linha 1: Coluna: CPF inválido', ''],
            'cpf pela metade' => ['cpf', '529.982', '', 'linha 1: Coluna: CPF incompleto'],
            'documento com cpf' => ['documento', '52998224725', '', ''],
            'documento com cpf errado' => ['documento', '52998224724', 'linha 1: Coluna: CPF inválido', ''],
            'documento com cnpj alfanumérico' => ['documento', '12ABC34501DE35', '', ''],
            'documento com cnpj errado' => ['documento', '11222333000180', 'linha 1: Coluna: CNPJ inválido', ''],
            'documento pela metade' => ['documento', '5299822', '', 'linha 1: Coluna: CPF ou CNPJ incompleto'],
            'máscara inventada' => ['telefone', '61', 'linha 1: Coluna: a máscara "telefone" não é reconhecida', ''],
        ];
    }

    #[DataProvider('celulasNumericas')]
    public function testColunaNumericaRespeitaOsLimites(array $coluna, mixed $valor, string $erro): void
    {
        $this->assertSame($erro, $this->tabelaComColuna($coluna)->validate([10 => [['c' => $valor]]])[10] ?? '');
    }

    public static function celulasNumericas(): array
    {
        return [
            'no mínimo' => [['type' => 'number', 'min' => 1], 1, ''],
            'abaixo do mínimo' => [['type' => 'number', 'min' => 1], 0, 'linha 1: Coluna: o valor mínimo é 1'],
            'acima do máximo' => [['type' => 'currency', 'max' => 10], 11, 'linha 1: Coluna: o valor máximo é 10'],
            'tipo inventado' => [['type' => 'mapa'], 'x', 'linha 1: Coluna: tipo de coluna não suportado: mapa'],
        ];
    }

    public function testColunaSomenteLeituraNaoEConferida(): void
    {
        $validator = $this->tabelaComColuna(['type' => 'number', 'readOnly' => true, 'required' => true]);

        $this->assertSame([], $validator->validate([10 => [['c' => 'texto']]]));
        $this->assertSame([], $validator->pendencias([10 => [[]]]));
    }

    public function testTabelaAcimaDoMaximoDeLinhasERecusada(): void
    {
        foreach ([
            ['tipo_questao' => 'Tabela Dinâmica', 'config' => ['minRows' => 0, 'maxRows' => 1, 'columns' => []]],
            ['tipo_questao' => 'tabela', 'config' => ['tabela' => ['minLinhas' => 0, 'maxLinhas' => 1, 'colunas' => []]]],
        ] as $trocas) {
            $validator = $this->validator([$this->questao($trocas)]);

            $this->assertSame([], $validator->validate([10 => [['a' => 1]]]));
            $this->assertArrayHasKey(10, $validator->validate([10 => [['a' => 1], ['a' => 2]]]));
        }
    }

    public function testColunaDeTabelaRespeitaOsLimitesDeclarados(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'tabela', 'config' => ['tabela' => ['minLinhas' => 0, 'colunas' => [
            ['id' => 'nome', 'nome' => 'Nome', 'tipo' => 'text', 'maxCaracteres' => 3],
            ['id' => 'valor', 'nome' => 'Valor', 'tipo' => 'currency', 'valorMinimo' => 5, 'valorMaximo' => 9],
            ['id' => 'total', 'nome' => 'Total', 'tipo' => 'currency', 'somenteLeitura' => true, 'obrigatoria' => true],
        ]]]])]);

        $this->assertSame([], $validator->validate([10 => [['nome' => 'Ana', 'valor' => 5]]]));
        $this->assertSame([10 => 'linha 1: Nome: o texto passa de 3 caracteres'], $validator->validate([10 => [['nome' => 'Anas']]]));
        $this->assertSame([10 => 'linha 1: Valor: o valor mínimo é 5'], $validator->validate([10 => [['valor' => 4]]]));
        $this->assertSame([10 => 'linha 1: Valor: o valor máximo é 9'], $validator->validate([10 => [['valor' => 10]]]));
        $this->assertSame([], $validator->pendencias([10 => [['nome' => 'Ana']]]), 'a somente leitura não é cobrada');
    }

    public function testInvalidaPesaMaisQueIncompletaNaMesmaTabela(): void
    {
        $linhas = [['nome' => ''], ['vinculo' => 'parente']];

        $this->assertStringContainsString('linha 2: Vínculo', $this->real(1)->validate([2654 => $linhas])[2654]);
    }

    public function testLimiteDeMarcacoesERespeitado(): void
    {
        $opcao = ['limiteMarcacoes' => 1, 'itens' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]];
        $validator = $this->validator([$this->questao(['tipo_questao' => 'checkbox', 'opcao' => $opcao])]);

        $this->assertSame([], $validator->validate([10 => ['a']]));
        $this->assertSame([10 => 'o limite é de 1 marcações'], $validator->validate([10 => ['a', 'b']]));
    }

    public function testComplementoComMascaraEConferido(): void
    {
        $opcao = ['itens' => [['id' => 'a', 'label' => 'A', 'campoComplementar' => ['nome' => 'CEP do local', 'padraoValidacao' => 'cep']]]];
        $validator = $this->validator([$this->questao(['tipo_questao' => 'radio', 'opcao' => $opcao])]);

        $this->assertSame([10 => 'CEP do local: CEP inválido'], $validator->validate([10 => ['id' => 'a', 'complemento' => 'x']]));
    }

    public function testDataComHoraSoNaQuestaoQueADeclara(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'data', 'config' => ['comHora' => true]])]);

        $this->assertSame([], $validator->validate([10 => '2026-12-31T10:30']));
        $this->assertSame([], $validator->validate([10 => '2026-12-31']));
        $this->assertArrayHasKey(10, $validator->validate([10 => '2026-12-31T25:00']));
    }

    public function testLimitesDeTextoDaQuestaoValemOMaisRestrito(): void
    {
        $validator = $this->validator([$this->questao(['tipo_questao' => 'textarea', 'valor_minimo' => 2, 'valor_maximo' => 4.0, 'config' => ['limiteCaracteres' => 10]])]);

        $this->assertSame([], $validator->validate([10 => 'abcd']));
        $this->assertSame([], $validator->validate([10 => '']));
        $this->assertSame([10 => 'o texto passa de 4 caracteres'], $validator->validate([10 => 'abcde']));
        $this->assertSame([10 => 'o texto precisa de pelo menos 2 caracteres'], $validator->validate([10 => 'a']));
    }

    public function testMinimoNumericoVemDaQuestaoOuDaConfiguracao(): void
    {
        $validator = $this->validator([$this->questao(['valor_minimo' => 3, 'config' => ['numericoMinimo' => 1.0]])]);

        $this->assertSame([10 => 'o valor mínimo é 3'], $validator->validate([10 => 2]));
    }

    public function testPendenciasCobramDocumentoEIgnoramPlanilhaEDataDePublicacao(): void
    {
        $validator = $this->validator([
            $this->questao(),
            $this->questao(['id_questao' => 20, 'tipo_questao' => 'anexo', 'tag' => 'planilha', 'nome' => 'Planilha de resultados']),
            $this->questao(['id_questao' => 21, 'tipo_questao' => 'anexo', 'tag' => 'planilha', 'nome' => 'Planilha/PDF de comissão avaliadora']),
            $this->questao(['id_questao' => 22, 'tipo_questao' => 'múltiplos anexos', 'tag' => 'documentos', 'nome' => 'Documentos', 'requerida' => false]),
            $this->questao(['id_questao' => 23, 'tipo_questao' => 'anexo', 'tag' => 'documentos', 'nome' => 'Documentos', 'somente_leitura' => true]),
            $this->questao(['id_questao' => 30, 'tipo_questao' => 'data', 'tag' => 'data_publicacao_resultado_1_1']),
            $this->questao(['id_questao' => 16, 'tipo_questao' => 'texto', 'tag' => 'opcional', 'requerida' => false]),
        ]);

        $this->assertSame([10, 21], array_keys($validator->pendencias([])));
        $this->assertSame([21 => 'envio de documentos ainda não disponível'], $validator->pendencias([10 => 5]));
    }

    public function testPendenciasCobramOMinimoDeLinhasDaTabela(): void
    {
        $config = ['minRows' => 2, 'columns' => [['key' => 'a', 'label' => 'A', 'type' => 'number']]];
        $validator = $this->validator([
            $this->questao(['id_questao' => 15, 'tipo_questao' => 'Tabela Dinâmica', 'tag' => 'categorias', 'config' => $config]),
        ]);

        $this->assertSame([15 => 'preencha pelo menos 2 linha(s)'], $validator->pendencias([15 => [['a' => 1]]]));
        $this->assertSame([], $validator->pendencias([15 => [['a' => 1], ['a' => 2]]]));
        $this->assertSame([15 => 'resposta obrigatória'], $validator->pendencias([]));
    }

    public function testTabelaComMinimoZeroESatisfeitaPelaListaVazia(): void
    {
        $this->assertArrayNotHasKey(2843, $this->real(9)->pendencias([2843 => []]));
        $this->assertSame('resposta obrigatória', $this->real(9)->pendencias([])[2843]);
    }

    public function testDocumentosRequeridosDa23ContamEAPlanilhaNao(): void
    {
        $pendencias = $this->real(14)->pendencias([]);

        $this->assertSame([2547, 2548], array_values(array_intersect([2546, 2547, 2548, 2549], array_keys($pendencias))));
        $this->assertArrayNotHasKey(1979, $this->real(12)->pendencias([]));
    }

    public function testRequeridaInvisivelNaoConta(): void
    {
        $this->assertArrayNotHasKey(1845, $this->real(1)->pendencias([1843 => true]));
        $this->assertSame('resposta obrigatória', $this->real(1)->pendencias([1843 => false])[1845]);
        $this->assertArrayNotHasKey(1845, $this->real(1)->pendencias([1843 => true, 1845 => 12]), 'nem a resposta inválida da invisível conta');
    }

    #[DataProvider('respostasGravadasComoTexto')]
    public function testTextoGravadoEmOutroTipoEPendencia(int $idQuestao, string $motivo): void
    {
        $this->assertStringContainsString($motivo, $this->real(1)->pendencias([$idQuestao => 'texto livre'])[$idQuestao]);
    }

    public static function respostasGravadasComoTexto(): array
    {
        return [
            'radio' => [2186, 'opção fora da lista'],
            'lista de opções' => [1842, 'lista de opções'],
            'tabela' => [2654, 'lista de linhas'],
        ];
    }

    public function testRespostaInvalidaDeQuestaoOpcionalEPendencia(): void
    {
        $validator = $this->validator([$this->questao(['requerida' => false])]);

        $this->assertSame([], $validator->pendencias([]));
        $this->assertSame([], $validator->pendencias([10 => '']), 'vazia é como não respondida');
        $this->assertSame([10 => 'o valor deve ser numérico'], $validator->pendencias([10 => 'abc']));
    }

    public function testListaDeOpcoesVaziaERespostaObrigatoria(): void
    {
        $this->assertSame('resposta obrigatória', $this->real(12)->pendencias([2242 => []])[2242]);
        $this->assertSame('marque pelo menos 2 opções', $this->real(12)->pendencias([2242 => ['declaracao_integral']])[2242]);
    }

    #[DataProvider('questoesQueBloqueiam')]
    public function testOQueOSistemaNaoTrataBloqueia(array $trocas, string $motivo): void
    {
        $validator = $this->validator([$this->questao($trocas + ['requerida' => false])]);

        $this->assertSame([10 => $motivo], $validator->bloqueios([]));
    }

    public static function questoesQueBloqueiam(): array
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'gt', 'valor' => 1, 'fonteIdQuestaoEtapa' => 1]];
        $complemento = ['id' => 'a', 'label' => 'A', 'campoComplementar' => ['padraoValidacao' => 'telefone']];

        return [
            'tipo' => [['tipo_questao' => 'assinatura'], 'tipo de questão não suportado'],
            'regra' => [['config' => ['regras' => ['itens' => [$regra]]]], 'regra de exibição não suportada'],
            'regra em documento' => [['tipo_questao' => 'anexo', 'config' => ['regras' => ['itens' => [$regra]]]], 'regra de exibição não suportada'],
            'máscara da questão' => [['tipo_questao' => 'texto', 'mascara' => 'telefone'], 'máscara não reconhecida: telefone'],
            'máscara da configuração' => [['tipo_questao' => 'texto', 'config' => ['padraoValidacao' => 'telefone']], 'máscara não reconhecida: telefone'],
            'máscara do complemento' => [['tipo_questao' => 'radio', 'opcao' => ['itens' => [$complemento]]], 'máscara não reconhecida: telefone'],
            'máscara da coluna' => [['tipo_questao' => 'Tabela Dinâmica', 'config' => ['columns' => [['key' => 'a', 'type' => 'text', 'mask' => 'telefone']]]], 'máscara não reconhecida: telefone'],
            'tipo da coluna' => [['tipo_questao' => 'tabela', 'config' => ['tabela' => ['colunas' => [['id' => 'a', 'tipo' => 'mapa']]]]], 'tipo de coluna não suportado: mapa'],
        ];
    }

    public function testQuestaoInvisivelOuSomenteLeituraNaoBloqueia(): void
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => true, 'fonteIdQuestaoEtapa' => 1]];
        $validator = $this->validator([
            $this->questao(['id_questao' => 1, 'id_questao_etapa' => 1, 'tipo_questao' => 'booleano']),
            $this->questao(['id_questao' => 2, 'tipo_questao' => 'assinatura', 'config' => ['regras' => ['itens' => [$regra]]]]),
            $this->questao(['id_questao' => 3, 'tipo_questao' => 'assinatura', 'somente_leitura' => true]),
        ]);

        $this->assertSame([], $validator->bloqueios([1 => false]));
        $this->assertSame([2], array_keys($validator->bloqueios([1 => true])));
    }

    #[DataProvider('estruturasPublicadas')]
    public function testNenhumaEstruturaPublicadaBloqueia(string $nome): void
    {
        $this->assertSame([], (new FormularioRespostasValidator($this->respostaDaConecta($nome)))->bloqueios([]));
    }
}
