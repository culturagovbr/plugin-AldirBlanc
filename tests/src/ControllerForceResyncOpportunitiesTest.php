<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use AldirBlanc\Jobs\OpportunityForceResyncJob;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Job;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * POST /aldirblanc/forceResyncOpportunities — disparo do reenvio em lote pela tela de
 * sincronização, restrita a saasSuperAdmin.
 */
class ControllerForceResyncOpportunitiesTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use RequestFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** O 401 do visitante é respondido pelo core, e não escreve corpo nesta resposta. */
    private function callPost(mixed $opportunityIds): void
    {
        $controller = new TestableController();
        $controller->data = $opportunityIds === null ? [] : ['opportunityIds' => $opportunityIds];

        $this->app->response = new Response();

        try {
            $controller->callForceResyncOpportunities();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }
    }

    private function post(mixed $opportunityIds): array
    {
        $this->callPost($opportunityIds);

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    /** @return Job[] */
    private function resyncJobs(): array
    {
        return $this->app->repo('Job')->findBy(['type' => OpportunityForceResyncJob::SLUG]);
    }

    function testVisitanteNaoDisparaReenvio()
    {
        $this->app->auth->authenticatedUser = null;
        // requireAuthentication monta a URL de retorno a partir da requisição em curso.
        $this->app->request = $this->requestFactory->mapasPOST('aldirblanc', 'forceResyncOpportunities');

        $this->callPost([1, 2]);

        $this->assertSame(401, $this->responseStatus(), 'Visitante não pode disparar reenvio');
        $this->assertCount(0, $this->resyncJobs());
    }

    function testUsuarioSemSaasSuperAdminRecebe403()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $this->post([1, 2]);

        $this->assertSame(403, $this->responseStatus(), 'admin do subsite não tem acesso à tela de sincronização');
        $this->assertCount(0, $this->resyncJobs());
    }

    function testSelecaoVaziaRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $resposta = $this->post([]);

        $this->assertSame(400, $this->responseStatus());
        $this->assertStringContainsString('ao menos uma', $resposta['data'], 'Lista vazia é seleção faltando, não entrada malformada');
        $this->assertCount(0, $this->resyncJobs());
    }

    function testSelecaoAusenteRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $resposta = $this->post(null);

        $this->assertSame(400, $this->responseStatus());
        $this->assertStringContainsString('inválida', $resposta['data'], 'Sem o campo, a entrada é malformada');
        $this->assertCount(0, $this->resyncJobs());
    }

    function testIdNaoNumericoRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $this->post([1, 'abc']);

        $this->assertSame(400, $this->responseStatus());
        $this->assertCount(0, $this->resyncJobs());
    }

    function testIdComSufixoNaoNumericoRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $this->post([1, '12abc']);

        $this->assertSame(400, $this->responseStatus(), 'Um id truncado silenciosamente reenviaria a oportunidade errada');
        $this->assertCount(0, $this->resyncJobs());
    }

    function testIdZeroRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $this->post([1, 0]);

        $this->assertSame(400, $this->responseStatus());
        $this->assertCount(0, $this->resyncJobs());
    }

    function testIdNegativoRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $this->post([1, -3]);

        $this->assertSame(400, $this->responseStatus());
        $this->assertCount(0, $this->resyncJobs());
    }

    function testSelecaoAcimaDoTetoRecebe400ENaoEnfileira()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $this->post(range(1, 501));

        $this->assertSame(400, $this->responseStatus());
        $this->assertCount(0, $this->resyncJobs());
    }

    function testSelecaoNoTetoEhAceita()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $resposta = $this->post(range(1, 500));

        $this->assertSame(500, $resposta['accepted']);
        $this->assertCount(1, $this->resyncJobs());
    }

    function testSelecaoValidaEnfileiraOJobEmLoteComOsIdsRecebidos()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $resposta = $this->post([7, 9, 7]);

        $this->assertSame(2, $resposta['accepted'], 'O id repetido conta uma vez só');

        $jobs = $this->resyncJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame([7, 9], $jobs[0]->opportunityIds);
    }
}
