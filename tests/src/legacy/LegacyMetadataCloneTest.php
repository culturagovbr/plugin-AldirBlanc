<?php

namespace Tests\AldirBlanc\Legacy;

use AldirBlanc\Controller;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de regressão para EntityMetadata::resetCreatedMetadataCache().
 *
 * Garante que as alterações no trait EntityMetadata (método resetCreatedMetadataCache)
 * e no EntityManagerModel (chamada após clone em generateModel e generateOpportunity)
 * não quebraram o comportamento básico de getMetadata/setMetadata nem a leitura do banco.
 *
 * Cobre o mesmo domínio funcional de MetadataTests::testGetMetadata e testNullValues
 * (tests/src/MetadataTest.php), testes legados do core que não rodam por restrição
 * de infraestrutura (MapasCulturais_TestCase não está no autoload do Composer).
 *
 * Contexto da mudança:
 *   PHP clone faz cópia rasa de arrays; $__createdMetadata é copiado para o clone.
 *   getMetadata() verifica $__createdMetadata ANTES de consultar o banco — logo o clone
 *   retornaria valores do cache do original em vez de ler o estado real do banco.
 *   resetCreatedMetadataCache() resolve isso zerando ambos os arrays em memória.
 */
class LegacyMetadataCloneTest extends TestCase
{
    use UserDirector;

    private function createOpportunity(): Opportunity
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        /** @var Opportunity $opp */
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Legacy';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    /**
     * Ciclo básico: setMetadata + getMetadata sem round-trip ao banco.
     *
     * Análogo a MetadataTests::testGetMetadata — verifica que o getter retorna
     * exatamente o valor definido pelo setter (lido do cache em memória).
     */
    function testSetMetadataGetMetadataCicloBasico()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $value = $opp->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $this->app->enableAccessControl();

        $this->assertSame('1', $value, 'getMetadata deve retornar o valor definido por setMetadata');
    }

    /**
     * setMetadata + save + reload do banco retorna o valor correto.
     *
     * Análogo a MetadataTests::testNullValues — verifica que o round-trip ao banco
     * (save → em->clear → repo->find) preserva o valor.
     */
    function testSetMetadataPersisteDoBancoAposSave()
    {
        $opp = $this->createOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $value = $reloaded->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $this->app->enableAccessControl();

        $this->assertSame('1', $value, 'Valor de metadata deve persistir no banco após save e reload');
    }

    /**
     * Valor null é persistido e relido como null.
     *
     * Análogo a MetadataTests::testNullValues — verifica que setar null após um valor
     * válido apaga o dado no banco.
     */
    function testSetMetadataNullPersiste()
    {
        $opp = $this->createOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();

        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opp->save(true);

        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, null);
        $opp->save(true);

        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $value = $reloaded->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $this->app->enableAccessControl();

        $this->assertNull($value, 'Metadata definida como null deve ser nula após save e reload');
    }

    /**
     * Documenta o problema que resetCreatedMetadataCache resolve:
     * clone simples herda $__createdMetadata do original (cópia rasa de array PHP).
     *
     * getMetadata() prioriza $__createdMetadata sobre a consulta ao banco —
     * o clone retornaria o valor do cache do original. Este teste verifica
     * explicitamente esse comportamento antes do reset para documentar o bug corrigido.
     */
    function testCloneSemResetHerdaMetadataCacheDoOriginal()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();

        // valor apenas no cache em memória — não salvo no banco
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');

        $clone = clone $opp;

        // sem reset: clone herda $__createdMetadata por cópia rasa
        $valueFromClone = $clone->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);

        $this->app->enableAccessControl();

        $this->assertSame(
            '1',
            $valueFromClone,
            'Clone sem resetCreatedMetadataCache herda o cache do original (documenta o comportamento pré-fix)'
        );
    }

    /**
     * Após resetCreatedMetadataCache(), clone não herda o cache do original.
     *
     * getMetadata() consulta o banco e retorna null porque o valor só existia
     * no cache em memória (não foi salvo). É o comportamento exigido por
     * generateModel() e generateOpportunity() para não herdarem flags de integração.
     */
    function testCloneComResetNaoHerdaMetadataCacheDoOriginal()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();

        // valor apenas no cache em memória — não salvo no banco
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');

        $clone = clone $opp;
        $clone->resetCreatedMetadataCache();

        // após reset: consulta o banco, onde não há linha para este clone
        $valueFromClone = $clone->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);

        $this->app->enableAccessControl();

        $this->assertNull(
            $valueFromClone,
            'Clone com resetCreatedMetadataCache não herda o cache do original'
        );
    }

    /**
     * resetCreatedMetadataCache() no clone não afeta o original.
     *
     * Garante que o reset é local ao objeto que o recebe — efeito colateral
     * sobre o original quebraria o estado do modelo-fonte.
     */
    function testResetNoCloneNaoAfetaOriginal()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();

        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');

        $clone = clone $opp;
        $clone->resetCreatedMetadataCache();

        $valueFromOriginal = $opp->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);

        $this->app->enableAccessControl();

        $this->assertSame('1', $valueFromOriginal, 'Reset no clone não deve apagar o cache do original');
    }

    /**
     * resetCreatedMetadataCache() pode ser chamado múltiplas vezes sem erro.
     */
    function testResetIdempotente()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opp->resetCreatedMetadataCache();
        $opp->resetCreatedMetadataCache();
        $this->app->enableAccessControl();

        $this->assertTrue(true, 'resetCreatedMetadataCache pode ser chamado múltiplas vezes sem exceção');
    }

    /**
     * Após resetCreatedMetadataCache(), setMetadata e getMetadata funcionam normalmente.
     *
     * Garante que o reset não corrompe o estado interno para uso futuro da entidade.
     */
    function testSetGetAposResetFuncionaNormalmente()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opp->resetCreatedMetadataCache();

        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '0');
        $value = $opp->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $this->app->enableAccessControl();

        $this->assertSame('0', $value, 'set/getMetadata funciona normalmente após resetCreatedMetadataCache');
    }
}
