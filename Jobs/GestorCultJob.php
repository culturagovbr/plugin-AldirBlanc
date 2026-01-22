<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Services\UserAccessService;

class GestorCultJob
{
    private GestorDocument $gestorDocument;

    public function __construct(GestorDocument $gestorDocument)
    {
        $this->gestorDocument = $gestorDocument;
    }

    public function sync(): void
    {
        $app = App::i();
        $federativeEntities = (new GestorClient($this->gestorDocument))->get();

        /**
         * TODO:
         * 
         * Provalmente, se não retornar nada, significa que o usuário não é gestor CultBR
         * e devemos retornar para evitar erros de permissão e de associação de entidades federadas
         */
        if (empty($federativeEntities)) {
            return;
        }

        // Atribuindo o papel de Gestor CultBR ao usuário se ele não tiver
        if (!UserAccessService::isGestorCultBr()) {
            $app->disableAccessControl();
            $app->user->addRole(Role::GESTOR_CULT_BR);
            $app->enableAccessControl();
        }

        // Sincronizando os entes federados do usuário
        $this->associateFederativeEntities($app->user->profile, $federativeEntities);
    }

    /**
     * @param Agent $agent
     * @param array $federativeEntities
     */
    private function associateFederativeEntities(Agent $agent, array $federativeEntities): void
    {
        $app = App::i();
        $em  = $app->em;

        $apiDocuments = array_column($federativeEntities, 'document');
        $apiLookup    = array_flip($apiDocuments);

        // Obtendo as associações atuais do agente
        $currentRelations = $app->repo(FederativeEntityAgentRelation::class)->findBy([
            'agent' => $agent
        ]);

        $mapeamentoDocumentos = [];
        foreach ($currentRelations as $relation) {
            $mapeamentoDocumentos[$relation->owner->document] = $relation;
        }

        // Obtendo as entidades federadas existentes
        $existingEntities = $app->repo(FederativeEntity::class)->findBy([
            'document' => $apiDocuments
        ]);

        // Mapeando as entidades federadas existentes pelo documento
        $mapeamentoEntidades = [];
        foreach ($existingEntities as $entity) {
            $mapeamentoEntidades[$entity->document] = $entity;
        }

        // Iniciando a transação
        $em->beginTransaction();

        try {
            // Removendo as associações que não vieram da API
            foreach ($mapeamentoDocumentos as $doc => $relation) {
                if (!isset($apiLookup[$doc])) {
                    $em->remove($relation);
                }
            }

            // Criando / atualizando / associando as entidades federadas
            foreach ($federativeEntities as $data) {
                $doc = $data['document'];

                if (isset($mapeamentoEntidades[$doc])) {
                    $entity = $mapeamentoEntidades[$doc] ?? null;
                    if ($entity && $entity->name !== $data['name']) {
                        $entity->name = $data['name'];
                        $em->persist($entity);
                    }
                    continue;
                }

                $entity = $mapeamentoEntidades[$doc] ?? null;

                if ($entity) {
                    if ($entity->name !== $data['name']) {
                        $entity->name = $data['name'];
                        $em->persist($entity);
                    }
                } else {
                    // Criando a entidade federada se ela não existir
                    $entity = new FederativeEntity();
                    $entity->name = $data['name'];
                    $entity->document = $doc;
                    $entity->createTimestamp = new \DateTime();
                    $entity->subsite = $app->getCurrentSubsite();
                    $em->persist($entity);
                }

                // Criando a associação entre o agente e a entidade federada
                $relation = new FederativeEntityAgentRelation();
                $relation->agent = $agent;
                $relation->owner = $entity;
                $relation->hasControl = false;
                $relation->status = AgentRelation::STATUS_ENABLED;

                $em->persist($relation);
            }

            // Finalizando a transação
            $em->flush();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }
    }
}
