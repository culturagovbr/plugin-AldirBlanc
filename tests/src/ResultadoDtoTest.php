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
        $this->assertSame([], $dto->arquivos);
    }

    public function testConstrucaoDiretaComOsTiposDoContrato(): void
    {
        $dto = new Resultado(id_tipo: 3, id_status: 3, data_publicacao: '2026-10-16T20:00:00-03:00');

        $this->assertSame(
            ['id_tipo' => 3, 'id_status' => 3, 'data_publicacao' => '2026-10-16T20:00:00-03:00', 'respostas' => null],
            $dto->toArray(),
            'linhas só entram quando há o que enviar',
        );
    }

    public function testLinhasEntramSemOTipoDaPlanilha(): void
    {
        $linhas = [['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00']];

        $corpo = (new Resultado(id_tipo: 2, id_status: 3, linhas: $linhas))->toArray();

        $this->assertSame($linhas, $corpo['linhas']);
        $this->assertArrayNotHasKey('tipo_planilha', $corpo);
    }

    public function testArquivoNaoEntraNoCorpo(): void
    {
        $dto = new Resultado(id_tipo: 2, id_status: 3, arquivos: [['nome' => 'ata.pdf', 'caminho' => '/tmp/ata.pdf', 'tipo' => 'application/pdf', 'descricao' => 'Ata']]);

        $this->assertSame(['id_tipo', 'id_status', 'data_publicacao', 'respostas'], array_keys($dto->toArray()));
    }

    public function testListaVaziaDeLinhasNaoEntraNoCorpo(): void
    {
        $this->assertArrayNotHasKey('linhas', (new Resultado(id_tipo: 2, id_status: 3, linhas: []))->toArray());
    }

    public function testRoundTripPreservaAsLinhas(): void
    {
        $payload = $this->payloadCompleto() + ['linhas' => [['identificador_edital' => 'Edital X']]];

        $this->assertSame($payload, Resultado::fromArray($payload)->toArray());
    }
}
