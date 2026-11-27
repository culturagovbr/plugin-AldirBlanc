<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\FaixasDoEdital;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;

/** Valor por contemplado de cada faixa do edital. */
class FaixasDoEditalTest extends TestCase
{
    #[DataProvider('faixas')]
    public function testValorPorContempladoEOValorDaFaixaDivididoPelasVagas(array $faixa, ?float $esperado): void
    {
        $faixas = new FaixasDoEdital([['label' => ' Música '] + $faixa]);

        $this->assertSame(['Música'], $faixas->rotulos());
        $this->assertSame($esperado, $faixas->valorPorContemplado('Música'));
        $this->assertNull($faixas->valorPorContemplado('Faixa que o edital não tem'));
    }

    public static function faixas(): array
    {
        return [
            'divisão exata' => [['limit' => 60, 'value' => 240000], 4000.0],
            'divisão com arredondamento' => [['limit' => 3, 'value' => 1000], 333.33],
            'números em texto' => [['limit' => '40', 'value' => '60000'], 1500.0],
            'sem valor' => [['limit' => 3, 'value' => null], null],
            'valor vazio' => [['limit' => 3, 'value' => ''], null],
            'sem vagas' => [['value' => 1000], null],
            'vagas zero' => [['limit' => 0, 'value' => 1000], null],
        ];
    }

    public function testTodasAsVagasPreenchidasDaoOValorDaFaixa(): void
    {
        $faixas = new FaixasDoEdital([['label' => 'A', 'limit' => 3, 'value' => 1000], ['label' => 'B', 'limit' => 2, 'value' => 500]]);

        $this->assertSame(1500.0, $faixas->totalRepassado(['A' => 3, 'B' => 2]), 'a soma vem antes do arredondamento');
        $this->assertSame(666.67, $faixas->totalRepassado(['A' => 2]));
        $this->assertSame(0.0, $faixas->totalRepassado([]));
    }

    public function testFaixaSemValorDeixaOTotalEmAberto(): void
    {
        $faixas = new FaixasDoEdital([['label' => 'A', 'limit' => 3, 'value' => 1000], ['label' => 'B', 'limit' => 2]]);

        $this->assertNull($faixas->totalRepassado(['A' => 3, 'B' => 2]));
    }

    #[DataProvider('semFaixas')]
    public function testEditalSemFaixaComRotuloNaoTemFaixas(mixed $registrationRanges): void
    {
        $this->assertSame([], (new FaixasDoEdital($registrationRanges))->rotulos());
    }

    public static function semFaixas(): array
    {
        return ['nulo' => [null], 'lista vazia' => [[]], 'sem rótulo' => [[['limit' => 1, 'value' => 1], ['label' => ' ']]], 'texto' => ['x']];
    }
}
