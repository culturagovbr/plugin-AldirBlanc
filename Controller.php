<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\Traits;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Entities\User;

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

        if (!User::isGestorCultBr()) {
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
