<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\Traits;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
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

        // Marca que o sync começou
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = false;

        // Dispara a sincronização em background
        try {
            $gestorDocument = new \AldirBlanc\Dtos\GestorDocument((new \AldirBlanc\Services\UserService())->getCpf());
            (new \AldirBlanc\Jobs\GestorCultJob($gestorDocument))->sync();
            
            $this->json(['started' => true]);
        } catch (\Throwable $e) {
            // Em caso de erro, marca como concluído para não travar
            $_SESSION['gestor_cult_sync_completed'] = true;
            $this->json(['started' => false, 'error' => $e->getMessage()]);
        }
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

        // Se o sync não foi iniciado, ainda não está pronto
        if (!$syncStarted) {
            $this->json(['ready' => false]);
            return;
        }

        // Se o sync foi concluído, retorna pronto
        if ($syncCompleted) {
            $this->json(['ready' => true]);
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

        if ($syncCompleted) {
            // Após o sync terminar, redireciona para o painel
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
}
