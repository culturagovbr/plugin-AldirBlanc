<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;
use MapasCulturais\Definitions\JobType;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\EnvioSituacao;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\PlanilhaIndisponivel;
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

	/** O POST nunca se repete sozinho: sem resposta, o próximo pedido confere na origem se ele chegou. */
	const MAX_ATTEMPTS = 1;

	public const ACTION = 'resultado';

	protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
	{
		$opportunity = $data['opportunity'];

		return "resultado-cult:{$opportunity->id}";
	}

	public function _execute(Job $job)
	{
		$app = App::i();
		$raizId = (int) $job->opportunity->id;
		$service = new ResultadoService();

		if ($service->isEnvioAConferirNoPrazo($raizId)) {
			$app->log->info("ResultadoCultJob: edital {$raizId} tem envio sem resposta dentro do prazo - nada enviado");

			return true;
		}

		// Recarrega e recheca no estado vigente: entre enfileirar e executar o gestor pode ter despublicado.
		$raiz = $app->repo('Opportunity')->find($raizId);
		$faseFinal = $raiz?->lastPhase;
		$impedimento = $this->impedimento($service, $raiz, $faseFinal);

		if ($impedimento !== null) {
			$app->log->info("ResultadoCultJob: edital {$raizId} - {$impedimento} - nada enviado");
			$service->persistUltimoEnvio($raizId, EnvioSituacao::NaoEnviado, ['mensagem' => $impedimento]);

			return true;
		}

		$conteudo = $this->conteudo($service, $raiz);
		$hash = $conteudo !== null ? $this->contentHash($conteudo) : null;
		$falha = null;

		try {
			$isAdotado = $this->concilia($service, $raizId);
		} catch (IntegrationError $falha) {
			$isAdotado = false;
		}

		// Alteração que não muda o conteúdo não vira retificado novo (eles coexistem publicados).
		if ($falha === null && $hash !== null && $service->resultadoLastPayloadHash($raizId) === $hash) {
			$app->log->info("ResultadoCultJob: edital {$raizId} - payload idêntico ao último enviado, reenvio dispensado");

			if (!$isAdotado) {
				$service->persistUltimoEnvio($raizId, EnvioSituacao::NadaMudou);
			}

			return true;
		}

		$runner = new SendRunner('ResultadoCultJob', self::MAX_ATTEMPTS, new CultBrRequestLogService());

		$runner->run(
			opportunityId: $raizId,
			action: self::ACTION,
			requestUuid: null,
			user: $job->user ?? null,
			attempt: self::MAX_ATTEMPTS,
			send: fn() => $falha !== null ? throw $falha : $this->sendToCult($service, $raiz, $faseFinal, $conteudo),
			onSuccess: fn(SendOutcome $outcome) => $this->registraAceite($service, $raizId, $outcome),
			scheduleRetry: fn() => null,
		);

		return true;
	}

	/** O que impede o envio na hora de executar, ou null. */
	private function impedimento(ResultadoService $service, ?Opportunity $raiz, ?Opportunity $faseFinal): ?string
	{
		if (!$faseFinal || !$service->isEligibleForResultSync($faseFinal)) {
			return i::__('O resultado do edital não está publicado ou o edital não está integrado ao CultBR.');
		}

		try {
			return (new FormularioResultadoService())->isFormularioCompleto($raiz)
				? null
				: i::__('O formulário de resultado está incompleto.');
		} catch (IntegrationError) {
			// Completude indisponível não encerra aqui: o envio adiante acusa o mesmo erro no histórico.
			return null;
		}
	}

	private function conteudo(ResultadoService $service, Opportunity $raiz): ?array
	{
		try {
			return $service->conteudo($raiz);
		} catch (\Throwable) {
			// O envio remonta e registra a falha no histórico.
			return null;
		}
	}

	/** Confere na origem o envio anterior que ficou sem resposta; diz se ele chegou e foi adotado. */
	private function concilia(ResultadoService $service, int $raizId): bool
	{
		$aConferir = $service->envioAConferir($raizId);

		if ($aConferir === null) {
			return false;
		}

		try {
			$id = $this->idNovo($this->provider()->fetchIdsPublicados($this->edital($service, $raizId)), $aConferir['idsAntes']);
		} catch (IntegrationError $e) {
			$service->persistUltimoEnvio($raizId, EnvioSituacao::SemConfirmacao, [
				'contadoDe' => $aConferir['em'],
				'mensagem' => $this->motivoDaConsulta(),
			]);

			throw $e;
		}

		if ($id === null) {
			$service->persistUltimoEnvio($raizId, EnvioSituacao::Aguardando, ['aConferir' => null]);

			return false;
		}

		$service->persistParEditalResultadoId($raizId, $id);
		$service->persistResultadoLastPayloadHash($raizId, $aConferir['hash']);
		$service->persistUltimoEnvio($raizId, EnvioSituacao::Publicado, ['aConferir' => null, 'id' => $id, 'tipo' => $aConferir['tipo']]);

		return true;
	}

	private function sendToCult(ResultadoService $service, Opportunity $raiz, Opportunity $faseFinal, ?array $conteudo): SendOutcome
	{
		$raizId = (int) $raiz->id;

		try {
			$provider = $this->provider();
			$edital = $this->edital($service, $raizId);
			$conteudo ??= $service->conteudo($raiz);
			$this->exigeEditalSemPlanilha($edital);
			$payload = $service->buildResultado($faseFinal, $conteudo, $provider->fetchIdPublicado($edital));
			$idsAntes = $provider->fetchIdsPublicados($edital);
		} catch (\Throwable $e) {
			$service->persistUltimoEnvio($raizId, EnvioSituacao::NaoEnviado, ['mensagem' => $this->motivoSemPost($e)]);

			throw $e;
		}

		$agora = (new \DateTime())->format(\DateTime::ATOM);
		$service->persistUltimoEnvio($raizId, EnvioSituacao::Enviando, [
			'contadoDe' => $agora,
			'tipo' => $payload->id_tipo,
			'aConferir' => ['idsAntes' => $idsAntes, 'hash' => $this->contentHash($conteudo), 'tipo' => $payload->id_tipo, 'em' => $agora],
		]);

		try {
			$outcome = $provider->sendResultado($edital, $payload);
		} catch (\Throwable $e) {
			$this->registraFalha($service, $raizId, $payload->id_tipo, $e);

			throw $e;
		}

		if ($outcome->result === SendResult::Error) {
			$service->persistUltimoEnvio($raizId, EnvioSituacao::SemConfirmacao, ['tipo' => $payload->id_tipo]);
		}

		return $outcome;
	}

	private function exigeEditalSemPlanilha(ParEditalId $edital): void
	{
		if ((new ModeloPlanilhaService())->doEnvio($edital->id)['possui']) {
			throw new PlanilhaIndisponivel(i::__('O envio da planilha de contemplados ainda não está disponível.'));
		}
	}

	private function motivoSemPost(\Throwable $erro): string
	{
		return match (true) {
			$erro instanceof PlanilhaIndisponivel => $erro->getMessage(),
			$erro instanceof IntegrationError && $erro->kind() === IntegrationError::KIND_CONFIGURATION => i::__('O envio de resultado ao CultBR não está configurado nesta instalação.'),
			$erro instanceof IntegrationError => $this->motivoDaConsulta(),
			default => i::__('Não foi possível montar o envio do resultado.'),
		};
	}

	private function motivoDaConsulta(): string
	{
		return i::__('Não foi possível consultar o CultBR. Tente novamente em instantes.');
	}

	/** Só 4xx diz que o CultBR recusou; qualquer outra falha depois do POST pode ter publicado. */
	private function registraFalha(ResultadoService $service, int $raizId, int $tipo, \Throwable $erro): void
	{
		$status = $erro instanceof IntegrationError ? $erro->httpStatus() : null;

		match (true) {
			$status >= 400 && $status < 500 => $service->persistUltimoEnvio($raizId, EnvioSituacao::Recusado, [
				'aConferir' => null,
				'tipo' => $tipo,
				'mensagem' => i::__('O CultBR recusou o envio.'),
			]),
			$erro instanceof IntegrationError && $erro->kind() === IntegrationError::KIND_CONFIGURATION => $service->persistUltimoEnvio($raizId, EnvioSituacao::NaoEnviado, [
				'aConferir' => null,
				'mensagem' => $this->motivoSemPost($erro),
			]),
			default => $service->persistUltimoEnvio($raizId, EnvioSituacao::SemConfirmacao, [
				'tipo' => $tipo,
				'mensagem' => i::__('O CultBR não confirmou o envio.'),
			]),
		};
	}

	private function edital(ResultadoService $service, int $raizId): ParEditalId
	{
		return new ParEditalId((int) $service->parEditalId($raizId));
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

	/** Resposta sem o id não falha o envio: o resultado que passou a existir na lista é o que acabou de sair. */
	private function registraAceite(ResultadoService $service, int $raizId, SendOutcome $outcome): void
	{
		$enviado = $service->envioAConferir($raizId);
		$service->persistResultadoLastPayloadHash($raizId, $enviado['hash']);

		if ($outcome->result === SendResult::Simulated) {
			$service->persistUltimoEnvio($raizId, EnvioSituacao::Simulado, ['aConferir' => null, 'tipo' => $enviado['tipo']]);

			return;
		}

		$id = $this->idDaResposta($outcome->response) ?? $this->idNovoNaLista($service, $raizId, $enviado['idsAntes']);

		if ($id === null) {
			App::i()->log->warning("ResultadoCultJob: resposta sem id_par_edital_resultado para oportunidade: {$raizId}");
		} else {
			$service->persistParEditalResultadoId($raizId, $id);
		}

		$service->persistUltimoEnvio($raizId, EnvioSituacao::Publicado, ['aConferir' => null, 'id' => $id, 'tipo' => $enviado['tipo']]);
	}

	private function idDaResposta(?string $response): ?int
	{
		$decoded = is_string($response) ? json_decode($response, true) : null;
		$id = filter_var($decoded['id_par_edital_resultado'] ?? null, FILTER_VALIDATE_INT);

		return $id === false || $id < 1 ? null : $id;
	}

	private function idNovoNaLista(ResultadoService $service, int $raizId, array $idsAntes): ?int
	{
		try {
			return $this->idNovo($this->provider()->fetchIdsPublicados($this->edital($service, $raizId)), $idsAntes);
		} catch (IntegrationError $e) {
			App::i()->log->warning("ResultadoCultJob: lista de resultados indisponível para oportunidade: {$raizId} - " . $e->getMessage());

			return null;
		}
	}

	private function idNovo(array $idsDepois, array $idsAntes): ?int
	{
		$novos = array_diff($idsDepois, $idsAntes);

		return $novos === [] ? null : max($novos);
	}
}
