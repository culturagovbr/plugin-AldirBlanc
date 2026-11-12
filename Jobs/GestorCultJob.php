<?php

namespace AldirBlanc\Jobs;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\OpportunityMeta;
use MapasCulturais\Entities\Role as MapasRole;
use AldirBlanc\Enum\Role;
use AldirBlanc\Enum\SyncFailure;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Dtos\FederativeEntitySnapshot;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Integration\FederativeEntityDocument;
use AldirBlanc\Plugin;
use AldirBlanc\Services\UserAccessService;

class GestorCultJob
{
    public const API_UNAVAILABLE_MESSAGE = SyncFailure::MENSAGEM_API_INDISPONIVEL;
    private const CONTRACT_ERROR_MESSAGE = 'Resposta da API CultBr fora do contrato esperado';

    private GestorDocument $gestorDocument;

    /** TTL de segurança do lock de concorrência: cobre o tempo de uma sincronização real (chamada à API), liberado no finally ao terminar. */
    private const SYNC_LOCK_TTL = 300;

    private const MISSING_ENTITY_GRACE_DAYS = 7;
    private const MISSING_SINCE_KEY = 'missingSince';
    private const MISSING_SINCE_FORMAT = 'Y-m-d H:i:s';

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

        try {
            $snapshot = $this->fetchGestorData();
            $federativeEntities = $snapshot === null ? [] : $this->federativeEntitiesFrom($snapshot, $userId, $document);

            $app->log->info($snapshot === null
                ? "[Gestores CultBR] Documento desconhecido pela origem | Usuário ID: {$userId} | Documento: {$document}"
                : "[Gestores CultBR] Resposta da API recebida | Usuário ID: {$userId} | Documento: {$document} | Entes federados retornados: " . count($federativeEntities));
        } catch (\Throwable $e) {
            $app->log->error("[Gestores CultBR] Erro ao buscar dados da API durante sincronização | Usuário ID: {$userId} | Documento: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());

            // Marca como concluído com erro para não travar a tela
            $_SESSION['gestor_cult_sync_completed'] = true;

            // Quem classifica a causa é o controller, que conhece os dois lados da exceção.
            throw $e;
        }

        $hasFederativeEntities = $federativeEntities !== [];
        $shouldGrantGestorRole = $hasFederativeEntities && !UserAccessService::isGestorCultBr();

        try {
            $this->associateFederativeEntities($agent, $federativeEntities, function () use ($app, $agent, $snapshot, $hasFederativeEntities, $shouldGrantGestorRole, $userId) {
                $app->disableAccessControl();

                try {
                    if ($snapshot !== null && $hasFederativeEntities) {
                        $this->updateAgentFromGestorResponse($agent, $snapshot);
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
            $app->log->error("[Gestores CultBR] Erro ao associar entes federados durante sincronização | Usuário ID: {$userId} | Documento: {$document} | Erro: " . $e->getMessage() . " | Código: " . $e->getCode());

            // Marca como concluído com erro
            $_SESSION['gestor_cult_sync_completed'] = true;

            throw $e;
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

    /** @return list<array> os entes do snapshot que atendem ao contrato, já sem duplicados */
    private function federativeEntitiesFrom(ManagerSnapshot $snapshot, $userId, string $document): array
    {
        $federativeEntities = $this->extractFederativeEntitiesFromResponse($this->entitiesAsArray($snapshot));
        $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);

        return $this->discardEntitiesOutOfContract($federativeEntities, $userId, $document);
    }

    protected function fetchGestorData(): ?ManagerSnapshot
    {
        return Plugin::getInstance()->integrationProvider()->fetchManager($this->gestorDocument);
    }

    /** O job trabalha com a forma que já sabe tratar; a origem dela é que passou a ser o provedor. */
    private function entitiesAsArray(ManagerSnapshot $snapshot): array
    {
        return array_map(
            fn(FederativeEntitySnapshot $ente) => [
                'document' => $ente->document,
                'name' => $ente->name,
                'exercicios' => $ente->exercices,
            ],
            $snapshot->entities(),
        );
    }

    /**
     * Mapeamento: campo do contrato => chave de metadado do Agent.
     * Apenas campos que devem ser atualizados no sync.
     */
    private const GESTOR_API_TO_AGENT_METADATA = [
        'rg' => 'rgNumero',
        'cep' => 'En_CEP',
        'name' => 'nomeCompleto',
        'cellphone' => 'telefone1',      // telefone privado 1 (campo no tema Pnab)
        'number' => 'En_Num',
        'complement' => 'En_Complemento',
    ];

    /**
     * Atualiza o agente com os dados do retorno da API do gestor.
     * Altera apenas metadados cujo valor seja diferente do atual; se nada mudou, não persiste.
     *
     * @param Agent $agent
     * @param ManagerSnapshot $snapshot dados de pessoa que a origem devolveu
     */
    protected function updateAgentFromGestorResponse(Agent $agent, ManagerSnapshot $snapshot): void
    {
        foreach (self::GESTOR_API_TO_AGENT_METADATA as $campo => $agentKey) {
            $apiValue = $snapshot->{$campo}();
            $normalizedApi = $this->normalizeStringForComparison($apiValue);

            // Campo sem valor na resposta é ausência de informação, não ordem de apagar: En_CEP e
            // En_Num são obrigatórios no perfil, e esvaziá-los devolve o gestor a completeProfile.
            if ($normalizedApi === '') {
                continue;
            }

            $currentValue = $agent->getMetadata($agentKey);
            $normalizedCurrent = $this->normalizeStringForComparison($currentValue);

            if ($normalizedApi === $normalizedCurrent) {
                continue;
            }

            $agent->setMetadata($agentKey, (string) $apiValue);
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
        if (is_array($federativeEntities)) {
            return FederativeEntityDocument::dedupe($federativeEntities);
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
        $exercises = $data['exercicios'] ?? [];

        return is_array($exercises) && ($exercises === [] || $this->isValidExercisesList($exercises)) ? $exercises : [];
    }

    /** Resposta sem exercicios não apaga a árvore gravada: sem ela o gestor não cria oportunidade. */
    private function applyEntityExercises(FederativeEntity $entity, array $exercicios): bool
    {
        $gravada = $entity->exercices;

        if ($exercicios === [] && $gravada !== null && $gravada !== []) {
            App::i()->log->warning("[Gestores CultBR] Árvore do PAR preservada | Ente: {$entity->document} | Motivo: resposta sem exercicios");

            return false;
        }

        if (($gravada === null ? null : json_encode($gravada)) === json_encode($exercicios)) {
            return false;
        }

        $entity->exercices = $exercicios;

        return true;
    }

    private function validateFederativeEntitiesContract(array $federativeEntities): void
    {
        [, $discarded] = $this->partitionFederativeEntitiesByContract($federativeEntities);

        if ($discarded !== []) {
            $index = array_key_first($discarded);
            throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ": item {$index} {$discarded[$index]}");
        }
    }

    /**
     * Descarta os entes fora do contrato e mantém os demais, para um item ruim não derrubar o sync inteiro.
     */
    private function discardEntitiesOutOfContract(array $federativeEntities, $userId, string $document): array
    {
        [$valid, $discarded] = $this->partitionFederativeEntitiesByContract($federativeEntities);

        if ($discarded === []) {
            return $valid;
        }

        $app = App::i();
        foreach ($discarded as $index => $reason) {
            $app->log->warning("[Gestores CultBR] Ente federado descartado | Usuário ID: {$userId} | Documento: {$document} | Item: {$index} | Motivo: {$reason}");
        }

        // Seguir com a lista vazia revogaria a role e apagaria todas as relações do agente.
        if ($valid === []) {
            throw new \UnexpectedValueException(self::CONTRACT_ERROR_MESSAGE . ': nenhum ente federado válido na resposta');
        }

        return $valid;
    }

    /**
     * Separa os entes que atendem ao contrato dos que precisam ser descartados.
     *
     * @return array{0: list<array>, 1: array<int|string, string>} válidos, e o motivo de descarte por índice
     */
    private function partitionFederativeEntitiesByContract(array $federativeEntities): array
    {
        $valid = [];
        $discarded = [];
        $documents = [];

        foreach ($federativeEntities as $index => $data) {
            $violation = $this->federativeEntityContractViolation($data, $documents);

            if ($violation !== null) {
                $discarded[$index] = $violation;
                continue;
            }

            $documents[trim((string) $data['document'])] = true;
            $valid[] = $data;
        }

        return [$valid, $discarded];
    }

    private function federativeEntityContractViolation($data, array $knownDocuments): ?string
    {
        if (!is_array($data)) {
            return 'deve ser array';
        }

        if (!isset($data['document']) || trim((string) $data['document']) === '') {
            return 'sem document';
        }

        if (!isset($data['name']) || trim((string) $data['name']) === '') {
            return 'sem name';
        }

        $document = trim((string) $data['document']);
        if (isset($knownDocuments[$document])) {
            return "com document duplicado {$document}";
        }

        return null;
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

    /** Grava a data em que o ente deixou de vir da origem; devolve se a marca é nova. */
    private function markMissing(FederativeEntityAgentRelation $relation): bool
    {
        $metadata = (array) ($relation->metadata ?? []);
        if (isset($metadata[self::MISSING_SINCE_KEY])) {
            return false;
        }

        $metadata[self::MISSING_SINCE_KEY] = (new \DateTime())->format(self::MISSING_SINCE_FORMAT);
        $relation->metadata = $metadata;
        App::i()->em->persist($relation);

        return true;
    }

    private function clearMissingMark(FederativeEntityAgentRelation $relation): void
    {
        $metadata = (array) ($relation->metadata ?? []);
        if (!isset($metadata[self::MISSING_SINCE_KEY])) {
            return;
        }

        unset($metadata[self::MISSING_SINCE_KEY]);
        $relation->metadata = $metadata === [] ? null : $metadata;
        App::i()->em->persist($relation);
        App::i()->log->info("[Gestores CultBR] Ente federado voltou a ser informado pela origem, marca removida | Ente ID: {$relation->owner->id}");
    }

    private function isMissingMarkExpired(FederativeEntityAgentRelation $relation): bool
    {
        $missingSince = ((array) ($relation->metadata ?? []))[self::MISSING_SINCE_KEY] ?? null;
        $markedAt = is_string($missingSince) ? \DateTime::createFromFormat(self::MISSING_SINCE_FORMAT, $missingSince) : false;

        return $markedAt !== false && $markedAt <= new \DateTime('-' . self::MISSING_ENTITY_GRACE_DAYS . ' days');
    }

    private function agentOwnsOpportunityInEntity(Agent $agent, FederativeEntity $entity): bool
    {
        $metas = App::i()->repo(OpportunityMeta::class)->findBy([
            'key' => 'federativeEntityId',
            'value' => (string) $entity->id,
        ]);

        foreach ($metas as $meta) {
            if ($meta->owner?->owner?->id === $agent->id) {
                return true;
            }
        }

        return false;
    }

    private function revokeGestorRoleOf(Agent $agent): void
    {
        $app = App::i();
        $user = $agent->user;
        if (!$user || !$user->is(Role::GESTOR_CULT_BR)) {
            return;
        }

        $app->disableAccessControl();
        try {
            $user->removeRole(Role::GESTOR_CULT_BR);
        } finally {
            $app->enableAccessControl();
        }

        $app->log->warning("[Gestores CultBR] Nenhum vínculo com ente restante, papel GestorCultBr removido | Usuário ID: {$user->id} | Agente ID: {$agent->id}");
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
            $removedCount = 0;
            $markedCount = 0;
            foreach ($currentByDoc as $doc => $relation) {
                if (isset($apiLookup[$doc])) {
                    $this->clearMissingMark($relation);
                    continue;
                }

                if ($this->isMissingMarkExpired($relation) && !$this->agentOwnsOpportunityInEntity($agent, $relation->owner)) {
                    $em->remove($relation);
                    $removedCount++;
                    $app->log->warning("[Gestores CultBR] Vínculo com ente não informado pela origem há mais de " . self::MISSING_ENTITY_GRACE_DAYS . " dias removido | Agente ID: {$agent->id} | Ente ID: {$relation->owner->id}");
                    continue;
                }

                if ($this->markMissing($relation)) {
                    $markedCount++;
                    $app->log->warning("[Gestores CultBR] Ente federado não informado pela origem, vínculo mantido e marcado | Agente ID: {$agent->id} | Ente ID: {$relation->owner->id}");
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

                    if ($this->applyEntityExercises($entity, $exercicios)) {
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

            if (count($currentByDoc) - $removedCount + $newAssociationsCount === 0) {
                $this->revokeGestorRoleOf($agent);
            }

            // Salva as alterações no banco de dados
            $this->beforeFlushFederativeEntityAssociations();
            if ($beforeFlush) {
                $beforeFlush();
            }

            $em->flush();
            $em->commit();
            $app->log->info("[Gestores CultBR] Associações com Entes Federados atualizadas | Agente ID: {$agent->id} | Novas: {$newAssociationsCount} | Marcadas: {$markedCount} | Removidas: {$removedCount}");
        } catch (\Throwable $e) {
            $em->rollback();
            $app->log->error("[Gestores CultBR] Erro ao associar Entes Federados | Agente ID: {$agent->id} | Erro: " . $e->getMessage());
            throw $e;
        }
    }

    protected function beforeFlushFederativeEntityAssociations(): void
    {
    }
}
