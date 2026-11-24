<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\ModeloPlanilhaService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\InMemoryProvider;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;

/** Leitura e cache do modelo de planilha do edital. */
class ModeloPlanilhaServiceTest extends TestCase
{
    use SendsOpportunityThroughJob;

    private static int $edital = 800000;

    private function comServico(int $ttl, callable $exercicio): void
    {
        FakeIntegrationProvider::reset();

        $this->comConfigDoPlugin(
            function (array $config) use ($ttl) {
                $config['integration']['formularioCacheTTL'] = $ttl;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, fn() => $exercicio(new ModeloPlanilhaService(), ++self::$edital)),
        );
    }

    public function testModeloDoEditalFicaGuardado(): void
    {
        $this->comServico(300, function (ModeloPlanilhaService $servico, int $edital) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true, 'modelo' => 'tec', 'nome_modelo' => 'TEC'];
            $esperado = ['possui' => true, 'motivo' => null, 'modelo' => 'tec', 'nomeModelo' => 'TEC'];

            $this->assertSame($esperado, $servico->doEdital($edital));
            $this->assertSame($esperado, $servico->doEdital($edital));
            $this->assertSame([$edital], FakeIntegrationProvider::$modelosBuscados);
        });
    }

    public function testComCacheDesligadoCadaLeituraBusca(): void
    {
        $this->comServico(0, function (ModeloPlanilhaService $servico, int $edital) {
            $servico->doEdital($edital);
            $servico->doEdital($edital);

            $this->assertCount(2, FakeIntegrationProvider::$modelosBuscados);
        });
    }

    public function testComCacheDesligadoAFalhaNaoFicaGuardada(): void
    {
        $this->comServico(0, function (ModeloPlanilhaService $servico, int $edital) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => throw IntegrationError::transport('Connection timed out', 28);
            $this->assertNull($servico->doEdital($edital));

            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true];
            $this->assertTrue($servico->doEdital($edital)['possui']);
        });
    }

    public function testLeituraDoEnvioVaiAOrigemERegravaOGuardado(): void
    {
        $this->comServico(300, function (ModeloPlanilhaService $servico, int $edital) {
            $servico->doEdital($edital);
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true];

            $this->assertTrue($servico->doEnvio($edital)['possui']);
            $this->assertTrue($servico->doEdital($edital)['possui']);
            $this->assertCount(2, FakeIntegrationProvider::$modelosBuscados);
        });
    }

    #[DataProvider('leiturasQueFalham')]
    public function testLeituraQueFalhaNaoSeRepeteNaTelaEErraNoEnvio(callable $leitura): void
    {
        $this->comServico(300, function (ModeloPlanilhaService $servico, int $edital) use ($leitura) {
            FakeIntegrationProvider::$aoBuscarModelo = $leitura;

            $this->assertNull($servico->doEdital($edital));
            $this->assertNull($servico->doEdital($edital));
            $this->assertCount(1, FakeIntegrationProvider::$modelosBuscados);

            $this->expectException(IntegrationError::class);
            $servico->doEnvio($edital);
        });
    }

    public static function leiturasQueFalham(): array
    {
        return [
            'origem fora do ar' => [fn() => throw IntegrationError::transport('Connection timed out', 28)],
            'resposta sem a chave' => [fn() => ['motivo' => 'x']],
            'chave em texto' => [fn() => ['possui_planilha' => 'false']],
        ];
    }

    public function testProvedorSemAConsultaDeixaOModeloIndisponivel(): void
    {
        $this->comProviderConfigurado(InMemoryProvider::class, function () {
            $this->assertNull((new ModeloPlanilhaService())->doEdital(++self::$edital));
        });
    }
}
