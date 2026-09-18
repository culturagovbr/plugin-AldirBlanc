<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Enum\Provider;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Plugin;
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

        if (!is_subclass_of($classe, IntegrationProvider::class)) {
            throw $this->configurationError("classe {$classe} não implementa " . IntegrationProvider::class);
        }

        $provider = $this->instanciar($classe);

        $this->logIdentification($provider);

        return $provider;
    }

    /** Classe abstrata ou construtor com argumento é erro de configuração, não API fora do ar. */
    private function instanciar(string $classe): IntegrationProvider
    {
        try {
            return new $classe();
        } catch (\Throwable $e) {
            throw $this->configurationError("classe {$classe} não pôde ser construída: " . $e->getMessage());
        }
    }

    /**
     * Uma linha por processo, porque a resolução é memoizada: na virada é o que confirma que
     * php-fpm e o loop de jobs leram a variável nova, sem depender de olhar a tela.
     */
    private function logIdentification(IntegrationProvider $provider): void
    {
        $config = Plugin::getInstance()?->config['client'] ?? [];
        $bucket = $config['providers'][$provider->provider()->value] ?? [];
        $token = (string) ($bucket['token'] ?? '');

        App::i()->log->info(sprintf(
            '[CultBR] integração ativa | provedor: %s | host: %s | token: %s',
            $provider->provider()->value,
            $bucket['host'] ?? '(sem host)',
            // Só o prefixo: o token inteiro no log vaza credencial para quem lê arquivo de log.
            $token === '' ? '(sem token)' : substr($token, 0, 6) . '...'
        ));
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
