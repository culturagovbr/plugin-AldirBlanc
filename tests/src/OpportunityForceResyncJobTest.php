<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Jobs\OpportunityForceResyncJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/**
 * OpportunityForceResyncJob — reenvio em lote disparado pela tela de sincronização: enfileira
 * um OportunidadeCultJob de update por oportunidade elegível, sem deixar falha isolada escapar.
 *
 * O tema Pnab está ativo nesta suíte e o save de preparo já enfileira o envio pelo hook, daí a
 * fila ser limpa depois de montar o cenário e antes de disparar o lote.
 */
class OpportunityForceResyncJobTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        parent::tearDown();
    }

    /** Subsite da integração: o único cujas oportunidades a regra aceita. */
    private function integrationSubsite(User $owner): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Reenvio';
        $subsite->url = 'reenvio-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();

        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        return $subsite;
    }

    private function opportunity(User $owner, Subsite $subsite, bool $withPar = true): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $className = $owner->profile->opportunityClassName;
        $opportunity = new $className();
        $opportunity->owner = $owner->profile;
        $opportunity->ownerEntity = $owner->profile;
        $opportunity->name = 'Oportunidade de reenvio';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->subsite = $subsite;
        $opportunity->save(true);

        $opportunity->setMetadata('federativeEntityId', 1);

        if ($withPar) {
            foreach (['parExercicioId' => '2024', 'parMetaId' => '2', 'parAcaoId' => '3', 'parAtividadeId' => '4'] as $key => $value) {
                $opportunity->setMetadata($key, $value);
            }
        }

        $opportunity->save(true);
        $this->app->enableAccessControl();

        return $opportunity;
    }

    private function enqueueResync(array $ids): void
    {
        $this->app->enqueueOrReplaceJob(OpportunityForceResyncJob::SLUG, ['opportunityIds' => $ids]);
    }


    private function findSyncJob(int $opportunityId): ?Job
    {
        $id = md5('oportunidade-cult:oportunidade-cult-update:' . $opportunityId);

        return $this->app->repo('Job')->findOneBy(['id' => $id]);
    }

    function testEnfileiraUmEnvioParaCadaOportunidadeElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $uma = $this->opportunity($owner, $subsite);
        $outra = $this->opportunity($owner, $subsite);

        $this->clearJobQueue();
        $this->enqueueResync([$uma->id, $outra->id]);
        $this->processJobs(number_of_jobs: 1);

        $envio = $this->findSyncJob((int) $uma->id);

        $this->assertNotNull($envio, 'A primeira elegível deve ter envio enfileirado');
        $this->assertNotNull($this->findSyncJob((int) $outra->id), 'A segunda elegível deve ter envio enfileirado');
        $this->assertLessThanOrEqual(new \DateTime(), $envio->nextExecutionTimestamp, 'O envio é enfileirado para começar de imediato');
    }

    function testOportunidadeInelegivelNaoGeraEnvio()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $semPar = $this->opportunity($owner, $subsite, withPar: false);

        $this->clearJobQueue();
        $this->enqueueResync([$semPar->id]);
        $this->processJobs(number_of_jobs: 1);

        $this->assertNull($this->findSyncJob((int) $semPar->id), 'Oportunidade sem os dados do PAR não pode ser reenviada');
    }

    function testIdInexistenteNaoInterrompeOLote()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $elegivel = $this->opportunity($owner, $subsite);
        $inexistente = ((int) $elegivel->id) + 999999;

        $this->clearJobQueue();
        $this->enqueueResync([$inexistente, $elegivel->id]);
        $this->processJobs(number_of_jobs: 1);

        $this->assertNotNull($this->findSyncJob((int) $elegivel->id), 'A oportunidade existente deve ser reenviada mesmo com id inexistente no lote');
        $this->assertNull($this->findSyncJob($inexistente), 'Id inexistente não pode gerar envio');
    }






}
