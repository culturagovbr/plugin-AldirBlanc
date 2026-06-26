<?php

namespace Tests\AldirBlanc\Legacy;

use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de regressão para o hook entity(Opportunity).save:before registrado no Pnab Theme.
 *
 * O hook define o metadado publishedTimestamp quando a oportunidade é publicada
 * (status → STATUS_ENABLED) e o timestamp ainda não está definido.
 * Se já existir, não sobrescreve.
 *
 * Contexto: publishedTimestamp é enviado ao CultBr como data_publicacao_edital.
 * Sem ele, o edital chega sem data de publicação.
 */
class LegacyOpportunitySaveBeforeHookTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        parent::tearDown();
    }

    private function createDraftOpportunity(): Opportunity
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        /** @var Opportunity $opp */
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade SaveBefore';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    /**
     * Salvar com STATUS_ENABLED deve definir publishedTimestamp automaticamente.
     *
     * O hook save:before do Pnab Theme verifica se status === STATUS_ENABLED e
     * publishedTimestamp está ausente. Se sim, grava a data/hora atual.
     */
    function testSaveBeforeDefinePublishedTimestampAoPublicar()
    {
        $opp = $this->createDraftOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $timestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertNotNull($timestamp, 'publishedTimestamp deve ser definido ao publicar (STATUS_ENABLED)');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $timestamp,
            'publishedTimestamp deve estar no formato Y-m-d H:i:s'
        );
    }

    /**
     * Salvar com STATUS_DRAFT não deve definir publishedTimestamp.
     *
     * O hook só age quando status === STATUS_ENABLED. Draft não é publicação.
     */
    function testSaveBeforeNaoDefinePublishedTimestampEmDraft()
    {
        $opp = $this->createDraftOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->name = 'Nome atualizado';
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $timestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertNull($timestamp, 'publishedTimestamp NÃO deve ser definido enquanto status é DRAFT');
    }

    /**
     * publishedTimestamp já existente não deve ser sobrescrito em saves subsequentes.
     *
     * O hook verifica !$this->getMetadata('publishedTimestamp') antes de gravar.
     * Re-saves de uma oportunidade publicada não devem alterar a data original.
     */
    function testSaveBeforeNaoSobreescrevePublishedTimestampExistente()
    {
        $opp = $this->createDraftOpportunity();
        $oppId = $opp->id;

        $dataOriginal = '2024-01-15 10:30:00';

        $this->app->disableAccessControl();
        $opp->setMetadata('publishedTimestamp', $dataOriginal);
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $this->app->enableAccessControl();

        // Segundo save com ENABLED — o hook NÃO deve sobrescrever o timestamp já existente
        $this->app->disableAccessControl();
        $opp->name = 'Nome atualizado';
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $timestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertSame(
            $dataOriginal,
            $timestamp,
            'publishedTimestamp original não deve ser sobrescrito em saves subsequentes com ENABLED'
        );
    }

    /**
     * Transição explícita DRAFT → ENABLED define publishedTimestamp na mesma transação.
     *
     * Garante que o hook age no momento exato da publicação, não em save posterior.
     * Relevante para o fluxo de saveOpportunityPostGenerate.
     */
    function testPublishedTimestampDefinidoNaTransicaoDraftParaEnabled()
    {
        $opp = $this->createDraftOpportunity();
        $oppId = $opp->id;

        $before = (new \DateTime())->modify('-1 second')->format('Y-m-d H:i:s');

        $this->app->disableAccessControl();
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $this->app->enableAccessControl();

        $after = (new \DateTime())->modify('+1 second')->format('Y-m-d H:i:s');

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $timestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertNotNull($timestamp);
        $this->assertGreaterThanOrEqual(
            $before,
            $timestamp,
            'publishedTimestamp deve ser maior ou igual ao momento antes da publicação'
        );
        $this->assertLessThanOrEqual(
            $after,
            $timestamp,
            'publishedTimestamp deve ser menor ou igual ao momento após a publicação'
        );
    }

    /**
     * Fases (parent !== null, status STATUS_PHASE = -1) não devem receber publishedTimestamp.
     *
     * O hook verifica status === STATUS_ENABLED; fases têm status = -1.
     * Uma fase jamais deve ser tratada como oportunidade publicada.
     */
    function testSaveBeforeNaoDefinePublishedTimestampParaFase()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;

        $parent = new $className();
        $parent->owner = $user->profile;
        $parent->ownerEntity = $user->profile;
        $parent->name = 'Oportunidade Pai';
        $parent->shortDescription = 'desc';
        $parent->status = Opportunity::STATUS_DRAFT;
        $parent->save(true);

        $phase = new $className();
        $phase->owner = $user->profile;
        $phase->ownerEntity = $user->profile;
        $phase->parent = $parent;
        $phase->name = 'Fase da Oportunidade';
        $phase->shortDescription = 'desc fase';
        // STATUS_PHASE = -1; usar diretamente o valor para não depender de visibilidade da constante
        $phase->status = -1;
        $phase->save(true);
        $phaseId = $phase->id;
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($phaseId);
        $timestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertNull($timestamp, 'Fase (status = -1) não deve receber publishedTimestamp');
    }
}
