<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Integration\ManagerSnapshotMapper;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\CapturesLog;

/** O mapeamento é a última fronteira antes do job, e o que ele descarta o job nunca chega a ver. */
class ManagerSnapshotMapperTest extends TestCase
{
    use CapturesLog;

    private function comEnte(mixed ...$entes): array
    {
        return ['nome' => 'GESTOR', 'entes_federados' => $entes];
    }

    function testEnteBemFormadoViraSnapshot()
    {
        $snapshot = ManagerSnapshotMapper::fromResponse(
            $this->comEnte(['document' => '12198693000158', 'name' => 'MUNICIPIO', 'exercicios' => [['id' => 1]]]),
            acceptFlatList: false,
        );

        $this->assertCount(1, $snapshot->entities());
        $this->assertSame('MUNICIPIO', $snapshot->entities()[0]->name);
    }

    /**
     * O construtor do snapshot exige array, então o filtro é obrigatório; o que não pode é ele
     * ser silencioso, porque a validação de contrato do job só enxerga o que passou por aqui.
     */
    function testEnteQueNaoEhArrayEhDescartadoComRegistro()
    {
        $capturado = $this->capturandoLog(function () {
            $snapshot = ManagerSnapshotMapper::fromResponse(
                $this->comEnte('nem array', ['document' => '12198693000158', 'name' => 'MUNICIPIO']),
                acceptFlatList: false,
                documento: '11122233344',
            );

            $this->assertCount(1, $snapshot->entities(), 'o que não é array não vira ente');
        });

        $avisos = array_filter(
            $capturado->getRecords(),
            fn($registro) => str_contains($registro['message'], 'descartado no mapeamento'),
        );

        $this->assertCount(1, $avisos, 'o descarte precisa deixar rastro');
        $this->assertStringContainsString('deve ser array', reset($avisos)['message']);
        $this->assertStringContainsString(
            '11122233344',
            reset($avisos)['message'],
            'sem o dono, o aviso não diz qual sync perdeu o ente',
        );
    }

    /** Sem document o ente sobrevive ao mapeamento: quem o descarta, com motivo, é o job. */
    function testEnteSemDocumentoChegaAoJob()
    {
        $snapshot = ManagerSnapshotMapper::fromResponse(
            $this->comEnte(['name' => 'SEM DOCUMENTO']),
            acceptFlatList: false,
        );

        $this->assertCount(1, $snapshot->entities());
        $this->assertSame('', $snapshot->entities()[0]->document);
    }
}
