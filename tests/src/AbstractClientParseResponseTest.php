<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
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

    function testCorpoVazioComStatusDeSucessoLancaAusenciaDeResposta()
    {
        try {
            $this->client()->callParseResponse('', 200);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('API não retornou resposta', $e->getMessage());
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

    /** `detail` real das duas APIs quando o CPF não existe na base do CultBR. */
    function testHttp404ComDetailDeNegocioLancaExcecao()
    {
        $response = json_encode(['detail' => 'Pessoa com o CPF fornecido não encontrada no sistema.']);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->client()->callParseResponse($response, 404);
    }

    /** É o corpo que a Conecta devolve para rota inexistente, e o que mais se parece com ausência de dados. */
    function testHttp404NotFoundGenericoLancaExcecao()
    {
        $response = json_encode(['detail' => 'Not Found']);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->client()->callParseResponse($response, 404);
    }

    function testHttp200ComEntesVaziosRetornaRespostaIntacta()
    {
        $response = json_encode(['entes_federados' => []]);

        $result = $this->client()->callParseResponse($response, 200);

        $this->assertSame(['entes_federados' => []], $result);
    }

    /** `detail` em lista é a forma do 422 do FastAPI; num 404 continua sendo erro. */
    function testHttp404ComDetailEmListaLancaExcecao()
    {
        $response = json_encode(['detail' => ['motivo' => 'algo']]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->client()->callParseResponse($response, 404);
    }

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

    function testChaveErrorComValorNullUsaOStatusNaMensagem()
    {
        $response = json_encode(['error' => null, 'rg' => '123']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Erro HTTP 400');

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


    function testHttp422ComDetailEmListaTrazOsCamposNaMensagem()
    {
        $response = json_encode(['detail' => [
            ['type' => 'int_parsing', 'loc' => ['path', 'id_mapas'], 'msg' => 'Input should be a valid integer'],
        ]]);

        try {
            $this->client()->callParseResponse($response, 422);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertStringContainsString('path.id_mapas', $e->getMessage());
            $this->assertStringContainsString('valid integer', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
    }

    function testHttp401ComDetailStringTrazAMensagemDaApi()
    {
        $response = json_encode(['detail' => 'Token expirado']);

        try {
            $this->client()->callParseResponse($response, 401);
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('Token expirado', $e->getMessage());
            $this->assertSame(401, $e->getCode());
        }
    }

    /** A lib de curl entrega string vazia, não null: sem isso a exceção sairia sem texto. */
    function testMensagemNaoFicaVaziaQuandoOCurlNaoTrazTexto()
    {
        try {
            $this->client()->callParseResponse('Internal Server Error', 500, true, '');
            $this->fail('Esperava uma exceção');
        } catch (\Exception $e) {
            $this->assertSame('Erro HTTP 500', $e->getMessage());
        }
    }

    function testCorpoNaoJsonComStatusDeErroPreservaStatusECorpo()
    {
        try {
            $this->client()->callParseResponse('Internal Server Error', 500);
            $this->fail('Esperava uma exceção');
        } catch (IntegrationError $e) {
            $this->assertSame(500, $e->httpStatus());
            $this->assertSame('Internal Server Error', $e->rawBody());
            $this->assertSame(IntegrationError::KIND_HTTP, $e->kind());
        }
    }

    /** Trocar host é onde redirect aparece, e sem FOLLOWLOCATION ele chegaria como resposta boa. */
    function testRespostaDeRedirecionamentoNaoEhSucesso()
    {
        try {
            $this->client()->callParseResponse(json_encode(['location' => 'https://outro']), 301);
            $this->fail('Esperava uma exceção');
        } catch (IntegrationError $e) {
            $this->assertSame(301, $e->httpStatus());
            $this->assertSame(IntegrationError::KIND_HTTP, $e->kind());
        }
    }

    function testChaveMessageComStatusDeSucessoNaoLancaExcecao()
    {
        $payload = ['message' => 'planilha gerada', 'url' => 'https://exemplo'];

        $this->assertSame($payload, $this->client()->callParseResponse(json_encode($payload), 200));
    }

    function testErroDeTransporteEhClassificadoComoTal()
    {
        try {
            $this->client()->callParseResponse('', 0, true, 'Connection timed out', 28);
            $this->fail('Esperava uma exceção');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_TRANSPORT, $e->kind());
            $this->assertSame(28, $e->getCode());
            $this->assertNull($e->httpStatus());
        }
    }

    function testHandleErrorPreservaOErroOriginal()
    {
        $original = IntegrationError::http('Token inválido', 401, '{"detail":"Token inválido"}');

        try {
            $this->client()->callHandleError($original);
            $this->fail('Esperava uma exceção');
        } catch (IntegrationError $e) {
            $this->assertSame($original, $e);
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('{"detail":"Token inválido"}', $e->rawBody());
        }
    }

    function testHandleErrorEnvolveErroDesconhecidoSemPerderACausa()
    {
        $original = new \RuntimeException('endpoint não configurado');

        try {
            $this->client()->callHandleError($original);
            $this->fail('Esperava uma exceção');
        } catch (IntegrationError $e) {
            $this->assertSame('endpoint não configurado', $e->getMessage());
            $this->assertSame($original, $e->getPrevious());
        }
    }
}
