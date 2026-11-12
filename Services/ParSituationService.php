<?php

namespace AldirBlanc\Services;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Integration\FetchesParSituation;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\i;

/** Por que o ente não tem exercício habilitado, consultado na Conecta e guardado por um dia. */
class ParSituationService
{
    private const VALIDITY_SECONDS = 86400;
    private const RETRY_AFTER_FAILURE_SECONDS = 3600;
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly ?FetchesParSituation $provider = null)
    {
    }

    /** Motivo para o ente sem árvore do PAR, consultando a Conecta quando a situação gravada venceu. */
    public function reasonFor(FederativeEntity $entity): ?string
    {
        if ($this->hasTree($entity)) {
            return null;
        }

        $stored = $entity->parSituation;
        if (!$this->isFresh($stored) && !$this->failedRecently($stored)) {
            $stored = $this->refresh($entity, $stored);
        }

        return $this->isFresh($stored) ? $this->describe($stored['cadastros'] ?? []) : null;
    }

    /** O mesmo motivo, só com o que está gravado: serve às páginas que não podem esperar a Conecta. */
    public function storedReasonFor(FederativeEntity $entity): ?string
    {
        $stored = $entity->parSituation;

        return !$this->hasTree($entity) && $this->isFresh($stored) ? $this->describe($stored['cadastros'] ?? []) : null;
    }

    private function hasTree(FederativeEntity $entity): bool
    {
        // empty() em propriedade mágica cai no __isset e ignora o valor — ler antes de testar.
        $exercices = $entity->exercices;

        return !empty($exercices);
    }

    private function isFresh(?array $situation): bool
    {
        return $this->secondsSince($situation['checkedAt'] ?? null) < self::VALIDITY_SECONDS;
    }

    private function failedRecently(?array $situation): bool
    {
        return $this->secondsSince($situation['failedAt'] ?? null) < self::RETRY_AFTER_FAILURE_SECONDS;
    }

    private function secondsSince(?string $timestamp): int
    {
        $moment = \DateTime::createFromFormat(self::TIMESTAMP_FORMAT, (string) $timestamp);

        return $moment === false ? PHP_INT_MAX : time() - $moment->getTimestamp();
    }

    /** Falha da Conecta vai para o log e só marca a hora: a situação gravada fica e a tela segue com o texto geral. */
    private function refresh(FederativeEntity $entity, ?array $stored): ?array
    {
        $provider = $this->provider ?? Plugin::getInstance()->integrationProvider();
        if (!$provider instanceof FetchesParSituation) {
            return $stored;
        }

        $agora = (new \DateTime())->format(self::TIMESTAMP_FORMAT);
        try {
            $situation = ['checkedAt' => $agora, 'cadastros' => $provider->fetchParSituation($entity->document)];
        } catch (\Throwable $e) {
            App::i()->log->warning("[Gestores CultBR] Situação do PAR indisponível | Ente: {$entity->document} | Erro: " . $e->getMessage());
            $situation = ['failedAt' => $agora] + ($stored ?? []);
        }

        $this->save($entity, $situation);

        return $situation;
    }

    private function save(FederativeEntity $entity, array $situation): void
    {
        $app = App::i();
        $entity->parSituation = $situation;

        $app->disableAccessControl();
        try {
            $app->em->persist($entity);
            $app->em->flush();
        } finally {
            $app->enableAccessControl();
        }
    }

    private function describe(array $cadastros): string
    {
        $vigentes = array_values(array_filter($cadastros, fn(array $cadastro) => !($cadastro['excluido'] ?? false)));
        if ($vigentes === []) {
            return i::__('Sem PAR cadastrado');
        }

        usort($vigentes, fn(array $a, array $b) => ($b['ano'] ?? 0) <=> ($a['ano'] ?? 0));
        foreach ($vigentes as $cadastro) {
            if (trim((string) ($cadastro['situacao'] ?? '')) !== '') {
                return sprintf(i::__('PAR %s: %s'), $cadastro['ano'] ?? '', $cadastro['situacao']);
            }
        }

        return sprintf(i::__('PAR %s sem situação informada'), $vigentes[0]['ano'] ?? '');
    }
}
