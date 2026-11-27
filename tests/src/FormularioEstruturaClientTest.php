<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\FetchesFormularioResultado;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Estrutura do formulário de resultado e tipo de ação do edital, lidos da Conecta. */
class FormularioEstruturaClientTest extends TestCase
{
    use ConfiguresPlugin;
    use LoadsConectaResponses;

    private const HOST = 'http://cultbr.invalid';

    private const ECO_COM_ACAO = '{"id_par_edital": 53809, "acao": {"id_par_acao_meta_acao": 45908, "id_par_tipo_acao": 1}}';

    private function comConecta(callable $exercicio, array $trocas = []): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                $trocas + ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live'],
                'conecta',
            ),
            $exercicio,
        );
    }

    function testBuscaAEstruturaComBarraFinalAntesDaQuery()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 200, body: '{"id_formulario": 109, "secoes": []}');

            $estrutura = (new ConectaProvider($transporte))->fetchFormularioResultado(12);

            $this->assertSame(['GET'], $transporte->metodos());
            $this->assertSame(
                self::HOST . '/par/formularios/estrutura?modulo=RESULTADO_EDITAL&id_tipo_acao=12',
                $transporte->ultimaUrl(),
                'a rota canônica é sem barra: com ela a API responde 307',
            );
            $this->assertSame(109, $estrutura['id_formulario']);
        });
    }

    function testBarraFinalESuprimidaMesmoComConfigComBarra()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 200, body: '{"id_formulario": 109}');

            (new ConectaProvider($transporte))->fetchFormularioResultado(1);

            $this->assertStringContainsString('/par/formularios/estrutura?', $transporte->ultimaUrl());
        }, ['formularioEstruturaEndpoint' => 'par/formularios/estrutura/']);
    }

    function testTipoDeAcaoSemFormularioPublicadoDevolveNull()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 404, body: '{"detail": "Formulário não encontrado"}');

            $this->assertNull((new ConectaProvider($transporte))->fetchFormularioResultado(2));
        });
    }

    public function testTipoDeAcaoSemFormularioDaRespostaRealDevolveNull(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 404, body: $this->corpoDaConecta('estrutura-sem-formulario'));

            $this->assertNull((new ConectaProvider($transporte))->fetchFormularioResultado(7));
        });
    }

    #[DataProvider('falhasQueNaoDizemSemFormulario')]
    public function testOutraFalhaNaoSomeComAAba(int $status, string $corpo): void
    {
        $this->comConecta(function () use ($status, $corpo) {
            $this->expectException(IntegrationError::class);

            (new ConectaProvider(new FakeTransport(status: $status, body: $corpo)))->fetchFormularioResultado(1);
        });
    }

    public static function falhasQueNaoDizemSemFormulario(): array
    {
        return [
            'endereço errado' => [404, '{"detail": "Not Found"}'],
            '404 sem detalhe' => [404, '{}'],
            '404 com detalhe vazio' => [404, '{"detail": " "}'],
            '404 com detalhe em lista' => [404, '{"detail": ["x"]}'],
            '404 que não é JSON' => [404, '<html></html>'],
            'mais de um formulário ativo' => [409, '{"detail": "Mais de um formulário ativo"}'],
        ];
    }

    function testErroQueNaoE404Relanca()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 500, body: 'Internal Server Error');

            $this->expectException(IntegrationError::class);

            (new ConectaProvider($transporte))->fetchFormularioResultado(1);
        });
    }

    function testEcoDaOportunidadeResolveOTipoDeAcao()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 200, body: self::ECO_COM_ACAO);

            $idTipoAcao = (new ConectaProvider($transporte))->fetchParTipoAcao(new OpportunityId(12471));

            $this->assertSame(['GET'], $transporte->metodos());
            $this->assertSame(self::HOST . '/oportunidades/12471', $transporte->ultimaUrl());
            $this->assertSame(1, $idTipoAcao);
        });
    }

    function testOportunidadeNaoSincronizadaDevolveTipoNull()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 404, body: '{"detail": "Oportunidade não encontrada"}');

            $this->assertNull((new ConectaProvider($transporte))->fetchParTipoAcao(new OpportunityId(999)));
        });
    }

    function testEcoSemOTipoDeAcaoDevolveNull()
    {
        $this->comConecta(function () {
            $semAcao = new FakeTransport(status: 200, body: '{"id_par_edital": 53809, "acao": {}}');
            $this->assertNull((new ConectaProvider($semAcao))->fetchParTipoAcao(new OpportunityId(12471)));

            $tipoInvalido = new FakeTransport(status: 200, body: '{"acao": {"id_par_tipo_acao": 0}}');
            $this->assertNull((new ConectaProvider($tipoInvalido))->fetchParTipoAcao(new OpportunityId(12471)));
        });
    }

    function testModoSimuladoUsaAsFixturesSemRede()
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 200);
            $provider = new ConectaProvider($transporte);

            $estrutura = $provider->fetchFormularioResultado(1);
            $idTipoAcao = $provider->fetchParTipoAcao(new OpportunityId(12471));

            $this->assertSame([], $transporte->metodos(), 'modo simulado não sai pela rede');
            $this->assertSame(109, $estrutura['id_formulario']);
            $this->assertSame(541, $estrutura['id_formulario_versao'], 'o modo simulado mostra o formulário vigente');
            $this->assertSame(1, $idTipoAcao);
        }, ['mode' => 'development']);
    }

    function testSoAConectaDeclaraACapacidadeDoFormulario()
    {
        $this->assertTrue(is_a(ConectaProvider::class, FetchesFormularioResultado::class, true));
        $this->assertFalse(is_a(GestaoProvider::class, FetchesFormularioResultado::class, true));
    }
}
