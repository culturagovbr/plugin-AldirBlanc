<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use AldirBlanc\Integration\IntegrationProvider;
use AldirBlanc\Integration\ManagerSnapshotMapper;
use AldirBlanc\Jobs\GestorCultJob;
use MapasCulturais\Entities\Agent;

/**
 * Expõe os métodos protected de GestorCultJob como públicos, só para teste.
 * Não altera nenhum comportamento — apenas wrappers finos.
 */
class TestableGestorCultJob extends GestorCultJob
{
    private ?GestorDocument $documento = null;
    private ?Transport $transport = null;
    private Provider $provedor = Provider::Gestao;

    private mixed $gestorResponse = null;
    private bool $hasGestorResponse = false;
    private ?\Throwable $gestorException = null;
    private ?\Throwable $associateException = null;
    private ?\Throwable $updateAgentException = null;
    private ?\Throwable $grantRoleException = null;
    private ?\Throwable $beforeFlushException = null;

    public function __construct(GestorDocument $gestorDocument)
    {
        parent::__construct($gestorDocument);

        $this->documento = $gestorDocument;
    }

    /** Liga o provedor de verdade ao job, para exercitar o parse junto com o gate de revogação. */
    public function useTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    /** Qual API o job atende: decide a implementação construída e se a lista plana é aceita. */
    public function useProvider(Provider $provedor): void
    {
        $this->provedor = $provedor;
    }

    /** Guarda a resposta crua; o parse espera o provedor, que o teste pode trocar depois disto. */
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

    protected function fetchGestorData(): ?ManagerSnapshot
    {
        if ($this->gestorException) {
            throw $this->gestorException;
        }

        if ($this->hasGestorResponse) {
            return is_array($this->gestorResponse)
                ? ManagerSnapshotMapper::fromResponse($this->gestorResponse, acceptFlatList: $this->aceitaListaPlana())
                : $this->gestorResponse;
        }

        if ($this->transport !== null) {
            return $this->provedorReal()->fetchManager($this->documento);
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

    protected function updateAgentFromGestorResponse(Agent $agent, ManagerSnapshot $snapshot): void
    {
        if ($this->updateAgentException) {
            throw $this->updateAgentException;
        }

        parent::updateAgentFromGestorResponse($agent, $snapshot);
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

    /** Recebe a resposta como a API a devolve e monta o snapshot, para o teste ler igual ao real. */
    public function callUpdateAgentFromGestorResponse(Agent $agent, array $apiResponse): void
    {
        $this->updateAgentFromGestorResponse($agent, ManagerSnapshotMapper::fromResponse(
            $apiResponse + ['entes_federados' => []],
            acceptFlatList: false,
        ));
    }

    private function provedorReal(): IntegrationProvider
    {
        return match ($this->provedor) {
            Provider::Conecta => new ConectaProvider($this->transport),
            Provider::Gestao => new GestaoProvider($this->transport),
        };
    }

    /** Só a Gestão tolera a lista sem envelope; a Conecta a recusa como erro de contrato. */
    private function aceitaListaPlana(): bool
    {
        return match ($this->provedor) {
            Provider::Gestao => true,
            Provider::Conecta => false,
        };
    }
}
