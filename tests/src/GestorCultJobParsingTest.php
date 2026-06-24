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

    function testNormalizeJaArrayRetornaComoEsta()
    {
        $entes = [['document' => '1']];

        $this->assertSame($entes, $this->job()->callNormalizeFederativeEntities($entes));
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
