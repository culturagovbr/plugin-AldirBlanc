<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;

/**
 * Testes de parsing e normalização de resposta da API (GestorCultJob).
 * Puro: sem App::i(), sem banco, sem rede.
 */
class GestorCultJobParsingTest extends TestCase
{
    private function job(): TestableGestorCultJob
    {
        return new TestableGestorCultJob(new GestorDocument('12345678901'));
    }

    // ===== extractFederativeEntitiesFromResponse =====

    function testExtractFormatoNovoComChaveEntesFederados()
    {
        $entes = [['document' => '1', 'name' => 'Ente 1']];
        $response = ['entes_federados' => $entes, 'rg' => '123'];

        $this->assertSame($entes, $this->job()->callExtractFederativeEntitiesFromResponse($response));
    }

    function testExtractFormatoAntigoArrayDireto()
    {
        $entes = [['document' => '1', 'name' => 'Ente 1'], ['document' => '2', 'name' => 'Ente 2']];

        $this->assertSame($entes, $this->job()->callExtractFederativeEntitiesFromResponse($entes));
    }

    function testExtractRespostaNaoArrayRetornaArrayVazio()
    {
        $this->assertSame([], $this->job()->callExtractFederativeEntitiesFromResponse('string qualquer'));
        $this->assertSame([], $this->job()->callExtractFederativeEntitiesFromResponse(null));
        $this->assertSame([], $this->job()->callExtractFederativeEntitiesFromResponse(123));
        $this->assertSame([], $this->job()->callExtractFederativeEntitiesFromResponse(false));
        $this->assertSame([], $this->job()->callExtractFederativeEntitiesFromResponse(new \stdClass()));
    }

    function testExtractComEntesFederadosNaoArrayLancaErroDeContrato()
    {
        $response = ['entes_federados' => 'não é um array', 'rg' => '123'];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('entes_federados deve ser array');

        $this->job()->callExtractFederativeEntitiesFromResponse($response);
    }

    function testExtractFormatoNovoSemEntesFederadosLancaErroDeContrato()
    {
        $response = ['rg' => '123', 'nome' => 'Gestor Sem Chave De Entes'];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('chave entes_federados ausente');

        $this->job()->callExtractFederativeEntitiesFromResponse($response);
    }

    // ===== normalizeFederativeEntities =====

    function testNormalizeCompletaODocumentoComZerosAEsquerda()
    {
        $entes = [['document' => '1']];

        $this->assertSame(
            [['document' => '00000000000001']],
            $this->job()->callNormalizeFederativeEntities($entes),
        );
    }

    /** As duas grafias do mesmo CNPJ eram chaves distintas, e viravam duas linhas do mesmo ente. */
    function testNormalizeUneAsDuasGrafiasDoMesmoDocumento()
    {
        $entes = [
            ['document' => '01612689000178', 'name' => 'MUNICIPIO DE MATUREIA'],
            ['document' => '1612689000178', 'name' => 'MUNICIPIO DE MATUREIA'],
        ];

        $unidos = $this->job()->callNormalizeFederativeEntities($entes);

        $this->assertCount(1, $unidos);
        $this->assertSame('01612689000178', $unidos[0]['document']);
    }

    /** Há homônimos com CNPJ legitimamente distinto: agrupar por nome uniria entes diferentes. */
    function testNormalizeNaoUneHomonimosComDocumentoDistinto()
    {
        $entes = [
            ['document' => '06553481000149', 'name' => 'ESTADO DO PIAUI'],
            ['document' => '06553481000300', 'name' => 'ESTADO DO PIAUI'],
        ];

        $this->assertCount(2, $this->job()->callNormalizeFederativeEntities($entes));
    }

    /** Descartar o irmão que traz a árvore apagaria o que o gestor precisa para criar oportunidade. */
    function testNormalizePrefereOIrmaoQueTrazAArvore()
    {
        $entes = [
            ['document' => '1612689000178', 'name' => 'SEM ARVORE', 'exercicios' => []],
            ['document' => '01612689000178', 'name' => 'COM ARVORE', 'exercicios' => [['id' => 1]]],
        ];

        $unidos = $this->job()->callNormalizeFederativeEntities($entes);

        $this->assertCount(1, $unidos);
        $this->assertSame('COM ARVORE', $unidos[0]['name']);
        $this->assertSame([['id' => 1]], $unidos[0]['exercicios']);
        $this->assertSame('01612689000178', $unidos[0]['document'], 'o documento sobrevive normalizado');
    }

    /** A ordem da API só decide quando ela não traz árvore em nenhum dos lados. */
    function testNormalizeMantemOPrimeiroQuandoAArvoreNaoDesempata()
    {
        $ambosVazios = $this->job()->callNormalizeFederativeEntities([
            ['document' => '1612689000178', 'name' => 'PRIMEIRO', 'exercicios' => []],
            ['document' => '01612689000178', 'name' => 'SEGUNDO', 'exercicios' => []],
        ]);

        $ambosComArvore = $this->job()->callNormalizeFederativeEntities([
            ['document' => '1612689000178', 'name' => 'PRIMEIRO', 'exercicios' => [['id' => 1]]],
            ['document' => '01612689000178', 'name' => 'SEGUNDO', 'exercicios' => [['id' => 2]]],
        ]);

        $this->assertSame('PRIMEIRO', $ambosVazios[0]['name'], 'nenhum dos dois traz árvore');
        $this->assertSame('PRIMEIRO', $ambosComArvore[0]['name'], 'sem critério para trocar, não se troca');
    }

    /** O irmão com árvore pode vir antes; nesse caso não há nada a fazer. */
    function testNormalizeNaoTrocaQuandoOPrimeiroJaTrazAArvore()
    {
        $unidos = $this->job()->callNormalizeFederativeEntities([
            ['document' => '01612689000178', 'name' => 'COM ARVORE', 'exercicios' => [['id' => 1]]],
            ['document' => '1612689000178', 'name' => 'SEM ARVORE', 'exercicios' => []],
        ]);

        $this->assertCount(1, $unidos);
        $this->assertSame('COM ARVORE', $unidos[0]['name']);
    }

    /** O dedupe não filtra nada: descartar com motivo é de quem vem depois, o mapper e a validação. */
    function testNormalizeNaoEngoleItemSemDocumento()
    {
        $entes = [['name' => 'sem documento'], 'nem array'];

        $this->assertCount(2, $this->job()->callNormalizeFederativeEntities($entes));
    }

    function testNormalizeJsonStringValidaDecodifica()
    {
        $entes = [['document' => '1', 'name' => 'Ente 1']];
        $json = json_encode($entes);

        $this->assertSame($entes, $this->job()->callNormalizeFederativeEntities($json));
    }

    function testNormalizeStringSerializadaUnserializa()
    {
        $entes = [['document' => '1', 'name' => 'Ente 1']];
        $serialized = serialize($entes);

        $this->assertSame($entes, $this->job()->callNormalizeFederativeEntities($serialized));
    }

    function testNormalizeStringInvalidaRetornaArrayVazio()
    {
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities('isso não é JSON nem serialize válido'));
    }

    function testNormalizeJsonStringEscalarTentaUnserializeEFalhaRetornandoArrayVazio()
    {
        // '123' é JSON válido (decodifica pra int 123, não array) — cai pro unserialize, que falha pra essa string.
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities('123'));
    }

    function testNormalizeStringSerializadaParaNaoArrayRetornaArrayVazio()
    {
        // serialize('texto') é uma string serializada válida, mas desserializa pra string, não array.
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities(serialize('texto')));
    }

    function testNormalizeTiposNaoStringNaoArrayRetornamArrayVazio()
    {
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities(null));
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities(123));
        $this->assertSame([], $this->job()->callNormalizeFederativeEntities(false));
    }

    // ===== normalizeStringForComparison =====

    function testNormalizeStringForComparisonNullEVazioSaoIguais()
    {
        $job = $this->job();

        $this->assertSame('', $job->callNormalizeStringForComparison(null));
        $this->assertSame('', $job->callNormalizeStringForComparison(''));
        $this->assertSame($job->callNormalizeStringForComparison(null), $job->callNormalizeStringForComparison(''));
    }

    function testNormalizeStringForComparisonRemoveEspacos()
    {
        $this->assertSame('valor', $this->job()->callNormalizeStringForComparison('  valor  '));
    }

    function testNormalizeStringForComparisonValoresIguaisAposTrim()
    {
        $job = $this->job();

        $this->assertSame(
            $job->callNormalizeStringForComparison('valor'),
            $job->callNormalizeStringForComparison('  valor  ')
        );
    }

    /**
     * Gotcha clássico do PHP: "0" é falsy, mas não é null nem '' — não deve ser tratado como vazio.
     */
    function testNormalizeStringForComparisonZeroComoStringNaoEhTratadoComoVazio()
    {
        $this->assertSame('0', $this->job()->callNormalizeStringForComparison('0'));
        $this->assertNotSame('', $this->job()->callNormalizeStringForComparison('0'));
    }

    function testNormalizeStringForComparisonValorNaoStringEhConvertido()
    {
        $this->assertSame('123', $this->job()->callNormalizeStringForComparison(123));
        $this->assertSame('0', $this->job()->callNormalizeStringForComparison(0));
    }
}
