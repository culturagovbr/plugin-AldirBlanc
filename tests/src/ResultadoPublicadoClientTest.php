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

/** Resultado publicado do edital, lido da Conecta. */
class ResultadoPublicadoClientTest extends TestCase
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
                'resultadoPublicadoEndpoint' => 'editais/{id_edital}/resultado/publicado',
            ], 'conecta'),
            $exercicio,
        );
    }

    public function testIdVemDoPublicado(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(body: $this->corpoDaConecta('publicado'));

            $this->assertSame(20127, (new ConectaProvider($transporte))->fetchIdPublicado(new ParEditalId(54042)));
            $this->assertSame('http://cultbr.invalid/editais/54042/resultado/publicado', $transporte->ultimaUrl());
        });
    }

    public function testEditalSemPublicadoDevolveNull(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport(status: 404, body: $this->corpoDaConecta('publicado-inexistente'));

            $this->assertNull((new ConectaProvider($transporte))->fetchIdPublicado(new ParEditalId(54042)));
        });
    }

    #[DataProvider('leiturasSemCerteza')]
    public function testLeituraSemCertezaSegueComoErro(FakeTransport $transporte): void
    {
        $this->comConecta(function () use ($transporte) {
            $this->expectException(IntegrationError::class);

            (new ConectaProvider($transporte))->fetchIdPublicado(new ParEditalId(54042));
        });
    }

    public static function leiturasSemCerteza(): array
    {
        return [
            '404 de outra frase' => [new FakeTransport(status: 404, body: '{"detail": "Resultado não encontrado"}')],
            'a frase com outro status' => [new FakeTransport(status: 400, body: '{"detail": "Resultado publicado não encontrado"}')],
            '404 sem detalhe' => [new FakeTransport(status: 404, body: '{}')],
            'erro do servidor' => [new FakeTransport(status: 500, body: '{}')],
            'falha de transporte' => [FakeTransport::falhaDeTransporte()],
            'publicado sem id' => [new FakeTransport(body: '{"id_par_edital": 54042}')],
            'id em texto' => [new FakeTransport(body: '{"id_par_edital_resultado": "20127"}')],
        ];
    }

    public function testModoSimuladoDevolveOPublicadoDaFixture(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport();

            $this->assertSame(20010, (new ConectaProvider($transporte))->fetchIdPublicado(new ParEditalId(53809)));
            $this->assertSame([], $transporte->requisicoes);
        }, ['mode' => 'development']);
    }
}
