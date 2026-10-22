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

        // Linha incompleta derrubaria o resultado inteiro no validador, que é atômico; sem o conjunto
        // pronto o resultado publica sem planilha, e o reenvio sai quando o gestor completar a grade.
        $linhas = (new LinhasDoResultadoService())->montar($raiz);

        return new Resultado(
            id_tipo: ($retificacao ? ResultadoTipo::FinalRetificado : ResultadoTipo::Final)->value,
            id_status: ResultadoStatus::Publicado->value,
            data_publicacao: $dataPublicacao,
            respostas: (new FormularioResultadoService())->respostasParaEnvio($raiz, $dataPublicacao),
            tipo_planilha: $linhas === null ? null : LinhasDoResultadoService::TIPO_PLANILHA,
            linhas: $linhas,
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
