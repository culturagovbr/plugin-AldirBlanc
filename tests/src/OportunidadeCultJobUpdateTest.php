<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\OportunidadeCultJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de integração do fluxo update de OportunidadeCultJob.
 *
 * Cobre: execução em modo development (sem chamada HTTP real), ausência de flag
 * após sucesso (update não persiste cultBrCreateSynced), mecanismo de retry em caso
 * de falha, e respeito ao limite de MAX_ATTEMPTS.
 *
 * Pré-requisito de ambiente: PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT e
 * ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB devem estar configurados (ver docker-compose.yml
 * deste diretório). PNAB_CULTBR_MODE não é setado, então usa o default 'development'
 * definido em Plugin.php — o AbstractClient::put() retorna o payload sem chamada HTTP.
 */
class OportunidadeCultJobUpdateTest extends TestCase
{
    use UserDirector;

    private function createOpportunity(User $user): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Cult Update Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function enqueueUpdateJob(Opportunity $opp): void
    {
        $this->app->enqueueOrReplaceJob(OportunidadeCultJob::SLUG, [
            'opportunity' => $opp,
            'action'      => 'update',
        ]);
    }

    private function findUpdateJob(int $opportunityId): ?Job
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    /**
     * Remove o registro da oportunidade do banco via SQL direto, mantendo o objeto
     * na identity map do Doctrine — assim $job->opportunity->id continua acessível,
     * mas findOpportunityWithIntegrationData() (DQL) retorna null, disparando a
     * lógica de erro/retry do job.
     */
    private function deleteOpportunityFromDb(int $opportunityId): void
    {
        $this->app->em->getConnection()->executeStatement(
            'DELETE FROM opportunity WHERE id = ?',
            [$opportunityId]
        );
    }

    /**
     * Em modo development (default), put() em AbstractClient retorna o payload sem
     * chamada HTTP. O job deve executar com sucesso e ser removido da fila.
     */
    function testUpdateJobEmModoDesenvolvimentoExecutaComSucesso()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $this->enqueueUpdateJob($opp);
        $this->assertNotNull($this->findUpdateJob($opp->id), 'Job deve existir antes de processar');

        $this->processJobs(number_of_jobs: 1);

        $this->assertNull(
            $this->findUpdateJob($opp->id),
            'Job deve ser removido após execução bem-sucedida'
        );
    }

    /**
     * O job de update não persiste nenhum flag após sucesso — ao contrário do create,
     * que grava cultBrCreateSynced. Verificar que nenhuma linha de flag de update
     * é criada na opportunity_meta.
     */
    function testUpdateJobNaoGravaFlagCultBrCreateSyncedAposSucesso()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        // cultBrCreateSynced só deve ser gravado pelo create job — não pelo update.
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertFalse($row, 'Update job não deve gravar flag cultBrCreateSynced');
    }

    /**
     * Quando updateInCult() falha (oportunidade não encontrada no DQL), o job original
     * é processado com return true (deletado da fila) e um novo job de retry é enfileirado.
     */
    function testUpdateJobReenfileiraAposFalhaAbaixoDoMaxTentativas()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);

        $this->deleteOpportunityFromDb($oppId);

        $this->processJobs(number_of_jobs: 1);

        $this->assertNotNull(
            $this->findUpdateJob($oppId),
            'Job de retry deve ser enfileirado após falha'
        );
    }

    /**
     * Após MAX_ATTEMPTS (3) falhas consecutivas, nenhum retry deve ser enfileirado.
     * Usa executeJob() diretamente (em vez de processJobs()) pelo mesmo motivo do
     * teste equivalente em OportunidadeCultJobCreateTest: o hash hex do job pode
     * começar com letra, zerando o cast (int) dentro do while do core.
     */
    function testUpdateJobNaoReenfileiraAposMaxTentativas()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        for ($i = 0; $i < 5; $i++) {
            if ($this->findUpdateJob($oppId) === null) {
                break;
            }
            $this->app->executeJob('2100-01-01 00:00');
        }

        $this->assertNull(
            $this->findUpdateJob($oppId),
            'Não deve restar job na fila após esgotar MAX_ATTEMPTS'
        );
    }

    /**
     * Quando a oportunidade não é encontrada pelo DQL (updateInCult lança Exception),
     * o job falha mas não grava cultBrCreateSynced — esse flag é exclusivo do create job.
     * Garante que o update job não introduz esse metadado como efeito colateral de falha.
     */
    function testUpdateJobOportunidadeNaoEncontradaNaoGravaFlagCultBrCreateSynced()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        $this->processJobs(number_of_jobs: 1);

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertFalse($row, 'Update job com falha não deve gravar flag cultBrCreateSynced');
    }

    function testUpdateJobGravaCultBrLastSyncedAtAposSucesso()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT]
        );
        $this->assertNotFalse($row, 'cultBrLastSyncedAt deve ser gravado após update bem-sucedido');
        $this->assertNotEmpty($row['value'], 'cultBrLastSyncedAt não deve ser vazio');
    }

    function testUpdateJobNaoGravaCultBrLastSyncedAtEmFalha()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);
        $this->processJobs(number_of_jobs: 1);

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT]
        );
        $this->assertFalse($row, 'cultBrLastSyncedAt não deve ser gravado após falha do job');
    }

    function testUpdateJobSobrescreveCultBrLastSyncedAtNaSegundaExecucao()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $first = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT]
        );

        sleep(1);

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->app->em->getConnection()->fetchAllAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT]
        );
        $this->assertCount(1, $rows, 'Deve existir exatamente uma linha de cultBrLastSyncedAt');
        $this->assertNotEquals($first['value'], $rows[0]['value'], 'Timestamp deve ser atualizado na segunda execução');
    }
}
