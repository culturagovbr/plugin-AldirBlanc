<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Enum\Provider;
use AldirBlanc\Exceptions\IntegrationError;
use MapasCulturais\App;

/** Escolhe a implementação ativa pela configuração, e recusa qualquer coisa que não sirva. */
final class ProviderResolver
{
    private const NAMESPACE_PREFIX = 'AldirBlanc\\Integration\\';

    private ?IntegrationProvider $resolved = null;

    private ?IntegrationError $failure = null;

    public function __construct(private readonly mixed $configured)
    {
    }

    public function resolve(): IntegrationProvider
    {
        // A falha também é memoizada: sem isso, um lote de envios logaria um critical por item.
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            return $this->resolved ??= $this->build();
        } catch (IntegrationError $e) {
            $this->failure = $e;

            throw $e;
        }
    }

    /** A configuração é leitura, mas o teste precisa reler a cada troca dentro do mesmo processo. */
    public function reset(): void
    {
        $this->resolved = null;
        $this->failure = null;
    }

    private function build(): IntegrationProvider
    {
        $declarado = trim((string) $this->configured);

        if ($declarado === '') {
            throw $this->configurationError('sem valor — declare ' . $this->aceitos());
        }

        $classe = $this->className($declarado);

        if (!class_exists($classe)) {
            throw $this->configurationError("classe {$classe} não existe");
        }

        $provider = new $classe();

        if (!$provider instanceof IntegrationProvider) {
            throw $this->configurationError("classe {$classe} não implementa " . IntegrationProvider::class);
        }

        return $provider;
    }

    /** Valor com barra é nome de classe completo; valor curto precisa ser um provedor conhecido. */
    private function className(string $declarado): string
    {
        if (str_contains($declarado, '\\')) {
            return $declarado;
        }

        $provider = Provider::tryFrom($declarado);

        if ($provider === null) {
            throw $this->configurationError("valor {$declarado} desconhecido — use " . $this->aceitos());
        }

        return self::NAMESPACE_PREFIX . $provider->name . '\\' . $provider->name . 'Provider';
    }

    private function aceitos(): string
    {
        return implode(' ou ', array_column(Provider::cases(), 'value')) . ', ou um nome de classe completo';
    }

    private function configurationError(string $detalhe): IntegrationError
    {
        $erro = IntegrationError::configuration('PNAB_CULTBR_PROVIDER', $detalhe);

        App::i()->log->critical('[CultBR] ' . $erro->getMessage());

        return $erro;
    }
}
