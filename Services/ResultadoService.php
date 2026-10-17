<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;

/**
 * Régua e payload do envio de resultado: a fase final publicada decide, a raiz identifica no PAR.
 */
class ResultadoService
{
    public const TIPO_FINAL = 2;
    public const TIPO_RETIFICADO = 3;
    public const STATUS_PUBLICADO = 3;

    private OpportunityService $opportunityService;

    public function __construct()
    {
        $this->opportunityService = new OpportunityService();
    }

    /** A régua é a fase final publicada — o flag da raiz mente em fluxo contínuo e nunca é olhado. */
    public function isEligibleForResultSync(Opportunity $fase): bool
    {
        if (!$fase->isLastPhase || !$fase->publishedRegistrations) {
            return false;
        }

        $raiz = $fase->firstPhase;
        if (!$raiz || !$raiz->id) {
            return false;
        }

        if ($this->parEditalId((int) $raiz->id) === null) {
            return false;
        }

        return $this->opportunityService->isEligibleForSync($raiz);
    }

    /** Id do edital no PAR, lido do banco: o upsert do worker não aparece na entidade em memória. */
    public function parEditalId(int $raizId): ?int
    {
        $valor = $this->meta($raizId, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID);

        return $valor === null ? null : (int) $valor;
    }

    /** Id do resultado vigente na Conecta, lido do banco pela mesma razão. */
    public function parEditalResultadoId(int $raizId): ?int
    {
        $valor = $this->meta($raizId, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID);

        return $valor === null ? null : (int) $valor;
    }

    /** Monta o corpo do envio: final na primeira vez, retificado nas correções — publicado é imutável. */
    public function buildResultado(Opportunity $faseFinal): Resultado
    {
        $raizId = (int) $faseFinal->firstPhase->id;
        $retificacao = $this->parEditalResultadoId($raizId) !== null;

        return new Resultado(
            id_tipo: $retificacao ? self::TIPO_RETIFICADO : self::TIPO_FINAL,
            id_status: self::STATUS_PUBLICADO,
            data_publicacao: $this->dataPublicacao($faseFinal, $retificacao),
        );
    }

    /** Ponto único de enfileiramento do envio de resultado; o delay padrão é o da integração. */
    public function enqueueResultadoSync(Opportunity $raiz, ?string $delay = null): void
    {
        $delay ??= Plugin::getInstance()->config['integration']['delayJob'];
        $start = (new \DateTime())->modify($delay)->format('Y-m-d H:i:s');

        App::i()->enqueueOrReplaceJob(ResultadoCultJob::SLUG, ['opportunity' => $raiz], $start);
    }

    public function persistParEditalResultadoId(int $raizId, int $id): void
    {
        $conn = App::i()->em->getConnection();
        $key = Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID;

        $updated = $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => (string) $id, 'id' => $raizId, 'key' => $key]
        );
        if ($updated === 0) {
            $conn->executeStatement(
                'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
                ['id' => $raizId, 'key' => $key, 'value' => (string) $id]
            );
        }
    }

    /** No final vale a data da publicação no Mapas; no retificado, o instante da retificação. */
    private function dataPublicacao(Opportunity $faseFinal, bool $retificacao): string
    {
        if (!$retificacao && $faseFinal->publishTimestamp) {
            return $faseFinal->publishTimestamp->format(\DateTime::ATOM);
        }

        return (new \DateTime())->format(\DateTime::ATOM);
    }

    private function meta(int $opportunityId, string $key): ?string
    {
        $valor = App::i()->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opportunityId, 'key' => $key],
        );

        return ($valor === false || trim((string) $valor) === '') ? null : (string) $valor;
    }
}
