<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Jobs\OportunidadeCultJob;
use AldirBlanc\Services\CultBrRequestLogService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableOportunidadeCultJob;
use Tests\Traits\UserDirector;

/**
 * Gravação do histórico de envios pelo OportunidadeCultJob (aba "Logs CultBr").
 *
 * Em modo development (default nesta suíte), AbstractClient::put() devolve o payload sem
 * chamada HTTP — a tentativa é registrada como `simulated`, sem resposta de servidor.
 */
class OportunidadeCultJobLogTest extends TestCase
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
        $opp->name = 'Oportunidade Log CultBR Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function enqueueUpdateJob(Opportunity $opp, array $extra = []): void
    {
        $this->app->enqueueOrReplaceJob(OportunidadeCultJob::SLUG, [
            'opportunity' => $opp,
            'action'      => 'update',
        ] + $extra);
    }

    private function logs(int $opportunityId): array
    {
        return (new CultBrRequestLogService())->findByOpportunity($opportunityId);
    }

    /** Ver OportunidadeCultJobUpdateTest: apaga a linha mantendo o objeto na identity map. */
    private function deleteOpportunityFromDb(int $opportunityId): void
    {
        $this->app->em->getConnection()->executeStatement(
            'DELETE FROM opportunity WHERE id = ?',
            [$opportunityId]
        );
    }

    function testEnvioBemSucedidoRegistraUmLogComUmaTentativa()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($opp->id);

        $this->assertCount(1, $rows, 'Deve haver um envio registrado');
        $this->assertEquals(CultBrRequestLog::RESULT_SUCCESS, $rows[0]['status']);
        $this->assertCount(1, $rows[0]['attempts']);
        $this->assertEquals(1, $rows[0]['attempts'][0]['attempt']);
        $this->assertEquals(
            CultBrRequestLogAttempt::RESULT_SIMULATED,
            $rows[0]['attempts'][0]['status'],
            'Em modo development a tentativa é simulada'
        );
    }

    /**
     * A retentativa precisa entrar como tentativa 2 do MESMO envio — é isso que dá
     * a leitura "Tentativa 2/3" sob um único uuid na aba.
     */
    function testRetentativaEntraNoMesmoEnvio()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows, 'Falha não pode criar um segundo envio');
        $uuidPrimeiraTentativa = $rows[0]['requestUuid'];
        $this->assertEquals(
            CultBrRequestLog::RESULT_PENDING,
            $rows[0]['status'],
            'Com retentativa pendente o envio segue em andamento'
        );

        // Executa o job de retry enfileirado pela falha anterior.
        $this->app->executeJob('2100-01-01 00:00');

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows, 'Retentativa não pode criar novo envio');
        $this->assertEquals($uuidPrimeiraTentativa, $rows[0]['requestUuid']);
    }

    /**
     * Quem salvou a oportunidade fica registrado no envio: App::enqueueJob grava o usuário
     * logado em Job::$user, e a retentativa (que roda sem sessão) preserva esse autor.
     */
    function testEnvioRegistraQuemDisparouEPreservaNaRetentativa()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows);
        $this->assertEquals($user->id, $rows[0]['user']['id'] ?? null, 'Autor do envio deve ser quem salvou');

        // A retentativa roda no worker, sem usuário logado.
        $this->app->executeJob('2100-01-01 00:00');

        $rows = $this->logs($oppId);
        $this->assertEquals($user->id, $rows[0]['user']['id'] ?? null, 'Retentativa não pode perder o autor');
    }

    /**
     * 404 do CultBR é recusa, não sucesso: o parseResponse aceita a resposta sem lançar, e o
     * endpoint é upsert (não devolve 404 por id inexistente), então sem essa checagem o job
     * carimbaria cultBrLastSyncedAt para um envio que o servidor recusou.
     */
    function testRespostaRecusadaPeloCultBrNaoMarcaOportunidadeComoSincronizada()
    {
        $job = new TestableOportunidadeCultJob(OportunidadeCultJob::SLUG);

        $this->assertTrue($job->callApiRejectedSend(CultBrRequestLogAttempt::RESULT_REJECTED));
        $this->assertFalse($job->callApiRejectedSend(CultBrRequestLogAttempt::RESULT_SUCCESS));
        $this->assertFalse($job->callApiRejectedSend(CultBrRequestLogAttempt::RESULT_SIMULATED));
        $this->assertFalse($job->callApiRejectedSend(null), 'Sem tentativa registrada, não há recusa a inferir');
    }

    /** Esgotadas as 3 tentativas, o envio fecha como falha — hoje o job engole a exceção. */
    function testEnvioFechaComoFalhaAoEsgotarTentativas()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        for ($i = 0; $i < 5; $i++) {
            $this->app->executeJob('2100-01-01 00:00');
        }

        $rows = $this->logs($oppId);

        $this->assertCount(1, $rows);
        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $rows[0]['status']);
    }
}
