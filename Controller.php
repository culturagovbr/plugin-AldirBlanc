<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Traits;
use MapasCulturais\Entities\Opportunity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Helpers\IntegrationTokenHelper;
use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\FederativeEntityService;
use AldirBlanc\Services\GestorCultBrSyncLimitResetService;
use AldirBlanc\Services\OpportunityService;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\User;

class Controller extends \MapasCulturais\Controllers\EntityController
{
    use Traits\ControllerAPI;

    /**
     * Gravado em POST_saveOpportunityPostGenerate (fluxo «usar modelo» no tema Pnab).
     * O tema Pnab consulta em getCultBrIntegrationBlockReason (gate comum a POST create e PUT publish no Cult).
     */
    public const OPPORTUNITY_META_IS_GENERATED_FROM_MODEL = 'isGeneratedFromModel';

    /** Gravado em OportunidadeCultJob após POST create no Cult; o tema Pnab não re-enfileira create em rascunho enquanto isto estiver ativo. */
    public const OPPORTUNITY_META_CULT_BR_CREATE_SYNCED = 'cultBrCreateSynced';

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
            return;
        }

        $agent = $app->user->profile;
        if (!$agent) {
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

        if (!UserAccessService::isSaasSuperAdmin()) {
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

    private function removeDuplicatedParActions(array $actions): array
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

    private function getParActionLabelKey(string $label): string
    {
        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)*)\b/u', $label, $matches)) {
            return $matches[1];
        }

        return mb_strtolower($label);
    }

    private function sortParActionsByLabel(array $actions): array
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

        // Verifica se o sync já foi iniciado
        if (isset($_SESSION['gestor_cult_sync_started']) && $_SESSION['gestor_cult_sync_started'] === true) {
            $this->json(['started' => true]);
            return;
        }

        // Marca que o sync começou e limpa flags de erro anteriores
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;
        unset($_SESSION['gestor_cult_sync_error']);
        unset($_SESSION['gestor_cult_sync_error_message']);
        
        // Dispara a sincronização em background
        try {
            $gestorDocument = new \AldirBlanc\Dtos\GestorDocument((new \AldirBlanc\Services\UserService())->getCpf());
            (new \AldirBlanc\Jobs\GestorCultJob($gestorDocument))->sync();
            
            // Se o sync foi bem-sucedido mas há flags antigas, limpa
            if (isset($_SESSION['gestor_cult_sync_error'])) {
                unset($_SESSION['gestor_cult_sync_error']);
                unset($_SESSION['gestor_cult_sync_error_message']);
            }
            
            // Garante que syncCompleted está definido
            if (!isset($_SESSION['gestor_cult_sync_completed'])) {
                $_SESSION['gestor_cult_sync_completed'] = true;
            }
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
                $_SESSION['gestor_cult_sync_error_message'] = 'Não foi possível consolidar seus dados, tente novamente mais tarde';
            }
            
            $this->json(['started' => false, 'error' => $e->getMessage()]);
            return;
        }
        
        $this->json(['started' => true]);
    }

    /**
     * Faz logout quando há erro de consolidação
     * 
     * POST /aldirblanc/logout-on-error
     */
    function POST_logoutOnError()
    {
        $app = App::i();
        
        // Limpa todas as flags de sync
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
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
     * Super admin / SaaS super admin: zera cache e metadados de consolidação CultBR do usuário informado (com perfil).
     *
     * POST /aldirblanc/clearGestorCultBrSyncLimits
     * Body: userId (JSON ou application/x-www-form-urlencoded)
     */
    public function POST_clearGestorCultBrSyncLimits(): void
    {
        $app = App::i();
        $this->requireAuthentication();

        if ($app->user->is('guest')) {
            $this->json(['ok' => false, 'message' => i::__('É necessário estar logado.')], 401);
            return;
        }

        if (!$app->user->is('superAdmin') && !$app->user->is('saasSuperAdmin')) {
            $this->json(['ok' => false, 'message' => i::__('Permissão negada.')], 403);
            return;
        }

        $payload = is_array($this->data) ? $this->data : [];
        $userId = (int) ($payload['userId'] ?? 0);
        if ($userId <= 0) {
            $this->json(['ok' => false, 'message' => i::__('Identificador de usuário inválido.')], 400);
            return;
        }

        /** @var User|null $targetUser */
        $targetUser = $app->repo('User')->find($userId);
        if ($targetUser === null) {
            $this->json(['ok' => false, 'message' => i::__('Usuário não encontrado.')], 404);
            return;
        }

        if (!GestorCultBrSyncLimitResetService::isEligibleTarget($targetUser)) {
            $this->json([
                'ok' => false,
                'message' => i::__('Não é possível ajustar sincronização: usuário sem perfil/agente.'),
            ], 400);
            return;
        }

        GestorCultBrSyncLimitResetService::clearForUser($app, $targetUser);

        $this->json(['ok' => true, 'message' => i::__('Limite limpo; consolidação CultBR reabilitada para este usuário, se aplicável.')]);
    }

    /**
     * Verifica o status da sincronização
     * Retorna true quando a sincronização terminar
     * 
     * GET /aldirblanc/check-sync-status
     */
    function GET_checkSyncStatus()
    {
        // Verifica se o sync foi iniciado
        $syncStarted = isset($_SESSION['gestor_cult_sync_started']) && $_SESSION['gestor_cult_sync_started'] === true;
        
        // Verifica se o sync foi concluído
        $syncCompleted = isset($_SESSION['gestor_cult_sync_completed']) && $_SESSION['gestor_cult_sync_completed'] === true;
        
        // Verifica se houve erro (verifica se a flag existe e não está vazia)
        $hasError = isset($_SESSION['gestor_cult_sync_error']) && 
                   $_SESSION['gestor_cult_sync_error'] !== null && 
                   $_SESSION['gestor_cult_sync_error'] !== '';
        $errorMessage = $_SESSION['gestor_cult_sync_error_message'] ?? 'Não foi possível consolidar seus dados, tente novamente mais tarde';

        // Se o sync não foi iniciado, ainda não está pronto
        if (!$syncStarted) {
            $this->json(['ready' => false]);
            return;
        }

        // Se o sync foi concluído, retorna pronto (com ou sem erro)
        if ($syncCompleted) {
            // Se não há erro real, garante que as flags estão limpas
            if (!$hasError) {
                unset($_SESSION['gestor_cult_sync_error']);
                unset($_SESSION['gestor_cult_sync_error_message']);
            }
            
            $this->json([
                'ready' => true,
                'error' => $hasError,
                'errorMessage' => $hasError ? $errorMessage : null
            ]);
            return;
        }

        // Sync ainda em andamento
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

        // Verifica se o sync já foi concluído
        $syncCompleted = isset($_SESSION['gestor_cult_sync_completed']) && $_SESSION['gestor_cult_sync_completed'] === true;
        $hasError = isset($_SESSION['gestor_cult_sync_error']) && 
                   $_SESSION['gestor_cult_sync_error'] !== null && 
                   $_SESSION['gestor_cult_sync_error'] !== '';

        // Se há erro, mostra a tela de erro (usuário não pode avançar)
        if ($syncCompleted && $hasError) {
            $this->render('consolidating-data');
            return;
        }

        // Se concluído sem erro, redireciona para o painel
        if ($syncCompleted && !$hasError) {
            $app->redirect($app->createUrl('panel', 'index'));
            return;
        }

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
        $entityName = $this->data['entityName'] ?? null;
        $entityDocument = $this->data['entityDocument'] ?? null;

        if (!$entityId) {
            $this->error('ID da entidade federativa não informado', 400);
            return;
        }

        // Salva na sessão como JSON
        $federativeEntity = [
            'id' => $entityId,
            'name' => $entityName,
            'document' => $entityDocument
        ];
        $_SESSION['selectedFederativeEntity'] = json_encode($federativeEntity);

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
            'entityId' => $entityId,
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

        $profile->save(true);

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

        if ($application->user->is('guest')) {
            $this->errorJson(i::__('Não autorizado'), 403);
            return;
        }

        $requestBody = $this->data;
        $opportunityId = isset($requestBody['opportunityId'])
            ? (int) $requestBody['opportunityId']
            : 0;
        if ($opportunityId < 1) {
            $this->errorJson(['opportunityId' => [i::__('ID da oportunidade inválido.')]], 400);
            return;
        }

        $shortDescriptionFromRequest = isset($requestBody['shortDescription'])
            ? trim((string) $requestBody['shortDescription'])
            : '';
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
            $opportunity->checkPermission('@control');
        } catch (\MapasCulturais\Exceptions\PermissionDenied) {
            $this->errorJson(i::__('Permissão negada.'), 403);
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

            $opportunity->save(true);
        } catch (\Throwable $persistenceOrMetadataFailure) {
            $application->log->error(
                '[AldirBlanc] saveOpportunityPostGenerate: '
                . $persistenceOrMetadataFailure->getMessage()
            );
            $this->errorJson(i::__('Não foi possível salvar os dados. Tente novamente.'), 500);
            return;
        }

        $this->json(['success' => true, 'id' => $opportunity->id]);
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
