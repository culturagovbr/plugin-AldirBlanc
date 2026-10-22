<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\ResultadoCultJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** POST /aldirblanc/resultadoEnviar — o botão do gestor, único caminho do primeiro envio ao CultBR. */
class ControllerResultadoEnviarTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use BuildsPublishedFinalPhase;
    use ConfiguresPlugin;

    /** Estrutura com uma questão obrigatória: o formulário nunca completa. */
    private const ESTRUTURA_INCOMPLETA = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
        ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
    ]]]]]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Edital integrado, com a fase final publicada ou não, e o gestor logado. */
    private function cenario(string $nome, bool $publicada = true, bool $comIdDoPar = true): Opportunity
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, $nome));
        $this->faseFinal($raiz, publicada: $publicada);

        if ($comIdDoPar) {
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        }

        $this->login($owner);
        $this->clearJobQueue();

        return $raiz;
    }

    private function enviar(Opportunity $raiz): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id];

        $this->app->response = new Response();

        try {
            $controller->callPostResultadoEnviar();
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testBotaoEnfileiraOPrimeiroEnvio()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Feliz');

            $payload = $this->enviar($raiz);

            $this->assertSame(200, $this->app->response->getStatusCode());
            $this->assertTrue($payload['enfileirado']);
            $this->assertCount(1, $this->jobsDeResultado(), 'o botão é o único caminho do primeiro envio');
        });
    }

    function testBotaoAcionadoDeNovoEnfileiraORetificado()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Retificado');
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado(), 'resultado já publicado é imutável: o reenvio sai como retificado');
        });
    }

    function testIdDeFaseEnviaPelaRaiz()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Por Fase');
            $faseFinal = $raiz->lastPhase;

            $controller = new TestableController();
            $controller->data = ['opportunityId' => $faseFinal->id];
            $this->app->response = new Response();
            try {
                $controller->callPostResultadoEnviar();
            } catch (Halt) {
            }

            $tipo = $this->app->getRegisteredJobType(ResultadoCultJob::SLUG);
            $jobs = $this->jobsDeResultado();

            $this->assertCount(1, $jobs, 'o gestor pode chegar pelo id de uma fase');
            $this->assertSame(
                $tipo->generateId(['opportunity' => $raiz], '', '', 1),
                $jobs[0]->id,
                'a fase daria outro id de job e montaria o corpo de outro edital'
            );
        });
    }

    function testFaseFinalNaoPublicadaRecusa()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Nao Publicada', publicada: false);

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertStringContainsString('Publique', (string) $payload['data']);
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    function testEditalSemIdDoParRecusa()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Sem Par', comIdDoPar: false);

            $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    function testFormularioIncompletoRecusa()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Incompleto');

            $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário completo o envio tomaria 422 do validador');
        }, self::ESTRUTURA_INCOMPLETA);
    }
}
