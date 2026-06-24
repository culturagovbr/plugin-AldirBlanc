<?php

namespace Tests\AldirBlanc;

use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableAbstractClient;

/**
 * Testes de AbstractClient::parseResponse.
 * Puro em relação ao curl: recebe $httpCode/$curlError/$curlErrorMessage como parâmetros explícitos.
 */
class AbstractClientParseResponseTest extends TestCase
{
    private function client(): TestableAbstractClient
    {
        return new TestableAbstractClient();
    }

    function testJsonValidoComDadosRetornaArrayDecodificado()
    {
        $payload = ['rg' => '123', 'nome' => 'Fulano'];

        $result = $this->client()->callParseResponse(json_encode($payload), 200);

        $this->assertSame($payload, $result);
    }

    function testRespostaStringVaziaSemCurlErrorMessageUsaErroHttpNaMensagem()
    {
        try {
            $this->client()->callParseResponse('', 200);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('Erro HTTP 200', $e->getMessage());
            $this->assertSame(200, $e->getCode());
        }
    }

    function testRespostaStringVaziaComCurlErrorMessageUsaMensagemDoCurl()
    {
        try {
            $this->client()->callParseResponse('', 503, true, 'Falha de conexão');
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('Falha de conexão', $e->getMessage());
            $this->assertSame(503, $e->getCode());
        }
    }

    function testJsonMalformadoLancaExcecao()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resposta da API não é um JSON válido');

        $this->client()->callParseResponse('{json invalido', 200);
    }

    function testHttp404ComDetailNaoEncontradoRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'Pessoa não encontrada']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailRealDaCultBrRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'Pessoa com o CPF fornecido não encontrada no sistema.']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailNotFoundEmInglesRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'Resource not found']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailNaoEncontradaFormaFemininaRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'Entidade não encontrada']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailCaseInsensitiveAsciiRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'RESOURCE NOT FOUND']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailMaiusculoAcentuadoRetornaArrayVazio()
    {
        $response = json_encode(['detail' => 'PESSOA NÃO ENCONTRADA']);

        $result = $this->client()->callParseResponse($response, 404);

        $this->assertSame([], $result);
    }

    function testHttp404ComDetailNaoStringNaoAtivaCasoDeAusencia()
    {
        $response = json_encode(['detail' => ['motivo' => 'algo']]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->client()->callParseResponse($response, 404);
    }

    /**
     * Nuance real do código: a checagem de $httpCode >= 400 só é alcançada quando $response
     * já é array/objeto (não uma string JSON) — dentro do bloco is_string(), qualquer JSON válido
     * sem chave error/message/erro retorna antes de chegar nesse check (ver branch abaixo:
     * testHttp404ComDetailNaoRelacionadoEStringRetornaComoSucesso).
     */
    function testHttp404ComArrayJaDecodificadoLancaExcecaoComCodigo()
    {
        try {
            $this->client()->callParseResponse(['detail' => 'Algum outro motivo'], 404);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    function testHttpErroMaiorOuIgual400ComArrayJaDecodificadoLancaExcecaoComCodigo()
    {
        try {
            $this->client()->callParseResponse(['algo' => 'sem chave de erro'], 500);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame(500, $e->getCode());
        }
    }

    function testHttp404ComDetailNaoRelacionadoEStringLancaExcecao()
    {
        $response = json_encode(['detail' => 'Algum outro motivo']);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->client()->callParseResponse($response, 404);
    }

    function testHttpErro500ComStringJsonValidaLancaExcecao()
    {
        $response = json_encode(['algo' => 'sem chave de erro']);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(500);

        $this->client()->callParseResponse($response, 500);
    }

    function testRespostaComChaveErrorLancaExcecaoComMensagemDaApi()
    {
        $this->expectExceptionMessage('Mensagem de erro da API');

        $this->client()->callParseResponse(json_encode(['error' => 'Mensagem de erro da API']), 400);
    }

    function testRespostaComChaveMessageLancaExcecaoComMensagemDaApi()
    {
        $this->expectExceptionMessage('Outra mensagem de erro');

        $this->client()->callParseResponse(json_encode(['message' => 'Outra mensagem de erro']), 400);
    }

    function testRespostaComChaveErroEmPortuguesLancaExcecaoComMensagemDaApi()
    {
        $this->expectExceptionMessage('Mensagem em português');

        $this->client()->callParseResponse(json_encode(['erro' => 'Mensagem em português']), 400);
    }

    function testPrioridadeMessageSobreErrorEErro()
    {
        $response = json_encode(['error' => 'erro', 'message' => 'mensagem', 'erro' => 'erro pt']);

        try {
            $this->client()->callParseResponse($response, 400);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('mensagem', $e->getMessage());
        }
    }

    function testChaveErrorComValorNullLancaExcecaoGenerica()
    {
        $response = json_encode(['error' => null, 'rg' => '123']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Erro na resposta da API');

        $this->client()->callParseResponse($response, 400);
    }

    function testJsonStringNullRetornaArrayVazio()
    {
        $result = $this->client()->callParseResponse('null', 200);

        $this->assertSame([], $result);
    }

    function testJsonStringNullComErroHttpLancaExcecao()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(500);

        $this->client()->callParseResponse('null', 500);
    }

    /**
     * JSON válido que decodifica para escalar (não array, não null) não é tratado por nenhum
     * branch dentro de is_string() — cai no fallthrough até "formato não reconhecido".
     */
    function testJsonStringEscalarSemErroHttpLancaFormatoNaoReconhecido()
    {
        $this->expectExceptionMessage('Formato de resposta da API não reconhecido');

        $this->client()->callParseResponse('"apenas um texto"', 200);
    }

    function testObjetoPuroSemErroRetornaComoEsta()
    {
        $response = new \stdClass();
        $response->a = 1;

        $this->assertSame($response, $this->client()->callParseResponse($response, 200));
    }

    function testTipoInesperadoSemErroHttpLancaFormatoNaoReconhecido()
    {
        $this->expectExceptionMessage('Formato de resposta da API não reconhecido');

        $this->client()->callParseResponse(123, 200);
    }

    function testRespostaNullLancaExcecaoDeAusenciaDeResposta()
    {
        $this->expectExceptionMessage('API não retornou resposta');

        $this->client()->callParseResponse(null, 0);
    }

    function testCurlErrorTrueSemRespostaDeErroHttpLancaExcecao()
    {
        try {
            $this->client()->callParseResponse(['ok' => true], 200, true, 'Timeout de conexão', 28);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('Timeout de conexão', $e->getMessage());
            $this->assertSame(28, $e->getCode());
        }
    }

    function testArrayPuroSemErroRetornaComoEsta()
    {
        $response = ['a' => 1, 'b' => 2];

        $this->assertSame($response, $this->client()->callParseResponse($response, 200));
    }
}
