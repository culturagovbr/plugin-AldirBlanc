<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\Role as MapasRole;
use AldirBlanc\Enum\Role;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Http\Clients\GestorClient;
use AldirBlanc\Services\UserAccessService;

class GestorCultJob
{
    public const API_UNAVAILABLE_MESSAGE = 'Não conseguimos estabelecer conexão com a API CultBr. Tente novamente mais tarde.';
    private const CONTRACT_ERROR_MESSAGE = 'Resposta da API CultBr fora do contrato esperado';

    private GestorDocument $gestorDocument;

    /** TTL de segurança do lock de concorrência: cobre o tempo de uma sincronização real (chamada à API), liberado no finally ao terminar. */
    private const SYNC_LOCK_TTL = 300;

    public function __construct(GestorDocument $gestorDocument)
    {
        $this->gestorDocument = $gestorDocument;
    }

    public function sync(): bool
    {
        $app = App::i();
        $userId = $app->user->id;
        $document = $this->gestorDocument->document;
        $agent = $app->user->profile;

        $app->log->info("[Gestores CultBR] Sync iniciado | Usuário ID: {$userId} | Documento: {$document}");

        // Limpa flags de erro no início do sync (caso tenha sobrado de tentativa anterior)
        unset($_SESSION['gestor_cult_sync_error']);
        unset($_SESSION['gestor_cult_sync_error_message']);

        if (!$agent) {
            $app->log->info("[Gestores CultBR] Sync abortado: usuário sem agente (profile) | Usuário ID: {$userId}");
            // Marca que o sync terminou mesmo sem agente (sem erro)
            $_SESSION['gestor_cult_sync_completed'] = true;
            return true;
        }

        // Evita que requisições concorrentes (ex.: dupla submissão, abas paralelas) disparem
        // chamadas reais simultâneas à API para o mesmo usuário/documento.
        $lockKey = "gestor_cult_sync_lock:{$userId}:{$document}";
        if ($app->cache->contains($lockKey)) {
            $app->log->info("[Gestores CultBR] Sync já em andamento em outra requisição, ignorando | Usuário ID: {$userId} | Documento: {$document}");
            return false;
        }
        $app->cache->save($lockKey, true, self::SYNC_LOCK_TTL);

        try {
            $this->performSync($agent, $userId, $document);
        } finally {
            $app->cache->delete($lockKey);
        }

        return true;
    }

    private function performSync(Agent $agent, $userId, string $document): void
    {
        $app = App::i();
        $apiResponse = null;

        try {
            $apiResponse = $this->fetchGestorData();

            $federativeEntities = $this->extractFederativeEntitiesFromResponse($apiResponse);
            $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);
            $this->validateFederativeEntitiesContract($federativeEntities);

            $app->log->info("[Gestores CultBR] Resposta da API recebida | Usuário ID: {$userId} | Documento: {$document} | Entes federados retornados: " . count($federativeEntities));
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
        }

        // Se não houver entes federados (404 - CPF não encontrado), remove a permissão GestorCultBr
        if ($federativeEntities === false || $federativeEntities === null || empty($federativeEntities)) {
            $app->log->info("[Gestores CultBR] API não retornou entes federados, revogando GestorCultBr | Usuário ID: {$userId} | Documento: {$document} | Agente ID: {$agent->id}");

            if (UserAccessService::isGestorCultBr()) {
                $app->disableAccessControl();
                $app->user->removeRole(Role::GESTOR_CULT_BR);
                $app->enableAccessControl();
                $app->log->info("[Gestores CultBR] Role GestorCultBr removida | Usuário ID: {$userId} | Agente ID: {$agent->id}");
            }

            $this->removeFederativeEntityAgentRelations($agent);

            $_SESSION['gestor_cult_sync_completed'] = true;
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);
            $app->log->info("[Gestores CultBR] Sync concluído (revogação) | Usuário ID: {$userId} | Agente ID: {$agent->id}");
            return;
        }

        $shouldGrantGestorRole = !UserAccessService::isGestorCultBr();

        try {
            $this->associateFederativeEntities($agent, $federativeEntities, function () use ($app, $agent, $apiResponse, $shouldGrantGestorRole, $userId) {
                $app->disableAccessControl();

                try {
                    if (is_array($apiResponse)) {
                        $this->updateAgentFromGestorResponse($agent, $apiResponse);
                        $agent->setMetadata('gestorCultBrLastSyncedAt', (new \DateTime())->format('Y-m-d H:i:s'));
                        $agent->save(false);
                    }

                    if ($shouldGrantGestorRole) {
                        $this->grantGestorCultBrRole($userId, $agent);
                    }
                } finally {
                    $app->enableAccessControl();
                }
            });
            
            // Limpa flags de erro se o sync foi bem-sucedido
            unset($_SESSION['gestor_cult_sync_error']);
            unset($_SESSION['gestor_cult_sync_error_message']);

            // Marca como concluído sem erro
            $_SESSION['gestor_cult_sync_completed'] = true;
            $app->log->info("[Gestores CultBR] Sync concluído com sucesso | Usuário ID: {$userId} | Agente ID: {$agent->id} | Entes federados associados: " . count($federativeEntities));
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
     * @param mixed $response Retorno bruto da API
     * @return array Lista de entes (cada item com 'name' e 'document')
     */
    protected function extractFederativeEntitiesFromResponse($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if (array_key_exists('entes_federados', $response)) {
            if (!is_array($response['entes_federados'])) {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ': entes_federados deve ser array');
            }

            return $response['entes_federados'];
        }

        if (!$this->isListArray($response)) {
            throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ': chave entes_federados ausente');
        }

        // Formato antigo: o próprio retorno é a lista de entes
        return $response;
    }

    protected function fetchGestorData()
    {
        return (new GestorClient($this->gestorDocument))->get();
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
    protected function updateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
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

    protected function grantGestorCultBrRole($userId, Agent $agent): void
    {
        $app = App::i();

        $roleDefinition = $app->getRoleDefinition(Role::GESTOR_CULT_BR);
        if (is_null($roleDefinition)) {
            throw new \RuntimeException('Role GestorCultBr não registrada');
        }

        if ($app->user->is(Role::GESTOR_CULT_BR)) {
            return;
        }

        $role = new MapasRole();
        $role->user = $app->user;
        $role->name = Role::GESTOR_CULT_BR;
        $role->subsiteId = $roleDefinition->subsiteContext ? $app->getCurrentSubsiteId() : null;

        $app->em->persist($role);
        $this->appendRoleToCurrentUser($role);
        $app->log->info("[Gestores CultBR] Role GestorCultBr concedida | Usuário ID: {$userId} | Agente ID: {$agent->id}");
    }

    private function appendRoleToCurrentUser(MapasRole $role): void
    {
        $user = App::i()->user;

        \Closure::bind(function () use ($role) {
            $this->roles[] = $role;
        }, $user, get_class($user))();
    }

    /**
     * Normaliza valor para comparação (evita diferença entre null, '' e espaços).
     */
    protected function normalizeStringForComparison($value): string
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
    protected function normalizeFederativeEntities($federativeEntities): array
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
            
            // Se não for JSON válido, tenta unserialize
            $unserialized = @unserialize($federativeEntities);
            if ($unserialized !== false && is_array($unserialized)) {
                return $unserialized;
            }
        }

        // Se não conseguiu normalizar, retorna array vazio
        return [];
    }

    private function isListArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }

    private function normalizeEntityExercises(array $data): array
    {
        if (!array_key_exists('exercicios', $data)) {
            if (array_key_exists('exercices', $data)) {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ': chave exercices não é suportada');
            }

            return [];
        }

        return is_array($data['exercicios']) ? $data['exercicios'] : [];
    }

    private function hasParData(array $data): bool
    {
        return array_key_exists('exercicios', $data) && $this->isValidExercisesList($data['exercicios']);
    }

    private function filterFederativeEntitiesWithParData(array $federativeEntities): array
    {
        return array_values(array_filter($federativeEntities, fn(array $data) => $this->hasParData($data)));
    }

    private function validateFederativeEntitiesContract(array $federativeEntities): void
    {
        $documents = [];

        foreach ($federativeEntities as $index => $data) {
            if (!is_array($data)) {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": item {$index} deve ser array");
            }

            if (!isset($data['document']) || trim((string) $data['document']) === '') {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": item {$index} sem document");
            }

            if (!isset($data['name']) || trim((string) $data['name']) === '') {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": item {$index} sem name");
            }

            $document = trim((string) $data['document']);
            if (isset($documents[$document])) {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": document duplicado {$document}");
            }
            $documents[$document] = true;

            $exercises = $this->normalizeEntityExercises($data);
            if ($exercises !== [] && !$this->isValidExercisesList($exercises)) {
                throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": item {$index} com exercicios inválidos");
            }
        }
    }

    private function isValidExercisesList($exercises): bool
    {
        if (!is_array($exercises) || $exercises === [] || !$this->isListArray($exercises)) {
            return false;
        }

        foreach ($exercises as $exercise) {
            if (!is_array($exercise)) {
                return false;
            }

            if (!array_key_exists('id', $exercise) || !array_key_exists('ano', $exercise) || !array_key_exists('metas', $exercise)) {
                return false;
            }

            if (!is_array($exercise['metas'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove somente as associações entre o agente e Entes Federados.
     *
     * @param \MapasCulturais\Entities\Agent $agent
     * @return void
     */
    private function removeFederativeEntityAgentRelations(Agent $agent): void
    {
        $app = App::i();
        $em = $app->em;

        $relations = $app->repo(FederativeEntityAgentRelation::class)->findBy([
            'agent' => $agent
        ]);

        if (empty($relations)) {
            $app->log->info("[Gestores CultBR] Nenhuma associação de Ente Federado para remover | Agente ID: {$agent->id}");
            return;
        }

        $em->beginTransaction();

        try {
            $removedCount = 0;
            foreach ($relations as $relation) {
                if (!$relation->owner instanceof FederativeEntity) {
                    continue;
                }

                $em->remove($relation);
                $removedCount++;
            }

            $em->flush();
            $em->commit();
            $app->log->info("[Gestores CultBR] Associações com Entes Federados removidas | Agente ID: {$agent->id} | Removidas: {$removedCount}");
        } catch (\Throwable $e) {
            $em->rollback();
            $app->log->critical("[Gestores CultBR] Erro ao remover associações com Entes Federados | Agente ID: {$agent->id} | Erro: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Associa os entes federados ao agente
     * 
     * @param \MapasCulturais\Entities\Agent $agent
     * @param array $federativeEntities
     * @return void
     */
    protected function associateFederativeEntities(Agent $agent, array $federativeEntities, ?callable $beforeFlush = null): void
    {
        $app = App::i();
        $em  = $app->em;

        $this->validateFederativeEntitiesContract($federativeEntities);

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
            $removedCount = 0;
            foreach ($currentByDoc as $doc => $relation) {
                if (!isset($apiLookup[$doc])) {
                    $em->remove($relation);
                    $removedCount++;
                }
            }

            // Processa os Entes Federados da API
            $newAssociationsCount = 0;
            foreach ($federativeEntities as $data) {
                $doc = trim((string) $data['document']);
                $name = trim((string) $data['name']);
                $exercicios = $this->normalizeEntityExercises($data);

                $entity = $entitiesByDoc[$doc] ?? null;

                if ($entity) {
                    $changed = false;
                    if ($entity->name !== $name) {
                        $entity->name = $name;
                        $changed = true;
                    }

                    $currentJson = $entity->exercices === null ? null : json_encode($entity->exercices);
                    $newJson = json_encode($exercicios);
                    if ($currentJson !== $newJson) {
                        $entity->exercices = $exercicios;
                        $changed = true;
                    }
                    if ($changed) {
                        $em->persist($entity);
                    }
                } else {
                    $entity = new FederativeEntity();
                    $entity->name = $name;
                    $entity->document = $doc;
                    $entity->exercices = $exercicios;
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
                    $newAssociationsCount++;
                }
            }

            // Salva as alterações no banco de dados
            $this->beforeFlushFederativeEntityAssociations();
            if ($beforeFlush) {
                $beforeFlush();
            }

            $em->flush();
            $em->commit();
            $app->log->info("[Gestores CultBR] Associações com Entes Federados atualizadas | Agente ID: {$agent->id} | Novas: {$newAssociationsCount} | Removidas: {$removedCount}");
        } catch (\Throwable $e) {
            $em->rollback();
            $app->log->critical("[Gestores CultBR] Erro ao associar Entes Federados | Agente ID: {$agent->id} | Erro: " . $e->getMessage());
            throw $e;
        }
    }

    protected function beforeFlushFederativeEntityAssociations(): void
    {
    }
}
