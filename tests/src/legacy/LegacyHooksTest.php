<?php

namespace Tests\AldirBlanc\Legacy;

use Tests\Abstract\TestCase;

/**
 * Testes de regressão para o comportamento de hooks relevante às nossas alterações.
 *
 * Cobre o mesmo domínio funcional de HooksTest::testHookOrder e testHookWildcard
 * (tests/src/HooksTest.php), testes legados do core que não rodam por restrição
 * de infraestrutura (MapasCulturais_TestCase não está no autoload do Composer).
 *
 * Contexto da mudança em EntityManagerModel::generateMetadata():
 *   Usado applyHookBoundTo (em vez de applyHook) para passar $excludedKeys por referência.
 *   Ambos propagam referências quando chamados com [&$var] em PHP 8.3; a diferença
 *   semântica de applyHookBoundTo é vincular o callback ao $target_object (binding de $this),
 *   o que é consistente com todos os demais hooks by-reference do core.
 */
class LegacyHooksTest extends TestCase
{
    /**
     * applyHookBoundTo com parâmetro por referência modifica a variável do chamador.
     *
     * É o comportamento que EntityManagerModel::generateMetadata usa para permitir
     * que plugins adicionem chaves ao array $excludedKeys via hook.
     */
    function testApplyHookBoundToModificaArrayPorReferencia()
    {
        $hookName = 'test.legacy.boundto.ref.' . uniqid();

        $this->app->hook($hookName, function (array &$arr) {
            $arr[] = 'adicionadoPeloHook';
        });

        $myArray = [];
        $this->app->applyHookBoundTo($this, $hookName, [&$myArray]);

        $this->assertContains(
            'adicionadoPeloHook',
            $myArray,
            'applyHookBoundTo deve propagar argumentos por referência ao callback do hook'
        );
    }

    /**
     * applyHookBoundTo vincula o callback ao target object ($this no callback = o alvo passado).
     *
     * Essa é a diferença semântica real em relação a applyHook: o callback é vinculado
     * ao objeto alvo via Closure::bind. Relevante quando o hook precisa acessar estado
     * interno do objeto que dispara o hook — padrão usado em toda a base de código.
     */
    function testApplyHookBoundToVinculaCallbackAoAlvo()
    {
        $hookName = 'test.legacy.boundto.bind.' . uniqid();
        $capturedContext = null;

        $this->app->hook($hookName, function () use (&$capturedContext) {
            $capturedContext = $this;
        });

        $this->app->applyHookBoundTo($this, $hookName, []);

        $this->assertSame(
            $this,
            $capturedContext,
            'applyHookBoundTo deve vincular o callback ao objeto alvo passado'
        );
    }

    /**
     * Hooks com prioridades diferentes executam em ordem crescente de prioridade.
     *
     * Análogo a HooksTest::testHookOrder — prioridade menor = executa primeiro.
     * Garante que a ordenação não foi quebrada pelas nossas alterações.
     */
    function testOrdemHooksPorPrioridade()
    {
        $hookName = 'test.legacy.order.' . uniqid();
        $result = [];

        $this->app->hook($hookName, function () use (&$result) { $result[] = 4; }, 11);
        $this->app->hook($hookName, function () use (&$result) { $result[] = 1; }, 10);
        $this->app->hook($hookName, function () use (&$result) { $result[] = 2; }, 10);
        $this->app->hook($hookName, function () use (&$result) { $result[] = 3; }, 10);
        $this->app->hook($hookName, function () use (&$result) { $result[] = 0; }, 9);

        $this->app->applyHook($hookName);

        $this->assertEquals(
            [0, 1, 2, 3, 4],
            $result,
            'Hooks devem executar em ordem crescente de prioridade numérica'
        );
    }

    /**
     * Hooks com wildcard <<*>> casam com qualquer segmento do nome.
     *
     * Análogo a HooksTest::testHookWildcard — garante que o padrão
     * 'EntityManagerModel.generateMetadata.<<*>>' funcionaria corretamente
     * (caso alguém queira registrar um hook mais genérico no futuro).
     */
    function testHookWildcardCasaComQualquerSufixo()
    {
        $base = 'test.legacy.wildcard.' . uniqid();
        $result = [];

        $this->app->hook("{$base}.<<*>>", function () use (&$result) {
            $result[] = 'wildcard';
        });

        $this->app->applyHook("{$base}.excludedKeys");

        $this->assertContains(
            'wildcard',
            $result,
            'Hook com wildcard <<*>> deve casar com qualquer sufixo no nome'
        );
    }

    /**
     * applyHookBoundTo permite que múltiplos hooks adicionem itens ao mesmo array.
     *
     * Simula o cenário real de generateMetadata: o array $excludedKeys começa vazio
     * e cada plugin registrado no hook pode adicionar suas próprias chaves.
     */
    function testApplyHookBoundToAcumulaDeMultiplosHooks()
    {
        $hookName = 'test.legacy.boundto.multi.' . uniqid();

        $this->app->hook($hookName, function (array &$arr) { $arr[] = 'chave1'; });
        $this->app->hook($hookName, function (array &$arr) { $arr[] = 'chave2'; });
        $this->app->hook($hookName, function (array &$arr) { $arr[] = 'chave3'; });

        $excludedKeys = [];
        $this->app->applyHookBoundTo($this, $hookName, [&$excludedKeys]);

        $this->assertCount(3, $excludedKeys, 'Três hooks devem acumular três chaves no array');
        $this->assertContains('chave1', $excludedKeys);
        $this->assertContains('chave2', $excludedKeys);
        $this->assertContains('chave3', $excludedKeys);
    }

    /**
     * Hook sem listeners registrados não lança exceção.
     *
     * Garante que applyHookBoundTo com array vazio de exclusões (quando nenhum
     * plugin registra o hook) não quebra o fluxo de generateMetadata.
     */
    function testHookSemListenersNaoLancaExcecao()
    {
        $hookName = 'test.legacy.empty.' . uniqid();

        $excludedKeys = [];
        $this->app->applyHookBoundTo($this, $hookName, [&$excludedKeys]);

        $this->assertEmpty($excludedKeys, 'Array deve permanecer vazio quando nenhum hook está registrado');
    }
}
