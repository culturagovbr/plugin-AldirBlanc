<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Definitions\JobType;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\SendRunner;
use AldirBlanc\Integration\SendsResultado;
use AldirBlanc\Services\CultBrRequestLogService;
use AldirBlanc\Services\ResultadoService;

class ResultadoCultJob extends JobType
{
	const SLUG = 'resultado-cult';

	const MAX_ATTEMPTS = 3;

	public const ACTION = 'resultado';

	protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
	{
		$opportunity = $data['opportunity'];

		return "resultado-cult:{$opportunity->id}";
	}

	public function _execute(Job $job)
	{
		$app = App::i();

		$raiz = $job->opportunity;
		$attempt = (int) ($job->attempt ?? 1);
		$raizId = (int) $raiz->id;

		$service = new ResultadoService();

		// Recarrega e recheca no estado vigente: entre enfileirar e executar o gestor pode ter despublicado.
		$fresca = $app->repo('Opportunity')->find($raizId);
		$faseFinal = $fresca?->lastPhase;

		if (!$faseFinal || !$service->isEligibleForResultSync($faseFinal)) {
			$app->log->info("ResultadoCultJob: edital {$raizId} não está elegível para envio de resultado - nada enviado");

			return true;
		}

		$runner = new SendRunner('ResultadoCultJob', self::MAX_ATTEMPTS, new CultBrRequestLogService());

		$runner->run(
			opportunityId: $raizId,
			action: self::ACTION,
			requestUuid: $job->requestUuid ?? null,
			user: $job->user ?? null,
			attempt: $attempt,
			send: fn() => $this->sendToCult($service, $faseFinal, $raizId),
			onSuccess: fn(SendOutcome $outcome) => $this->persistResultadoId($app, $service, $raizId, $outcome->response),
			scheduleRetry: fn(?string $requestUuid) => $app->enqueueOrReplaceJob(self::SLUG, [
				'opportunity' => $raiz,
				'attempt'     => $attempt + 1,
				'requestUuid' => $requestUuid,
			], Plugin::getInstance()->config['integration']['retryDelayJob']),
		);

		return true;
	}

	private function sendToCult(ResultadoService $service, Opportunity $faseFinal, int $raizId): SendOutcome
	{
		$provider = Plugin::getInstance()->integrationProvider();

		if (!$provider instanceof SendsResultado) {
			throw IntegrationError::configuration('client.provider', 'o provedor configurado não envia resultado');
		}

		return $provider->sendResultado(
			new ParEditalId((int) $service->parEditalId($raizId)),
			$service->buildResultado($faseFinal),
		);
	}

	/** Resposta sem o id (ou não-JSON) não falha o envio: a captura se recupera num reenvio. */
	private function persistResultadoId(App $app, ResultadoService $service, int $raizId, ?string $response): void
	{
		$decoded = is_string($response) ? json_decode($response, true) : null;
		$id = filter_var($decoded['id_par_edital_resultado'] ?? null, FILTER_VALIDATE_INT);

		if ($id === false || $id < 1) {
			$app->log->warning("ResultadoCultJob: resposta sem id_par_edital_resultado para oportunidade: {$raizId}");

			return;
		}

		$service->persistParEditalResultadoId($raizId, $id);
	}
}
