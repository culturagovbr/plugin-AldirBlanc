<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\FormularioResultadoService;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** Cache e resolução do formulário de resultado: o tipo de ação em três camadas, a estrutura com TTL próprio. */
class FormularioResultadoServiceTest extends TestCase
{
    use UserDirector;
    use SendsOpportunityThroughJob;

    private function comServico(int $formularioCacheTTL, callable $exercicio, int $cacheTTL = 0): void
    {
        FakeIntegrationProvider::reset();

        $this->comConfigDoPlugin(
            function (array $config) use ($formularioCacheTTL, $cacheTTL) {
                $config['integration']['formularioCacheTTL'] = $formularioCacheTTL;
                $config['integration']['cacheTTL'] = $cacheTTL;

                return $config;
            },
            fn() => $this->comProviderConfigurado(
                FakeIntegrationProvider::class,
                fn() => $exercicio(new FormularioResultadoService()),
            ),
        );
    }

    private function novoEditalId(): int
    {
        return (int) $this->createOpportunity($this->userDirector->createUser())->id;
    }

    private function gravarTipoAcaoMeta(int $editalId, string $valor): void
    {
        $this->app->em->getConnection()->executeStatement(
            'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
            ['id' => $editalId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID, 'value' => $valor],
        );
    }

    private function tipoAcaoMeta(int $editalId): string|false
    {
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $editalId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID],
        );

        return $row === false ? false : (string) $row['value'];
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

    function testGlobalCacheTTLNaoGovernaAEstrutura()
    {
        $this->comServico(0, function (FormularioResultadoService $servico) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 1];

            $servico->formStructureForTipoAcao(30);
            $servico->formStructureForTipoAcao(30);

            $this->assertSame([30, 30], FakeIntegrationProvider::$formulariosBuscados, 'o cacheTTL global não pode cachear a estrutura');
        }, cacheTTL: 300);
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
        $editalId = $this->novoEditalId();

        $this->comServico(0, function (FormularioResultadoService $servico) use ($editalId) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => ['id_formulario' => 109];

            $estrutura = $servico->formStructureForOpportunity($editalId);

            $this->assertSame([$editalId], FakeIntegrationProvider::$tiposAcaoBuscados);
            $this->assertSame([1], FakeIntegrationProvider::$formulariosBuscados);
            $this->assertSame(['id_formulario' => 109], $estrutura);
        });
    }

    function testEditalSemTipoDeAcaoNaoBuscaFormulario()
    {
        $editalId = $this->novoEditalId();

        $this->comServico(0, function (FormularioResultadoService $servico) use ($editalId) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => null;
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => $this->fail('sem tipo de ação não há formulário a buscar');

            $this->assertNull($servico->formStructureForOpportunity($editalId));
            $this->assertFalse($this->tipoAcaoMeta($editalId), 'tipo nulo não vira metadado');
        });
    }

    function testTipoDeAcaoResolvidoPorHttpViraMetadadoEDispensaNovoHttp()
    {
        $editalId = $this->novoEditalId();

        $this->comServico(0, function (FormularioResultadoService $servico) use ($editalId) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 7;

            $this->assertSame(7, $servico->parTipoAcaoForOpportunity($editalId));
            $this->assertSame(7, $servico->parTipoAcaoForOpportunity($editalId));

            $this->assertSame([$editalId], FakeIntegrationProvider::$tiposAcaoBuscados, 'com o metadado gravado, a 2ª resolução não chama a API nem com cache desligado');
            $this->assertSame('7', $this->tipoAcaoMeta($editalId), 'o tipo resolvido por HTTP é persistido');
        });
    }

    function testTipoDeAcaoVindoDoMetadadoNaoChamaAApi()
    {
        $editalId = $this->novoEditalId();
        $this->gravarTipoAcaoMeta($editalId, '9');

        $this->comServico(0, function (FormularioResultadoService $servico) use ($editalId) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => $this->fail('com metadado presente, não se pergunta à API');

            $this->assertSame(9, $servico->parTipoAcaoForOpportunity($editalId));
            $this->assertSame([], FakeIntegrationProvider::$tiposAcaoBuscados);
        });
    }

    function testTipoDeAcaoCacheadoDispensaBancoEApi()
    {
        $editalId = $this->novoEditalId();

        $this->comServico(300, function (FormularioResultadoService $servico) use ($editalId) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 14;

            $this->assertSame(14, $servico->parTipoAcaoForOpportunity($editalId));

            // Some o metadado: se a 2ª resolução ainda devolve, veio do cache, não do banco.
            $this->app->em->getConnection()->executeStatement(
                'DELETE FROM opportunity_meta WHERE object_id = :id AND key = :key',
                ['id' => $editalId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID],
            );

            $this->assertSame(14, $servico->parTipoAcaoForOpportunity($editalId));
            $this->assertSame([$editalId], FakeIntegrationProvider::$tiposAcaoBuscados, 'a 2ª resolução sai do cache, sem banco nem API');
        });
    }

    function testProvedorSemACapacidadeFalhaComoConfiguracao()
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

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
