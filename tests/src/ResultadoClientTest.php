<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use AldirBlanc\Integration\SendsResultado;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Traits\BuildsAnexos;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Envio de resultado pela Conecta: só-POST num edital do PAR, sobre a fundação da fase 1. */
class ResultadoClientTest extends TestCase
{
    use BuildsAnexos;
    use CapturesLog;
    use ConfiguresPlugin;
    use LoadsConectaResponses;

    private const HOST = 'http://cultbr.invalid';

    private function comConecta(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live'],
                'conecta',
            ),
            $exercicio,
        );
    }

    private function payload(): Resultado
    {
        return new Resultado(id_tipo: 2, id_status: 3, data_publicacao: '2026-10-16T18:00:00-03:00');
    }

    private function envia(FakeTransport $transporte)
    {
        return (new ConectaProvider($transporte))->sendResultado(new ParEditalId(53790), $this->payload());
    }

    function testEnvioCriaPeloPostNoEndpointDoEdital()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201, body: '{"id_par_edital_resultado": 10}');

            $outcome = $this->envia($transporte);

            $this->assertSame(['POST'], $transporte->metodos(), 'resultado publicado é imutável: nunca há PUT');
            $this->assertSame(self::HOST . '/editais/53790/resultado', $transporte->ultimaUrl());
            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame('POST', $outcome->method);
        });
    }

    function testEnvioHerdaBearerEContentTypeDaFundacao()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201);

            $this->envia($transporte);

            $cabecalhos = implode('|', $transporte->ultimosCabecalhos());
            $this->assertStringContainsString('Bearer token-de-teste', $cabecalhos);
            $this->assertStringContainsString('application/json', $cabecalhos);
        });
    }

    function testEndpointAusenteNaConfigFalhaComoConfiguracao()
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live', 'criarResultadoEndpoint' => null],
                'conecta',
            ),
            function () {
                try {
                    $this->envia(new FakeTransport(status: 201));
                    $this->fail('Esperava falha de configuração sem o endpoint declarado');
                } catch (IntegrationError $e) {
                    $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                    $this->assertStringContainsString('conecta.criarResultadoEndpoint', $e->getMessage());
                }
            },
        );
    }

    function testEnvioQueFalhaRegistraATentativaComoErro()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 422, body: '{"detail":[{"msg":"campo inválido"}]}');

            try {
                $this->envia($transporte);
                $this->fail('Esperava SendFailed com a tentativa registrada');
            } catch (SendFailed $e) {
                $this->assertSame(SendResult::Error, $e->outcome()->result);
                $this->assertSame('POST', $e->outcome()->method);
                $this->assertSame(422, $e->outcome()->httpStatus);
            }
        });
    }

    function testEnvioEmModoSimuladoNaoSaiPelaRede()
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'development'],
                'conecta',
            ),
            function () {
                $transporte = new FakeTransport(status: 201);

                $outcome = $this->envia($transporte);

                $this->assertSame([], $transporte->metodos(), 'modo simulado não sai pela rede');
                $this->assertSame(SendResult::Simulated, $outcome->result);
                $this->assertSame('POST', $outcome->method);
            },
        );
    }

    private function arquivoDoEnvio(string $nome, string $conteudo, string $descricao, string $tipo = 'application/pdf'): array
    {
        return ['nome' => $nome, 'caminho' => $this->upload($nome, $conteudo)['tmp_name'], 'tipo' => $tipo, 'descricao' => $descricao];
    }

    private function enviaComArquivos(array $arquivos, FakeTransport $transporte, ?string $data = '2026-10-16T18:00:00-03:00'): SendOutcome
    {
        $respostas = [['id_par_questao' => 13927, 'resposta' => ['resposta' => 'ação.pdf']]];

        try {
            return (new ConectaProvider($transporte))->sendResultado(
                new ParEditalId(53790),
                new Resultado(id_tipo: 2, id_status: 3, data_publicacao: $data, respostas: $respostas, arquivos: $arquivos),
            );
        } catch (SendFailed $e) {
            return $e->outcome();
        }
    }

    /** @return list<array{0: string, 1: string}> cabeçalho e conteúdo de cada parte, na ordem do corpo */
    private function partes(FakeTransport $transporte): array
    {
        $tipo = $transporte->ultimosCabecalhos()['Content-Type'];
        $this->assertMatchesRegularExpression('/^multipart\/form-data; boundary=[0-9a-f]{32}$/', $tipo);
        $boundary = explode('boundary=', $tipo)[1];
        $corpo = $transporte->requisicoes[0]['body'];
        $this->assertStringEndsWith("--{$boundary}--\r\n", $corpo);

        return array_map(
            fn(string $parte) => explode("\r\n\r\n", substr($parte, 0, -2), 2),
            array_filter(explode("--{$boundary}\r\n", substr($corpo, 0, -strlen("--{$boundary}--\r\n")))),
        );
    }

    public function testComArquivoOCorpoVaiEmMultipartComOsParesNaOrdem(): void
    {
        $this->comConecta(function () {
            $binario = implode('', array_map(chr(...), range(0, 255))) . "\r\n--x\r\n\r\n";
            $transporte = new FakeTransport(status: 201, body: '{"id_par_edital_resultado": 10}');

            $outcome = $this->enviaComArquivos([
                $this->arquivoDoEnvio('publicacao.pdf', $binario, 'Publicação'),
                $this->arquivoDoEnvio('publicacao-2.pdf', 'segundo', 'Publicação'),
                $this->arquivoDoEnvio('lista.csv', 'a;b', 'Outros documentos', 'text/csv'),
            ], $transporte);

            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame([
                ['Content-Disposition: form-data; name="id_tipo"', '2'],
                ['Content-Disposition: form-data; name="id_status"', '3'],
                ['Content-Disposition: form-data; name="data_publicacao"', '2026-10-16T18:00:00-03:00'],
                ['Content-Disposition: form-data; name="respostas_json"', '[{"id_par_questao":13927,"resposta":{"resposta":"ação.pdf"}}]'],
                ["Content-Disposition: form-data; name=\"arquivos\"; filename=\"publicacao.pdf\"\r\nContent-Type: application/pdf", $binario],
                ['Content-Disposition: form-data; name="descricoes"', 'Publicação'],
                ["Content-Disposition: form-data; name=\"arquivos\"; filename=\"publicacao-2.pdf\"\r\nContent-Type: application/pdf", 'segundo'],
                ['Content-Disposition: form-data; name="descricoes"', 'Publicação'],
                ["Content-Disposition: form-data; name=\"arquivos\"; filename=\"lista.csv\"\r\nContent-Type: text/csv", 'a;b'],
                ['Content-Disposition: form-data; name="descricoes"', 'Outros documentos'],
            ], array_values($this->partes($transporte)));
            $this->assertStringContainsString('Bearer token-de-teste', implode('|', $transporte->ultimosCabecalhos()));
        });
    }

    public function testNomeDoArquivoNaoQuebraOCabecalho(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201, body: '{}');

            $this->enviaComArquivos([['nome' => "a\"b\r\nX: y.pdf"] + $this->arquivoDoEnvio('a.pdf', 'x', 'd')], $transporte, null);

            $partes = array_column($this->partes($transporte), 0);
            $this->assertContains("Content-Disposition: form-data; name=\"arquivos\"; filename=\"a%22b%0D%0AX: y.pdf\"\r\nContent-Type: application/pdf", $partes);
            $this->assertSame([], preg_grep('/data_publicacao/', $partes), 'sem data, o campo não vai');
        });
    }

    #[DataProvider('desfechosComArquivo')]
    public function testHistoricoGuardaDoArquivoSoONome(string $modo, int $status): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente($config, ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => $modo], 'conecta'),
            function () use ($status) {
                $registro = $this->enviaComArquivos([$this->arquivoDoEnvio('ata.pdf', 'CONTEUDO', 'Ata')], new FakeTransport(status: $status, body: '{"detail": "x"}'));

                $this->assertSame(['ata.pdf'], $registro->payload['arquivos']);
                $this->assertStringNotContainsString('CONTEUDO', json_encode($registro->payload));
            },
        );
    }

    public static function desfechosComArquivo(): array
    {
        return ['aceite' => ['live', 201], 'recusa' => ['live', 400], 'simulado' => ['development', 201]];
    }

    public function testSemArquivoOCorpoEORegistroSeguemEmJson(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201, body: '{}');

            $registro = $this->enviaComArquivos([], $transporte);

            $this->assertSame('application/json', $transporte->ultimosCabecalhos()['Content-Type']);
            $this->assertSame(2, json_decode($transporte->requisicoes[0]['body'], true)['id_tipo']);
            $this->assertStringContainsString('ação.pdf', $transporte->requisicoes[0]['body']);
            $this->assertArrayNotHasKey('arquivos', $registro->payload);
        });
    }

    public function testArquivoIlegivelNaoSaiPelaRede(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 201, body: '{}');

            try {
                @$this->enviaComArquivos([['caminho' => '/nao/existe.pdf'] + $this->arquivoDoEnvio('a.pdf', 'x', 'd')], $transporte);
                $this->fail('Esperava a falha de leitura');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('a.pdf', $e->getMessage());
            }

            $this->assertSame([], $transporte->requisicoes);
        });
    }

    private const LINHAS = [['cpf' => '52998224725', 'nome_proponente' => 'Ana Paula Ferreira'], ['cpf' => '11144477735', 'nome_proponente' => 'Bruno']];

    private function payloadComPessoas(): Resultado
    {
        return new Resultado(id_tipo: 2, id_status: 3, respostas: [
            ['id_par_questao' => 13933, 'resposta' => ['resposta' => [
                ['nome' => 'Ana Paula Ferreira', 'cpf' => '529.982.247-25', 'vinculo' => 'servidor público'],
            ]]],
            ['id_par_questao' => 13921, 'resposta' => ['resposta' => ['Heteroidentificação para pessoas negras']]],
            ['id_par_questao' => 13920, 'resposta' => ['resposta' => 15000]],
        ], linhas: self::LINHAS);
    }

    private function enviaComPessoas(FakeTransport $transporte): SendOutcome
    {
        try {
            return (new ConectaProvider($transporte))->sendResultado(new ParEditalId(53790), $this->payloadComPessoas());
        } catch (SendFailed $e) {
            return $e->outcome();
        }
    }

    #[DataProvider('respostasDoCultBr')]
    public function testHistoricoNaoGuardaDadoDePessoaEOCorpoSaiInteiro(int $status, string $resposta): void
    {
        $this->comConecta(function () use ($status, $resposta) {
            $transporte = new FakeTransport(status: $status, body: $resposta);

            $registro = $this->enviaComPessoas($transporte);

            $this->assertSame($this->payloadComPessoas()->toArray(), json_decode($transporte->requisicoes[0]['body'], true), 'a redução é só do registro');
            $this->assertSame(['total' => 2, 'hash' => hash('sha256', json_encode(self::LINHAS))], $registro->payload['linhas']);
            $this->assertSame(
                ['nome' => 'Ana Paula Ferreira', 'cpf' => '***.***.***-**', 'vinculo' => 'servidor público'],
                $registro->payload['respostas'][0]['resposta']['resposta'][0],
            );
            $this->assertSame(['Heteroidentificação para pessoas negras'], $registro->payload['respostas'][1]['resposta']['resposta']);
            $this->assertSame(15000, $registro->payload['respostas'][2]['resposta']['resposta']);
        });
    }

    public static function respostasDoCultBr(): array
    {
        return [
            'aceite' => [201, '{"id_par_edital_resultado": 10}'],
            'recusa' => [422, '{"detail": "recusado"}'],
        ];
    }

    #[DataProvider('celulas')]
    public function testDocumentoEReconhecidoPeloConteudoDaCelula(mixed $celula, mixed $registrada): void
    {
        $this->comConecta(function () use ($celula, $registrada) {
            $registro = (new ConectaProvider(new FakeTransport(status: 201)))->sendResultado(
                new ParEditalId(53790),
                new Resultado(id_tipo: 2, id_status: 3, respostas: [['id_par_questao' => 1, 'resposta' => ['resposta' => [['c' => $celula]]]]]),
            );

            $this->assertSame($registrada, $registro->payload['respostas'][0]['resposta']['resposta'][0]['c']);
        });
    }

    public static function celulas(): array
    {
        return [
            'CPF com pontuação' => ['529.982.247-25', '***.***.***-**'],
            'CPF só com dígitos' => ['52998224725', '***********'],
            'CPF com espaço em volta' => [' 52998224725 ', ' *********** '],
            'CNPJ com pontuação' => ['11.222.333/0001-81', '**.***.***/****-**'],
            'CNPJ só com dígitos' => ['11222333000181', '**************'],
            'CNPJ alfanumérico' => ['12.ABC.345/01DE-35', '**.***.***/****-**'],
            'CNPJ alfanumérico em minúsculas' => ['12abc34501de35', '**************'],
            'texto comum' => ['Rua das Flores 12', 'Rua das Flores 12'],
            'CEP' => ['70068-900', '70068-900'],
            'número' => [52998224725, 52998224725],
            'vazia' => [null, null],
        ];
    }

    #[DataProvider('recusasComValor')]
    public function testHistoricoNaoGuardaOValorQueOCultBrRecusou(string $resposta): void
    {
        $this->comConecta(function () use ($resposta) {
            $registro = $this->enviaComPessoas(new FakeTransport(status: 422, body: $this->corpoDaConecta($resposta)));
            $guardada = json_decode($registro->response, true);

            $this->assertSame('Há linhas inválidas. Nenhuma linha foi salva.', $guardada['message']);

            foreach ($this->respostaDaConecta($resposta)['inconsistencias'] as $indice => $inconsistencia) {
                $this->assertArrayNotHasKey('valor_recebido', $guardada['inconsistencias'][$indice]);
                $this->assertStringNotContainsString($inconsistencia['valor_recebido'], $registro->response);
            }
        });
    }

    public static function recusasComValor(): array
    {
        return [['recusa-422-linhas-invalidas'], ['recusa-422-linha-valor'], ['recusa-422-linha-opcoes']];
    }

    public function testRespostaSemInconsistenciaEGuardadaComoVeio(): void
    {
        $this->comConecta(function () {
            $this->assertSame('{"detail":  "recusado"}', $this->enviaComPessoas(new FakeTransport(status: 422, body: '{"detail":  "recusado"}'))->response);
            $this->assertSame('{"inconsistencias":[]}', $this->enviaComPessoas(new FakeTransport(status: 422, body: '{"inconsistencias": ["x"]}'))->response);
            $this->assertSame('<html>', $this->enviaComPessoas(new FakeTransport(status: 500, body: '<html>'))->response);
        });
    }

    public function testLogDaAplicacaoNaoTrazOCorpo(): void
    {
        $this->comConecta(function () {
            $linhas = $this->capturandoLog(fn() => $this->enviaComPessoas(new FakeTransport(status: 422, body: $this->corpoDaConecta('recusa-422-linhas-invalidas'))));
            $texto = implode("
", array_column($linhas->getRecords(), 'message'));

            $this->assertStringContainsString('POST payload | URL: ' . self::HOST, $texto);
            $this->assertStringContainsString('POST response | HTTP: 422', $texto);
            $this->assertStringNotContainsString('Body', $texto);
            $this->assertStringNotContainsString('529.982.247-25', $texto);
            $this->assertStringNotContainsString('quinze mil', $texto);
        });
    }

    public function testRegistroSimuladoTambemEReduzido(): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente($config, ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'development'], 'conecta'),
            fn() => $this->assertSame(2, $this->enviaComPessoas(new FakeTransport())->payload['linhas']['total']),
        );
    }

    public function testResultadoSemRespostasERegistradoComoVeio(): void
    {
        $this->comConecta(function () {
            $this->assertNull($this->envia(new FakeTransport(status: 201))->payload['respostas']);
        });
    }

    function testSoAConectaDeclaraACapacidadeDeEnviarResultado()
    {
        $this->assertTrue(is_a(ConectaProvider::class, SendsResultado::class, true));
        $this->assertFalse(is_a(GestaoProvider::class, SendsResultado::class, true));
    }
}
