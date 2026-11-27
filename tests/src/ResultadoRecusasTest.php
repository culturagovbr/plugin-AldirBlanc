<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\ResultadoRecusas;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Recusas reais do CultBR na forma que o gestor lê. */
class ResultadoRecusasTest extends TestCase
{
    use LoadsConectaResponses;

    private function recusas(int $status, string $resposta, ?int $tipoAcao = null): ResultadoRecusas
    {
        return ResultadoRecusas::daResposta(
            $status,
            $this->corpoDaConecta($resposta),
            $tipoAcao === null ? null : $this->respostaDaConecta("estrutura-tipo-{$tipoAcao}"),
        );
    }

    public function testQuestaoObrigatoriaSaiComONomeDaEstrutura(): void
    {
        $recusas = $this->recusas(422, 'recusa-422-obrigatoria', 1);

        $this->assertSame('O CultBR recusou respostas do formulário.', $recusas->resumo);
        $this->assertSame([[
            'tipo' => 'questao',
            'id_questao' => 2081,
            'nome' => 'Declaração de responsabilidade e veracidade',
            'descricao' => 'Esta questão é obrigatória.',
        ]], $recusas->itens, 'o nome antigo, que o servidor repete, não aparece');
        $this->assertFalse($recusas->isDeOutraVersao);
    }

    public function testCadaQuestaoRecusadaEUmItem(): void
    {
        $nomes = array_column($this->recusas(422, 'recusa-422-tres-obrigatorias', 14)->itens, 'nome');

        $this->assertCount(3, $nomes);
        $this->assertSame(['Outros documentos', 'Planilha de resultados'], array_slice($nomes, 1));
    }

    public function testQuestaoQueAEstruturaNaoTemSaiPeloId(): void
    {
        $item = $this->recusas(422, 'recusa-422-obrigatoria')->itens[0];

        $this->assertSame('Questão 13934', $item['nome']);
        $this->assertNull($item['id_questao'], 'sem a estrutura, não há questão da tela a marcar');
    }

    #[DataProvider('detalhesEmTexto')]
    public function testDetalheEmTextoSaiComoVeio(int $status, string $resposta, string $texto): void
    {
        $recusas = $this->recusas($status, $resposta);

        $this->assertSame($texto, $recusas->resumo);
        $this->assertSame([], $recusas->itens);
    }

    public static function detalhesEmTexto(): array
    {
        return [
            'questão repetida' => [400, 'recusa-400-questao-repetida', 'Uma questão-etapa não pode aparecer duas vezes'],
            'sem modelo' => [422, 'recusa-422-sem-modelo', 'Edital sem modelo de planilha de resultados: Forma de execução da ação 1.1 não respondida no edital'],
            'storage' => [400, 'recusa-400-storage', "Erro ao enviar anexo 'planilha-de-resultados.csv' para o storage"],
            'storage com PDF' => [400, 'recusa-400-storage-pdf', "Erro ao enviar anexo 'publicacao-resultado.pdf' para o storage"],
            'edital inexistente' => [404, 'recusa-404-edital', 'Edital não encontrado'],
            'corpo incompleto' => [422, 'recusa-422-corpo-incompleto', 'id_tipo, id_status e respostas são obrigatórios (respostas_json no multipart)'],
            'questão da planilha' => [422, 'recusa-422-questao-da-planilha', 'O formulário de resultado deste edital não tem a questão da planilha de resultados (tag planilha_de_resultados_conforme_padrao_de_dados ou upload_planilha_csv_<ação>).'],
            'edital que não permite' => [400, 'recusa-400-nao-permite', 'Edital não permite resultados.'],
            'já publicado' => [409, 'recusa-409-publicado', "Status 'publicado' não permite edição"],
        ];
    }

    public function testQuestaoForaDaVersaoEApontada(): void
    {
        $recusas = $this->recusas(400, 'recusa-400-fora-da-versao', 1);

        $this->assertSame('Questões não pertencem à versão do envio', $recusas->resumo);
        $this->assertSame(['Questão 7914'], array_column($recusas->itens, 'nome'));
        $this->assertTrue($recusas->isDeOutraVersao);
    }

    public function testDetalheComMensagemESemIdsNaoEDeOutraVersao(): void
    {
        $recusas = ResultadoRecusas::daResposta(400, '{"detail": {"message": "Recusado"}}', null);

        $this->assertSame('Recusado', $recusas->resumo);
        $this->assertFalse($recusas->isDeOutraVersao);
    }

    #[DataProvider('recusasDeLinhas')]
    public function testRecusaDeLinhaSaiSemOValorRecebido(string $resposta, int $total): void
    {
        $recusas = $this->recusas(422, $resposta);

        $this->assertStringContainsString('planilha de contemplados', $recusas->resumo);
        $this->assertCount($total, $recusas->itens);

        foreach ($this->respostaDaConecta($resposta)['inconsistencias'] as $indice => $inconsistencia) {
            $this->assertSame(
                ['tipo', 'indice', 'inscricao', 'coluna', 'regra', 'descricao'],
                array_keys($recusas->itens[$indice]),
            );
            $this->assertSame($inconsistencia['coluna'], $recusas->itens[$indice]['coluna']);

            if ($inconsistencia['valor_recebido'] !== '') {
                $this->assertStringNotContainsString($inconsistencia['valor_recebido'], json_encode($recusas, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    public static function recusasDeLinhas(): array
    {
        return [
            'quatro inconsistências' => ['recusa-422-linhas-invalidas', 4],
            'obrigatória' => ['recusa-422-linha-obrigatoria', 1],
            'condicional' => ['recusa-422-linha-condicional', 1],
            'valor monetário' => ['recusa-422-linha-valor', 1],
            'opções' => ['recusa-422-linha-opcoes', 2],
            'lista onde cabe um valor' => ['recusa-422-linha-lista', 1],
        ];
    }

    public function testLinhaRecusadaSaiComAInscricaoEONomeDaColuna(): void
    {
        $recusas = ResultadoRecusas::daResposta(422, $this->corpoDaConecta('recusa-422-linhas-invalidas'), null, [
            'inscricoes' => ['on-1', 'on-2'],
            'colunas' => ['cpf' => 'CPF', 'cota' => 'Ampla concorrência ou cota'],
        ]);

        $this->assertSame(
            [['on-2', 'CPF'], ['on-2', 'Ampla concorrência ou cota'], ['on-2', 'valor_total_subsidio'], ['on-2', 'cep_espaco_cultural']],
            array_map(fn(array $item) => [$item['inscricao'], $item['coluna']], $recusas->itens),
        );
        $this->assertNull($this->recusas(422, 'recusa-422-linhas-invalidas')->itens[0]['inscricao']);
    }

    public function testDescricaoContinuaLegivelSemOValor(): void
    {
        $this->assertSame(
            'Campo obrigatório não preenchido (em caso de pessoa jurídica ou mei)',
            $this->recusas(422, 'recusa-422-linha-condicional')->itens[0]['descricao'],
        );
        $this->assertSame(
            "Valor monetário inválido: '…'. Informe um número (ex: 1500.00)",
            $this->recusas(422, 'recusa-422-linha-valor')->itens[0]['descricao'],
        );
        $this->assertStringContainsString('Branca, Preta, Parda', $this->recusas(422, 'recusa-422-linha-opcoes')->itens[1]['descricao'], 'a opção aceita não é o valor recebido');
    }

    #[DataProvider('valoresRecebidos')]
    public function testSoAOcorrenciaInteiraDoValorSai(mixed $valor, string $descricao, string $esperada): void
    {
        $limpa = ResultadoRecusas::semValorRecebido(['coluna' => 'c', 'descricao' => $descricao, 'valor_recebido' => $valor]);

        $this->assertSame(['descricao' => $esperada, 'coluna' => 'c'], $limpa);
    }

    public static function valoresRecebidos(): array
    {
        return [
            'valor dentro de número' => ['1', "Valor '1' inválido (ex: 1500.00)", "Valor '…' inválido (ex: 1500.00)"],
            'número JSON' => [15, 'Recebido 15', 'Recebido …'],
            'lista' => [['a', 'b'], 'Recebidos a e b', 'Recebidos … e …'],
            'valor com caractere de expressão' => ['1.5*', "Valor '1.5*'", "Valor '…'"],
            'vazio' => ['', 'Campo obrigatório', 'Campo obrigatório'],
            'nulo' => [null, 'Campo obrigatório', 'Campo obrigatório'],
            'lista de listas' => [[['a']], 'Array recebido', 'Array recebido'],
            'valor no fim de número' => ['1', "Valor '1', máximo 21", "Valor '…', máximo 21"],
        ];
    }

    public function testDetalheEmListaSaiPorCampo(): void
    {
        $recusas = ResultadoRecusas::daResposta(422, '{"detail": [{"loc": ["body", "id_tipo"], "msg": "Field required", "input": "segredo"}, "texto solto"]}', null);

        $this->assertSame('O CultBR recusou o envio.', $recusas->resumo);
        $this->assertSame([
            ['tipo' => 'geral', 'nome' => 'body.id_tipo', 'descricao' => 'Field required'],
            ['tipo' => 'geral', 'nome' => '', 'descricao' => 'texto solto'],
        ], $recusas->itens);
    }

    #[DataProvider('respostasSemDetalhe')]
    public function testSemDetalheOResumoVemDoStatus(int $status, ?string $corpo, string $resumo): void
    {
        $this->assertSame($resumo, ResultadoRecusas::daResposta($status, $corpo, null)->resumo);
    }

    public static function respostasSemDetalhe(): array
    {
        return [
            'grande demais' => [413, '<html>413 Request Entity Too Large</html>', 'O envio passou do tamanho que o CultBR aceita.'],
            'corpo ilegível' => [400, '<html></html>', 'O CultBR recusou o envio (HTTP 400).'],
            'sem corpo' => [404, null, 'O CultBR recusou o envio (HTTP 404).'],
            'detalhe vazio' => [422, '{"detail": " "}', 'O CultBR recusou o envio (HTTP 422).'],
            'detalhe em lista vazia' => [422, '{"detail": []}', 'O CultBR recusou o envio (HTTP 422).'],
        ];
    }

    #[DataProvider('listasComItemIlegivel')]
    public function testItemIlegivelNaoDerrubaALeitura(string $corpo): void
    {
        $this->assertCount(1, ResultadoRecusas::daResposta(422, $corpo, null)->itens);
    }

    public static function listasComItemIlegivel(): array
    {
        return [
            'questões' => ['{"errors": ["x", {"field": "questao:1", "message": "m"}]}'],
            'linhas' => ['{"inconsistencias": ["x", {"indice": 0, "coluna": "c", "descricao": "d"}]}'],
        ];
    }

    public function testListaDeRecusasTemTeto(): void
    {
        $inconsistencias = array_fill(0, 150, ['indice' => 0, 'coluna' => 'cpf', 'regra' => 'cpf_valido', 'descricao' => 'CPF inválido']);

        $this->assertCount(100, ResultadoRecusas::daResposta(422, json_encode(['inconsistencias' => $inconsistencias]), null)->itens);
    }
}
