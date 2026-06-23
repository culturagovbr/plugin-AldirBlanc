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
    public const API_UNAVAILABLE_MESSAGE = 'Não conseguimos estabelecer conexão com a API CultBr. Tente novamente mais tarde.';

    private GestorDocument $gestorDocument;

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
        
        $apiResponse = null;

        try {
            $apiResponse = (new GestorClient($this->gestorDocument))->get();

            $federativeEntities = $this->extractFederativeEntitiesFromResponse($apiResponse);
            $federativeEntities = $this->normalizeFederativeEntities($federativeEntities);
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

            // Atualiza o agente com dados do gestor (apenas campos alterados)
            if (is_array($apiResponse)) {
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
     * @param mixed $response Retorno bruto da API
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
            
            // Se não for JSON válido, tenta unserialize
            $unserialized = @unserialize($federativeEntities);
            if ($unserialized !== false && is_array($unserialized)) {
                return $unserialized;
            }
        }

        // Se não conseguiu normalizar, retorna array vazio
        return [];
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
