<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Enum\ResultadoStatus;
use AldirBlanc\Enum\ResultadoTipo;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;

/**
 * Régua e payload do envio de resultado: a fase final publicada decide, a raiz identifica no PAR.
 */
class ResultadoService
{
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
        $raiz = $faseFinal->firstPhase;
        $retificacao = $this->parEditalResultadoId((int) $raiz->id) !== null;
        $dataPublicacao = $this->dataPublicacao($faseFinal, $retificacao);

        return new Resultado(
            id_tipo: ($retificacao ? ResultadoTipo::FinalRetificado : ResultadoTipo::Final)->value,
            id_status: ResultadoStatus::Publicado->value,
            data_publicacao: $dataPublicacao,
            respostas: (new FormularioResultadoService())->respostasParaEnvio($raiz, $dataPublicacao),
        );
    }

    /** O envio exige o formulário de resultado completo; sem certeza da completude, nada é enfileirado. */
    public function isResultadoFormComplete(Opportunity $raiz): bool
    {
        try {
            return (new FormularioResultadoService())->isFormularioCompleto($raiz);
        } catch (IntegrationError $e) {
            App::i()->log->warning("Resultado do edital {$raiz->id}: completude do formulário indisponível - " . $e->getMessage());

            return false;
        }
    }

    /** Alteração pendente desde o último envio? O carimbo vive na fase final e quem o grava é o reenvio por alteração. */
    public function hasPendingResultChange(Opportunity $faseFinal): bool
    {
        return $this->meta((int) $faseFinal->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE) !== null;
    }

    /** Janela do debounce entre alterações. */
    private const RESYNC_DEBOUNCE = '+1 minute';

    /** Teto da janela: alcançado, o job agendado roda mesmo que as alterações continuem. */
    private const RESYNC_WINDOW_CEILING_SECONDS = 600;

    /** @var array<int, true> fases com reenvio adiado: postRemove roda dentro do flush e não tolera outro. */
    private static array $deferredResyncs = [];

    private static bool $deferredFlushRegistered = false;

    /** Mudança numa Registration só dispara reenvio se a fase é a final publicada de edital elegível e já enviado. */
    public function isEligibleForResultResync(Opportunity $fase): bool
    {
        if (!$this->isEligibleForResultSync($fase)) {
            return false;
        }

        return $this->parEditalResultadoId((int) $fase->firstPhase->id) !== null;
    }

    /**
     * Agenda o reenvio coalescendo alterações: debounce de 1 minuto remarcado a cada mudança,
     * até o teto de 10 minutos desde a primeira alteração da janela — alcançado o teto com um
     * job pendente, não remarca e deixa o job rodar no horário já agendado.
     */
    public function scheduleResultadoResync(Opportunity $faseFinal): void
    {
        $faseId = (int) $faseFinal->id;
        $raiz = $faseFinal->firstPhase;
        $dirtySince = $this->meta($faseId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE);

        if ($dirtySince === null) {
            $this->persistResultadoDirtySince($faseId);
            $this->enqueueResultadoSync($raiz, self::RESYNC_DEBOUNCE);

            return;
        }

        $idadeDaJanela = time() - (new \DateTime($dirtySince))->getTimestamp();

        if ($idadeDaJanela >= self::RESYNC_WINDOW_CEILING_SECONDS) {
            if ($this->hasPendingResultadoJob($raiz)) {
                return;
            }

            // Carimbo órfão (o job daquela janela já morreu): reinicia a janela em vez de nunca remarcar.
            $this->persistResultadoDirtySince($faseId);
        }

        $this->enqueueResultadoSync($raiz, self::RESYNC_DEBOUNCE);
    }

    /** Marca para enfileirar fora do flush; o enfileiramento grava um Job e flusharia dentro do postRemove. */
    public function deferResultadoResync(Opportunity $faseFinal): void
    {
        self::$deferredResyncs[(int) $faseFinal->id] = true;

        if (!self::$deferredFlushRegistered) {
            self::$deferredFlushRegistered = true;
            register_shutdown_function(static fn() => (new self())->flushDeferredResultadoResyncs());
        }
    }

    /** Agenda os reenvios adiados; seguro de chamar mais de uma vez — inclusive no shutdown, onde não pode lançar. */
    public function flushDeferredResultadoResyncs(): void
    {
        $faseIds = array_keys(self::$deferredResyncs);
        self::$deferredResyncs = [];

        foreach ($faseIds as $faseId) {
            try {
                $fase = App::i()->repo('Opportunity')->find($faseId);

                if ($fase && $this->isEligibleForResultResync($fase)) {
                    $this->scheduleResultadoResync($fase);
                }
            } catch (\Throwable $e) {
                App::i()->log->warning("Reenvio de resultado adiado falhou para a fase {$faseId}: " . $e->getMessage());
            }
        }
    }

    public function clearResultadoDirtySince(int $faseId): void
    {
        App::i()->em->getConnection()->executeStatement(
            'DELETE FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $faseId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE]
        );
    }

    private function persistResultadoDirtySince(int $faseId): void
    {
        $conn = App::i()->em->getConnection();
        $key = Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE;
        $agora = (new \DateTime())->format('Y-m-d H:i:s');

        $updated = $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => $agora, 'id' => $faseId, 'key' => $key]
        );
        if ($updated === 0) {
            $conn->executeStatement(
                'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
                ['id' => $faseId, 'key' => $key, 'value' => $agora]
            );
        }
    }

    private function hasPendingResultadoJob(Opportunity $raiz): bool
    {
        $app = App::i();
        $tipo = $app->getRegisteredJobType(ResultadoCultJob::SLUG);
        $id = $tipo->generateId(['opportunity' => $raiz], '', '', 1);

        return $app->repo('Job')->findOneBy(['id' => $id]) !== null;
    }

    /** Ponto único de enfileiramento do envio de resultado; o delay padrão é o da integração. */
    public function enqueueResultadoSync(Opportunity $raiz, ?string $delay = null): void
    {
        if (!$this->isResultadoFormComplete($raiz)) {
            App::i()->log->info("Resultado do edital {$raiz->id} não enfileirado: formulário de resultado incompleto");

            return;
        }

        $delay ??= Plugin::getInstance()->config['integration']['delayJob'];
        $start = (new \DateTime())->modify($delay)->format('Y-m-d H:i:s');

        App::i()->enqueueOrReplaceJob(ResultadoCultJob::SLUG, ['opportunity' => $raiz], $start);
    }

    public function persistParEditalResultadoId(int $raizId, int $id): void
    {
        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, (string) $id);
    }

    /** Hash do conteúdo do último resultado enviado (null se nunca enviou). */
    public function resultadoLastPayloadHash(int $raizId): ?string
    {
        return $this->meta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_LAST_HASH);
    }

    public function persistResultadoLastPayloadHash(int $raizId, string $hash): void
    {
        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_LAST_HASH, $hash);
    }

    private function upsertMeta(int $raizId, string $key, string $value): void
    {
        $conn = App::i()->em->getConnection();

        $updated = $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => $value, 'id' => $raizId, 'key' => $key]
        );
        if ($updated === 0) {
            $conn->executeStatement(
                'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
                ['id' => $raizId, 'key' => $key, 'value' => $value]
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
