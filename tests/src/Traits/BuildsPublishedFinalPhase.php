<?php

namespace Tests\AldirBlanc\Traits;

use AldirBlanc\Controller;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;

/** Monta o cenário do envio de resultado: raiz elegível na régua da fase 1 + fase final publicada. */
trait BuildsPublishedFinalPhase
{
    protected function integrationSubsite(User $owner, string $name): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = $name;
        $subsite->url = 'resultado-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();

        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        return $subsite;
    }

    /** Raiz que passa inteira na régua de envio da fase 1 (ente, subsite, PAR completo). */
    protected function raizElegivel(User $owner, Subsite $subsite): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $raiz = new ($owner->profile->opportunityClassName)();
        $raiz->owner = $owner->profile;
        $raiz->ownerEntity = $owner->profile;
        $raiz->name = 'Edital com resultado';
        $raiz->shortDescription = 'desc';
        $raiz->status = Opportunity::STATUS_ENABLED;
        $raiz->subsite = $subsite;
        $raiz->save(true);

        $raiz->setMetadata('federativeEntityId', 1);
        $raiz->setMetadata('parExercicioId', '1');
        $raiz->setMetadata('parMetaId', '2');
        $raiz->setMetadata('parAcaoId', '3');
        $raiz->setMetadata('parAtividadeId', '4');
        $raiz->save(true);

        $this->app->enableAccessControl();

        return $raiz;
    }

    /** A fase final já nasce com a raiz (OpportunityPhases): criar outra deixaria duas isLastPhase. */
    protected function faseFinal(Opportunity $raiz, bool $publicada = true, ?\DateTime $publicadaEm = new \DateTime('2026-10-10 12:00:00')): Opportunity
    {
        $this->app->disableAccessControl();

        $fase = $raiz->lastPhase;

        if (!$fase || !$fase->id || (int) $fase->id === (int) $raiz->id) {
            $fase = new ($raiz->owner->opportunityClassName)();
            $fase->owner = $raiz->owner;
            $fase->ownerEntity = $raiz->owner;
            $fase->name = 'Publicação final do resultado';
            $fase->shortDescription = 'desc';
            $fase->status = Opportunity::STATUS_PHASE;
            $fase->parent = $raiz;
            $fase->save(true);

            // '1' literal: a query do core que resolve lastPhase filtra por m.value = '1'.
            $fase->setMetadata('isLastPhase', '1');
            $fase->save(true);
        }

        if ($publicada) {
            $fase->publishedRegistrations = true;
            $fase->publishTimestamp = $publicadaEm;
            $fase->save(true);
        }

        $this->app->enableAccessControl();

        return $fase;
    }

    /** Grava como o worker grava: SQL direto, fora da entidade em memória. */
    protected function gravaMeta(int $opportunityId, string $key, string $value): void
    {
        $this->app->em->getConnection()->executeStatement(
            'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
            ['id' => $opportunityId, 'key' => $key, 'value' => $value]
        );
    }

    /** Cenário completo: raiz elegível com id do PAR + fase final publicada. */
    protected function editalComFaseFinalPublicada(User $owner, string $subsiteName = 'Subsite Resultado'): array
    {
        $subsite = $this->integrationSubsite($owner, $subsiteName);
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        return [$raiz, $fase];
    }

    /** Estrutura publicada sem questões obrigatórias: o formulário é completo por vacuidade. */
    protected const ESTRUTURA_SEM_OBRIGATORIAS = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => []];

    /** Roda o exercício com o formulário de resultado do edital resolvendo completo (ou como a estrutura dada). */
    protected function comFormularioResultado(callable $exercicio, ?array $estrutura = null): void
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => 1;
        FakeIntegrationProvider::$aoBuscarFormulario = fn() => $estrutura ?? self::ESTRUTURA_SEM_OBRIGATORIAS;

        $plugin = Plugin::getInstance();

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = FakeIntegrationProvider::class;
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            function () use ($exercicio, $plugin) {
                $plugin->resetIntegrationProvider();

                try {
                    $exercicio();
                } finally {
                    $plugin->resetIntegrationProvider();
                    FakeIntegrationProvider::reset();
                }
            },
        );
    }
}
