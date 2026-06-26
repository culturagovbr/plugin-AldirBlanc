<?php

namespace Tests\AldirBlanc\Legacy;

use MapasCulturais\Entities\Opportunity;
use OpportunityPhases\Jobs\SyncPhaseRegistrations;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de regressão para SyncPhaseRegistrations.
 *
 * Verifica que o job de sincronização de inscrições entre fases executa como
 * operação de sistema, sem depender das permissões do usuário que o enfileirou.
 *
 * Contexto do bug: quando o status de uma inscrição muda, o hook
 * entity(Registration).status dispara com o candidato como usuário autenticado.
 * O job é enfileirado com esse candidato como job->user. Na execução,
 * syncRegistrations() chama checkPermission('@control') sobre a fase —
 * o candidato não tem @control sobre a oportunidade → PermissionDenied.
 *
 * Job::execute() captura PermissionDenied internamente e só loga o erro;
 * o sinal de sucesso vs. falha é a presença do job na fila após processamento:
 * sucesso → job deletado; falha → job permanece.
 */
class LegacySyncPhaseRegistrationsTest extends TestCase
{
    use UserDirector;

    private function createOpportunityWithPhase(): array
    {
        $owner = $this->userDirector->createUser();
        $this->login($owner);

        $this->app->disableAccessControl();
        $className = $owner->profile->opportunityClassName;

        $opportunity = new $className();
        $opportunity->owner = $owner->profile;
        $opportunity->ownerEntity = $owner->profile;
        $opportunity->name = 'Oportunidade Teste';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        // A fase final é auto-criada pelo hook insert:after de OpportunityPhases.
        $lastPhase = $opportunity->lastPhase;

        return [$owner, $opportunity, $lastPhase];
    }

    private function findSyncJob(int $opportunityId): bool
    {
        $internalId = "SyncPhaseRegistrations:{$opportunityId}";
        $hashedId = md5("SyncPhaseRegistrations:{$internalId}");
        $count = (int) $this->app->em->getConnection()->fetchScalar(
            "SELECT COUNT(*) FROM job WHERE id = ?",
            [$hashedId]
        );
        return $count > 0;
    }

    /**
     * Job bem-sucedido é removido da fila. Job com falha permanece.
     *
     * Quando o usuário do job não tem @control sobre a oportunidade,
     * syncRegistrations lança PermissionDenied → Job::execute() captura e
     * mantém o job na fila. Com disableAccessControl() em _execute(), o job
     * executa com sucesso e é removido.
     */
    function testJobRemovidoDaFilaAposExecucaoBemSucedida(): void
    {
        [, , $lastPhase] = $this->createOpportunityWithPhase();

        $candidato = $this->userDirector->createUser();
        $this->login($candidato);
        $this->app->enqueueOrReplaceJob(SyncPhaseRegistrations::SLUG, [
            'opportunity'   => $lastPhase,
            'registrations' => [],
        ]);

        $this->assertTrue($this->findSyncJob($lastPhase->id), 'Job deve existir antes de processar');

        $this->processJobs();

        $this->assertFalse(
            $this->findSyncJob($lastPhase->id),
            'Job deve ser removido após execução bem-sucedida; se permanece, indica PermissionDenied'
        );
    }

    /**
     * O controle de acesso deve ser restaurado após a execução do job (try/finally).
     */
    function testJobRestaurarControleDeAcessoAposExecucao(): void
    {
        [, , $lastPhase] = $this->createOpportunityWithPhase();

        $candidato = $this->userDirector->createUser();
        $this->login($candidato);
        $this->app->enqueueOrReplaceJob(SyncPhaseRegistrations::SLUG, [
            'opportunity'   => $lastPhase,
            'registrations' => [],
        ]);

        $this->processJobs();

        $this->assertTrue($this->app->isAccessControlEnabled());
    }
}
