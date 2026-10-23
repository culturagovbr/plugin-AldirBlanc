<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Resultado;
use Tests\Abstract\TestCase;

/** Contrato do corpo de resultado: round-trip de array no formato da API Conecta MinC. */
class ResultadoDtoTest extends TestCase
{
    private function payloadCompleto(): array
    {
        return [
            'id_tipo' => 2,
            'id_status' => 3,
            'data_publicacao' => '2026-10-16T18:00:00-03:00',
            'respostas' => [
                ['id_par_questao' => 100, 'resposta' => ['resposta' => 'resposta1']],
                ['id_par_questao' => 101, 'resposta' => ['resposta' => 42]],
            ],
            'anexos' => [
                ['nome' => 'resultado-final.pdf', 'caminho' => 'https://mapas.exemplo/files/resultado-final.pdf', 'descricao' => 'Lista de selecionados'],
            ],
        ];
    }

    function testRoundTripPreservaTodosOsCampos()
    {
        $payload = $this->payloadCompleto();

        $this->assertSame($payload, Resultado::fromArray($payload)->toArray());
    }

    function testOpcionaisAusentesViramNulos()
    {
        $dto = Resultado::fromArray(['id_tipo' => 2, 'id_status' => 3]);

        $this->assertSame(2, $dto->id_tipo);
        $this->assertSame(3, $dto->id_status);
        $this->assertNull($dto->data_publicacao);
        $this->assertNull($dto->respostas);
        $this->assertNull($dto->anexos);
    }

    function testConstrucaoDiretaComOsTiposDoContrato()
    {
        $dto = new Resultado(id_tipo: 3, id_status: 3, data_publicacao: '2026-10-16T20:00:00-03:00');

        $this->assertSame(
            [
                'id_tipo' => 3,
                'id_status' => 3,
                'data_publicacao' => '2026-10-16T20:00:00-03:00',
                'respostas' => null,
                'anexos' => null,
            ],
            $dto->toArray()
        );
    }

    function testSemPlanilhaOCorpoNaoGanhaAsChavesDaPlanilha()
    {
        $corpo = (new Resultado(id_tipo: 2, id_status: 3))->toArray();

        $this->assertArrayNotHasKey('tipo_planilha', $corpo, 'chave a mais mudaria o hash e faria todo resultado publicado virar retificado');
        $this->assertArrayNotHasKey('linhas', $corpo);
    }

    function testComPlanilhaOCorpoCarregaOTipoEAsLinhas()
    {
        $linhas = [['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00']];

        $corpo = (new Resultado(id_tipo: 2, id_status: 3, tipo_planilha: 'acao_cultural_realizada', linhas: $linhas))->toArray();

        $this->assertSame('acao_cultural_realizada', $corpo['tipo_planilha']);
        $this->assertSame($linhas, $corpo['linhas']);
    }

    function testLinhasSemOTipoDaPlanilhaNaoEntramNoCorpo()
    {
        $corpo = (new Resultado(id_tipo: 2, id_status: 3, linhas: [['identificador_edital' => 'Edital X']]))->toArray();

        $this->assertArrayNotHasKey('linhas', $corpo, 'as duas chaves só fazem sentido juntas');
        $this->assertArrayNotHasKey('tipo_planilha', $corpo);
    }

    function testRoundTripPreservaAPlanilha()
    {
        $payload = $this->payloadCompleto() + [
            'tipo_planilha' => 'acao_cultural_realizada',
            'linhas' => [['identificador_edital' => 'Edital X']],
        ];

        $this->assertSame($payload, Resultado::fromArray($payload)->toArray());
    }
}
