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
 * Testes de integração do fluxo create de OportunidadeCultJob.
 *
 * Cobre: execução em modo development (sem chamada HTTP real), persistência do flag
 * cultBrCreateSynced=1 após sucesso, mecanismo de retry em caso de falha, e respeito
 * ao limite de MAX_ATTEMPTS.
 *
 * Pré-requisito de ambiente: PNAB_CULTBR_CREATE_OPORTUNIDADE_ENDPOINT e
 * ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB devem estar configurados (ver docker-compose.yml
 * deste diretório). PNAB_CULTBR_MODE não é setado, então usa o default 'development'
 * definido em Plugin.php — o AbstractClient::post() retorna o payload sem chamada HTTP.
 */
class OportunidadeCultJobCreateTest extends TestCase
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
        $opp->name = 'Oportunidade Cult Create Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function enqueueCreateJob(Opportunity $opp): void
    {
        $this->app->enqueueOrReplaceJob(OportunidadeCultJob::SLUG, [
            'opportunity' => $opp,
            'action'      => 'create',
        ]);
    }

    private function findCreateJob(int $opportunityId): ?Job
    {
        $internalId = "oportunidade-cult-create:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    /**
     * Remove o registro da oportunidade do banco via SQL direto, sem afetar a
     * identity map do Doctrine. Isso faz com que findOpportunityWithIntegrationData()
     * (DQL) retorne null durante a execução do job, disparando a lógica de retry —
     * enquanto $job->opportunity->id continua acessível via identity map.
     */
    private function deleteOpportunityFromDb(int $opportunityId): void
    {
        $this->app->em->getConnection()->executeStatement(
            'DELETE FROM opportunity WHERE id = ?',
            [$opportunityId]
        );
    }

    /**
     * Em modo development (default), post() em AbstractClient retorna o payload sem
     * chamada HTTP. O job deve executar com sucesso, persistir o flag e ser removido.
     */
    function testCreateJobEmModoDesenvolvimentoPersisteFlagAposSucesso()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $this->enqueueCreateJob($opp);
        $this->assertNotNull($this->findCreateJob($opp->id), 'Job deve existir antes de processar');

        $this->processJobs(number_of_jobs: 1);

        $this->assertNull(
            $this->findCreateJob($opp->id),
            'Job deve ser removido após execução bem-sucedida'
        );

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertSame('1', $row['value'] ?? null, 'Flag cultBrCreateSynced deve ser gravado após sucesso');
    }

    /**
     * Quando createInCult() falha (oportunidade não encontrada no DQL), o job original
     * é processado com return true (deletado da fila) e um novo job de retry é enfileirado.
     *
     * A oportunidade é deletada via SQL direto, mantendo o objeto na identity map do
     * Doctrine — assim $job->opportunity->id funciona (via identity map), mas
     * findOpportunityWithIntegrationData() (DQL) retorna null (sem linha no banco).
     */
    function testCreateJobReenfileiraAposFalhaAbaixoDoMaxTentativas()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueCreateJob($opp);

        $this->deleteOpportunityFromDb($oppId);

        $this->processJobs(number_of_jobs: 1);

        $retryJob = $this->findCreateJob($oppId);
        $this->assertNotNull($retryJob, 'Job de retry deve ser enfileirado após falha');
    }

    /**
     * Após MAX_ATTEMPTS (3) falhas consecutivas no mesmo job, nenhum retry deve ser
     * enfileirado. Executa processJobs() sem limite para que as 3 tentativas ocorram
     * dentro do mesmo ciclo — o counter de tentativas (ArrayAdapter) persiste entre
     * as iterações porque reset() só é chamado uma vez, ao fim do processJobs().
     */
    function testCreateJobNaoReenfileiraAposMaxTentativas()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueCreateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        // Sem limite: o loop processa original + 2 retries até esgotar MAX_ATTEMPTS=3.
        // O delay de retry é 'now' (ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB=now), então
        // cada retry é imediatamente processável na próxima iteração do loop.
        $this->processJobs();

        $this->assertNull(
            $this->findCreateJob($oppId),
            'Não deve restar job na fila após esgotar MAX_ATTEMPTS'
        );
    }

    /**
     * Quando o job falha (oportunidade não encontrada), persistCultCreateSyncedFlag
     * nunca é chamado — o flag não deve existir na tabela opportunity_meta.
     */
    function testCreateJobNaoGravaFlagAposFalha()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueCreateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        $this->processJobs(number_of_jobs: 1);

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertFalse($row, 'Flag cultBrCreateSynced não deve existir após falha do job');
    }

    /**
     * Quando cultBrCreateSynced já existe (UPDATE path), o valor deve ser sobrescrito para '1'.
     * Garante que persistCultCreateSyncedFlag executa UPDATE quando o registro já existe,
     * sem duplicar a linha.
     */
    function testCreateJobSobreescreveFlagExistente()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        // Insere a meta com valor '0' simulando um estado inconsistente anterior.
        $this->app->em->getConnection()->executeStatement(
            'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, 'value' => '0']
        );

        $this->enqueueCreateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertSame('1', $row['value'] ?? null, 'Flag deve ser atualizado para 1 mesmo quando já existia');

        // Garante que não foi criada uma segunda linha para a mesma chave.
        $count = $this->app->em->getConnection()->fetchOne(
            'SELECT count(*) FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $oppId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED]
        );
        $this->assertSame('1', (string) $count, 'Não deve haver linhas duplicadas na opportunity_meta');
    }
}
