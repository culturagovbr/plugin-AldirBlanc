<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\OportunidadeCultJob;
use MapasCulturais\App;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de OportunidadeCultJob::persistCultCreateSyncedFlag.
 *
 * Verifica que gravar o flag de sincronização via SQL direto não dispara update:finish,
 * que enfileiraria um job de update no CultBr logo após o create.
 */
class OportunidadeCultJobSyncFlagTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        parent::tearDown();
    }

    private function findUpdateJob(int $opportunityId): ?Job
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    private function createEnabledOpportunity(User $user): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Ativa';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Sync Test';
        $subsite->url = 'subsite-sync-' . uniqid();
        $subsite->save(true);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        $opp->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '0');
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $this->app->enableAccessControl();

        return $opp;
    }

    /**
     * Gravar cultBrCreateSynced via SQL direto não deve enfileirar job de update,
     * mesmo que a oportunidade esteja ENABLED e com todos os guards passando.
     *
     * Se usasse Entity::save(), o update:finish dispararia o PUT antes do POST estar completo.
     */
    function testPersistirFlagNaoDisparaJobDeUpdate()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createEnabledOpportunity($user);

        // limpar qualquer job de update gerado pelo save() acima (setup)
        $conn = $this->app->em->getConnection();
        $conn->executeStatement(
            "DELETE FROM job WHERE name LIKE '%oportunidade-cult%' AND metadata::text LIKE :pattern",
            ['pattern' => '%"update"%']
        );

        // simular o que persistCultCreateSyncedFlag faz (via SQL direto — fix do bug)
        $job = new class(OportunidadeCultJob::SLUG) extends OportunidadeCultJob {
            public function callPersistFlag(App $app, int $opportunityId): void
            {
                $this->persistCultCreateSyncedFlag($app, $opportunityId);
            }
        };
        $job->callPersistFlag($this->app, $opp->id);

        // verificar que o flag foi gravado — usa SQL direto porque persistCultCreateSyncedFlag
        // atualiza apenas o banco (SQL direto), não a entity em cache do Doctrine
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertSame('1', $row['value'] ?? null, 'Flag deve ser gravado');

        // verificar que nenhum job de update foi enfileirado pela gravação do flag
        $this->assertNull(
            $this->findUpdateJob($opp->id),
            'Gravar o flag não deve enfileirar job de update'
        );
    }

    /**
     * persistCultCreateSyncedFlag deve ser silencioso quando a oportunidade não existe.
     */
    function testPersistirFlagEmOportunidadeInexistenteNaoLancaExcecao()
    {
        $job = new class(OportunidadeCultJob::SLUG) extends OportunidadeCultJob {
            public function callPersistFlag(App $app, int $opportunityId): void
            {
                $this->persistCultCreateSyncedFlag($app, $opportunityId);
            }
        };

        // não deve lançar exceção
        $job->callPersistFlag($this->app, 999999999);
        $this->assertTrue(true); // se chegou aqui, passou
    }
}
