<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\FormularioResultadoService;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;

/** Cache e resolução do formulário de resultado: uma busca por tipo de ação, não por acesso. */
class FormularioResultadoServiceTest extends TestCase
{
    use SendsOpportunityThroughJob;

    private function comServico(int $cacheTTL, callable $exercicio): void
    {
        FakeIntegrationProvider::reset();

        $this->comConfigDoPlugin(
            function (array $config) use ($cacheTTL) {
                $config['integration']['cacheTTL'] = $cacheTTL;

                return $config;
            },
            fn() => $this->comProviderConfigurado(
                FakeIntegrationProvider::class,
                fn() => $exercicio(new FormularioResultadoService()),
            ),
        );
    }

    function testComCacheLigadoAEstruturaEBuscadaUmaVezPorTipoDeAcao()
    {
        $this->comServico(300, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 40];

            $primeira = $servico->formStructureForTipoAcao(12);
            $segunda = $servico->formStructureForTipoAcao(12);

            $this->assertSame([12], FakeIntegrationProvider::$formulariosBuscados);
            $this->assertSame(['id_formulario' => 40], $primeira);
            $this->assertSame($primeira, $segunda);
        });
    }

    function testComCacheDesligadoCadaAcessoBusca()
    {
        $this->comServico(0, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 5];

            $servico->formStructureForTipoAcao(13);
            $servico->formStructureForTipoAcao(13);

            $this->assertSame([13, 13], FakeIntegrationProvider::$formulariosBuscados);
        });
    }

    function testTipoSemFormularioTambemEntraNoCache()
    {
        $this->comServico(300, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => null;

            $this->assertNull($servico->formStructureForTipoAcao(2));
            $this->assertNull($servico->formStructureForTipoAcao(2));

            $this->assertSame([2], FakeIntegrationProvider::$formulariosBuscados, 'o 404 repetido também é request desnecessária');
        });
    }

    function testEstruturaDoEditalResolveOTipoEBuscaOFormulario()
    {
        $this->comServico(0, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 109];

            $estrutura = $servico->formStructureForOpportunity(12471);

            $this->assertSame([12471], FakeIntegrationProvider::$tiposAcaoBuscados);
            $this->assertSame([1], FakeIntegrationProvider::$formulariosBuscados);
            $this->assertSame(['id_formulario' => 109], $estrutura);
        });
    }

    function testEditalSemTipoDeAcaoNaoBuscaFormulario()
    {
        $this->comServico(0, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => null;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => $this->fail('sem tipo de ação não há formulário a buscar');

            $this->assertNull($servico->formStructureForOpportunity(999));
        });
    }

    function testOTipoDeAcaoDoEditalTambemECacheado()
    {
        $this->comServico(300, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 14;

            $this->assertSame(14, $servico->parTipoAcaoForOpportunity(777));
            $this->assertSame(14, $servico->parTipoAcaoForOpportunity(777));

            $this->assertSame([777], FakeIntegrationProvider::$tiposAcaoBuscados);
        });
    }

    function testProvedorSemACapacidadeFalhaComoConfiguracao()
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['cacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado('gestao', function () {
                try {
                    (new FormularioResultadoService())->formStructureForTipoAcao(1);
                    $this->fail('Esperava falha de configuração com o provedor sem a capacidade');
                } catch (IntegrationError $e) {
                    $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
                }
            }),
        );
    }
}
