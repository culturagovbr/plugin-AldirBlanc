<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesFormularioResultado;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;

/** Estrutura do formulário de resultado do PAR: resolve o tipo de ação do edital e busca com cache. */
class FormularioResultadoService
{
    public const ESTADO_SEM_FORMULARIO = 'sem-formulario';
    public const ESTADO_AGUARDANDO_PUBLICACAO = 'aguardando-publicacao';
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_COMPLETO = 'completo';
    public const ESTADO_ENVIADO = 'enviado';

    private const CACHE_ESTRUTURA = 'aldirblanc:cultbr:formulario_resultado:tipo_acao:';
    private const CACHE_TIPO_ACAO = 'aldirblanc:cultbr:par_tipo_acao:oportunidade:';

    /** @var array<int, array<int, mixed>> memoização por request: o save e o estado reusam o mesmo cálculo */
    private array $automaticasPorRaiz = [];

    /** Estrutura do formulário do edital, ou null quando ele não tem formulário de resultado no PAR. */
    public function formStructureForOpportunity(int $opportunityId): ?array
    {
        $idTipoAcao = $this->parTipoAcaoForOpportunity($opportunityId);

        return $idTipoAcao === null ? null : $this->formStructureForTipoAcao($idTipoAcao);
    }

    /** Estrutura do formulário do tipo de ação, ou null quando não há formulário publicado (404). */
    public function formStructureForTipoAcao(int $idTipoAcao): ?array
    {
        return $this->cached(
            self::CACHE_ESTRUTURA . $idTipoAcao,
            fn() => $this->provider()->fetchFormularioResultado($idTipoAcao),
        );
    }

    /** Tipo de ação PAR do edital, ou null quando a oportunidade não existe na origem ou vem sem o tipo. */
    public function parTipoAcaoForOpportunity(int $opportunityId): ?int
    {
        return $this->cached(
            self::CACHE_TIPO_ACAO . $opportunityId,
            fn() => $this->provider()->fetchParTipoAcao(new OpportunityId($opportunityId)),
        );
    }

    /** O retrato que a tela do edital consome: estado do ciclo, estrutura e respostas salvas. */
    public function formStateForOpportunity(Opportunity $raiz): array
    {
        $estrutura = $this->formStructureForOpportunity((int) $raiz->id);
        $respostas = $this->savedRespostas((int) $raiz->id) + $this->respostasSugeridas($raiz, $estrutura);
        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);
        $estado = $this->estado($raiz, $estrutura, $automaticas + $respostas);

        return [
            'estado' => $estado,
            'estrutura' => $estrutura,
            // (object): vazio precisa serializar como {} — como [] o cliente trataria respostas como lista.
            'respostas' => (object) $respostas,
            'respostasAutomaticas' => (object) $automaticas,
            'questoesSemFonte' => $this->questoesSemFonte($raiz, $estrutura, $automaticas),
            'envioHabilitado' => $estado === self::ESTADO_COMPLETO,
            'dataPublicacao' => $this->dataPublicacaoAutomatica($raiz),
        ];
    }

    /**
     * Respostas calculadas do próprio edital — o formulário não repete o que o CultEditais sabe:
     * contagens reais de inscritas e contempladas e, quando as Configurações de categorias do
     * edital existem, o valor repassado e as linhas por categoria.
     * @return array<int, mixed> por id_questao; questão sem dado calculável fica de fora (vira campo)
     */
    public function respostasAutomaticas(Opportunity $raiz, ?array $estrutura): array
    {
        if ($estrutura === null) {
            return [];
        }

        if (isset($this->automaticasPorRaiz[(int) $raiz->id])) {
            return $this->automaticasPorRaiz[(int) $raiz->id];
        }

        $automaticas = [];

        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    $valor = $this->valorAutomatico($raiz, $questao);

                    if ($valor !== null) {
                        $automaticas[(int) $questao['id_questao']] = $valor;
                    }
                }
            }
        }

        return $this->automaticasPorRaiz[(int) $raiz->id] = $automaticas;
    }

    /**
     * Questões de natureza automática cuja fonte falta (edital sem Configurações de categorias):
     * não viram campo — o preenchimento é configurar as categorias no edital, a fonte única.
     * @return list<int>
     */
    private function questoesSemFonte(Opportunity $raiz, ?array $estrutura, array $automaticas): array
    {
        if ($estrutura === null) {
            return [];
        }

        $semFonte = [];

        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    $id = (int) $questao['id_questao'];

                    if ($this->isTagAutomatica((string) ($questao['tag'] ?? '')) && !array_key_exists($id, $automaticas)) {
                        $semFonte[] = $id;
                    }
                }
            }
        }

        return $semFonte;
    }

    private function isTagAutomatica(string $tag): bool
    {
        foreach (['total_inscritos', 'total_contemplados', 'total_vagas_contempladas', 'dados_por_categoria', 'tabela_categorias'] as $prefixo) {
            if (str_starts_with($tag, $prefixo)) {
                return true;
            }
        }

        return false;
    }

    private function valorAutomatico(Opportunity $raiz, array $questao): mixed
    {
        $tag = (string) ($questao['tag'] ?? '');

        if (str_starts_with($tag, 'total_inscritos')) {
            return $this->totalInscritas($raiz);
        }

        if (str_starts_with($tag, 'total_contemplados') || str_starts_with($tag, 'total_vagas_contempladas')) {
            return $this->totalContempladas($raiz);
        }

        if (str_starts_with($tag, 'dados_por_categoria') || str_starts_with($tag, 'tabela_categorias')) {
            return $this->linhasPorCategoria($raiz, $questao);
        }

        return null;
    }

    /**
     * Pré-preenchimentos editáveis: valores calculáveis que o gestor pode ajustar — hoje, o
     * valor total repassado (soma por categoria). Resposta salva sempre vence a sugestão.
     * @return array<int, mixed> por id_questao
     */
    public function respostasSugeridas(Opportunity $raiz, ?array $estrutura): array
    {
        if ($estrutura === null) {
            return [];
        }

        $sugeridas = [];

        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    if (!str_starts_with((string) ($questao['tag'] ?? ''), 'valor_total_repassado')) {
                        continue;
                    }

                    $valor = $this->valorTotalRepassado($raiz);

                    if ($valor !== null) {
                        $sugeridas[(int) $questao['id_questao']] = $valor;
                    }
                }
            }
        }

        return $sugeridas;
    }

    private function totalInscritas(Opportunity $raiz): int
    {
        return (int) App::i()->repo('Registration')->count([
            'opportunity' => $raiz,
            'status' => [
                Registration::STATUS_SENT,
                Registration::STATUS_INVALID,
                Registration::STATUS_NOTAPPROVED,
                Registration::STATUS_WAITLIST,
                Registration::STATUS_APPROVED,
            ],
        ]);
    }

    private function totalContempladas(Opportunity $raiz): int
    {
        $faseFinal = $raiz->lastPhase;

        if (!$faseFinal || !$faseFinal->isLastPhase) {
            return 0;
        }

        return (int) App::i()->repo('Registration')->count([
            'opportunity' => $faseFinal,
            'status' => Registration::STATUS_APPROVED,
        ]);
    }

    /**
     * Categorias das Configurações do edital (registrationRanges: label, limit, value) com a
     * contagem REAL de contempladas por categoria — contemplado é desfecho, não vaga.
     * @return list<array{label: string, valor: ?float, contempladas: int}>|null null sem categorias
     */
    private function categoriasComContagem(Opportunity $raiz): ?array
    {
        $categorias = $raiz->registrationRanges;

        if (!is_array($categorias) || $categorias === []) {
            return null;
        }

        $faseFinal = $raiz->lastPhase;
        $temFaseFinal = $faseFinal && $faseFinal->isLastPhase;

        $linhas = [];
        foreach ($categorias as $categoria) {
            $rotulo = trim((string) ($categoria['label'] ?? ''));
            if ($rotulo === '') {
                continue;
            }

            $valor = $categoria['value'] ?? null;

            // Um count por categoria (são poucas): hidratar as selecionadas pesaria em edital grande.
            $contempladas = $temFaseFinal ? (int) App::i()->repo('Registration')->count([
                'opportunity' => $faseFinal,
                'status' => Registration::STATUS_APPROVED,
                'range' => $rotulo,
            ]) : 0;

            $linhas[] = [
                'label' => $rotulo,
                'valor' => is_numeric($valor) ? (float) $valor : null,
                'contempladas' => $contempladas,
            ];
        }

        return $linhas === [] ? null : $linhas;
    }

    private function valorTotalRepassado(Opportunity $raiz): ?float
    {
        $categorias = $this->categoriasComContagem($raiz);

        if ($categorias === null) {
            return null;
        }

        $total = 0.0;
        foreach ($categorias as $categoria) {
            if ($categoria['valor'] === null) {
                // Categoria sem valor configurado: o total seria mentira — o campo volta ao gestor.
                return null;
            }

            $total += $categoria['valor'] * $categoria['contempladas'];
        }

        return round($total, 2);
    }

    /** Linhas na forma das colunas da própria questão (os formulários variam as chaves por tipo de ação). */
    private function linhasPorCategoria(Opportunity $raiz, array $questao): ?array
    {
        $categorias = $this->categoriasComContagem($raiz);

        if ($categorias === null) {
            return null;
        }

        $colunas = array_column($questao['config']['columns'] ?? [], 'key');

        if ($colunas === []) {
            return null;
        }

        $linhas = [];
        foreach ($categorias as $categoria) {
            $linha = [];
            foreach ($colunas as $chave) {
                $linha[$chave] = $this->celulaAutomatica($chave, $categoria);
            }
            $linhas[] = $linha;
        }

        return $linhas;
    }

    private function celulaAutomatica(string $chave, array $categoria): mixed
    {
        if (str_contains($chave, 'valor_total')) {
            return $categoria['valor'] === null ? null : round($categoria['valor'] * $categoria['contempladas'], 2);
        }

        if (str_contains($chave, 'valor')) {
            return $categoria['valor'];
        }

        if (str_contains($chave, 'quantidade')) {
            return $categoria['contempladas'];
        }

        if (str_contains($chave, 'categoria')) {
            return $categoria['label'];
        }

        return null;
    }

    /** A data que o envio usará (a questão do formulário é somente leitura); null antes de publicar. */
    private function dataPublicacaoAutomatica(Opportunity $raiz): ?string
    {
        $faseFinal = $raiz->lastPhase;

        return $faseFinal?->publishTimestamp?->format(\DateTime::ATOM);
    }

    private function estado(Opportunity $raiz, ?array $estrutura, array $respostas): string
    {
        if ($estrutura === null) {
            return self::ESTADO_SEM_FORMULARIO;
        }

        if (!$this->isFaseFinalPublicada($raiz)) {
            return self::ESTADO_AGUARDANDO_PUBLICACAO;
        }

        if ((new ResultadoService())->parEditalResultadoId((int) $raiz->id) !== null) {
            return self::ESTADO_ENVIADO;
        }

        return $this->faltantes($raiz, $estrutura, $respostas) === [] ? self::ESTADO_COMPLETO : self::ESTADO_PENDENTE;
    }

    /** @return list<int> obrigatórias sem resposta, já descontadas as questões sem fonte */
    private function faltantes(Opportunity $raiz, array $estrutura, array $respostas): array
    {
        $faltantes = (new FormularioRespostasValidator($estrutura))->missingRequired($respostas);

        // Questão sem fonte nem aparece na tela — não pode travar a completude invisivelmente.
        return array_values(array_diff($faltantes, $this->questoesSemFonte($raiz, $estrutura, $this->respostasAutomaticas($raiz, $estrutura))));
    }

    /** O formulário tem todas as obrigatórias respondidas? Independe de já haver envio — vale para retificações. */
    public function isFormularioCompleto(Opportunity $raiz): bool
    {
        $estrutura = $this->formStructureForOpportunity((int) $raiz->id);

        if ($estrutura === null) {
            return false;
        }

        $respostas = $this->savedRespostas((int) $raiz->id) + $this->respostasSugeridas($raiz, $estrutura);

        return $this->faltantes($raiz, $estrutura, $this->respostasAutomaticas($raiz, $estrutura) + $respostas) === [];
    }

    /**
     * Respostas do formulário no corpo do envio: automáticas, salvas e sugeridas, mais a data de
     * publicação informada — a mesma do campo próprio do POST, para nunca divergirem.
     * @return list<array{id_par_questao: int, resposta: array{resposta: mixed}}>|null null sem formulário
     */
    public function respostasParaEnvio(Opportunity $raiz, string $dataPublicacao): ?array
    {
        $estrutura = $this->formStructureForOpportunity((int) $raiz->id);

        if ($estrutura === null) {
            return null;
        }

        $valores = $this->respostasAutomaticas($raiz, $estrutura)
            + $this->savedRespostas((int) $raiz->id)
            + $this->respostasSugeridas($raiz, $estrutura);

        $respostas = [];

        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    $valor = str_starts_with((string) ($questao['tag'] ?? ''), 'data_publicacao')
                        ? $dataPublicacao
                        : ($valores[(int) $questao['id_questao']] ?? null);

                    // No envio a questão é endereçada pelo id_questao_etapa, não pelo id_questao.
                    $idEnvio = (int) ($questao['id_questao_etapa'] ?? 0);

                    if ($valor === null || $idEnvio === 0) {
                        continue;
                    }

                    $respostas[] = ['id_par_questao' => $idEnvio, 'resposta' => ['resposta' => $valor]];
                }
            }
        }

        return $respostas;
    }

    /** O travamento do card tem espelho aqui: sem a publicação final, nada se edita nem se salva. */
    public function isFaseFinalPublicada(Opportunity $raiz): bool
    {
        $faseFinal = $raiz->lastPhase;

        return (bool) ($faseFinal && $faseFinal->isLastPhase && $faseFinal->publishedRegistrations);
    }

    /** @return array<int, mixed> respostas salvas do formulário, por id_questao */
    public function savedRespostas(int $raizId): array
    {
        $respostas = $this->savedFormData($raizId)['respostas'] ?? [];

        return is_array($respostas) ? $respostas : [];
    }

    /**
     * Merge parcial sobre as respostas salvas (null limpa a resposta) e grava com a versão do
     * formulário vigente, para detectar estrutura que mudou entre o preenchimento e o envio.
     * Questões automáticas são descartadas: o cálculo do edital é a única fonte delas.
     * @return array<int, mixed> as respostas como ficaram salvas
     */
    public function saveRespostas(Opportunity $raiz, array $respostas, array $estrutura): array
    {
        $raizId = (int) $raiz->id;
        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);
        $respostas = array_diff_key($respostas, $automaticas);
        $respostas = array_diff_key($respostas, array_flip($this->questoesSemFonte($raiz, $estrutura, $automaticas)));


        $salvas = $this->savedRespostas($raizId);

        foreach ($respostas as $idQuestao => $valor) {
            if ($valor === null) {
                unset($salvas[(int) $idQuestao]);
                continue;
            }

            $salvas[(int) $idQuestao] = $valor;
        }

        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => $estrutura['id_formulario'] ?? null,
            'id_formulario_versao' => $estrutura['id_formulario_versao'] ?? null,
            'versao' => $estrutura['versao'] ?? null,
            'respostas' => $salvas,
        ], JSON_UNESCAPED_UNICODE));

        return $salvas;
    }

    private function savedFormData(int $raizId): array
    {
        $bruto = App::i()->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM],
        );

        $dados = is_string($bruto) && $bruto !== '' ? json_decode($bruto, true) : null;

        return is_array($dados) ? $dados : [];
    }

    private function upsertMeta(int $raizId, string $key, string $value): void
    {
        $conn = App::i()->em->getConnection();

        $updated = $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => $value, 'id' => $raizId, 'key' => $key],
        );
        if ($updated === 0) {
            $conn->executeStatement(
                'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
                ['id' => $raizId, 'key' => $key, 'value' => $value],
            );
        }
    }

    private function provider(): FetchesFormularioResultado
    {
        $provider = Plugin::getInstance()->integrationProvider();

        if (!$provider instanceof FetchesFormularioResultado) {
            throw IntegrationError::configuration('client.provider', 'o provedor configurado não serve o formulário de resultado');
        }

        return $provider;
    }

    /** O null (sem formulário / não sincronizada) também é cacheado — por isso o embrulho em array. */
    private function cached(string $chave, callable $busca): mixed
    {
        $app = App::i();
        $ttl = (int) (Plugin::getInstance()->config['integration']['cacheTTL'] ?? 0);

        if ($ttl <= 0) {
            return $busca();
        }

        if ($app->cache->contains($chave)) {
            return $app->cache->fetch($chave)['valor'];
        }

        $valor = $busca();
        $app->cache->save($chave, ['valor' => $valor], $ttl);

        return $valor;
    }
}
