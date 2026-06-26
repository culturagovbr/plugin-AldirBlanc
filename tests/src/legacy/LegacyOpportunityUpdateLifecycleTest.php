<?php

namespace Tests\AldirBlanc\Legacy;

use AldirBlanc\Controller;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de regressão para o ciclo de vida de update de oportunidades.
 *
 * Foca nos comportamentos do hook entity(Opportunity).update:finish que não
 * estão cobertos em ThemeGenerateOpportunityHooksTest (que testa os guards de
 * validateIntegrationJob) — aqui testamos o ciclo de update em si:
 * atualizar campo sem mudar status, re-save do mesmo status, etc.
 *
 * Também cobre o hook entity(Opportunity).save:before no contexto de update
 * (publishedTimestamp não deve ser sobrescrito em re-saves).
 */
class LegacyOpportunityUpdateLifecycleTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        parent::tearDown();
    }

    private function createEnabledOpportunityWithSubsite(string $subsiteName = 'Subsite Update Test'): array
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;

        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Update';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);

        $subsite = new Subsite();
        $subsite->name = $subsiteName;
        $subsite->url = strtolower(str_replace(' ', '-', $subsiteName)) . '-' . uniqid();
        $subsite->save(true);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);

        $this->app->enableAccessControl();

        return ['opportunity' => $opp, 'subsite' => $subsite];
    }

    private function findUpdateJob(int $opportunityId): mixed
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    private function clearUpdateJob(int $opportunityId): void
    {
        $conn = $this->app->em->getConnection();
        $conn->executeStatement(
            "DELETE FROM job WHERE name LIKE '%oportunidade-cult%' AND metadata::text LIKE :pattern",
            ['pattern' => '%"update"%']
        );
    }

    // ===== update:finish — quando NÃO enfileira =====

    /**
     * Atualizar apenas o nome (sem mudar status) não deve enfileirar job de update CultBr.
     *
     * O hook update:finish verifica status === STATUS_ENABLED internamente, mas o guard
     * validateIntegrationJob já verifica múltiplas condições. A mudança de nome não
     * altera o status, mas o hook ainda dispara — o que importa é que o update:finish
     * verifica o status atual da entidade (ENABLED) e, com cultBrCreateSynced=1, SIM
     * enfileiraria um job. Este teste documenta que update:finish dispara a cada save
     * quando ENABLED + condições satisfeitas.
     *
     * Propósito: garantir que o comportamento não muda silenciosamente. Se este teste
     * começar a falhar, significa que a lógica de enfileiramento foi alterada.
     */
    function testResalvarOportunidadeEnabledComCreateSincronizadoEnfileiraCultBrJob()
    {
        ['opportunity' => $opp] = $this->createEnabledOpportunityWithSubsite('Subsite Update Enable');

        // limpar job gerado no setUp (ao fazer status ENABLED já enfileirou update)
        $this->clearUpdateJob($opp->id);
        $this->assertNull($this->findUpdateJob($opp->id), 'Job deve estar limpo antes do re-save');

        // Re-save com ENABLED + cultBrCreateSynced=1: deve enfileirar novamente
        $this->app->disableAccessControl();
        $opp->name = 'Nome atualizado';
        $opp->save(true);
        $this->app->enableAccessControl();

        $job = $this->findUpdateJob($opp->id);
        $this->assertNotNull(
            $job,
            'update:finish deve enfileirar job de update quando ENABLED + cultBrCreateSynced=1, mesmo em re-save'
        );
        $this->assertSame('update', $job->action);
    }

    /**
     * Re-save com STATUS_ENABLED mas sem cultBrCreateSynced não enfileira job.
     *
     * Caso: oportunidade foi habilitada mas o create ainda não chegou ao CultBr.
     * Enfileirar update antes do create resultaria em 404 na API do CultBr.
     */
    function testResalvarStatusEnabledNaoEnfileiraJobSeCreateNaoSincronizado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;

        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Sem Sync';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);

        $subsite = new Subsite();
        $subsite->name = 'Subsite Sem Sync';
        $subsite->url = 'subsite-sem-sync-' . uniqid();
        $subsite->save(true);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        // cultBrCreateSynced deliberadamente ausente (create não foi sincronizado)
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $this->app->enableAccessControl();

        $job = $this->findUpdateJob($opp->id);
        $this->assertNull(
            $job,
            'update:finish NÃO deve enfileirar job de update quando create não foi sincronizado'
        );
    }

    /**
     * Transição DRAFT → ENABLED com cultBrCreateSynced=1 enfileira job de update.
     *
     * Controle positivo: garante que o caminho feliz do update:finish funciona.
     * Cobre o caso do saveOpportunityPostGenerate quando a oportunidade é publicada.
     * (Já coberto em ThemeGenerateOpportunityHooksTest::testDisparaUpdateQuandoTudoCorretoESubsiteCoincide,
     * mas repetido aqui para documentar o ciclo completo do update lifecycle.)
     */
    function testTransicaoDraftParaEnabledComCreateSincronizadoEnfileiraCultBrJob()
    {
        ['opportunity' => $opp] = $this->createEnabledOpportunityWithSubsite('Subsite Transicao');

        $job = $this->findUpdateJob($opp->id);
        $this->assertNotNull(
            $job,
            'update:finish deve enfileirar job quando: ENABLED + isGeneratedFromModel + cultBrCreateSynced + subsiteCorreto'
        );
        $this->assertSame('update', $job->action, 'A ação do job deve ser "update"');
    }

    // ===== save:before — publishedTimestamp no ciclo de update =====

    /**
     * publishedTimestamp definido em um save não deve ser sobrescrito em updates subsequentes.
     *
     * Contexto: saveOpportunityPostGenerate publica a oportunidade (ENABLED) e mais tarde
     * o gestor pode fazer edições. O hook save:before verifica !$this->getMetadata('publishedTimestamp')
     * antes de gravar, garantindo que o timestamp original da publicação seja preservado.
     */
    function testPublishedTimestampNaoEhSobrescritoEmUpdateSubsequente()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;

        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Published';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);

        // Primeiro save com ENABLED: define publishedTimestamp
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $oppId = $opp->id;
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($oppId);
        $firstTimestamp = $reloaded->getMetadata('publishedTimestamp');
        $this->assertNotNull($firstTimestamp, 'Pré-condição: publishedTimestamp deve estar definido');

        // Update subsequente: só altera o nome, mantém ENABLED
        $reloaded->name = 'Nome Editado';
        $reloaded->save(true);
        $this->app->enableAccessControl();

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $afterUpdate = $this->app->repo('Opportunity')->find($oppId);
        $secondTimestamp = $afterUpdate->getMetadata('publishedTimestamp');
        $this->app->enableAccessControl();

        $this->assertSame(
            $firstTimestamp,
            $secondTimestamp,
            'publishedTimestamp não deve ser sobrescrito em updates subsequentes com ENABLED'
        );
    }
}
