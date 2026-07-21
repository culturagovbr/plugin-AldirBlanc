<?php

namespace AldirBlanc\Services;

use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Histórico de envios de oportunidade ao CultBR (aba "Logs CultBr").
 *
 * Um CultBrRequestLog por envio, com uma CultBrRequestLogAttempt por tentativa do
 * OportunidadeCultJob. Persiste com persist()/flush() — e não com save() —, seguindo
 * GestorCultJob::associate*, porque estas entidades não têm dono nem permissão associada.
 */
class CultBrRequestLogService
{
    /**
     * Recupera o envio pelo uuid (retentativa) ou cria um novo (primeira tentativa).
     * O autor só é gravado na criação: na retentativa o job roda no worker, sem usuário logado,
     * e quem disparou continua sendo quem salvou a oportunidade.
     */
    public function startOrResume(
        int $opportunityId,
        string $action,
        ?string $requestUuid = null,
        ?User $user = null
    ): CultBrRequestLog {
        $app = App::i();

        if ($requestUuid) {
            $existing = $app->repo(CultBrRequestLog::class)->findOneBy(['requestUuid' => $requestUuid]);
            if ($existing) {
                return $existing;
            }
        }

        $log = new CultBrRequestLog();
        $log->requestUuid = $requestUuid ?: $this->generateUuid();
        $log->opportunityId = $opportunityId;
        $log->action = $action;
        $log->result = CultBrRequestLog::RESULT_PENDING;
        $log->user = $user;
        $log->createTimestamp = new \DateTime();

        $app->em->persist($log);
        $app->em->flush();

        return $log;
    }

    /**
     * Grava uma tentativa. `$exchange` vem do recorder do AbstractClient (payload, resposta,
     * status HTTP, duração), acrescido de `attempt`/`maxAttempts` pelo job.
     */
    public function recordAttempt(CultBrRequestLog $log, array $exchange): CultBrRequestLogAttempt
    {
        $app = App::i();

        $attempt = new CultBrRequestLogAttempt();
        $attempt->log = $log;
        $attempt->attempt = (int) ($exchange['attempt'] ?? 1);
        $attempt->maxAttempts = (int) ($exchange['maxAttempts'] ?? 1);
        $attempt->endpoint = (string) ($exchange['endpoint'] ?? '');
        $attempt->httpMethod = (string) ($exchange['method'] ?? 'PUT');
        $attempt->httpStatus = isset($exchange['httpStatus']) ? (int) $exchange['httpStatus'] : null;
        $attempt->payload = $this->asJsonColumn($exchange['payload'] ?? null);
        $attempt->response = $this->asJsonColumn($exchange['response'] ?? null);
        $attempt->responseHeaders = isset($exchange['responseHeaders']) && is_array($exchange['responseHeaders'])
            ? $exchange['responseHeaders']
            : null;
        $attempt->errorMessage = $exchange['error'] ?? null;
        // Desfecho ausente é falha: sem informação, não se assume que o envio deu certo.
        $attempt->result = (string) ($exchange['status'] ?? CultBrRequestLogAttempt::RESULT_ERROR);
        $attempt->sentAt = $exchange['sentAt'] ?? new \DateTime();
        $attempt->durationMs = isset($exchange['durationMs']) ? (int) $exchange['durationMs'] : null;

        $app->em->persist($attempt);
        $app->em->flush();

        return $attempt;
    }

    /**
     * Fecha o envio. Enquanto restarem retentativas, o status segue `pending`.
     */
    public function finish(CultBrRequestLog $log, string $result): void
    {
        $app = App::i();

        $log->result = $result;
        $log->updateTimestamp = new \DateTime();

        $app->em->persist($log);
        $app->em->flush();
    }

    /**
     * Envios de uma oportunidade, mais recentes primeiro, com as tentativas aninhadas.
     */
    public function findByOpportunity(int $opportunityId, int $skip = 0, int $limit = 20): array
    {
        $app = App::i();

        $logs = $app->repo(CultBrRequestLog::class)->findBy(
            ['opportunityId' => $opportunityId],
            ['createTimestamp' => 'DESC'],
            $limit,
            $skip
        );

        return array_map(fn(CultBrRequestLog $log) => $this->toArray($log), $logs);
    }

    public function countByOpportunity(int $opportunityId): int
    {
        $app = App::i();

        return (int) $app->em->getConnection()->fetchOne(
            'SELECT count(*) FROM cultbr_request_log WHERE opportunity_id = :id',
            ['id' => $opportunityId]
        );
    }

    /**
     * Formato consumido pela aba "Logs CultBr".
     */
    public function toArray(CultBrRequestLog $log): array
    {
        // Consulta direta em vez de $log->attempts: a coleção inversa não enxerga a tentativa
        // gravada na mesma sessão (o job grava e lê no mesmo request).
        $rows = App::i()->repo(CultBrRequestLogAttempt::class)->findBy(
            ['log' => $log],
            ['attempt' => 'ASC']
        );

        $attempts = [];
        foreach ($rows as $attempt) {
            $attempts[] = [
                'attempt' => $attempt->attempt,
                'maxAttempts' => $attempt->maxAttempts,
                'status' => $attempt->result,
                'endpoint' => $attempt->endpoint,
                'httpMethod' => $attempt->httpMethod,
                'httpStatus' => $attempt->httpStatus,
                'payload' => $attempt->payload,
                'response' => $attempt->response,
                'responseHeaders' => $attempt->responseHeaders,
                'errorMessage' => $attempt->errorMessage,
                'sentAt' => $attempt->sentAt ? $attempt->sentAt->format(\DateTime::ATOM) : null,
                'durationMs' => $attempt->durationMs,
            ];
        }

        return [
            'requestUuid' => $log->requestUuid,
            'opportunityId' => $log->opportunityId,
            'action' => $log->action,
            'status' => $log->result,
            'user' => $this->userToArray($log),
            'createdAt' => $log->createTimestamp ? $log->createTimestamp->format(\DateTime::ATOM) : null,
            'updatedAt' => $log->updateTimestamp ? $log->updateTimestamp->format(\DateTime::ATOM) : null,
            'attempts' => $attempts,
        ];
    }

    /**
     * Autor do envio para a aba: só o id. Nulo quando não houve usuário (sync em lote, CLI).
     * O e-mail não é exposto — é dado pessoal e o id já identifica quem disparou.
     */
    private function userToArray(CultBrRequestLog $log): ?array
    {
        $user = $log->user;

        return $user ? ['id' => (int) $user->id] : null;
    }

    /**
     * A coluna é JSONB: array passa direto, string de resposta não-JSON vai como {"raw": "..."}.
     */
    private function asJsonColumn($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : ['raw' => $value];
        }

        return ['raw' => (string) $value];
    }

    /**
     * UUID v4 sem dependência externa (o plugin não tem lib de uuid).
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
