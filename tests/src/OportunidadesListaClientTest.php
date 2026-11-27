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

/** Se a ação do edital recebe resultado, lido da listagem de oportunidades da Conecta. */
class OportunidadesListaClientTest extends TestCase
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
                'listarOportunidadesEndpoint' => 'oportunidades',
            ], 'conecta'),
            $exercicio,
        );
    }

    #[DataProvider('listagens')]
    public function testPermissaoVemDoItemDoEdital(string $resposta, int $edital, ?bool $esperado): void
    {
        $this->comConecta(function () use ($resposta, $edital, $esperado) {
            $transporte = new FakeTransport(body: $this->corpoDaConecta($resposta));

            $this->assertSame($esperado, (new ConectaProvider($transporte))->fetchResultadoPermitido(new ParEditalId($edital)));
            $this->assertSame(
                "http://cultbr.invalid/oportunidades?page=1&per_page=10&id_oportunidades={$edital}",
                $transporte->ultimaUrl(),
            );
        });
    }

    public static function listagens(): array
    {
        return [
            'ação que não permite' => ['listagem-nao-permite', 54044, false],
            'ação que permite' => ['listagem-permite', 54042, true],
            'dois editais, o primeiro' => ['listagem-dois-editais', 54044, false],
            'dois editais, o segundo' => ['listagem-dois-editais', 54042, true],
            'lista sem o edital' => ['listagem-permite', 1, null],
            'lista vazia' => ['listagem-vazia', 54042, null],
        ];
    }

    #[DataProvider('itensSemResposta')]
    public function testItemQueNaoDizNaoAfirmaNada(string $item): void
    {
        $this->comConecta(function () use ($item) {
            $transporte = new FakeTransport(body: '{"items": [{"id_oportunidade": 10' . $item . '}]}');

            $this->assertNull((new ConectaProvider($transporte))->fetchResultadoPermitido(new ParEditalId(10)));
        });
    }

    public static function itensSemResposta(): array
    {
        return ['sem o campo' => [''], 'nulo' => [', "permite_resultado": null'], 'em texto' => [', "permite_resultado": "false"']];
    }

    public function testFalhaDaListagemSegueComoErro(): void
    {
        $this->comConecta(function () {
            $this->expectException(IntegrationError::class);

            (new ConectaProvider(new FakeTransport(status: 500, body: '{}')))->fetchResultadoPermitido(new ParEditalId(10));
        });
    }

    public function testModoSimuladoDevolveOEditalDa11(): void
    {
        $this->comConecta(function () {
            $transporte = new FakeTransport();

            $this->assertTrue((new ConectaProvider($transporte))->fetchResultadoPermitido(new ParEditalId(53809)));
            $this->assertSame([], $transporte->requisicoes);
        }, ['mode' => 'development']);
    }
}
