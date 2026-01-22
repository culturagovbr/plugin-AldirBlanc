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

    // Constantes de configuração
    private const CACHE_TTL = 3600;           // 1 hora em segundos
    private const MAX_REQUESTS_PER_DAY = 10;  // Limite máximo de requests por dia
    private const SYNC_LOCK_TTL = 300;        // 5 minutos para lock de sincronização

    public function __construct(GestorDocument $gestorDocument)
    {
        $this->gestorDocument = $gestorDocument;
    }

    public function sync(): void
    {
        $app = App::i();
        $userId = $app->user->id;
        $document = $this->gestorDocument->document;
        $agent = $app->user->profile;

        if (!$agent) {
            // Marca que o sync terminou mesmo sem agente
            $_SESSION['gestor_cult_sync_completed'] = true;
            return;
        }

        // Chaves de cache para sincronização
        $cacheKey    = "gestor_cult_sync:{$userId}:{$document}";
        $lockKey     = "gestor_cult_sync_lock:{$userId}:{$document}";
        $requestsKey = "gestor_cult_requests:{$userId}:" . date('Y-m-d');

        // Verifica se a sincronização já está em andamento
        if ($app->cache->contains($lockKey)) {
            // Se está em lock, verifica se já há dados no cache
            // Se houver, marca como concluído (outro processo já sincronizou)
            $cachedEntities = $app->cache->fetch($cacheKey);
            if ($cachedEntities !== false && $cachedEntities !== null) {
                // Normaliza os dados antes de verificar se está vazio
                $cachedEntities = $this->normalizeFederativeEntities($cachedEntities);
                if (!empty($cachedEntities)) {
                    // Já há dados no cache, marca como concluído
                    $_SESSION['gestor_cult_sync_completed'] = true;
                }
            }
            // Se não há dados, retorna e a tela continuará verificando
            return;
        }

        // Verifica se o limite (por usuário) de requests por dia foi atingido
        $requestsCount = (int) ($app->cache->fetch($requestsKey) ?? 0);
        if ($requestsCount >= self::MAX_REQUESTS_PER_DAY) {
            // Marca que o sync terminou (limite atingido)
            $_SESSION['gestor_cult_sync_completed'] = true;
            return;
        }

        // Verifica se a última sincronização foi há menos de 1 hora [banco de dados]
        if ($this->wasSyncedLessThanOneHourAgo($agent)) {
            // Marca que o sync terminou (já sincronizado recentemente)
            $_SESSION['gestor_cult_sync_completed'] = true;
            return;
        }

        // Obtém os entes federados do cache
        $federativeEntities = $app->cache->fetch($cacheKey);
        $syncedFromApi = false;

        // Se os entes federados não estão no cache, busca na API
        if ($federativeEntities === false || $federativeEntities === null) {
            $app->cache->save($lockKey, true, self::SYNC_LOCK_TTL);

            try {
                $app->cache->save($requestsKey, $requestsCount + 1, 86400);
                $federativeEntities = (new GestorClient($this->gestorDocument))->get();
                $syncedFromApi = true;
                
                // Garante que seja um array (pode vir como string JSON)
                $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);
                
                $app->cache->save($cacheKey, $federativeEntities, self::CACHE_TTL);
            } finally {
                $app->cache->delete($lockKey);
            }
        } else {
            // Normaliza os dados do cache (pode estar serializado)
            $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);
        }

        // Se não houver entes federados, marca como concluído e para aqui
        if ($federativeEntities === false || $federativeEntities === null || empty($federativeEntities)) {
            $_SESSION['gestor_cult_sync_completed'] = true;
            return;
        }

        // Se o usuário não é gestor CultBR, adiciona a permissão
        if (!UserAccessService::isGestorCultBr()) {
            $app->disableAccessControl();
            $app->user->addRole(Role::GESTOR_CULT_BR);
            $app->enableAccessControl();
        }

        try {
            // Associa os entes federados ao agente
            $this->associateFederativeEntities($agent, $federativeEntities);

            // Se a sincronização foi feita da API, atualiza a data da última sincronização
            if ($syncedFromApi) {
                $app->disableAccessControl();
                $agent->setMetadata('gestorCultBrLastSyncedAt', (new \DateTime())->format('Y-m-d H:i:s'));
                $agent->save(true);
                $app->enableAccessControl();
            }
        } catch (\Throwable $e) {
            // Em caso de erro, ainda marca como concluído para não travar a tela
            error_log('Erro ao associar entes federados: ' . $e->getMessage());
        } finally {
            // Sempre marca que o sync terminou
            $_SESSION['gestor_cult_sync_completed'] = true;
        }
    }

    /**
     * Normaliza os dados dos entes federados para garantir que seja um array
     * 
     * @param mixed $federativeEntities
     * @return array
     */
    private function normalizeFederativeEntities($federativeEntities): array
    {
        // Se já é um array, retorna como está
        if (is_array($federativeEntities)) {
            return $federativeEntities;
        }

        // Se é uma string, tenta decodificar JSON
        if (is_string($federativeEntities)) {
            // Tenta decodificar JSON primeiro
            $decoded = json_decode($federativeEntities, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            
            // Se não for JSON válido, tenta unserialize (caso o cache tenha serializado)
            $unserialized = @unserialize($federativeEntities);
            if ($unserialized !== false && is_array($unserialized)) {
                return $unserialized;
            }
        }

        // Se não conseguiu normalizar, retorna array vazio
        return [];
    }

    /**
     * Verifica se a última sincronização foi há menos de 1 hora
     * 
     * @param Agent $agent
     * @return bool Retorna true se foi sincronizado há menos de 1 hora, false caso contrário
     */
    private function wasSyncedLessThanOneHourAgo(Agent $agent): bool
    {
        $lastSyncedAt = $agent->getMetadata('gestorCultBrLastSyncedAt');
        if (!$lastSyncedAt) {
            return false;
        }

        // Converter para timestamp se necessário
        if ($lastSyncedAt instanceof \DateTimeInterface) {
            $lastSyncTime = $lastSyncedAt->getTimestamp();
        } elseif (is_string($lastSyncedAt)) {
            $lastSyncTime = strtotime($lastSyncedAt);
            if ($lastSyncTime === false) {
                return false;
            }
        } else {
            return false;
        }

        // Verifica se foi sincronizado há menos de 1 hora
        return (time() - $lastSyncTime) < self::CACHE_TTL;
    }

    /**
     * Associa os entes federados ao agente
     * 
     * @param \MapasCulturais\Entities\Agent $agent
     * @param array $federativeEntities
     * @return void
     */
    private function associateFederativeEntities(Agent $agent, array $federativeEntities): void
    {
        $app = App::i();
        $em  = $app->em;

        $apiDocs = array_column($federativeEntities, 'document');
        $apiLookup = array_flip($apiDocs);

        // Obtem os Entes Federados Associados ao Agente
        $currentRelations = $app->repo(FederativeEntityAgentRelation::class)->findBy([
            'agent' => $agent
        ]);

        // Cria um array de Entes Federados Associados ao Agente por documento
        $currentByDoc = [];
        foreach ($currentRelations as $relation) {
            $currentByDoc[$relation->owner->document] = $relation;
        }

        // Obtem os Entes Federados existentes pelo documento
        $existingEntities = $app->repo(FederativeEntity::class)->findBy([
            'document' => $apiDocs
        ]);

        // Cria um array de Entes Federados existentes por documento
        $entitiesByDoc = [];
        foreach ($existingEntities as $entity) {
            $entitiesByDoc[$entity->document] = $entity;
        }

        $em->beginTransaction();

        try {
            // Remove os Entes Federados Associados ao Agente que não estão na API
            foreach ($currentByDoc as $doc => $relation) {
                if (!isset($apiLookup[$doc])) {
                    $em->remove($relation);
                }
            }

            // Processa os Entes Federados da API
            foreach ($federativeEntities as $data) {
                $doc = $data['document'];

                $entity = $entitiesByDoc[$doc] ?? null;

                if ($entity) {
                    if ($entity->name !== $data['name']) {
                        $entity->name = $data['name'];
                        $em->persist($entity);
                    }
                } else {
                    $entity = new FederativeEntity();
                    $entity->name = $data['name'];
                    $entity->document = $doc;
                    $entity->createTimestamp = new \DateTime();
                    $entity->subsite = $app->getCurrentSubsite();
                    $em->persist($entity);
                }

                if (!isset($currentByDoc[$doc])) {
                    $relation = new FederativeEntityAgentRelation();
                    $relation->agent = $agent;
                    $relation->owner = $entity;
                    $relation->hasControl = false;
                    $relation->status = AgentRelation::STATUS_ENABLED;
                    $em->persist($relation);
                }
            }

            // Salva as alterações no banco de dados
            $em->flush();
            $em->commit();
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }
    }
}
