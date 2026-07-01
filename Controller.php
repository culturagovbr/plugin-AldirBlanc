<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Traits;
use MapasCulturais\Entities\Opportunity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Helpers\IntegrationTokenHelper;
use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\FederativeEntityService;
use AldirBlanc\Jobs\GestorCultJob;
use AldirBlanc\Jobs\OportunidadeCultJob;
use AldirBlanc\Jobs\OpportunityBatchSyncJob;
use AldirBlanc\Services\OpportunityService;
use AldirBlanc\Services\UserService;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Exceptions\Halt;

class Controller extends \MapasCulturais\Controllers\EntityController
{
    use Traits\ControllerAPI;

    private const SYNC_SESSION_TTL = 300;

    /**
     * `false` = ainda não resolvido (null é um valor de retorno legítimo de getRequestedEntity(),
     * não pode ser usado como sentinela de "não resolvido").
     */
    protected $_requestedEntity = false;

    /**
     * Sobrescreve ControllerEntity::getRequestedEntity() (que só resolve por urlData['id'] ou
     * action 'create'/'index') para rotas como completeProfile, que não têm id na URL mas
     * precisam de uma entidade "solicitada" pro layout montar lockedFields/lockedFieldSeals.
     */
    public function getRequestedEntity(): ?\MapasCulturais\Entity
    {
        if ($this->_requestedEntity !== false) {
            return $this->_requestedEntity;
        }
        return parent::getRequestedEntity();
    }

    /**
     * Gravado em POST_saveOpportunityPostGenerate (fluxo «usar modelo» no tema Pnab).
     * O tema Pnab consulta em getCultBrIntegrationBlockReason (gate comum a POST create e PUT publish no Cult).
     */
    public const OPPORTUNITY_META_IS_GENERATED_FROM_MODEL = 'isGeneratedFromModel';

    /** Gravado em OportunidadeCultJob após POST create no Cult; o tema Pnab não re-enfileira create em rascunho enquanto isto estiver ativo. */
    public const OPPORTUNITY_META_CULT_BR_CREATE_SYNCED = 'cultBrCreateSynced';

    /** Gravado em OportunidadeCultJob após PUT update bem-sucedido no Cult; registra o timestamp do último envio. */
    public const OPPORTUNITY_META_CULT_BR_LAST_SYNCED_AT = 'cultBrLastSyncedAt';

    function __construct() {}

    /**
     * Retorna os entes federados associados ao usuário atual
     * 
     * GET /aldirblanc/federative-entities
     */
    function GET_federativeEntities()
    {
        $app = App::i();

        if (!UserAccessService::isGestorCultBr()) {
            $this->json([]);
            return;
        }

        $agent = $app->user->profile;
        if (!$agent) {
            $this->json([]);
            return;
        }

        $relations = $app->em->getRepository(FederativeEntityAgentRelation::class)->findBy([
            'agent' => $agent
        ]);

        $federativeEntities = [];
        foreach ($relations as $relation) {
            if ($relation->owner) {
                $federativeEntities[] = [
                    'id' => $relation->owner->id,
                    'name' => $relation->owner->name,
                    'document' => $relation->owner->document
                ];
            }
        }

        $this->json($federativeEntities);
    }

    /**
     * Lista exercícios PAR (`exercices`) do ente selecionado na sessão (apenas gestor CultBR).
     * Não usa parâmetros de URL: o ente vem só da sessão.
     *
     * GET /aldirblanc/parExercicios
     */
    function GET_parExercicios()
    {
        if (!UserAccessService::isGestorCultBr()) {
            $this->json(['exercicios' => []]);
            return;
        }

        $sessionEntityId = FederativeEntityService::getSelectedFederativeEntityIdFromSession();
        $this->json([
            'federativeEntityId' => $sessionEntityId,
            'exercicios' => FederativeEntityService::getParExerciciosForSessionSelectedEntity(),
        ]);
    }

    /**
     * Lista ações do PAR diretamente da API CultBR.
     *
     * GET /aldirblanc/parAcoes
     */
    function GET_parAcoes()
    {
        $this->requireAuthentication();

        if (!UserAccessService::canAssociatePARAction()) {
            $this->errorJson(i::__('Permissão negada.'), 403);
            return;
        }

        $skip = isset($this->data['skip']) ? (int) $this->data['skip'] : ParAcaoClient::DEFAULT_SKIP;
        $limit = isset($this->data['limit']) ? (int) $this->data['limit'] : ParAcaoClient::DEFAULT_LIMIT;

        try {
            $cultBrResponse = (new ParAcaoClient($skip, $limit))->get();

            if (!is_array($cultBrResponse) || !array_key_exists('data', $cultBrResponse)) {
                $this->errorJson(i::__('Não recebemos dados pela API CultBr'), 502);
                return;
            }

            $data = is_array($cultBrResponse['data'] ?? null) ? $cultBrResponse['data'] : [];
            if (empty($data)) {
                $this->errorJson(i::__('Não recebemos dados pela API CultBr'), 502);
                return;
            }

            $pagination = is_array($cultBrResponse['pagination'] ?? null) ? $cultBrResponse['pagination'] : [];
            $normalizedData = array_values(array_filter(array_map(function (array $actionData) {
                $action = ParAction::fromArray($actionData);
                return $action->label !== '' ? $action->toArray() : null;
            }, $data)));
            $normalizedData = $this->removeDuplicatedParActions($normalizedData);
            $normalizedData = $this->sortParActionsByLabel($normalizedData);
        } catch (\Throwable $exception) {
            $this->errorJson(i::__('Não conseguimos estabelecer conexão com a API CultBr'), 504);
            return;
        }

        $this->json([
            'pagination' => [
                'skip' => isset($pagination['skip']) ? (int) $pagination['skip'] : $skip,
                'limit' => isset($pagination['limit']) ? (int) $pagination['limit'] : $limit,
                'total' => isset($pagination['total']) ? (int) $pagination['total'] : count($data),
                'next' => isset($pagination['next']) && $pagination['next'] !== null ? (int) $pagination['next'] : null,
                'previous' => isset($pagination['previous']) && $pagination['previous'] !== null ? (int) $pagination['previous'] : null,
            ],
            'data' => $normalizedData,
        ]);
    }

    protected function removeDuplicatedParActions(array $actions): array
    {
        $uniqueActions = [];
        $seenLabels = [];

        foreach ($actions as $action) {
            $label = trim((string) ($action['label'] ?? ''));
            $labelKey = $this->getParActionLabelKey($label);

            if ($labelKey === '' || isset($seenLabels[$labelKey])) {
                continue;
            }

            $seenLabels[$labelKey] = true;
            $uniqueActions[] = $action;
        }

        return $uniqueActions;
    }

    protected function getParActionLabelKey(string $label): string
    {
        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)*)\b/u', $label, $matches)) {
            return $matches[1];
        }

        return mb_strtolower($label);
    }

    protected function sortParActionsByLabel(array $actions): array
    {
        usort($actions, fn(array $firstAction, array $secondAction) => strnatcasecmp(
            (string) ($firstAction['label'] ?? ''),
            (string) ($secondAction['label'] ?? '')
        ));

        return $actions;
    }

    /**
     * Dispara a sincronização
     * 
     * POST /aldirblanc/start-sync
     */
    function POST_startSync()
    {
        $app = App::i();
        $userId = $app->user->id ?? 'N/A';

        // Evita disparos paralelos enquanto a sincronização atual ainda está em andamento
        $syncStarted = isset($_SESSION['gestor_cult_sync_started']) && $_SESSION['gestor_cult_sync_started'] === true;
        $syncCompleted = isset($_SESSION['gestor_cult_sync_completed']) && $_SESSION['gestor_cult_sync_completed'] === true;
        if ($syncStarted && !$syncCompleted && !$this->isSyncSessionStale()) {
            $app->log->info("[Gestores CultBR] startSync ignorado: sincronização já em andamento | Usuário ID: {$userId}");
            $this->json(['started' => true]);
            return;
        }

        $app->log->info("[Gestores CultBR] startSync disparado | Usuário ID: {$userId}");

        // Marca que o sync começou e limpa flags de erro anteriores
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        $_SESSION['gestor_cult_sync_started_at'] = time();
        unset($_SESSION['gestor_cult_sync_error']);
        unset($_SESSION['gestor_cult_sync_error_message']);

        // Dispara a sincronização em background
        try {
            $gestorDocument = new GestorDocument($this->getGestorCpf());
            $syncExecuted = $this->createGestorCultJob($gestorDocument)->sync();

            if (!$syncExecuted) {
                $_SESSION['gestor_cult_sync_completed'] = true;
                $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
                $_SESSION['gestor_cult_sync_error_message'] = GestorCultJob::API_UNAVAILABLE_MESSAGE;
            }

            if (isset($_SESSION['gestor_cult_sync_error']) && $_SESSION['gestor_cult_sync_error'] !== null && $_SESSION['gestor_cult_sync_error'] !== '') {
                $_SESSION['gestor_cult_sync_completed'] = true;

                $this->json([
                    'started' => false,
                    'error' => true,
                    'errorMessage' => $_SESSION['gestor_cult_sync_error_message'] ?? GestorCultJob::API_UNAVAILABLE_MESSAGE,
                ]);
                return;
            }

            $_SESSION['gestor_cult_sync_completed'] = true;
            $this->enqueueBatchSyncJobs($app->user->profile);
        } catch (Halt $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Dispara alerta para Telegram apenas se não foi já disparado pelo GestorCultJob
            // (se a flag de erro não está definida, significa que o erro ocorreu antes do sync ou em outro lugar)
            if (!isset($_SESSION['gestor_cult_sync_error'])) {
                $userId = $app->user->id ?? 'N/A';
                $app->log->critical("[Gestores CultBR] Erro ao iniciar sincronização | Usuário ID: {$userId} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());
            }
            
            // Em caso de erro, marca como concluído para não travar
            $_SESSION['gestor_cult_sync_completed'] = true;
            
            // Se não há mensagem de erro específica na sessão, trata como indisponibilidade da API
            if (!isset($_SESSION['gestor_cult_sync_error'])) {
                $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
                $_SESSION['gestor_cult_sync_error_message'] = GestorCultJob::API_UNAVAILABLE_MESSAGE;
            }
            
            $this->json([
                'started' => false,
                'error' => true,
                'errorMessage' => $_SESSION['gestor_cult_sync_error_message'] ?? GestorCultJob::API_UNAVAILABLE_MESSAGE,
            ]);
            return;
        }

        $app->log->info("[Gestores CultBR] startSync finalizado | Usuário ID: {$userId}");
        $this->json(['started' => true]);
    }

    protected function getGestorCpf(): string
    {
        return (new UserService())->getCpf();
    }

    protected function createGestorCultJob(GestorDocument $gestorDocument): GestorCultJob
    {
        return new GestorCultJob($gestorDocument);
    }

    protected function enqueueBatchSyncJobs(?\MapasCulturais\Entities\Agent $agent): void
    {
        if (!$agent) {
            return;
        }

        $subsiteId = (int) env('ALDIRBLANC_SUBSITE_ID', 0);
        if (!$subsiteId) {
            return;
        }

        App::i()->enqueueOrReplaceJob(OpportunityBatchSyncJob::SLUG, [
            'agentId'   => $agent->id,
            'subsiteId' => $subsiteId,
        ]);
    }

    protected function isSyncSessionStale(): bool
    {
        $startedAt = $_SESSION['gestor_cult_sync_started_at'] ?? null;

        return is_numeric($startedAt) && ((time() - (int) $startedAt) > self::SYNC_SESSION_TTL);
    }

    /**
     * Faz logout quando há erro de consolidação
     * 
     * POST /aldirblanc/logout-on-error
     */
    function POST_logoutOnError()
    {
        $app = App::i();
        $userId = $app->user->id ?? 'N/A';
        $app->log->info("[Gestores CultBR] Logout por erro de consolidação | Usuário ID: {$userId}");

        // Limpa todas as flags de sync
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        unset($_SESSION['gestor_cult_sync_started_at']);
        unset($_SESSION['gestor_cult_sync_error']);
        unset($_SESSION['gestor_cult_sync_error_message']);
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);
        
        // Faz logout
        $app->auth->logout();
        
        // Redireciona para login
        $this->json([
            'success' => true,
            'redirectTo' => $app->createUrl('auth', 'login')
        ]);
    }

    /**
     * Verifica o status da sincronização
     * Retorna true quando a sincronização terminar
     * 
     * GET /aldirblanc/check-sync-status
     */
    function GET_checkSyncStatus()
    {
        $app = App::i();
        $userId = $app->user->id ?? 'N/A';
        $sessionId = session_id() ?: 'N/A';

        // Verifica se o sync foi iniciado
        $syncStarted = isset($_SESSION['gestor_cult_sync_started']) && $_SESSION['gestor_cult_sync_started'] === true;

        // Verifica se o sync foi concluído
        $syncCompleted = isset($_SESSION['gestor_cult_sync_completed']) && $_SESSION['gestor_cult_sync_completed'] === true;

        // Verifica se houve erro (verifica se a flag existe e não está vazia)
        $hasError = isset($_SESSION['gestor_cult_sync_error']) &&
                   $_SESSION['gestor_cult_sync_error'] !== null &&
                   $_SESSION['gestor_cult_sync_error'] !== '';
        $errorMessage = $_SESSION['gestor_cult_sync_error_message'] ?? \AldirBlanc\Jobs\GestorCultJob::API_UNAVAILABLE_MESSAGE;

        $app->log->info("[Gestores CultBR] checkSyncStatus chamado | Usuário ID: {$userId} | Sessão: {$sessionId} | started: " . ($syncStarted ? '1' : '0') . " | completed: " . ($syncCompleted ? '1' : '0') . " | hasError: " . ($hasError ? '1' : '0'));

        // Se o sync não foi iniciado, ainda não está pronto
        if (!$syncStarted && !$syncCompleted) {
            $app->log->info("[Gestores CultBR] checkSyncStatus: sync não iniciado, retornando ready=false | Usuário ID: {$userId} | Sessão: {$sessionId}");
            $this->json(['ready' => false]);
            return;
        }

        if ($syncStarted && !$syncCompleted && $this->isSyncSessionStale()) {
            $_SESSION['gestor_cult_sync_completed'] = true;
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = GestorCultJob::API_UNAVAILABLE_MESSAGE;
            $syncCompleted = true;
            $hasError = true;
            $errorMessage = GestorCultJob::API_UNAVAILABLE_MESSAGE;
        }

        // Se o sync foi concluído, retorna pronto (com ou sem erro)
        if ($syncCompleted) {
            // Se não há erro real, garante que as flags estão limpas
            if (!$hasError) {
                unset($_SESSION['gestor_cult_sync_error']);
                unset($_SESSION['gestor_cult_sync_error_message']);
            }

            $app->log->info("[Gestores CultBR] checkSyncStatus: concluído, retornando ready=true | Usuário ID: {$userId} | Sessão: {$sessionId} | error: " . ($hasError ? '1' : '0'));
            $this->json([
                'ready' => true,
                'error' => $hasError,
                'errorMessage' => $hasError ? $errorMessage : null
            ]);
            return;
        }

        // Sync ainda em andamento
        $app->log->info("[Gestores CultBR] checkSyncStatus: sync em andamento, retornando ready=false | Usuário ID: {$userId} | Sessão: {$sessionId}");
        $this->json(['ready' => false]);
    }

    /**
     * Página de consolidação de dados (tela de loading)
     * 
     * GET /aldirblanc/consolidating-data
     */
    public function GET_consolidatingData()
    {
        $app = App::i();
        $userId = $app->user->id ?? 'N/A';

        // Verifica se o sync já foi concluído
        $syncCompleted = isset($_SESSION['gestor_cult_sync_completed']) && $_SESSION['gestor_cult_sync_completed'] === true;
        $hasError = isset($_SESSION['gestor_cult_sync_error']) &&
                   $_SESSION['gestor_cult_sync_error'] !== null &&
                   $_SESSION['gestor_cult_sync_error'] !== '';

        // Se há erro, mostra a tela de erro (usuário não pode avançar)
        if ($syncCompleted && $hasError) {
            $app->log->info("[Gestores CultBR] consolidatingData: exibindo tela de erro | Usuário ID: {$userId}");
            $this->render('consolidating-data');
            return;
        }

        // Se concluído sem erro, redireciona para o painel
        if ($syncCompleted && !$hasError) {
            $app->log->info("[Gestores CultBR] consolidatingData: sync já concluído, redirecionando ao painel | Usuário ID: {$userId}");
            $app->redirect($app->createUrl('panel', 'index'));
            return;
        }

        $app->log->info("[Gestores CultBR] consolidatingData: exibindo tela de consolidação (vai disparar sync) | Usuário ID: {$userId}");
        // Mostra a tela de consolidação (que vai disparar o sync)
        $this->render('consolidating-data');
    }

    /**
     * Limpa a seleção e redireciona para a página de seleção
     * 
     * GET /aldirblanc/change-federative-entity
     */
    public function GET_changeFederativeEntity()
    {
        $app = App::i();

        // Limpa a seleção da sessão
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);

        // Redireciona para a página de seleção
        $url = $app->createUrl('aldirblanc', 'selectFederativeEntity');
        $app->redirect($url);
    }

    /**
     * Página para seleção de entidade federativa
     * 
     * GET /aldirblanc/select-federative-entity
     */
    public function GET_selectFederativeEntity()
    {
        $app = App::i();

        // Se já tem uma seleção válida, redireciona para onde estava
        if (isset($_SESSION['selectedFederativeEntity'])) {
            $redirectUri = $_SESSION['federative_entity_redirect_uri'] ?? $app->createUrl('panel', 'index');
            unset($_SESSION['federative_entity_redirect_uri']);
            $app->redirect($redirectUri);
            return;
        }

        $this->render('select-federative-entity');
    }

    /**
     * Salva a entidade federativa selecionada na sessão
     * 
     * POST /aldirblanc/select-federative-entity
     */
    public function POST_selectFederativeEntity()
    {
        $app = App::i();

        $entityId = $this->data['entityId'] ?? null;

        if (!$entityId || !is_numeric($entityId)) {
            $this->errorJson(i::__('ID da entidade federativa não informado'), 400);
            return;
        }

        if (!UserAccessService::isGestorCultBr()) {
            $this->errorJson(i::__('Permissão negada.'), 403);
            return;
        }

        $agent = $app->user->profile;
        if (!$agent) {
            $this->errorJson(i::__('Permissão negada.'), 403);
            return;
        }

        // Não confia em entityName/entityDocument enviados pelo client: só aceita o entityId
        // se houver de fato uma FederativeEntityAgentRelation do agente logado para ele, e usa
        // os dados reais da entidade (não o que o client mandou) ao gravar na sessão.
        $relation = $app->em->getRepository(FederativeEntityAgentRelation::class)->findOneBy([
            'agent' => $agent,
            'owner' => (int) $entityId,
        ]);

        if (!$relation || !$relation->owner) {
            $this->errorJson(i::__('Você não tem permissão para selecionar este Ente Federado.'), 403);
            return;
        }

        $entityDocument = $relation->owner->document;

        // Salva na sessão como JSON
        $federativeEntity = [
            'id' => $relation->owner->id,
            'name' => $relation->owner->name,
            'document' => $entityDocument
        ];
        $_SESSION['selectedFederativeEntity'] = json_encode($federativeEntity);

        $userId = $app->user->id ?? 'N/A';
        $app->log->info("[Gestores CultBR] Ente Federado selecionado | Usuário ID: {$userId} | Ente ID: {$entityId} | Documento: " . ($entityDocument ?? 'N/A'));

        // Limpa cache de permissões sempre que o Ente Federado é alterado
        $userAgent = $app->user->profile;
        if ($userAgent) {
            if (method_exists($userAgent, 'clearPermissionCache')) {
                $userAgent->clearPermissionCache();
            }
            
            // Dispara hook para que outros módulos possam limpar cache também
            $app->applyHook('aldirblanc.selectFederativeEntity:after');
        }

        // Retorna a URI de redirecionamento se houver
        $redirectUri = $_SESSION['federative_entity_redirect_uri'] ?? null;
        if ($redirectUri) {
            unset($_SESSION['federative_entity_redirect_uri']);
        }

        $this->json([
            'success' => true,
            'entityId' => $relation->owner->id,
            'redirectUri' => $redirectUri
        ]);
    }

    /**
     * Página para complementar cadastro (apenas campos obrigatórios faltantes).
     * Exibida antes da escolha do ente (gestor) ou antes do painel (usuário comum).
     * GET /aldirblanc/complete-profile
     */
    public function GET_completeProfile()
    {
        $app = App::i();

        if ($app->user->is('guest')) {
            $app->redirect($app->createUrl('auth', 'login'));
            return;
        }

        $profile = $app->user->profile;
        if (!$profile) {
            $app->redirect($app->createUrl('panel', 'index'));
            return;
        }

        $theme = $app->view;
        if (!method_exists($theme, 'getRequeredsAgentIndividualMetadata') || !method_exists($theme, 'hasRequiredAgentFieldsFilled')) {
            $app->redirect($app->createUrl('panel', 'index'));
            return;
        }

        $profile->refresh();
        if ($theme->hasRequiredAgentFieldsFilled($profile)) {
            if (UserAccessService::isGestorCultBr() && !isset($_SESSION['selectedFederativeEntity'])) {
                $app->redirect($app->createUrl('aldirblanc', 'selectFederativeEntity'));
            } else {
                $app->redirect($app->createUrl('panel', 'index'));
            }
            return;
        }

        $redirectUri = $app->createUrl('panel', 'index');
        if (UserAccessService::isGestorCultBr() && !isset($_SESSION['selectedFederativeEntity'])) {
            $redirectUri = $app->createUrl('aldirblanc', 'selectFederativeEntity');
        }
        $app->view->jsObject['completeProfile'] = [
            'redirectUri' => $redirectUri,
        ];

        // Define a entidade solicitada para o layout/Theme (evita "lockedFields on null" no Theme.php)
        // — ver getRequestedEntity() sobrescrito acima, que de fato expõe isto pro core.
        $this->_requestedEntity = $profile;

        $this->render('complete-profile', [
            'entity' => $profile,
        ]);
    }

    /**
     * Salva os dados do formulário de complementação e redireciona.
     * POST /aldirblanc/complete-profile
     */
    public function POST_completeProfile()
    {
        $app = App::i();

        if ($app->user->is('guest')) {
            $this->errorJson('Não autorizado', 403);
            return;
        }

        $profile = $app->user->profile;
        if (!$profile) {
            $this->errorJson('Perfil não encontrado', 400);
            return;
        }

        $theme = $app->view;
        if (!method_exists($theme, 'getRequeredsAgentIndividualMetadata')) {
            $this->errorJson('Configuração indisponível', 500);
            return;
        }

        $data = $this->data;
        $requiredKeys = $theme->getRequeredsAgentIndividualMetadata();

        try {
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }
                $value = $data[$key];
                if (is_array($value) && empty($value)) {
                    $value = null;
                }
                $profile->$key = $value;
            }

            // Usa a validação declarativa do próprio metadado (agent-types.php: v::email(), v::brPhone(),
            // formato de CPF etc.) — o mesmo mecanismo que o PATCH genérico do agente usa. Sem isso, esse
            // endpoint aceitava qualquer valor pras chaves obrigatórias. Só bloqueia pelos campos que vieram
            // no corpo desta requisição (não por outros campos já inválidos antes, fora do que foi pedido aqui).
            $relevantErrors = array_intersect_key($profile->validationErrors, $data);
            if ($relevantErrors) {
                $this->errorJson($relevantErrors);
                return;
            }

            $profile->save(true);
        } catch (Halt $e) {
            // errorJson()/json() chamados dentro deste try (validação acima) halt via exceção —
            // deixa passar direto, sem cair no catch genérico abaixo.
            throw $e;
        } catch (\Throwable $e) {
            // Valor mal formatado pra um campo tipado (ex.: data inválida em dataDeNascimento)
            // lança exceção do próprio setter/Doctrine — não deixa subir sem tratamento.
            $app->log->error("[CompleteProfile] Erro ao salvar campos do perfil | Usuário ID: {$app->user->id} | Erro: " . $e->getMessage());
            $this->errorJson(i::__('Não foi possível salvar os dados informados. Verifique os valores e tente novamente.'), 400);
            return;
        }

        // Revalida a partir do estado real (não confia em "salvei o que veio no corpo, logo terminei") —
        // quem normalmente persiste os campos é o PATCH genérico do agente, chamado antes deste endpoint
        // pelo front; sem essa revalidação, uma chamada com corpo vazio (ou direta, sem o PATCH) declararia
        // sucesso mesmo com o perfil ainda incompleto.
        $profile->refresh();
        if (method_exists($theme, 'hasRequiredAgentFieldsFilled') && !$theme->hasRequiredAgentFieldsFilled($profile)) {
            $this->json([
                'success' => false,
                'redirectUri' => $app->createUrl('aldirblanc', 'completeProfile'),
            ]);
            return;
        }

        $redirectUri = $app->createUrl('panel', 'index');
        if (UserAccessService::isGestorCultBr() && !isset($_SESSION['selectedFederativeEntity'])) {
            $redirectUri = $app->createUrl('aldirblanc', 'selectFederativeEntity');
        }

        $this->json([
            'success' => true,
            'redirectUri' => $redirectUri,
        ]);
    }

    /**
     * Persiste descrição curta e metadados PAR após "usar modelo", sem passar pelo PATCH da oportunidade
     * (evita validações e merges do tema nesse fluxo parcial).
     *
     * POST /aldirblanc/save-opportunity-post-generate
     *
     * Body JSON: opportunityId (obrigatório), shortDescription (obrigatório),
     * parExercicioId, parMetaId, parAcaoId, parAtividadeId (opcionais).
     */
    public function POST_saveOpportunityPostGenerate(): void
    {
        $application = App::i();

        $this->requireAuthentication();

        $requestBody = $this->data;
        $opportunityId = isset($requestBody['opportunityId'])
            ? (int) $requestBody['opportunityId']
            : 0;
        if ($opportunityId < 1) {
            $this->errorJson(['opportunityId' => [i::__('ID da oportunidade inválido.')]], 400);
            return;
        }

        $shortDescriptionRaw = $requestBody['shortDescription'] ?? null;
        if ($shortDescriptionRaw !== null && !is_string($shortDescriptionRaw) && !is_numeric($shortDescriptionRaw)) {
            $this->errorJson(['shortDescription' => [i::__('O campo "Descrição curta" tem um formato inválido.')]], 400);
            return;
        }

        $shortDescriptionFromRequest = trim((string) $shortDescriptionRaw);
        if ($shortDescriptionFromRequest === '') {
            $this->errorJson(['shortDescription' => [i::__('O campo "Descrição curta" é obrigatório.')]], 400);
            return;
        }

        /** @var Opportunity|null $opportunity */
        $opportunity = $application->repo('Opportunity')->find($opportunityId);
        if (!$opportunity) {
            $this->errorJson(['opportunityId' => [i::__('Oportunidade não encontrada.')]], 404);
            return;
        }

        try {
            $this->checkOpportunityControlPermission($opportunity);
        } catch (\MapasCulturais\Exceptions\PermissionDenied) {
            $this->errorJson(i::__('Permissão negada.'), 403);
            return;
        } catch (\Throwable $permissionCheckFailure) {
            $application->log->error(
                '[AldirBlanc] saveOpportunityPostGenerate: falha ao verificar permissão: '
                . $permissionCheckFailure->getMessage()
            );
            $this->errorJson(i::__('Não foi possível verificar a permissão. Tente novamente.'), 500);
            return;
        }

        $parInstrumentMetadataKeys = [
            'parExercicioId',
            'parMetaId',
            'parAcaoId',
            'parAtividadeId',
        ];
        $requestIncludesAnyParField = false;
        foreach ($parInstrumentMetadataKeys as $parFieldKey) {
            if (array_key_exists($parFieldKey, $requestBody)) {
                $requestIncludesAnyParField = true;
                break;
            }
        }

        try {
            $opportunity->shortDescription = $shortDescriptionFromRequest;
            $opportunity->setMetadata(self::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');

            if ($requestIncludesAnyParField) {
                foreach ($parInstrumentMetadataKeys as $parFieldKey) {
                    if (!array_key_exists($parFieldKey, $requestBody)) {
                        continue;
                    }
                    $incomingParFieldValue = $requestBody[$parFieldKey];
                    $normalizedParMetadataValue =
                        ($incomingParFieldValue === null || $incomingParFieldValue === '')
                            ? null
                            : (string) $incomingParFieldValue;
                    $opportunity->setMetadata($parFieldKey, $normalizedParMetadataValue);
                }
            }

            $this->saveOpportunityAfterPostGenerate($opportunity);
        } catch (\Throwable $persistenceOrMetadataFailure) {
            $application->log->error(
                '[AldirBlanc] saveOpportunityPostGenerate: '
                . $persistenceOrMetadataFailure->getMessage()
            );
            $this->errorJson(i::__('Não foi possível salvar os dados. Tente novamente.'), 500);
            return;
        }

        $isCultBrCreateSynced = (bool) filter_var(
            $opportunity->getMetadata(self::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED),
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$isCultBrCreateSynced && $this->isEligibleForCultBrCreateJob($opportunity)) {
            try {
                $this->enqueueOportunidadeCreateJob($opportunity);
            } catch (\Throwable $enqueueFailure) {
                // Os dados da oportunidade já foram persistidos com sucesso; o enfileiramento
                // é fire-and-forget (mesma filosofia de retry do OportunidadeCultJob), então uma
                // falha aqui não deve impedir a resposta de sucesso ao cliente.
                $application->log->error(
                    '[AldirBlanc] saveOpportunityPostGenerate: falha ao enfileirar job de create: '
                    . $enqueueFailure->getMessage()
                );
            }
        }

        $this->json(['success' => true, 'id' => $opportunity->id]);
    }

    /**
     * Extraído como ponto de extensão para permitir testar o catch genérico de
     * saveOpportunityPostGenerate sem depender de um cenário real de falha de ACL.
     */
    protected function checkOpportunityControlPermission(Opportunity $opportunity): void
    {
        $opportunity->checkPermission('@control');
    }

    /**
     * Extraído como ponto de extensão para permitir testar o catch de persistência de
     * saveOpportunityPostGenerate sem depender de uma falha real de banco/Doctrine.
     */
    protected function saveOpportunityAfterPostGenerate(Opportunity $opportunity): void
    {
        $opportunity->save(true);
    }

    /**
     * Extraído como ponto de extensão para permitir testar o catch de enfileiramento de
     * saveOpportunityPostGenerate sem depender de uma falha real do sistema de filas.
     */
    protected function enqueueOportunidadeCreateJob(Opportunity $opportunity): void
    {
        App::i()->enqueueOrReplaceJob(
            OportunidadeCultJob::SLUG,
            ['action' => 'create', 'opportunity' => $opportunity],
            'now',
        );
    }

    /**
     * Verifica se a oportunidade está elegível para o job de create no CultBr.
     * Espelha os guards de validateIntegrationJob (Theme.php) para evitar enfileirar
     * oportunidades que falhariam na integração.
     */
    protected function isEligibleForCultBrCreateJob(Opportunity $opportunity): bool
    {
        $federativeEntityId = $opportunity->getMetadata('federativeEntityId');
        if (empty($federativeEntityId)) {
            return false;
        }

        $subsiteId = (int) $opportunity->subsite?->id;
        if ($subsiteId < 1) {
            return false;
        }

        $themePnabSubsiteId = (int) env('ALDIRBLANC_SUBSITE_ID', 0);
        if ($themePnabSubsiteId === 0 || $subsiteId !== $themePnabSubsiteId) {
            return false;
        }

        if ($opportunity->parent !== null) {
            return false;
        }

        if ((int) $opportunity->status === Opportunity::STATUS_PHASE) {
            return false;
        }

        return true;
    }

    /*
    * Endpoint de integração de oportunidades
     * 
     * GET /aldirblanc/opportunities/{id}
     */
    public function API_integrationOpportunities()
    {
        $app = App::i();

        // validação via JWT de "Meus Aplicativos"
        IntegrationTokenHelper::validateOrFail();

        $method = strtoupper($app->request->getMethod());

        if ($method === 'GET') {
            return $this->_getIntegrationOpportunities();
        }

        $this->json(['error' => true, 'message' => 'Método ' . $method . ' não permitido'], 405);
    }

    private function _getIntegrationOpportunities()
    {
        $app = App::i();

        $cacheTTL = (int) ($app->plugins['AldirBlanc']->config['integration']['cacheTTL']);

        // Obtém o ID da oportunidade
        $idOportunity = $this->data['id'] ?? null;

        // Verifica se o ID da oportunidade foi informado
        if (!$idOportunity) {
            $this->json(['error' => true, 'message' => 'ID da oportunidade não informado'], 400);
            return;
        }

        // verifica se a oportunidade já está em cache
        $cacheKey = "aldirblanc:integration_opportunity:{$idOportunity}";
        if ($app->cache->contains($cacheKey)) {
            $this->json($app->cache->fetch($cacheKey));
            return;
        }

        $service = new OpportunityService();
        // Obtém a oportunidade com metadados, arquivos, subsite e primeira fase
        $opportunity = $service->findOpportunityWithIntegrationData((int) $idOportunity);

        // Verifica se a oportunidade existe
        if (!$opportunity) {
            $this->json(['error' => true, 'message' => 'Oportunidade não encontrada'], 404);
            return;
        }

        // Verifica se a oportunidade está no subsite configurado para integração
        $subsiteId = (int) ($app->plugins['AldirBlanc']->config['integration']['subsiteId'] ?? null);
        if (!$opportunity->subsite || (isset($opportunity->subsite->id) && $opportunity->subsite->id !== $subsiteId)) {
            $this->json(['error' => true, 'message' => 'Oportunidade não encontrada no subsite configurado'], 404);
            return;
        }

        $federativeEntityId = $opportunity->getMetadata('federativeEntityId') ?? $service->getRawMetadataValue($opportunity, 'federativeEntityId');
        // verifica se a oportunidade tem o federativeEntityId
        if (!$federativeEntityId) {
            $this->json(['error' => true, 'message' => 'Oportunidade não tem o federativeEntityId'], 404);
            return;
        }

        $payload = $service->mapOpportunityToIntegrationPayload($opportunity);

        $response = [
            'success' => true,
            'data' => $payload
        ];
        $app->cache->save($cacheKey, $response, $cacheTTL);

        $this->json($response);
    }

}
