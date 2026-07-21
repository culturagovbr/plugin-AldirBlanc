<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Definitions\JobType;
use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Services\CultBrRequestLogService;
use AldirBlanc\Services\OpportunityService;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Http\Clients\OportunidadeCultClient;
use AldirBlanc\Controller;

class OportunidadeCultJob extends JobType
{
	private OpportunityService $opportunityService;
	private OportunidadeCultClient $oportunidadeCultClient;

    const SLUG = 'oportunidade-cult';

	const MAX_ATTEMPTS = 3;

	private const ACTIONS = [
		'update' => 'updateInCult',
	];

	protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
	{
		$opportunity = $data['opportunity'];
		$action = $data['action'];
        return "oportunidade-cult-{$action}:{$opportunity->id}";
    }

	private function initServices(int $opportunityId): void
	{
		$opportunityId = new OpportunityId($opportunityId);

		$this->opportunityService = new OpportunityService();
		$this->oportunidadeCultClient = new OportunidadeCultClient($opportunityId);
	}

	/**
	 * Carrega a oportunidade com metadados, arquivos, subsite e primeira fase (query performática).
	 * Define o subsite atual quando existir, para labels de metadados (tema Pnab) quando disponível.
	 */
	private function getOpportunityWithIntegrationData(Opportunity $opportunity): ?Opportunity
	{
		$app = App::i();
		$loaded = $this->opportunityService->findOpportunityWithIntegrationData((int) $opportunity->id);
		if (!$loaded) {
			return null;
		}
		if ($loaded->subsite !== null && isset($loaded->subsite->id)) {
			$app->setCurrentSubsiteId($loaded->subsite->id);
		}
		return $loaded;
	}

	public function _execute(Job $job)
	{
		$app = App::i();

		$this->initServices($job->opportunity->id);

		$opportunity = $job->opportunity;
		$action = $job->action;
		$attempt = (int) ($job->attempt ?? 1);

		$app->log->info("OportunidadeCultJob executando tentativa {$attempt}/" . self::MAX_ATTEMPTS . " para ação: {$action} para oportunidade: {$opportunity->id}");

		$method = self::ACTIONS[$action] ?? null;
		if (!$method) {
			throw new \Exception("Method not found: {$action}");
		}

		// Histórico da aba "Logs CultBr": o uuid nasce na primeira tentativa e viaja no payload
		// do job, de modo que as retentativas entrem como tentativas do mesmo envio.
		$logService = new CultBrRequestLogService();
		$requestLog = $this->recordLog(fn() => $logService->startOrResume((int) $opportunity->id, $action, $job->requestUuid ?? null));
		if ($requestLog) {
			$this->oportunidadeCultClient->setExchangeRecorder(
				fn(array $exchange) => $this->recordLog(fn() => $logService->recordAttempt($requestLog, $exchange + [
					'attempt'     => $attempt,
					'maxAttempts' => self::MAX_ATTEMPTS,
				]))
			);
		}

		try {
			$this->{$method}($opportunity);

			if ($action === 'update') {
				$this->persistCultLastSyncedAtFlag($app, (int) $job->opportunity->id);
			}

			$app->log->info("OportunidadeCultJob executado com sucesso para ação: {$action} para oportunidade: {$opportunity->id}");
		} catch (\Throwable $e) {
			$app->log->error("OportunidadeCultJob falhou na tentativa {$attempt}/" . self::MAX_ATTEMPTS . ": " . $e->getMessage() . " - ação: {$action} - oportunidade: {$opportunity->id}");

			if ($attempt < self::MAX_ATTEMPTS) {
				$delay = Plugin::getInstance()->config['integration']['retryDelayJob'] ?? 'now';
				$app->enqueueOrReplaceJob(self::SLUG, [
					'opportunity' => $opportunity,
					'action'      => $action,
					'attempt'     => $attempt + 1,
					'requestUuid' => $requestLog?->requestUuid,
				], $delay);
			} elseif ($requestLog) {
				// Sem retentativa restante: o envio se encerra em falha.
				$this->recordLog(fn() => $logService->finish($requestLog, CultBrRequestLog::RESULT_ERROR));
			}
			return true;
		}

		// Fora do try: uma falha ao gravar o log não pode cair no catch acima e
		// reenfileirar um PUT que já foi aceito pelo CultBR.
		if ($requestLog) {
			$this->recordLog(fn() => $logService->finish($requestLog, CultBrRequestLog::RESULT_SUCCESS));
		}

		return true;
	}

	/**
	 * Executa uma gravação do histórico sem deixar que ela interfira no envio: o log é
	 * acessório e sua falha (tabela ausente, banco indisponível) não pode alterar o fluxo.
	 */
	private function recordLog(callable $operation)
	{
		try {
			return $operation();
		} catch (\Throwable $e) {
			App::i()->log->error('[CultBR] Falha ao registrar log de envio: ' . $e->getMessage());
			return null;
		}
	}

	protected function persistCultLastSyncedAtFlag(App $app, int $opportunityId): void
	{
		if ($app->repo('Opportunity')->find($opportunityId) === null) {
			return;
		}
		$now = (new \DateTime())->format(\DateTime::ATOM);
		$conn = $app->em->getConnection();
		$updated = $conn->executeStatement(
			'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
			[
				'value' => $now,
				'id'    => $opportunityId,
				'key'   => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT,
			]
		);
		if ($updated === 0) {
			$conn->executeStatement(
				'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
				[
					'id'    => $opportunityId,
					'key'   => Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT,
					'value' => $now,
				]
			);
		}
	}

	private function updateInCult(Opportunity $opportunity)
	{
		$opportunityId = $opportunity->id;

		if (!$opportunityId) {
			throw new \Exception("ID da Oportunidade não encontrada: {$opportunityId}");
		}

		$loaded = $this->getOpportunityWithIntegrationData($opportunity);
		if (!$loaded) {
			throw new \Exception("Oportunidade não encontrada: {$opportunityId}");
		}

		$opportunityDto = OpportunityDto::fromArray($this->opportunityService->mapOpportunityToIntegrationPayload($loaded));

		$response = $this->oportunidadeCultClient->update($opportunityDto);

		return $response;
	}
}