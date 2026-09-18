<?php

namespace Tests\AldirBlanc\Traits;

use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Jobs\OportunidadeCultJob;
use AldirBlanc\Plugin;
use AldirBlanc\Services\CultBrRequestLogService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;

/** Exercita o envio de oportunidade pelo job, com o provedor trocado por dublê. */
trait SendsOpportunityThroughJob
{
    use ConfiguresPlugin;

    /** O bucket da Conecta não vem do override da suíte: o envio por ela precisa dele declarado. */
    private const CONFIG_CONECTA = [
        'mode' => 'development',
        'host' => 'http://conecta.invalid',
        'token' => 'token-de-teste',
        'entesEndpoint' => 'auth/pessoa/{document}/entes',
        'parAcoesEndpoint' => 'par/acoes',
        'oportunidadeEndpoint' => 'oportunidades/{id}',
        'validarTokenEndpoint' => 'validar-token',
    ];

    protected function createOpportunity(User $user): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Log CultBR Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    protected function enqueueUpdateJob(Opportunity $opp, array $extra = []): void
    {
        $this->app->enqueueOrReplaceJob(OportunidadeCultJob::SLUG, [
            'opportunity' => $opp,
            'action'      => 'update',
        ] + $extra);
    }

    protected function logs(int $opportunityId): array
    {
        return (new CultBrRequestLogService())->findByOpportunity($opportunityId);
    }

    /** A config do plugin é leitura: trocar o provedor exige reflexão e limpar a memoização. */
    protected function comProviderConfigurado(string $valor, callable $exercicio): void
    {
        $plugin = Plugin::getInstance();

        $this->comConfigDoPlugin(
            function (array $config) use ($valor) {
                $config['client']['provider'] = $valor;

                // O modo vale para a integração inteira, não para um provedor.
                $config['client']['mode'] = self::CONFIG_CONECTA['mode'];
                $config['client']['providers']['conecta'] = self::CONFIG_CONECTA;

                return $config;
            },
            function () use ($exercicio, $plugin) {
                $plugin->resetIntegrationProvider();

                try {
                    $exercicio();
                } finally {
                    $plugin->resetIntegrationProvider();
                }
            }
        );
    }

    /** Roda o exercício com o envio atendido por um provedor de teste, resolvido por nome de classe. */
    protected function comProvedorDuble(callable $aoEnviar, callable $exercicio): void
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoEnviar = $aoEnviar;

        try {
            $this->comProviderConfigurado(FakeIntegrationProvider::class, $exercicio);
        } finally {
            // O que foi enviado sobrevive ao exercício: é o que o teste asserta depois.
            FakeIntegrationProvider::$aoEnviar = null;
        }
    }

    protected function desfecho(array $trocas = []): SendOutcome
    {
        return new SendOutcome(
            provider: $trocas['provider'] ?? Provider::Conecta,
            result: $trocas['result'] ?? SendResult::Success,
            method: 'PUT',
            endpoint: $trocas['endpoint'] ?? 'http://conecta.invalid/oportunidades/9',
            payload: ['id' => 9],
            sentAt: new \DateTime(),
            durationMs: 42,
            response: $trocas['response'] ?? '{"id_par_edital":1249}',
            responseHeaders: ['HTTP/2 200'],
            httpStatus: array_key_exists('httpStatus', $trocas) ? $trocas['httpStatus'] : 200,
        );
    }
}
