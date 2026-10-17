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
                ['id_par_questao' => 100, 'resposta' => ['valor' => 'resposta1']],
                ['id_par_questao' => 101, 'resposta' => ['valor' => 42]],
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
}
