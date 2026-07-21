<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use AldirBlanc\Services\CultBrRequestLogService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

/**
 * GET /aldirblanc/opportunityCultLogs — histórico de envios ao CultBR exibido na aba
 * "Logs CultBr". A rota é restrita a admin: o payload expõe dados internos da integração,
 * e esconder só a aba no tema não protegeria nada.
 */
class ControllerOpportunityCultLogsTest extends TestCase
{
    use UserDirector;

    private function controller(): TestableController
    {
        return new TestableController();
    }

    private function callJson(callable $callback): array
    {
        $this->app->response = new Response();

        try {
            $callback();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function opportunity(User $user): Opportunity
    {
        $this->app->disableAccessControl();
        $class = $user->profile->opportunityClassName;
        $opp = new $class();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->name = 'Oportunidade Logs CultBR Test';
        $opp->shortDescription = 'desc';
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    function testUsuarioSemPermissaoRecebe403()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $opp = $this->opportunity($user);

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opp->id];

        $this->callJson(fn() => $controller->callGetOpportunityCultLogs());

        $this->assertEquals(403, $this->responseStatus());
    }

    function testAdminSemOpportunityIdRecebe400()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $controller = $this->controller();
        $controller->data = [];

        $this->callJson(fn() => $controller->callGetOpportunityCultLogs());

        $this->assertEquals(400, $this->responseStatus());
    }

    function testAdminComOportunidadeInexistenteRecebe404()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $controller = $this->controller();
        $controller->data = ['opportunityId' => 999999];

        $this->callJson(fn() => $controller->callGetOpportunityCultLogs());

        $this->assertEquals(404, $this->responseStatus());
    }

    function testAdminRecebeEnviosComTentativasAninhadas()
    {
        $admin = $this->userDirector->createUser([Role::ADMIN]);
        $this->login($admin);
        $opp = $this->opportunity($admin);

        $service = new CultBrRequestLogService();
        $log = $service->startOrResume((int) $opp->id, 'update');
        $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'endpoint' => 'https://cultbr.invalid/oportunidade/1/update',
            'method' => 'PUT',
            'payload' => ['nome' => 'Edital'],
            'httpStatus' => 200,
            'status' => 'success',
        ]);

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opp->id];

        $payload = $this->callJson(fn() => $controller->callGetOpportunityCultLogs());

        $this->assertEquals(1, $payload['total']);
        $this->assertCount(1, $payload['data']);
        $this->assertEquals($log->requestUuid, $payload['data'][0]['requestUuid']);
        $this->assertCount(1, $payload['data'][0]['attempts']);
        $this->assertEquals(['nome' => 'Edital'], $payload['data'][0]['attempts'][0]['payload']);
    }
}
