<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Services\CultBrRequestLogService;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Ciclo comum de um envio ao CultBR — histórico por tentativa, retentativa e fechamento
 * do log; o envio, o pós-sucesso e o reenfileiramento são de quem chama.
 */
final class SendRunner
{
    public function __construct(
        private readonly string $jobLabel,
        private readonly int $maxAttempts,
        private readonly CultBrRequestLogService $logService,
    ) {
    }

    /**
     * Executa uma tentativa do envio, registrando-a no histórico e agendando a retentativa quando couber.
     * O uuid nasce na primeira tentativa e viaja via $scheduleRetry: as retentativas entram no mesmo envio.
     *
     * @param callable(): SendOutcome $send
     * @param callable(SendOutcome): void $onSuccess roda dentro do ciclo: falha aqui é tratada como falha do envio
     * @param callable(?string): void $scheduleRetry recebe o requestUuid a preservar
     */
    public function run(
        int $opportunityId,
        string $action,
        ?string $requestUuid,
        ?User $user,
        int $attempt,
        callable $send,
        callable $onSuccess,
        callable $scheduleRetry,
    ): void {
        $app = App::i();

        $app->log->info("{$this->jobLabel} executando tentativa {$attempt}/{$this->maxAttempts} para ação: {$action} para oportunidade: {$opportunityId}");

        $requestLog = $this->recordLog(fn() => $this->logService->startOrResume(
            $opportunityId,
            $action,
            $requestUuid,
            $user
        ));

        try {
            $outcome = $send();
            $this->recordAttempt($requestLog, $outcome, $attempt);
            $falhou = $outcome->result === SendResult::Error;

            if (!$falhou) {
                $onSuccess($outcome);
            }

            $alvo = "para ação: {$action} para oportunidade: {$opportunityId}";

            if ($falhou) {
                $app->log->critical("{$this->jobLabel} falhou sem lançar exceção {$alvo} - envio encerrado");
            } else {
                $app->log->info("{$this->jobLabel} executado com sucesso {$alvo}");
            }
        } catch (\Throwable $e) {
            if ($e instanceof SendFailed) {
                $this->recordAttempt($requestLog, $e->outcome(), $attempt, $e->getMessage());
            }

            $failureMessage = "{$this->jobLabel} falhou na tentativa {$attempt}/{$this->maxAttempts}: " . $e->getMessage() . " - ação: {$action} - oportunidade: {$opportunityId}";

            if ($attempt < $this->maxAttempts && $this->shouldRetry($e)) {
                $app->log->error($failureMessage);
                $scheduleRetry($requestLog?->requestUuid);

                return;
            }

            $app->log->critical($failureMessage . ' - envio encerrado');

            if ($requestLog) {
                $this->recordLog(fn() => $this->logService->finish($requestLog, CultBrRequestLog::RESULT_ERROR));
            }

            return;
        }

        // Fora do try: uma falha ao gravar o log não pode cair no catch acima e
        // reenfileirar um envio que já foi aceito pelo CultBR.
        if ($requestLog) {
            $resultado = $falhou ? CultBrRequestLog::RESULT_ERROR : CultBrRequestLog::RESULT_SUCCESS;

            $this->recordLog(fn() => $this->logService->finish($requestLog, $resultado));
        }
    }

    /** Repete só o que pode mudar de resultado; falha que não sabemos classificar mantém a retentativa. */
    private function shouldRetry(\Throwable $e): bool
    {
        return !$e instanceof IntegrationError || $e->isRetryable();
    }

    /** Grava a tentativa juntando o desfecho do envio com o que só o ciclo sabe: em que tentativa está. */
    private function recordAttempt(
        ?CultBrRequestLog $requestLog,
        SendOutcome $outcome,
        int $attempt,
        ?string $error = null
    ): void {
        if (!$requestLog) {
            return;
        }

        $this->recordLog(fn() => $this->logService->recordAttempt($requestLog, [
            'method'          => $outcome->method,
            'endpoint'        => $outcome->endpoint,
            'payload'         => $outcome->payload,
            'response'        => $outcome->response,
            'responseHeaders' => $outcome->responseHeaders,
            'httpStatus'      => $outcome->httpStatus,
            'status'          => $outcome->result->value,
            'sentAt'          => $outcome->sentAt,
            'durationMs'      => $outcome->durationMs,
            'provider'        => $outcome->provider->value,
            'error'           => $error,
            'attempt'         => $attempt,
            'maxAttempts'     => $this->maxAttempts,
        ]));
    }

    /** O log é acessório: falha ao gravá-lo (tabela ausente, banco fora) não pode alterar o fluxo do envio. */
    private function recordLog(callable $operation)
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            App::i()->log->error('[CultBR] Falha ao registrar log de envio: ' . $e->getMessage());

            return null;
        }
    }
}
