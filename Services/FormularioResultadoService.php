<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesFormularioResultado;
use AldirBlanc\Integration\FetchesResultadoPermitido;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/** Estrutura do formulário de resultado do PAR: resolve o tipo de ação do edital e busca com cache. */
class FormularioResultadoService
{
    public const ESTADO_SEM_FORMULARIO = 'sem-formulario';
    public const ESTADO_ACAO_SEM_RESULTADO = 'acao-sem-resultado';
    public const ESTADO_AGUARDANDO_PUBLICACAO = 'aguardando-publicacao';
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_COMPLETO = 'completo';
    public const ESTADO_ENVIADO = 'enviado';

    private const ESTADOS_DE_ENVIO = [self::ESTADO_COMPLETO, self::ESTADO_ENVIADO];

    private const SEM_IMPEDIMENTOS = ['pendencias' => [], 'bloqueios' => []];

    private const FONTE_ROTULO = 'rotulo';
    private const FONTE_CONTAGEM = 'contagem';
    private const FONTE_VALOR = 'valor';

    private const CACHE_ESTRUTURA = 'aldirblanc:cultbr:formulario_resultado:tipo_acao:';
    private const CACHE_TIPO_ACAO = 'aldirblanc:cultbr:par_tipo_acao:oportunidade:';
    private const CACHE_PERMITE_RESULTADO = 'aldirblanc:cultbr:permite_resultado:edital:';

    /** Segundos até tentar de novo a leitura que falhou. */
    private const CACHE_FALHA_TTL = 60;

    /** @var array<int, array<int, mixed>> memoização por request: o save e o estado reusam o mesmo cálculo */
    private array $automaticasPorRaiz = [];

    /** Estrutura do formulário do edital, ou null quando ele não tem formulário de resultado no PAR. */
    public function formStructureForOpportunity(int $opportunityId): ?array
    {
        $idTipoAcao = $this->parTipoAcaoForOpportunity($opportunityId);

        return $idTipoAcao === null ? null : $this->formStructureForTipoAcao($idTipoAcao);
    }

    /** A mesma estrutura, relida da origem: o que vai ao CultBR não pode sair de uma versão guardada. */
    public function formStructureForEnvio(int $opportunityId): ?array
    {
        $idTipoAcao = $this->parTipoAcaoForOpportunity($opportunityId);

        if ($idTipoAcao === null) {
            return null;
        }

        $estrutura = $this->provider()->fetchFormularioResultado($idTipoAcao);
        $this->warmCache(self::CACHE_ESTRUTURA . $idTipoAcao, $estrutura, $this->formularioCacheTtl());

        return $estrutura;
    }

    /** Estrutura do formulário do tipo de ação, ou null quando não há formulário publicado (404). */
    public function formStructureForTipoAcao(int $idTipoAcao): ?array
    {
        return $this->cached(
            self::CACHE_ESTRUTURA . $idTipoAcao,
            fn() => $this->provider()->fetchFormularioResultado($idTipoAcao),
            $this->formularioCacheTtl(),
        );
    }

    /**
     * Tipo de ação PAR do edital, ou null quando a oportunidade não existe na origem ou vem sem o tipo.
     * Resolve em três camadas — cache, metadado persistido, HTTP — para não repetir nem a API nem o banco.
     */
    public function parTipoAcaoForOpportunity(int $opportunityId): ?int
    {
        $chave = self::CACHE_TIPO_ACAO . $opportunityId;
        $app = App::i();
        $ttl = $this->formularioCacheTtl();

        if ($ttl > 0 && $app->cache->contains($chave)) {
            return $app->cache->fetch($chave)['valor'];
        }

        $doMetadado = $this->tipoAcaoFromMeta($opportunityId);
        if ($doMetadado !== null) {
            $this->warmCache($chave, $doMetadado, $ttl);
            return $doMetadado;
        }

        $idTipoAcao = $this->provider()->fetchParTipoAcao(new OpportunityId($opportunityId));
        if ($idTipoAcao !== null) {
            $this->upsertMeta($opportunityId, Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID, (string) $idTipoAcao);
        }
        $this->warmCache($chave, $idTipoAcao, $ttl);

        return $idTipoAcao;
    }

    /** A ação do edital não recebe resultado no CultBR? Sem id no PAR ou sem leitura, não dá para afirmar. */
    public function isAcaoSemResultado(int $opportunityId): bool
    {
        $idParEdital = (new ResultadoService())->parEditalId($opportunityId);

        return $idParEdital !== null && $this->resultadoPermitido($idParEdital) === false;
    }

    private function resultadoPermitido(int $idParEdital): ?bool
    {
        $provider = Plugin::getInstance()->integrationProvider();

        if (!$provider instanceof FetchesResultadoPermitido) {
            return null;
        }

        $chave = self::CACHE_PERMITE_RESULTADO . $idParEdital;
        $ttl = $this->formularioCacheTtl();

        try {
            return $this->cached($chave, fn() => $provider->fetchResultadoPermitido(new ParEditalId($idParEdital)), $ttl);
        } catch (IntegrationError $e) {
            App::i()->log->warning("[CultBR] Não foi possível saber se o edital {$idParEdital} recebe resultado: " . $e->getMessage());
            $this->warmCache($chave, null, self::CACHE_FALHA_TTL);

            return null;
        }
    }

    /** O que impede o preenchimento da ação que não recebe resultado. */
    public static function motivoAcaoSemResultado(): string
    {
        return i::__('A ação deste edital não recebe resultado no CultBR.');
    }

    private function tipoAcaoFromMeta(int $opportunityId): ?int
    {
        $bruto = App::i()->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opportunityId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID],
        );
        $idTipoAcao = filter_var($bruto, FILTER_VALIDATE_INT);

        return $idTipoAcao === false || $idTipoAcao < 1 ? null : $idTipoAcao;
    }

    private function warmCache(string $chave, mixed $valor, int $ttl): void
    {
        if ($ttl > 0) {
            App::i()->cache->save($chave, ['valor' => $valor], $ttl);
        }
    }

    private function formularioCacheTtl(): int
    {
        return (int) (Plugin::getInstance()->config['integration']['formularioCacheTTL'] ?? 0);
    }

    /** O retrato que a tela do edital consome: estado do ciclo, estrutura e respostas salvas. */
    public function formStateForOpportunity(Opportunity $raiz): array
    {
        $estrutura = $this->formStructureForOpportunity((int) $raiz->id);
        $respostas = $this->savedRespostas((int) $raiz->id, $estrutura) + $this->respostasSugeridas($raiz, $estrutura);
        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);
        $impedimentos = $this->impedimentos($raiz, $estrutura);
        $estado = $this->estado($raiz, $estrutura, $impedimentos === self::SEM_IMPEDIMENTOS);

        return [
            'estado' => $estado,
            'motivo' => $estado === self::ESTADO_ACAO_SEM_RESULTADO ? self::motivoAcaoSemResultado() : null,
            'estrutura' => $estrutura,
            // (object): vazio precisa serializar como {} — como [] o cliente trataria respostas como lista.
            'respostas' => (object) $respostas,
            'respostasAutomaticas' => (object) $automaticas,
            'questoesSemFonte' => $this->questoesSemFonte($raiz, $estrutura, $automaticas),
            ...$impedimentos,
            'envioHabilitado' => in_array($estado, self::ESTADOS_DE_ENVIO, true) && $impedimentos === self::SEM_IMPEDIMENTOS,
            'dataPublicacao' => $this->dataPublicacaoAutomatica($raiz),
            'versaoAlterada' => $this->isDeOutraVersao($this->savedFormData((int) $raiz->id), $estrutura),
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

        foreach ((new FormularioEstrutura($estrutura))->questoes() as $id => $questao) {
            $valor = $this->valorAutomatico($raiz, $questao);

            if ($valor !== null) {
                $automaticas[$id] = $valor;
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

        foreach ((new FormularioEstrutura($estrutura))->questoes() as $id => $questao) {
            if ($this->isTagAutomatica((string) ($questao['tag'] ?? ''))
                && !array_key_exists($id, $automaticas)
                && !$this->isTabelaDoGestor($raiz, $questao)
            ) {
                $semFonte[] = $id;
            }
        }

        return $semFonte;
    }

    /** Com faixas no edital, a tabela que o cálculo não preenche volta para o gestor, se ele puder editá-la. */
    private function isTabelaDoGestor(Opportunity $raiz, array $questao): bool
    {
        return empty($questao['somente_leitura']) && $this->faixas($raiz)->rotulos() !== [];
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

        foreach ((new FormularioEstrutura($estrutura))->questoes() as $id => $questao) {
            if (!str_starts_with((string) ($questao['tag'] ?? ''), 'valor_total_repassado')) {
                continue;
            }

            $valor = $this->valorTotalRepassado($raiz);

            if ($valor !== null) {
                $sugeridas[$id] = $valor;
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

    private function faixas(Opportunity $raiz): FaixasDoEdital
    {
        return new FaixasDoEdital($raiz->registrationRanges);
    }

    /** Contemplado é desfecho, não vaga. @return array<string, int> contagem real por rótulo da faixa */
    private function contempladasPorFaixa(Opportunity $raiz): array
    {
        $faseFinal = $raiz->lastPhase;
        $temFaseFinal = $faseFinal && $faseFinal->isLastPhase;
        $contempladas = [];

        // Um count por faixa (são poucas): hidratar as selecionadas pesaria em edital grande.
        foreach ($this->faixas($raiz)->rotulos() as $rotulo) {
            $contempladas[$rotulo] = $temFaseFinal ? (int) App::i()->repo('Registration')->count([
                'opportunity' => $faseFinal,
                'status' => Registration::STATUS_APPROVED,
                'range' => $rotulo,
            ]) : 0;
        }

        return $contempladas;
    }

    private function valorTotalRepassado(Opportunity $raiz): ?float
    {
        $contempladas = $this->contempladasPorFaixa($raiz);

        return $contempladas === [] ? null : $this->faixas($raiz)->totalRepassado($contempladas);
    }

    /** Uma linha por faixa, nas colunas da questão; null sem faixas ou com coluna que o edital não responde. */
    private function linhasPorCategoria(Opportunity $raiz, array $questao): ?array
    {
        $fontes = $this->fontesDasColunas(FormularioTabela::daQuestao($questao)->colunas);
        $contempladas = $this->contempladasPorFaixa($raiz);

        if ($fontes === null || $contempladas === []) {
            return null;
        }

        $faixas = $this->faixas($raiz);
        $linhas = [];

        foreach ($contempladas as $rotulo => $quantidade) {
            $valores = [
                self::FONTE_ROTULO => (string) $rotulo,
                self::FONTE_CONTAGEM => $quantidade,
                self::FONTE_VALOR => $faixas->valorPorContemplado((string) $rotulo),
            ];
            $linhas[] = array_map(fn(string $fonte) => $valores[$fonte], $fontes);
        }

        return $linhas;
    }

    /**
     * De onde sai cada coluna, pelo tipo; null quando falta fonte para uma obrigatória ou duas disputam a mesma.
     * @return array<string, string>|null fonte por id da coluna
     */
    private function fontesDasColunas(array $colunas): ?array
    {
        $fontes = [];

        foreach ($colunas as $coluna) {
            $fonte = $this->fonteDaColuna($coluna);

            if ($fonte === null && !$coluna['obrigatoria']) {
                continue;
            }

            if ($fonte === null || in_array($fonte, $fontes, true)) {
                return null;
            }

            $fontes[$coluna['id']] = $fonte;
        }

        return $fontes === [] ? null : $fontes;
    }

    private function fonteDaColuna(array $coluna): ?string
    {
        return match (true) {
            $coluna['tipo'] === 'number' => self::FONTE_CONTAGEM,
            $coluna['tipo'] === 'currency' => self::FONTE_VALOR,
            $coluna['tipo'] === 'text' && $coluna['somenteLeitura'] => self::FONTE_ROTULO,
            default => null,
        };
    }

    /** A data que o envio usará (a questão do formulário é somente leitura); null antes de publicar. */
    private function dataPublicacaoAutomatica(Opportunity $raiz): ?string
    {
        $faseFinal = $raiz->lastPhase;

        return $faseFinal?->publishTimestamp?->format(\DateTime::ATOM);
    }

    private function estado(Opportunity $raiz, ?array $estrutura, bool $isCompleto): string
    {
        if ($estrutura === null) {
            return self::ESTADO_SEM_FORMULARIO;
        }

        if ($this->isAcaoSemResultado((int) $raiz->id)) {
            return self::ESTADO_ACAO_SEM_RESULTADO;
        }

        if (!$this->isFaseFinalPublicada($raiz)) {
            return self::ESTADO_AGUARDANDO_PUBLICACAO;
        }

        if ((new ResultadoService())->parEditalResultadoId((int) $raiz->id) !== null) {
            return self::ESTADO_ENVIADO;
        }

        return $isCompleto ? self::ESTADO_COMPLETO : self::ESTADO_PENDENTE;
    }

    /**
     * O que impede o envio do formulário: o que falta ao gestor e o que esta versão do sistema não sabe tratar.
     * @return array{pendencias: list<array>, bloqueios: list<array>}
     */
    public function impedimentosDoEnvio(Opportunity $raiz): array
    {
        return $this->impedimentos($raiz, $this->formStructureForEnvio((int) $raiz->id));
    }

    private function impedimentos(Opportunity $raiz, ?array $estrutura): array
    {
        if ($estrutura === null) {
            return self::SEM_IMPEDIMENTOS;
        }

        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);
        $respostas = $automaticas
            + $this->savedRespostas((int) $raiz->id, $estrutura)
            + $this->respostasSugeridas($raiz, $estrutura);
        $validator = new FormularioRespostasValidator($estrutura);

        // A sem fonte nem aparece na tela: não pode travar o envio invisivelmente.
        $foraDoGestor = $automaticas + array_flip($this->questoesSemFonte($raiz, $estrutura, $automaticas));

        return [
            'pendencias' => $this->comNomes(array_diff_key($validator->pendencias($respostas), $foraDoGestor), $estrutura),
            'bloqueios' => $this->comNomes(array_diff_key($validator->bloqueios($respostas), $foraDoGestor), $estrutura),
        ];
    }

    /** @return list<array{id_questao: int, nome: string, motivo: string}> */
    private function comNomes(array $motivos, array $estrutura): array
    {
        $questoes = (new FormularioEstrutura($estrutura))->questoes();

        return array_map(
            fn(int $id, string $motivo) => ['id_questao' => $id, 'nome' => $questoes[$id]['nome'] ?? '', 'motivo' => $motivo],
            array_keys($motivos),
            $motivos,
        );
    }

    /** Nada impede o envio do formulário? Independe de já haver envio — vale para retificações. */
    public function isFormularioCompleto(Opportunity $raiz): bool
    {
        $estrutura = $this->formStructureForEnvio((int) $raiz->id);

        return $estrutura !== null && $this->impedimentos($raiz, $estrutura) === self::SEM_IMPEDIMENTOS;
    }

    /**
     * Respostas do formulário no corpo do envio: automáticas, salvas e sugeridas, só as visíveis e válidas.
     * @return list<array{id_par_questao: int, resposta: array{resposta: mixed}}>|null null sem formulário
     */
    public function respostasParaEnvio(Opportunity $raiz): ?array
    {
        $estrutura = $this->formStructureForEnvio((int) $raiz->id);

        if ($estrutura === null) {
            return null;
        }

        $leitura = new FormularioEstrutura($estrutura);
        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);
        $valores = $automaticas
            + $this->savedRespostas((int) $raiz->id, $estrutura)
            + $this->respostasSugeridas($raiz, $estrutura);

        $enviaveis = $automaticas + (new FormularioRespostasValidator($estrutura))->enviaveis($valores);
        $regras = new FormularioRegras($leitura);
        $respostas = [];

        foreach (array_intersect_key($leitura->questoes(), $enviaveis) as $id => $questao) {
            // No envio a questão é endereçada pelo id_questao_etapa, não pelo id_questao.
            $idEnvio = (int) ($questao['id_questao_etapa'] ?? 0);

            if ($idEnvio !== 0 && $regras->isVisivel($questao, $valores)) {
                $respostas[] = ['id_par_questao' => $idEnvio, 'resposta' => ['resposta' => $this->valorParaEnvio($questao, $enviaveis[$id])]];
            }
        }

        return $respostas;
    }

    /** Ajusta o valor ao que o CultBR aceita por tipo de questão; os demais tipos viajam como estão. */
    private function valorParaEnvio(array $questao, mixed $valor): mixed
    {
        return match ($questao['tipo_questao'] ?? '') {
            'número', 'moeda' => $this->comoNumero($valor),
            'radio' => $this->comoRotulo($questao, $valor),
            'checkbox', 'múltipla escolha' => array_map(fn(mixed $escolha) => $this->comoRotulo($questao, $escolha), $valor),
            'tabela', 'Tabela Dinâmica' => $this->comoLinhas($questao, $valor),
            default => $valor,
        };
    }

    /** Inteiro sem casa decimal: 15000 e 15000.0 são o mesmo conteúdo. */
    private function comoNumero(mixed $valor): int|float
    {
        $numero = $valor + 0;

        return floor($numero) == $numero ? (int) $numero : $numero;
    }

    /** A opção viaja pelo texto, e a complementada como "Rótulo: texto". */
    private function comoRotulo(array $questao, mixed $escolha): string
    {
        $id = is_array($escolha) ? $escolha['id'] : $escolha;
        $rotulos = array_column(FormularioEstrutura::itens($questao), 'label', 'id');

        return is_array($escolha) ? "{$rotulos[$id]}: {$escolha['complemento']}" : $rotulos[$id];
    }

    private function comoLinhas(array $questao, array $linhas): array
    {
        $tipos = array_column(FormularioTabela::daQuestao($questao)->colunas, 'tipo', 'id');

        return array_map(fn(array $linha) => array_combine(
            array_keys($linha),
            array_map(
                fn(string $coluna, mixed $celula) => $this->isCelulaNumerica($tipos[$coluna] ?? '', $celula) ? $this->comoNumero($celula) : $celula,
                array_keys($linha),
                $linha,
            ),
        ), $linhas);
    }

    private function isCelulaNumerica(string $tipo, mixed $celula): bool
    {
        return in_array($tipo, ['number', 'currency'], true) && is_numeric($celula);
    }

    /** O travamento do card tem espelho aqui: sem a publicação final, nada se edita nem se salva. */
    public function isFaseFinalPublicada(Opportunity $raiz): bool
    {
        $faseFinal = $raiz->lastPhase;

        return (bool) ($faseFinal && $faseFinal->isLastPhase && $faseFinal->publishedRegistrations);
    }

    /**
     * Respostas salvas na versão vigente do formulário; as de outra versão não valem.
     * @return array<int, mixed> por id_questao
     */
    public function savedRespostas(int $raizId, ?array $estrutura): array
    {
        $dados = $this->savedFormData($raizId);

        return $this->isDeOutraVersao($dados, $estrutura) ? [] : $this->respostasGravadas($dados);
    }

    private function isDeOutraVersao(array $dados, ?array $estrutura): bool
    {
        if ($estrutura === null || $this->respostasGravadas($dados) === []) {
            return false;
        }

        $versaoGravada = isset($dados['id_formulario_versao']) ? (int) $dados['id_formulario_versao'] : null;

        return $versaoGravada !== (new FormularioEstrutura($estrutura))->versao();
    }

    private function respostasGravadas(array $dados): array
    {
        $respostas = $dados['respostas'] ?? [];

        return is_array($respostas) ? $respostas : [];
    }

    /**
     * Merge das respostas válidas sobre as salvas na versão vigente (null limpa a resposta). A recusada
     * não apaga a que estava salva, e o que não cabe ao gestor responder é descartado.
     * @return array<int, string> erros das recusadas, por id_questao
     */
    public function saveRespostas(Opportunity $raiz, array $respostas, array $estrutura): array
    {
        $raizId = (int) $raiz->id;
        $validator = new FormularioRespostasValidator($estrutura);
        $doGestor = $this->semCalculadas($raiz, $validator->editaveis($respostas), $estrutura);
        $erros = $validator->validate($doGestor);
        $validas = array_diff_key($doGestor, $erros);

        if ($validas === []) {
            return $erros;
        }

        $salvas = array_replace($this->savedRespostas($raizId, $estrutura), $validas);

        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => $estrutura['id_formulario'] ?? null,
            'id_formulario_versao' => $estrutura['id_formulario_versao'] ?? null,
            'versao' => $estrutura['versao'] ?? null,
            'respostas' => array_filter($salvas, fn($valor) => $valor !== null),
        ], JSON_UNESCAPED_UNICODE));

        return $erros;
    }

    /** Fora também as sem fonte: o cálculo do edital é a única origem delas. */
    private function semCalculadas(Opportunity $raiz, array $respostas, array $estrutura): array
    {
        $automaticas = $this->respostasAutomaticas($raiz, $estrutura);

        return array_diff_key($respostas, $automaticas, array_flip($this->questoesSemFonte($raiz, $estrutura, $automaticas)));
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
    private function cached(string $chave, callable $busca, int $ttl): mixed
    {
        if ($ttl <= 0) {
            return $busca();
        }

        $app = App::i();

        if ($app->cache->contains($chave)) {
            return $app->cache->fetch($chave)['valor'];
        }

        $valor = $busca();
        $app->cache->save($chave, ['valor' => $valor], $ttl);

        return $valor;
    }
}
