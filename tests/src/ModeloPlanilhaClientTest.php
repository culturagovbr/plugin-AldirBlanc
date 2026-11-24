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

/** Modelo de planilha do edital, lido da Conecta. */
class ModeloPlanilhaClientTest extends TestCase
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
                'modeloPlanilhaEndpoint' => 'editais/{id_edital}/resultado/modelo-planilha',
            ], 'conecta'),
            $exercicio,
        );
    }

    #[DataProvider('modelos')]
    public function testLeOModeloDoEdital(string $resposta, bool $possui, ?string $motivo, ?string $modelo): void
    {
        $this->comConecta(function () use ($resposta, $possui, $motivo, $modelo) {
            $transporte = new FakeTransport(body: $this->corpoDaConecta($resposta));

            $lido = (new ConectaProvider($transporte))->fetchModeloPlanilha(new ParEditalId(54042));

            $this->assertSame('http://cultbr.invalid/editais/54042/resultado/modelo-planilha', $transporte->ultimaUrl());
            $this->assertSame([$possui, $motivo, $modelo], [$lido['possui_planilha'], $lido['motivo'], $lido['modelo']]);
        });
    }

    public static function modelos(): array
    {
        return [
            'com planilha' => ['modelo-pontos-de-cultura', true, null, 'pontos_de_cultura'],
            'ação sem modelo' => ['modelo-acao-sem-planilha', false, 'A ação 1.2 não possui modelo de planilha', null],
            'forma de execução sem resposta' => ['modelo-sem-forma-de-execucao', false, 'Forma de execução da ação 1.1 não respondida no edital', null],
        ];
    }

    public function testFalhaSegueComoErro(): void
    {
        $this->comConecta(function () {
            $this->expectException(IntegrationError::class);

            (new ConectaProvider(new FakeTransport(status: 500, body: '{}')))->fetchModeloPlanilha(new ParEditalId(10));
        });
    }

    public function testModoSimuladoNaoTemPlanilha(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport();

            $this->assertFalse((new ConectaProvider($transporte))->fetchModeloPlanilha(new ParEditalId(53809))['possui_planilha']);
            $this->assertSame([], $transporte->requisicoes);
        }, ['mode' => 'development']);
    }
}
