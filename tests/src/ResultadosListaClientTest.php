<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Resultados publicados do edital, lidos da lista da Conecta. */
class ResultadosListaClientTest extends TestCase
{
    use ConfiguresPlugin;
    use LoadsConectaResponses;

    private function comConecta(callable $exercicio, array $trocas = []): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente($config, $trocas + [
                'mode' => 'live',
                'host' => 'http://cultbr.invalid',
                'token' => 'token-de-teste',
                'criarResultadoEndpoint' => 'editais/{id_edital}/resultado',
            ], 'conecta'),
            $exercicio,
        );
    }

    #[DataProvider('listas')]
    public function testDevolveOsIdsPublicados(string $resposta, array $ids): void
    {
        $this->comConecta(function () use ($resposta, $ids) {
            $transporte = new FakeTransport(body: $this->corpoDaConecta($resposta));

            $this->assertSame($ids, (new ConectaProvider($transporte))->fetchIdsPublicados(new ParEditalId(54042)));
            $this->assertSame('http://cultbr.invalid/editais/54042/resultado?limit=100', $transporte->ultimaUrl());
        });
    }

    public static function listas(): array
    {
        return [
            'final e dois retificados' => ['resultados-lista', [20128, 20127, 20129]],
            'retificado com data anterior à do final' => ['resultados-lista-retificado-anterior', [20010, 20011]],
        ];
    }

    private function comLista(string $corpo): array
    {
        $ids = [];
        $this->comConecta(function () use ($corpo, &$ids) {
            $ids = (new ConectaProvider(new FakeTransport(body: $corpo)))->fetchIdsPublicados(new ParEditalId(54042));
        });

        return $ids;
    }

    public function testListaVaziaNaoTemPublicados(): void
    {
        $this->assertSame([], $this->comLista('{"pagination": {"total": 0}, "data": []}'));
    }

    public function testRascunhoNaoContaComoPublicado(): void
    {
        $this->assertSame([2], $this->comLista('{"pagination": {"total": 2}, "data": [
            {"id_par_edital_resultado": 1, "id_par_edital_resultado_status": 1},
            {"id_par_edital_resultado": 2, "id_par_edital_resultado_status": 3}
        ]}'));
    }

    #[DataProvider('listasSemCerteza')]
    public function testListaSemCertezaSegueComoErro(FakeTransport $transporte): void
    {
        $this->comConecta(function () use ($transporte) {
            $this->expectException(IntegrationError::class);

            (new ConectaProvider($transporte))->fetchIdsPublicados(new ParEditalId(54042));
        });
    }

    public static function listasSemCerteza(): array
    {
        return [
            'com mais páginas' => [new FakeTransport(body: '{"pagination": {"total": 101}, "data": []}')],
            'sem o total' => [new FakeTransport(body: '{"data": []}')],
            'sem os itens' => [new FakeTransport(body: '{"pagination": {"total": 0}}')],
            'item sem id' => [new FakeTransport(body: '{"pagination": {"total": 1}, "data": [{"id_par_edital_resultado_status": 3}]}')],
            'erro do servidor' => [new FakeTransport(status: 500, body: '{}')],
            'falha de transporte' => [FakeTransport::falhaDeTransporte()],
        ];
    }

    public function testModoSimuladoDevolveAListaDaFixture(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport();

            $this->assertSame([20010, 20011], (new ConectaProvider($transporte))->fetchIdsPublicados(new ParEditalId(53809)));
            $this->assertSame([], $transporte->requisicoes);
        }, ['mode' => 'development']);
    }
}
