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
    /** Teto do corpo de resposta gravado, em bytes. */
    private const MAX_RESPONSE_LENGTH = 65536;

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

        // Depois de gravar o novo: se o insert falhasse antes, ficaríamos sem o envio antigo
        // e sem o novo.
        $this->abandonPendingLogs($opportunityId, $action, (int) $log->id);

        return $log;
    }

    /**
     * Fecha envios anteriores que ficaram pendentes: enqueueOrReplaceJob descarta o job de
     * retry quando um novo save enfileira o mesmo id, e sem isso o envio antigo apareceria
     * "em andamento" para sempre.
     */
    private function abandonPendingLogs(int $opportunityId, string $action, int $currentLogId): void
    {
        // UPDATE condicional em vez de ler-alterar-gravar: dois workers concorrentes leriam o
        // mesmo conjunto pendente, e o flush do UnitOfWork descarregaria tudo o que estivesse
        // em memória, não só estes registros.
        App::i()->em->createQuery(
            'UPDATE ' . CultBrRequestLog::class . ' l
                SET l.result = :abandoned, l.updateTimestamp = :now
              WHERE l.opportunityId = :opportunityId
                AND l.action = :action
                AND l.result = :pending
                AND l.id <> :currentLogId'
        )->execute([
            'abandoned' => CultBrRequestLog::RESULT_ABANDONED,
            'now' => new \DateTime(),
            'opportunityId' => $opportunityId,
            'action' => $action,
            'pending' => CultBrRequestLog::RESULT_PENDING,
            'currentLogId' => $currentLogId,
        ]);
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
        $attempt->response = $this->asJsonColumn($this->truncateResponse($exchange['response'] ?? null));
        // Também saneados: um header em latin-1 (comum em página de erro de proxy) quebraria a
        // serialização da coluna JSONB e fecharia o EntityManager no flush.
        $attempt->responseHeaders = isset($exchange['responseHeaders']) && is_array($exchange['responseHeaders'])
            ? array_map(fn($header) => $this->toValidUtf8((string) $header), $exchange['responseHeaders'])
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
     *
     * Só transiciona a partir de `pending`: um worker que termine depois de o envio ter sido
     * abandonado por um save mais novo não pode ressuscitá-lo como sucesso.
     */
    public function finish(CultBrRequestLog $log, string $result): void
    {
        if ($log->result !== CultBrRequestLog::RESULT_PENDING) {
            return;
        }

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

        $attemptsByLog = $this->findAttemptsGroupedByLog($logs);
        $userIdByLog = $this->findUserIdsByLog($logs);

        return array_map(
            fn(CultBrRequestLog $log) => $this->toArray(
                $log,
                $attemptsByLog[$log->id] ?? [],
                $userIdByLog[$log->id] ?? null
            ),
            $logs
        );
    }

    public function countByOpportunity(int $opportunityId): int
    {
        return App::i()->repo(CultBrRequestLog::class)->count(['opportunityId' => $opportunityId]);
    }

    /**
     * Tentativas de todos os envios da página numa consulta só, indexadas pelo id do envio.
     * Consulta direta em vez de $log->attempts: a coleção inversa não enxerga a tentativa
     * gravada na mesma sessão (o job grava e lê no mesmo request).
     *
     * @param \AldirBlanc\Entities\CultBrRequestLog[] $logs
     */
    private function findAttemptsGroupedByLog(array $logs): array
    {
        if (!$logs) {
            return [];
        }

        $rows = App::i()->repo(CultBrRequestLogAttempt::class)->findBy(
            ['log' => $logs],
            ['attempt' => 'ASC']
        );

        $grouped = [];
        foreach ($rows as $attempt) {
            $grouped[$attempt->log->id][] = $attempt;
        }

        return $grouped;
    }

    /**
     * Id do autor de cada envio da página, sem hidratar o User: acessar $log->user carregaria
     * o proxy e faria uma consulta por usuário distinto da listagem.
     *
     * @param \AldirBlanc\Entities\CultBrRequestLog[] $logs
     */
    private function findUserIdsByLog(array $logs): array
    {
        if (!$logs) {
            return [];
        }

        $rows = App::i()->em->createQuery(
            'SELECT l.id AS logId, IDENTITY(l.user) AS userId
               FROM ' . CultBrRequestLog::class . ' l
              WHERE l.id IN (:ids)'
        )->execute(['ids' => array_map(fn(CultBrRequestLog $log) => $log->id, $logs)]);

        $userIdByLog = [];
        foreach ($rows as $row) {
            $userIdByLog[$row['logId']] = $row['userId'] !== null ? (int) $row['userId'] : null;
        }

        return $userIdByLog;
    }

    /**
     * Formato consumido pela aba "Logs CultBr".
     *
     * @param \AldirBlanc\Entities\CultBrRequestLogAttempt[] $attemptEntities
     */
    private function toArray(CultBrRequestLog $log, array $attemptEntities, ?int $userId): array
    {
        $attempts = [];
        foreach ($attemptEntities as $attempt) {
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
            // Só o id: o e-mail é dado pessoal e o id já identifica quem disparou.
            'user' => $userId ? ['id' => $userId] : null,
            'createdAt' => $log->createTimestamp ? $log->createTimestamp->format(\DateTime::ATOM) : null,
            // Só depois de finish(): Entity::__construct do core já preenche updateTimestamp,
            // e expor isso num envio pendente daria a entender que ele foi atualizado.
            'updatedAt' => $this->finishedAt($log),
            'attempts' => $attempts,
        ];
    }

    /** Momento em que o envio deixou de estar pendente; nulo enquanto está em curso. */
    private function finishedAt(CultBrRequestLog $log): ?string
    {
        if ($log->result === CultBrRequestLog::RESULT_PENDING || !$log->updateTimestamp) {
            return null;
        }

        return $log->updateTimestamp->format(\DateTime::ATOM);
    }

    /**
     * Corta respostas gigantes (página de erro HTML, dump de stack) antes de gravar: sem teto,
     * cada tentativa levaria o corpo inteiro para o banco. O payload, que é gerado por nós,
     * não passa por aqui.
     */
    private function truncateResponse(mixed $response): mixed
    {
        if (!is_string($response) || strlen($response) <= self::MAX_RESPONSE_LENGTH) {
            return $response;
        }

        // mb_strcut e não substr: cortar no meio de um caractere multibyte (qualquer acento
        // das mensagens em português) produz UTF-8 inválido, o json_encode falha e a resposta
        // seria gravada vazia — perdendo justamente o log de um erro grande.
        return json_encode([
            'raw' => mb_strcut($response, 0, self::MAX_RESPONSE_LENGTH, 'UTF-8'),
            '_truncated' => true,
            '_originalLength' => strlen($response),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A coluna é JSONB: array passa direto, string de resposta não-JSON vai como {"raw": "..."}.
     */
    private function asJsonColumn(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            // A coluna é JSONB: bytes inválidos quebrariam a serialização do Doctrine na hora
            // de gravar, então o texto cru é saneado antes.
            return is_array($decoded) ? $decoded : ['raw' => $this->toValidUtf8($value)];
        }

        return ['raw' => $this->toValidUtf8((string) $value)];
    }

    /** Troca bytes inválidos por U+FFFD: a resposta pode não vir em UTF-8. */
    private function toValidUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
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
