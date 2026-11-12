<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Integration\FederativeEntityDocument;
use Tests\Abstract\TestCase;

/** A identidade do ente: as duas grafias do mesmo CNPJ precisam virar a mesma chave. */
class FederativeEntityDocumentTest extends TestCase
{
    function testGrafiaCurtaEGrafiaCompletaViramOMesmoDocumento()
    {
        $this->assertSame(
            FederativeEntityDocument::normalize('01612689000178'),
            FederativeEntityDocument::normalize('1612689000178'),
        );
    }

    function testCompletaComZerosAEsquerdaAteQuatorzeDigitos()
    {
        $this->assertSame('01612689000178', FederativeEntityDocument::normalize('1612689000178'));
    }

    function testDocumentoJaCompletoNaoMuda()
    {
        $this->assertSame('12198693000158', FederativeEntityDocument::normalize('12198693000158'));
    }

    /** Truncar transformaria um ente em outro, e é o que um lpad de 14 faria. */
    function testDocumentoMaiorQueQuatorzeDigitosNaoEhTruncado()
    {
        $this->assertSame('123456789012345', FederativeEntityDocument::normalize('123456789012345'));
    }

    function testPontuacaoEEspacoSaemAntesDaContagem()
    {
        $this->assertSame('01612689000178', FederativeEntityDocument::normalize('1.612.689/0001-78'));
        $this->assertSame('12198693000158', FederativeEntityDocument::normalize(' 12198693000158 '));
    }

    /** Ausência continua ausência: virar quatorze zeros passaria por documento válido. */
    function testDocumentoSemDigitoContinuaVazio()
    {
        $this->assertSame('', FederativeEntityDocument::normalize(null));
        $this->assertSame('', FederativeEntityDocument::normalize(''));
        $this->assertSame('', FederativeEntityDocument::normalize('   '));
        $this->assertSame('', FederativeEntityDocument::normalize('./-'));
    }

    /** Os quatro pares do retorno real: 19 entradas que precisam virar 15 entes. */
    function testOsParesDoRetornoRealColapsam()
    {
        $entradas = array_map(
            fn(string $documento) => ['document' => $documento],
            [
                '01612689000178', '1612689000178',
                '06553481000149', '6553481000149',
                '08883217000107', '8883217000107',
                '08939944000130', '8939944000130',
            ],
        );

        $this->assertCount(4, FederativeEntityDocument::dedupe($entradas));
    }

    public function testCnpjAlfanumericoEhPreservadoEmMaiusculas(): void
    {
        $this->assertSame('12ABC34501DE35', FederativeEntityDocument::normalize('12.abc.345/01de-35'));
        $this->assertSame('12ABC34501DE35', FederativeEntityDocument::normalize('12ABC34501DE35'));
    }

    public function testCnpjsAlfanumericosComOsMesmosDigitosContinuamDistintos(): void
    {
        $this->assertNotSame(
            FederativeEntityDocument::normalize('12ABC34501DE35'),
            FederativeEntityDocument::normalize('12XYZ34501DE35'),
        );
    }

    public function testDocumentoComLetraForaDaFormaDoCnpjAlfanumericoContinuaVazio(): void
    {
        $this->assertSame('', FederativeEntityDocument::normalize('abc'));
        $this->assertSame('', FederativeEntityDocument::normalize('2ABC34501DE35'), 'treze caracteres não ganham zeros');
        $this->assertSame('', FederativeEntityDocument::normalize('12ABC34501DEXY'), 'dígitos verificadores são numéricos');
    }

    public function testDeduplicacaoJuntaSoAMesmaGrafiaDoCnpjAlfanumerico(): void
    {
        $entes = FederativeEntityDocument::dedupe([
            ['document' => '12.abc.345/01de-35'],
            ['document' => '12ABC34501DE35'],
            ['document' => '12XYZ34501DE35'],
        ]);

        $this->assertSame(['12ABC34501DE35', '12XYZ34501DE35'], array_column($entes, 'document'));
    }
}
