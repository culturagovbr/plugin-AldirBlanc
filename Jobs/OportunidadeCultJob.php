<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Definitions\JobType;
use AldirBlanc\Integration\SendRunner;
use AldirBlanc\Services\CultBrRequestLogService;
use AldirBlanc\Services\OpportunityService;
use AldirBlanc\Services\ResultadoService;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Controller;

class OportunidadeCultJob extends JobType
{
	private OpportunityService $opportunityService;

    const SLUG = 'oportunidade-cult';

	const MAX_ATTEMPTS = 3;

	protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
	{
		$opportunity = $data['opportunity'];

        return "oportunidade-cult:{$opportunity->id}";
    }

	private function initServices(): void
	{
		$this->opportunityService = new OpportunityService();
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

		$this->initServices();

		$opportunity = $job->opportunity;
		$attempt = (int) ($job->attempt ?? 1);

		// O verbo sai do carimbo na hora de executar, não de quem enfileirou: entre uma coisa e outra
		// o edital pode ter passado a existir na origem, e repetir o POST criaria outro.
		$sendAction = $this->opportunityService->sendActionFor($opportunity);

		$runner = new SendRunner('OportunidadeCultJob', self::MAX_ATTEMPTS, new CultBrRequestLogService());

		$runner->run(
			opportunityId: (int) $opportunity->id,
			action: $sendAction->value,
			requestUuid: $job->requestUuid ?? null,
			// $job->user é o usuário logado no save que enfileirou o job (App::enqueueJob).
			user: $job->user ?? null,
			attempt: $attempt,
			send: fn() => $this->sendToCult($opportunity, $sendAction),
			onSuccess: function (SendOutcome $outcome) use ($app, $job) {
				$this->persistCultLastSyncedAtFlag($app, (int) $job->opportunity->id);
				$this->persistParEditalId($app, (int) $job->opportunity->id, $outcome->response);
				$this->persistParTipoAcaoId($app, (int) $job->opportunity->id, $outcome->response);
				$this->chainResultadoSync($app, (int) $job->opportunity->id);
			},
			scheduleRetry: fn(?string $requestUuid) => $app->enqueueOrReplaceJob(self::SLUG, [
				'opportunity' => $opportunity,
				'attempt'     => $attempt + 1,
				'requestUuid' => $requestUuid,
			], Plugin::getInstance()->config['integration']['retryDelayJob']),
		);

		return true;
	}

	protected function persistCultLastSyncedAtFlag(App $app, int $opportunityId): void
	{
		$now = (new \DateTime())->format(\DateTime::ATOM);
		$this->upsertOpportunityMeta($app, $opportunityId, Controller::OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT, $now);
	}

	/** Resposta sem o id (ou não-JSON) não falha o envio: a captura se recupera num reenvio. */
	protected function persistParEditalId(App $app, int $opportunityId, ?string $response): void
	{
		$decoded = is_string($response) ? json_decode($response, true) : null;
		$parEditalId = filter_var($decoded['id_par_edital'] ?? null, FILTER_VALIDATE_INT);

		if ($parEditalId === false || $parEditalId < 1) {
			$app->log->warning("OportunidadeCultJob: resposta sem id_par_edital para oportunidade: {$opportunityId}");
			return;
		}

		$this->upsertOpportunityMeta($app, $opportunityId, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, (string) $parEditalId);
	}

	/** O tipo de ação vem no bloco `acao` do mesmo eco; ausente, a montagem do formulário resolve por auto-cura. */
	protected function persistParTipoAcaoId(App $app, int $opportunityId, ?string $response): void
	{
		$decoded = is_string($response) ? json_decode($response, true) : null;
		$idTipoAcao = filter_var($decoded['acao']['id_par_tipo_acao'] ?? null, FILTER_VALIDATE_INT);

		if ($idTipoAcao === false || $idTipoAcao < 1) {
			return;
		}

		$this->upsertOpportunityMeta($app, $opportunityId, Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID, (string) $idTipoAcao);
	}

	/**
	 * Edital com resultado publicado tem o envio de resultado encadeado no sucesso da oportunidade.
	 * Falha aqui vira warning: não pode reenfileirar um envio de oportunidade que a API já aceitou.
	 */
	private function chainResultadoSync(App $app, int $opportunityId): void
	{
		try {
			$raiz = $app->repo('Opportunity')->find($opportunityId);
			$faseFinal = $raiz?->lastPhase;

			if (!$faseFinal) {
				return;
			}

			$service = new ResultadoService();

			if (!$service->isEligibleForResultSync($faseFinal)) {
				return;
			}

			// Só o encadeamento tem esta guarda: cada reenvio cria documento novo no PAR (retificado),
			// e o lote pós-login reenviaria um idêntico a cada login do gestor.
			$jaEnviado = $service->parEditalResultadoId($opportunityId) !== null;
			if ($jaEnviado && !$service->hasPendingResultChange($faseFinal)) {
				return;
			}

			$service->enqueueResultadoSync($raiz);
		} catch (\Throwable $e) {
			$app->log->warning("OportunidadeCultJob: falha ao encadear o envio de resultado da oportunidade {$opportunityId}: " . $e->getMessage());
		}
	}

	private function upsertOpportunityMeta(App $app, int $opportunityId, string $key, string $value): void
	{
		if ($app->repo('Opportunity')->find($opportunityId) === null) {
			return;
		}
		$conn = $app->em->getConnection();
		$updated = $conn->executeStatement(
			'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
			[
				'value' => $value,
				'id'    => $opportunityId,
				'key'   => $key,
			]
		);
		if ($updated === 0) {
			$conn->executeStatement(
				'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
				[
					'id'    => $opportunityId,
					'key'   => $key,
					'value' => $value,
				]
			);
		}
	}

	private function sendToCult(Opportunity $opportunity, SendAction $action): SendOutcome
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

		return Plugin::getInstance()->integrationProvider()->sendOpportunity(
			new OpportunityId((int) $opportunityId),
			$opportunityDto,
			$action
		);
	}
}