<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\Traits;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Helpers\IntegrationTokenHelper;
use AldirBlanc\Services\OpportunityService;
use AldirBlanc\Services\UserAccessService;

class Controller extends \MapasCulturais\Controllers\EntityController
{
    use Traits\ControllerAPI;

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
     * Endpoint de integração de oportunidades
     * 
     * GET /aldirblanc/opportunities/{id}
     */
    public function API_integrationOpportunities()
    {
        $app = App::i();

        IntegrationTokenHelper::validateOrFail();

        $method = strtoupper($app->request->getMethod());

        if ($method === 'GET') {
            return $this->GET_integrationOpportunities();
        }

        $this->json(['error' => true, 'message' => 'Método ' . $method . ' não permitido'], 405);
    }

    public function GET_integrationOpportunities()
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

        // Obtém a oportunidade
        $opportunity = $app->repo('Opportunity')->find($idOportunity);

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

        $federativeEntityId = $opportunity->getMetadata('federativeEntityId');
        // verifica se a oportunidade tem o federativeEntityId
        if (!$federativeEntityId) {
            $this->json(['error' => true, 'message' => 'Oportunidade não tem o federativeEntityId'], 404);
            return;
        }

        $service = new OpportunityService();
        $payload = $service->mapOpportunityToIntegrationPayload($opportunity);

        $response = [
            'success' => true,
            'data' => $payload
        ];
        $app->cache->save($cacheKey, $response, $cacheTTL);

        $this->json($response);
    }
}
