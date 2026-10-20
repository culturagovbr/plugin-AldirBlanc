<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\ResultadoCultJob;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** O gatilho do envio de resultado: publicar a fase final enfileira o job — e só isso enfileira. */
class ThemeResultadoHooksTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use BuildsPublishedFinalPhase;
    use ConfiguresPlugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    private function publica(Opportunity $fase): void
    {
        // O save do cenário já enfileirou o job de oportunidade pelo hook do tema: só o ato importa aqui.
        $this->clearJobQueue();
        $this->app->disableAccessControl();
        $fase->publishRegistrations();
        $this->app->enableAccessControl();
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testPublicarAFaseFinalDeEditalElegivelEnfileiraOJob()
    {
        $this->comFormularioResultado(function () {
            $owner = $this->userDirector->createUser();
            $subsite = $this->integrationSubsite($owner, 'Subsite Hook Feliz');
            $raiz = $this->raizElegivel($owner, $subsite);
            $fase = $this->faseFinal($raiz, publicada: false);
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

            $this->publica($fase);

            $this->assertCount(1, $this->jobsDeResultado(), 'a publicação da fase final é o gatilho do envio');
        });
    }

    /** Com inscrição selecionada, o loop de publicação roda em->clear() e o hook recebe a fase detached — o caso real. */
    function testPublicarComInscricaoSelecionadaEnfileiraMesmoComAEntidadeDetached()
    {
        $this->comFormularioResultado(function () {
            $owner = $this->userDirector->createUser();
            $subsite = $this->integrationSubsite($owner, 'Subsite Hook Detached');
            $raiz = $this->raizElegivel($owner, $subsite);
            $fase = $this->faseFinal($raiz, publicada: false);
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

            $this->app->disableAccessControl();
            $inscricao = new Registration();
            $inscricao->opportunity = $fase;
            $inscricao->owner = $owner->profile;
            $inscricao->save(true);
            $this->app->em->getConnection()->executeStatement(
                'UPDATE registration SET status = 10 WHERE id = :id',
                ['id' => (int) $inscricao->id]
            );
            $this->app->enableAccessControl();

            // Entidade fria, como no request real: metadados ainda não hidratados quando o clear do loop roda.
            $faseId = (int) $fase->id;
            $this->app->em->clear();
            $faseFria = $this->app->repo('Opportunity')->find($faseId);

            $this->publica($faseFria);

            $this->assertCount(1, $this->jobsDeResultado(), 'o gatilho funciona com a fase desanexada pelo loop de publicação');
        });
    }

    function testPublicarComFormularioIncompletoNaoEnfileira()
    {
        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->comFormularioResultado(function () {
            $owner = $this->userDirector->createUser();
            $subsite = $this->integrationSubsite($owner, 'Subsite Hook Incompleto');
            $raiz = $this->raizElegivel($owner, $subsite);
            $fase = $this->faseFinal($raiz, publicada: false);
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

            $this->publica($fase);

            $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário completo a publicação não enfileira');
        }, $estruturaComObrigatoria);
    }

    /** Fase intermediária e fase de recurso caem aqui: sem isLastPhase, publicar não envia nada. */
    function testPublicarFaseQueNaoEhAFinalNaoEnfileira()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Hook Intermediaria');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $this->app->disableAccessControl();
        $fase->setMetadata('isLastPhase', false);
        $fase->save(true);
        $this->app->enableAccessControl();
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->publica($fase);

        $this->assertCount(0, $this->jobsDeResultado());
    }

    function testPublicarComRaizSemIdDoParNaoEnfileira()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Hook Sem Par');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);

        $this->publica($fase);

        $this->assertCount(0, $this->jobsDeResultado());
    }

    /** O hook de despublicação existe no core; deliberadamente não registramos listener para ele. */
    function testDespublicarNaoEnfileiraNada()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Hook Despublica');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->clearJobQueue();
        $this->app->disableAccessControl();
        $fase->unPublishRegistrations();
        $this->app->enableAccessControl();

        $this->assertCount(0, $this->jobsDeResultado(), 'despublicar não fala com a Conecta — decisão travada');
    }
}
