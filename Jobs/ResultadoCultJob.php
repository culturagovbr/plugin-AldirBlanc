<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Definitions\JobType;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesResultadosDoEdital;
use AldirBlanc\Integration\SendRunner;
use AldirBlanc\Integration\SendsResultado;
use AldirBlanc\Services\CultBrRequestLogService;
use AldirBlanc\Services\FormularioResultadoService;
use AldirBlanc\Services\ModeloPlanilhaService;
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

		try {
			$formCompleto = (new FormularioResultadoService())->isFormularioCompleto($fresca);
		} catch (IntegrationError $e) {
			// Completude indisponível não encerra aqui: o envio adiante acusa o mesmo erro no log, com retry.
			$formCompleto = true;
		}

		if (!$formCompleto) {
			$app->log->info("ResultadoCultJob: edital {$raizId} com formulário de resultado incompleto - nada enviado");

			return true;
		}

		// Se a montagem falhar aqui, o runner remonta, loga e decide o retry.
		try {
			$conteudo = $service->conteudo($fresca);
		} catch (\Throwable $e) {
			$conteudo = null;
		}

		$hash = $conteudo !== null ? $this->contentHash($conteudo) : null;

		// Alteração que não muda o conteúdo não vira retificado novo (eles coexistem publicados).
		if ($hash !== null && $service->resultadoLastPayloadHash($raizId) === $hash) {
			$app->log->info("ResultadoCultJob: edital {$raizId} - payload idêntico ao último enviado, reenvio dispensado");

			return true;
		}

		$idsAntes = [];
		$runner = new SendRunner('ResultadoCultJob', self::MAX_ATTEMPTS, new CultBrRequestLogService());

		$runner->run(
			opportunityId: $raizId,
			action: self::ACTION,
			requestUuid: $job->requestUuid ?? null,
			user: $job->user ?? null,
			attempt: $attempt,
			send: function () use ($faseFinal, $fresca, $conteudo, $service, &$idsAntes) {
				return $this->sendToCult($faseFinal, $conteudo ?? $service->conteudo($fresca), $service, $idsAntes);
			},
			onSuccess: function (SendOutcome $outcome) use ($service, $raizId, $hash, &$idsAntes) {
				$this->persistResultadoId($service, $raizId, $outcome, $idsAntes);
				if ($hash !== null) {
					$service->persistResultadoLastPayloadHash($raizId, $hash);
				}
			},
			scheduleRetry: fn(?string $requestUuid) => $app->enqueueOrReplaceJob(self::SLUG, [
				'opportunity' => $raiz,
				'attempt'     => $attempt + 1,
				'requestUuid' => $requestUuid,
			], Plugin::getInstance()->config['integration']['retryDelayJob']),
		);

		return true;
	}

	/** @param list<int> $idsAntes sai com os ids publicados lidos antes do POST */
	private function sendToCult(Opportunity $faseFinal, array $conteudo, ResultadoService $service, array &$idsAntes): SendOutcome
	{
		$provider = $this->provider();
		$edital = new ParEditalId((int) $service->parEditalId((int) $faseFinal->firstPhase->id));

		if ((new ModeloPlanilhaService())->doEnvio($edital->id)['possui']) {
			throw IntegrationError::contract('o edital tem modelo de planilha, e o envio da planilha de contemplados ainda não está disponível');
		}

		$idPublicado = $provider->fetchIdPublicado($edital);
		$idsAntes = $provider->fetchIdsPublicados($edital);

		return $provider->sendResultado($edital, $service->buildResultado($faseFinal, $conteudo, $idPublicado));
	}

	private function provider(): SendsResultado&FetchesResultadosDoEdital
	{
		$provider = Plugin::getInstance()->integrationProvider();

		if (!$provider instanceof SendsResultado || !$provider instanceof FetchesResultadosDoEdital) {
			throw IntegrationError::configuration('client.provider', 'o provedor configurado não envia resultado');
		}

		return $provider;
	}

	/** Hash do conteúdo: a ordem das respostas, das linhas e das marcações não faz um envio diferente. */
	private function contentHash(array $conteudo): string
	{
		$ordenado = [$this->emOrdem($conteudo['respostas'] ?? []), $this->emOrdem($conteudo['linhas'] ?? [])];

		return hash('sha256', (string) json_encode($ordenado, JSON_UNESCAPED_UNICODE));
	}

	private function emOrdem(array $dados): array
	{
		$dados = array_map(fn(mixed $valor) => is_array($valor) ? $this->emOrdem($valor) : $valor, $dados);

		if (!array_is_list($dados)) {
			ksort($dados);

			return $dados;
		}

		usort($dados, fn(mixed $a, mixed $b) => strcmp((string) json_encode($a), (string) json_encode($b)));

		return $dados;
	}

	/**
	 * Resposta sem o id não falha o envio: o resultado que passou a existir na lista é o que acabou de sair.
	 * @param list<int> $idsAntes
	 */
	private function persistResultadoId(ResultadoService $service, int $raizId, SendOutcome $outcome, array $idsAntes): void
	{
		$id = $this->idDaResposta($outcome->response) ?? $this->idNovoNaLista($service, $raizId, $outcome, $idsAntes);

		if ($id === null) {
			App::i()->log->warning("ResultadoCultJob: resposta sem id_par_edital_resultado para oportunidade: {$raizId}");

			return;
		}

		$service->persistParEditalResultadoId($raizId, $id);
	}

	private function idDaResposta(?string $response): ?int
	{
		$decoded = is_string($response) ? json_decode($response, true) : null;
		$id = filter_var($decoded['id_par_edital_resultado'] ?? null, FILTER_VALIDATE_INT);

		return $id === false || $id < 1 ? null : $id;
	}

	private function idNovoNaLista(ResultadoService $service, int $raizId, SendOutcome $outcome, array $idsAntes): ?int
	{
		if ($outcome->result === SendResult::Simulated) {
			return null;
		}

		try {
			$idsDepois = $this->provider()->fetchIdsPublicados(new ParEditalId((int) $service->parEditalId($raizId)));
		} catch (IntegrationError $e) {
			App::i()->log->warning("ResultadoCultJob: lista de resultados indisponível para oportunidade: {$raizId} - " . $e->getMessage());

			return null;
		}

		$novos = array_diff($idsDepois, $idsAntes);

		return $novos === [] ? null : max($novos);
	}
}
