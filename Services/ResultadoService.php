<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Enum\EnvioSituacao;
use AldirBlanc\Enum\ResultadoStatus;
use AldirBlanc\Enum\ResultadoTipo;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesResultadosDoEdital;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Entities\Opportunity;

/**
 * Régua e payload do envio de resultado: a fase final publicada decide, a raiz identifica no PAR.
 */
class ResultadoService
{
    /** Segundos, contados do POST, em que o CultBR ainda pode concluir um envio sem resposta. */
    public const PRAZO_DO_ENVIO_EM_ANDAMENTO = 900;

    private const CACHE_PUBLICADO = 'aldirblanc:cultbr:resultado_publicado:oportunidade:';

    private const CACHE_PUBLICADO_TTL = 60;

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
    /**
     * O CultBR já tem resultado publicado deste edital? O id local basta; sem ele, vale o publicado,
     * que enxerga o resultado criado por fora. Sem leitura conclusiva, fica o que foi aceito por aqui.
     */
    public function isPublicadoNoCultBr(int $raizId): bool
    {
        if ($this->parEditalResultadoId($raizId) !== null) {
            return true;
        }

        $idParEdital = $this->parEditalId($raizId);
        $provider = Plugin::getInstance()->integrationProvider();

        if ($idParEdital === null || !$provider instanceof FetchesResultadosDoEdital) {
            return false;
        }

        $cache = App::i()->cache;
        $chave = self::CACHE_PUBLICADO . $raizId;

        if ($cache->contains($chave)) {
            return (bool) $cache->fetch($chave);
        }

        try {
            $isPublicado = $provider->fetchIdPublicado(new ParEditalId($idParEdital)) !== null;
        } catch (IntegrationError $e) {
            App::i()->log->warning("[CultBR] Não foi possível ler o resultado publicado do edital {$idParEdital}: " . $e->getMessage());

            return false;
        }

        $cache->save($chave, $isPublicado, self::CACHE_PUBLICADO_TTL);

        return $isPublicado;
    }

    /** Depois de um envio aceito, a próxima leitura não pode vir do cache. */
    public function esqueceOPublicado(int $raizId): void
    {
        App::i()->cache->delete(self::CACHE_PUBLICADO . $raizId);
    }

    public function parEditalResultadoId(int $raizId): ?int
    {
        $valor = $this->meta($raizId, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID);

        return $valor === null ? null : (int) $valor;
    }

    /**
     * O que o resultado diz, seja ele final ou retificado.
     * @return array{respostas: ?array, arquivos: list<array>, impedimento: ?string, linhas: list<array>, inscricoes: list<string>, colunas: array<string, string>, totalPendentes: int, pendentes: list<array>}
     */
    public function conteudo(Opportunity $raiz): array
    {
        $planilha = (new ModeloPlanilhaService())->doEnvio((int) $this->parEditalId((int) $raiz->id));
        $formulario = (new FormularioResultadoService())->conteudoParaEnvio($raiz);
        $isPlanilhaComAnexos = $planilha['possui'] && $formulario['arquivos'] !== [];

        return $formulario
            + ['impedimento' => $isPlanilhaComAnexos ? FormularioResultadoService::motivoPlanilhaComAnexos() : null]
            + ($planilha['possui'] && !$isPlanilhaComAnexos ? (new LinhasDoResultadoService($planilha))->montar($raiz) : LinhasDoResultadoService::SEM_PLANILHA);
    }

    /**
     * Corpo do envio: final enquanto o CultBR não tem publicado, retificado depois — publicado é imutável.
     * @param array{respostas: ?array, arquivos: list<array>, linhas: list<array>} $conteudo
     */
    public function buildResultado(Opportunity $faseFinal, array $conteudo, ?int $idPublicado): Resultado
    {
        $retificacao = $idPublicado !== null;

        return new Resultado(
            id_tipo: ($retificacao ? ResultadoTipo::FinalRetificado : ResultadoTipo::Final)->value,
            id_status: ResultadoStatus::Publicado->value,
            data_publicacao: $this->dataPublicacao($faseFinal, $retificacao),
            respostas: $conteudo['respostas'],
            linhas: $conteudo['linhas'],
            arquivos: $conteudo['arquivos'],
        );
    }

    /** O envio exige o formulário completo e a planilha resolvida; sem certeza disso, nada é enfileirado. */
    public function isResultadoFormComplete(Opportunity $raiz): bool
    {
        try {
            return (new FormularioResultadoService())->isEnvioLiberado($raiz);
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

        // Antes de enfileirar: com execução imediata, o job roda dentro da própria chamada.
        $this->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::Aguardando, ['contadoDe' => $this->agora()]);

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

    /**
     * Situação do último envio, ou null se nunca houve; o que passou do prazo em andamento vem sem confirmação.
     * @return array{situacao: string, em: string, tipo: ?int, id: ?int, mensagem: ?string, recusas: array, totalPendentes: int, novoPedidoEm: ?string}|null
     */
    public function ultimoEnvio(int $raizId): ?array
    {
        $gravado = $this->ultimoEnvioGravado($raizId);

        if ($gravado === null) {
            return null;
        }

        $situacao = EnvioSituacao::from($gravado['situacao']);
        $isNoPrazo = $this->isNoPrazo($gravado['contadoDe']);
        $isPedidoVencido = $situacao === EnvioSituacao::Aguardando && !$isNoPrazo;

        return [
            'situacao' => $this->situacaoVista($situacao, $isNoPrazo)->value,
            'em' => $gravado['em'],
            'tipo' => $gravado['tipo'],
            'id' => $gravado['id'],
            'mensagem' => $isPedidoVencido ? self::motivoPedidoVencido() : $gravado['mensagem'],
            'recusas' => $gravado['recusas'],
            'totalPendentes' => $gravado['totalPendentes'] ?? 0,
            'novoPedidoEm' => $situacao->isSemDesfecho() && $isNoPrazo ? $this->fimDoPrazo($gravado['contadoDe'])->format(\DateTime::ATOM) : null,
        ];
    }

    /** Fora do prazo, o pedido que não chegou ao POST foi cancelado, e o que saiu fica sem confirmação. */
    private function situacaoVista(EnvioSituacao $situacao, bool $isNoPrazo): EnvioSituacao
    {
        return match (true) {
            $isNoPrazo || !$situacao->isEmAndamento() => $situacao,
            $situacao === EnvioSituacao::Aguardando => EnvioSituacao::NaoEnviado,
            default => EnvioSituacao::SemConfirmacao,
        };
    }

    /** O pedido ficou na fila além do prazo sem chegar ao POST: o job que o encontrar não envia. */
    public function isPedidoVencido(int $raizId): bool
    {
        $gravado = $this->ultimoEnvioGravado($raizId);

        return $gravado !== null
            && EnvioSituacao::from($gravado['situacao']) === EnvioSituacao::Aguardando
            && !$this->isNoPrazo($gravado['contadoDe']);
    }

    public static function motivoPedidoVencido(): string
    {
        return i::__('O último pedido de envio não saiu no prazo e foi cancelado. Nada foi enviado; peça o envio de novo.');
    }

    /**
     * Envio que saiu e ficou sem desfecho, a conferir na origem antes de qualquer outro.
     * @return array{idsAntes: list<int>, hash: string, tipo: int, em: string}|null
     */
    public function envioAConferir(int $raizId): ?array
    {
        return $this->ultimoEnvioGravado($raizId)['aConferir'] ?? null;
    }

    /** O CultBR ainda pode estar processando um envio sem resposta? */
    public function isEnvioAConferirNoPrazo(int $raizId): bool
    {
        $aConferir = $this->envioAConferir($raizId);

        return $aConferir !== null && $this->isNoPrazo($aConferir['em']);
    }

    /** Grava a situação; o envio a conferir e o início do prazo seguem os mesmos até que `$dados` os mude. */
    public function persistUltimoEnvio(int $raizId, EnvioSituacao $situacao, array $dados = []): void
    {
        $anterior = $this->ultimoEnvioGravado($raizId);

        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_ULTIMO_ENVIO, (string) json_encode($dados + [
            'oportunidade' => $raizId,
            'situacao' => $situacao->value,
            'em' => $this->agora(),
            'contadoDe' => $anterior['contadoDe'] ?? $this->agora(),
            'aConferir' => $anterior['aConferir'] ?? null,
            'tipo' => null,
            'id' => null,
            'mensagem' => null,
            'recusas' => [],
            'totalPendentes' => 0,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * A recusa da questão deixa de valer quando a resposta muda; a data e a situação do envio ficam.
     * @param list<int> $idsQuestao
     */
    public function marcaRecusasAlteradas(int $raizId, array $idsQuestao): void
    {
        $gravado = $this->ultimoEnvioGravado($raizId);

        if ($gravado === null || $idsQuestao === [] || empty($gravado['recusas'])) {
            return;
        }

        $recusas = array_map(
            fn(array $recusa) => in_array($recusa['id_questao'] ?? null, $idsQuestao, true)
                ? ['alterada' => true] + $recusa
                : $recusa,
            $gravado['recusas'],
        );

        if ($recusas !== $gravado['recusas']) {
            $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_ULTIMO_ENVIO, (string) json_encode(['recusas' => $recusas] + $gravado, JSON_UNESCAPED_UNICODE));
        }
    }

    /** Duplicar ou gerar edital copia o metadado: o de outra oportunidade não vale. */
    private function ultimoEnvioGravado(int $raizId): ?array
    {
        $gravado = json_decode((string) $this->meta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_ULTIMO_ENVIO), true);

        return is_array($gravado) && ($gravado['oportunidade'] ?? null) === $raizId ? $gravado : null;
    }

    private function agora(): string
    {
        return (new \DateTime())->format(\DateTime::ATOM);
    }

    private function fimDoPrazo(string $contadoDe): \DateTime
    {
        return (new \DateTime($contadoDe))->modify('+' . self::PRAZO_DO_ENVIO_EM_ANDAMENTO . ' seconds');
    }

    private function isNoPrazo(string $contadoDe): bool
    {
        return new \DateTime() < $this->fimDoPrazo($contadoDe);
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
