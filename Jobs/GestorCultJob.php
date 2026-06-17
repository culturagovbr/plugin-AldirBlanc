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
use AldirBlanc\Plugin;
use AldirBlanc\Services\UserAccessService;

class GestorCultJob
{
    public const API_UNAVAILABLE_MESSAGE = 'Não conseguimos estabelecer conexão com a API CultBr. Tente novamente mais tarde.';

    private GestorDocument $gestorDocument;

    private const SYNC_LOCK_TTL = 300; // 5 minutos para lock de sincronização

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

        // Limpa flags de erro no início do sync (caso tenha sobrado de tentativa anterior)
        unset($_SESSION['gestor_cult_sync_error']);
        unset($_SESSION['gestor_cult_sync_error_message']);

        if (!$agent) {
            // Marca que o sync terminou mesmo sem agente (sem erro)
            $_SESSION['gestor_cult_sync_completed'] = true;
            return;
        }
        
        $integrationConfig = $this->getIntegrationConfig();
        $cacheTtlConfig = (int) ($integrationConfig['cacheTTL'] ?? 0);
        $maxRequestsPerDay = (int) $integrationConfig['maxRequestsPerDay'];

        // Chaves de cache para sincronização
        $cacheKey    = "gestor_cult_sync:{$userId}:{$document}";
        $lockKey     = "gestor_cult_sync_lock:{$userId}:{$document}";
        $requestsKey = "gestor_cult_requests:{$userId}:" . date('Y-m-d');

        // Verifica se a sincronização já está em andamento
        if ($app->cache->contains($lockKey)) {
            // Se está em lock, verifica se já há dados no cache
            // Se houver, marca como concluído (outro processo já sincronizou)
            $cachedEntities = false;
            if ($cacheTtlConfig > 0) {
                $cachedEntities = $app->cache->fetch($cacheKey);
            }

            if ($cachedEntities !== false && $cachedEntities !== null) {
                $cachedEntities = $this->extractFederativeEntitiesFromResponse($cachedEntities);
                $cachedEntities = $this->normalizeFederativeEntities($cachedEntities);
                if (!empty($cachedEntities)) {
                    // Já há dados no cache, marca como concluído (sem erro)
                    $_SESSION['gestor_cult_sync_completed'] = true;
                    // Limpa flags de erro se existirem
                    unset($_SESSION['gestor_cult_sync_error']);
                    unset($_SESSION['gestor_cult_sync_error_message']);
                }
            }
            // Se não há dados, retorna e a tela continuará verificando
            return;
        }

        // Verifica se o limite (por usuário) de requests por dia foi atingido
        $requestsCount = (int) ($app->cache->fetch($requestsKey) ?? 0);
        if ($requestsCount >= $maxRequestsPerDay) {
            // Marca que o sync terminou (limite atingido) - sem erro, apenas limite
            $_SESSION['gestor_cult_sync_completed'] = true;
            // Limpa flags de erro se existirem
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);
            return;
        }

        // Verifica se a última sincronização foi há menos do TTL configurado [banco de dados]
        if ($this->wasSyncedLessThanCacheTtlAgo($agent, $cacheTtlConfig)) {
            // Marca que o sync terminou (já sincronizado recentemente) - sem erro
            $_SESSION['gestor_cult_sync_completed'] = true;
            // Limpa flags de erro se existirem
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);
            return;
        }

        // Obtém os entes federados do cache (desligado quando cacheTTL é null no .env)
        $federativeEntities = $cacheTtlConfig > 0 ? $app->cache->fetch($cacheKey) : false;
        $syncedFromApi = false;
        $apiResponse = null;

        // Se os entes federados não estão no cache, busca na API
        if ($federativeEntities === false || $federativeEntities === null) {
            $app->cache->save($lockKey, true, self::SYNC_LOCK_TTL);

            try {
                $app->cache->save($requestsKey, $requestsCount + 1, 86400);
                $apiResponse = (new GestorClient($this->gestorDocument))->get();
                $syncedFromApi = true;

                $federativeEntities = $this->extractFederativeEntitiesFromResponse($apiResponse);
                $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);

                if ($cacheTtlConfig > 0) {
                    $app->cache->save($cacheKey, $federativeEntities, $cacheTtlConfig);
                }
            } catch (\Throwable $e) {
                // Dispara alerta para Telegram
                $app->log->critical("[Gestores CultBR] Erro ao buscar dados da API durante sincronização | Usuário ID: {$userId} | Documento: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());
                
                // Qualquer erro da API é tratado como indisponibilidade
                $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
                $_SESSION['gestor_cult_sync_error_message'] = self::API_UNAVAILABLE_MESSAGE;
                
                // Marca como concluído com erro para não travar a tela
                $_SESSION['gestor_cult_sync_completed'] = true;
                
                // Re-lança a exceção para ser capturada pelo try/catch externo
                throw $e;
            } finally {
                $app->cache->delete($lockKey);
            }
        } else {
            // Extrai lista de entes (cache pode ter formato novo ou antigo) e normaliza
            $federativeEntities = $this->extractFederativeEntitiesFromResponse($federativeEntities);
            $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);
        }

        // Se não houver entes federados (404 - CPF não encontrado), remove a permissão GestorCultBr
        if ($federativeEntities === false || $federativeEntities === null || empty($federativeEntities)) {
            if (UserAccessService::isGestorCultBr()) {
                $app->disableAccessControl();
                $app->user->removeRole(Role::GESTOR_CULT_BR);
                $app->enableAccessControl();
            }

            $_SESSION['gestor_cult_sync_completed'] = true;
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);
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

            // Se a sincronização foi feita da API, atualiza o agente com dados do gestor (apenas campos alterados)
            if ($syncedFromApi && is_array($apiResponse)) {
                $app->disableAccessControl();
                $this->updateAgentFromGestorResponse($agent, $apiResponse);
                $agent->setMetadata('gestorCultBrLastSyncedAt', (new \DateTime())->format('Y-m-d H:i:s'));
                $agent->save(true);
                $app->enableAccessControl();
            }
            
            // Limpa flags de erro se o sync foi bem-sucedido
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);
            
            // Marca como concluído sem erro
            $_SESSION['gestor_cult_sync_completed'] = true;
        } catch (\Throwable $e) {
            // Dispara alerta para Telegram
            $app->log->critical("[Gestores CultBR] Erro ao associar entes federados durante sincronização | Usuário ID: {$userId} | Documento: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());
            
            // Em caso de erro ao associar entes federados, trata como indisponibilidade da API
            $_SESSION['gestor_cult_sync_error'] = 'api_unavailable';
            $_SESSION['gestor_cult_sync_error_message'] = self::API_UNAVAILABLE_MESSAGE;
            
            // Marca como concluído com erro
            $_SESSION['gestor_cult_sync_completed'] = true;
        }
    }

    /**
     * Extrai a lista de entes federados do retorno da API.
     * Suporta formato novo (objeto com chave 'entes_federados') e antigo (array de entes).
     *
     * @param mixed $response Retorno bruto da API ou do cache
     * @return array Lista de entes (cada item com 'name' e 'document')
     */
    private function extractFederativeEntitiesFromResponse($response): array
    {
        if (!is_array($response)) {
            return [];
        }
        if (isset($response['entes_federados']) && is_array($response['entes_federados'])) {
            return $response['entes_federados'];
        }
        // Formato antigo: o próprio retorno é a lista de entes
        return $response;
    }

    /**
     * Mapeamento: chave no retorno da API do gestor => chave de metadado do Agent.
     * Apenas campos que devem ser atualizados no sync.
     */
    private const GESTOR_API_TO_AGENT_METADATA = [
        'rg' => 'rgNumero',
        'cep' => 'En_CEP',
        'nome' => 'nomeCompleto',
        'celular' => 'telefone1',        // telefone privado 1 (campo no tema Pnab)
        'numero' => 'En_Num',
        'complemento' => 'En_Complemento',
    ];

    /**
     * Atualiza o agente com os dados do retorno da API do gestor.
     * Altera apenas metadados cujo valor seja diferente do atual; se nada mudou, não persiste.
     *
     * @param Agent $agent
     * @param array $apiResponse Retorno bruto da API (objeto com rg, cep, nome, etc.)
     */
    private function updateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
    {
        foreach (self::GESTOR_API_TO_AGENT_METADATA as $apiKey => $agentKey) {
            $apiValue = $apiResponse[$apiKey] ?? null;
            $normalizedApi = $this->normalizeStringForComparison($apiValue);

            $currentValue = $agent->getMetadata($agentKey);
            $normalizedCurrent = $this->normalizeStringForComparison($currentValue);

            if ($normalizedApi === $normalizedCurrent) {
                continue;
            }

            $agent->setMetadata($agentKey, $apiValue === null ? null : (string) $apiValue);
        }
    }

    /**
     * Normaliza valor para comparação (evita diferença entre null, '' e espaços).
     */
    private function normalizeStringForComparison($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return trim((string) $value);
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

    private function getIntegrationConfig(): array
    {
        return Plugin::getInstance()->config['integration'] ?? [];
    }

    /**
     * Verifica se a última sincronização foi há menos do TTL configurado.
     */
    private function wasSyncedLessThanCacheTtlAgo(Agent $agent, ?int $cacheTtlConfig): bool
    {
        if ($cacheTtlConfig <= 0 || $cacheTtlConfig === null) {
            return false;
        }

        $lastSyncedAt = $agent->getMetadata('gestorCultBrLastSyncedAt');
        if (!$lastSyncedAt) {
            return false;
        }

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

        return (time() - $lastSyncTime) < $cacheTtlConfig;
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
                    $changed = false;
                    if ($entity->name !== ($data['name'] ?? null)) {
                        $entity->name = $data['name'];
                        $changed = true;
                    }

                    $exercicios = isset($data['exercicios']) && is_array($data['exercicios']) ? $data['exercicios'] : null;
                    $currentJson = $entity->exercices === null ? null : json_encode($entity->exercices);
                    $newJson = $exercicios === null ? null : json_encode($exercicios);
                    if ($currentJson !== $newJson) {
                        $entity->exercices = $exercicios;
                        $changed = true;
                    }
                    if ($changed) {
                        $em->persist($entity);
                    }
                } else {
                    $entity = new FederativeEntity();
                    $entity->name = $data['name'];
                    $entity->document = $doc;
                    $entity->exercices = isset($data['exercicios']) && is_array($data['exercicios']) ? $data['exercicios'] : null;
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
