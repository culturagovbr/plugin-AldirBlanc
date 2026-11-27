<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Jobs\OportunidadeCultJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** A duplicação salva a cópia antes de ela receber os metadados: o envio dela espera a requisição terminar. */
class EnvioDoEditalNaDuplicacaoTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use IsolatesJobQueue;

    private function editalElegivel(): Opportunity
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, 'Subsite Duplicacao ' . uniqid()));
        $this->clearJobQueue();

        return $raiz;
    }

    private function salva(Opportunity $raiz): void
    {
        $raiz->shortDescription = 'Descrição ' . uniqid();
        $raiz->save(true);
    }

    private function envios(): int
    {
        return count($this->app->repo(Job::class)->findBy(['type' => OportunidadeCultJob::SLUG]));
    }

    public function testDuranteADuplicacaoSalvarNaoAgendaOEnvio(): void
    {
        $raiz = $this->editalElegivel();
        $this->app->applyHook('ALL(opportunity.duplicate):before');

        try {
            $this->salva($raiz);
        } finally {
            $this->app->view->marcaDuplicacaoDeEdital(false);
        }

        $this->assertSame(0, $this->envios());
    }

    public function testFimDaRequisicaoLiberaOEnvio(): void
    {
        $raiz = $this->editalElegivel();
        $this->app->applyHook('ALL(opportunity.duplicate):before');
        $this->app->applyHook('mapasculturais.run:after');

        $this->salva($raiz);

        $this->assertSame(1, $this->envios());
    }

    public function testForaDaDuplicacaoSalvarAgendaOEnvio(): void
    {
        $this->salva($this->editalElegivel());

        $this->assertSame(1, $this->envios());
    }
}
