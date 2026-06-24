<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Jobs\GestorCultJob;
use MapasCulturais\Entities\Agent;

/**
 * Expõe os métodos protected de GestorCultJob como públicos, só para teste.
 * Não altera nenhum comportamento — apenas wrappers finos.
 */
class TestableGestorCultJob extends GestorCultJob
{
    private mixed $gestorResponse = null;
    private bool $hasGestorResponse = false;
    private ?\Throwable $gestorException = null;
    private ?\Throwable $associateException = null;
    private ?\Throwable $updateAgentException = null;
    private ?\Throwable $grantRoleException = null;
    private ?\Throwable $beforeFlushException = null;

    public function setGestorResponse(mixed $response): void
    {
        $this->gestorResponse = $response;
        $this->hasGestorResponse = true;
    }

    public function setGestorException(\Throwable $exception): void
    {
        $this->gestorException = $exception;
    }

    public function setAssociateException(\Throwable $exception): void
    {
        $this->associateException = $exception;
    }

    public function setUpdateAgentException(\Throwable $exception): void
    {
        $this->updateAgentException = $exception;
    }

    public function setGrantRoleException(\Throwable $exception): void
    {
        $this->grantRoleException = $exception;
    }

    public function setBeforeFlushException(\Throwable $exception): void
    {
        $this->beforeFlushException = $exception;
    }

    protected function fetchGestorData()
    {
        if ($this->gestorException) {
            throw $this->gestorException;
        }

        if ($this->hasGestorResponse) {
            return $this->gestorResponse;
        }

        return parent::fetchGestorData();
    }

    protected function associateFederativeEntities(Agent $agent, array $federativeEntities, ?callable $beforeFlush = null): void
    {
        if ($this->associateException) {
            throw $this->associateException;
        }

        parent::associateFederativeEntities($agent, $federativeEntities, $beforeFlush);
    }

    protected function updateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
    {
        if ($this->updateAgentException) {
            throw $this->updateAgentException;
        }

        parent::updateAgentFromGestorResponse($agent, $apiResponse);
    }

    protected function grantGestorCultBrRole($userId, Agent $agent): void
    {
        if ($this->grantRoleException) {
            throw $this->grantRoleException;
        }

        parent::grantGestorCultBrRole($userId, $agent);
    }

    protected function beforeFlushFederativeEntityAssociations(): void
    {
        if ($this->beforeFlushException) {
            throw $this->beforeFlushException;
        }
    }

    public function callExtractFederativeEntitiesFromResponse($response): array
    {
        return $this->extractFederativeEntitiesFromResponse($response);
    }

    public function callNormalizeFederativeEntities($federativeEntities): array
    {
        return $this->normalizeFederativeEntities($federativeEntities);
    }

    public function callNormalizeStringForComparison($value): string
    {
        return $this->normalizeStringForComparison($value);
    }

    public function callAssociateFederativeEntities(Agent $agent, array $federativeEntities): void
    {
        $this->associateFederativeEntities($agent, $federativeEntities);
    }

    public function callUpdateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
    {
        $this->updateAgentFromGestorResponse($agent, $apiResponse);
    }
}
