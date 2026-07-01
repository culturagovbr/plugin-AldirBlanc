<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Plugin;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

class ControllerIntegrationOpportunitiesTest extends TestCase
{
    use UserDirector;

    private ?int $originalSubsiteId = null;

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
        $this->login($user);
        $this->app->disableAccessControl();
        $class = $user->profile->opportunityClassName;
        $opp = new $class();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->name = 'Oportunidade Integração Test';
        $opp->shortDescription = 'desc';
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function setPluginSubsiteId(int $id): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);
        $config = $ref->getValue($plugin);
        $this->originalSubsiteId = $config['integration']['subsiteId'];
        $config['integration']['subsiteId'] = $id;
        $ref->setValue($plugin, $config);
    }

    private function restorePluginSubsiteId(): void
    {
        if ($this->originalSubsiteId === null) {
            return;
        }
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);
        $config = $ref->getValue($plugin);
        $config['integration']['subsiteId'] = $this->originalSubsiteId;
        $ref->setValue($plugin, $config);
        $this->originalSubsiteId = null;
    }

    function testIdAusenteRetorna400()
    {
        $controller = $this->controller();
        $controller->data = [];

        $payload = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());

        $this->assertSame(400, $this->responseStatus());
        $this->assertTrue($payload['error']);
    }

    function testOportunidadeInexistenteRetorna404()
    {
        $controller = $this->controller();
        $controller->data = ['id' => 999999999];

        $payload = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());

        $this->assertSame(404, $this->responseStatus());
        $this->assertTrue($payload['error']);
    }

    function testSubsiteErradoRetorna404()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Subsite Errado Test';
        $subsite->url = 'subsite-errado-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        // configura subsiteId para valor diferente do subsite da oportunidade
        $this->setPluginSubsiteId($subsite->id + 9999);

        $controller = $this->controller();
        $controller->data = ['id' => $opp->id];
        $payload = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());

        $this->assertSame(404, $this->responseStatus());
        $this->assertTrue($payload['error']);

        $this->restorePluginSubsiteId();
    }

    function testSemFederativeEntityIdRetorna404()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite FedId Test';
        $subsite->url = 'subsite-fedid-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opp->subsite = $subsite;
        // federativeEntityId deliberadamente não setado
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->setPluginSubsiteId($subsite->id);

        $controller = $this->controller();
        $controller->data = ['id' => $opp->id];
        $payload = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());

        $this->assertSame(404, $this->responseStatus());
        $this->assertTrue($payload['error']);

        $this->restorePluginSubsiteId();
    }

    function testSucessoRetornaPayloadComIdCorreto()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Sucesso Test';
        $subsite->url = 'subsite-sucesso-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->setPluginSubsiteId($subsite->id);

        $controller = $this->controller();
        $controller->data = ['id' => $opp->id];
        $payload = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('data', $payload);
        $this->assertSame($opp->id, $payload['data']['id']);

        $this->restorePluginSubsiteId();
    }

    function testSegundaChamadaRetornaResultadoCacheado()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Cache Test';
        $subsite->url = 'subsite-cache-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opp->subsite = $subsite;
        $opp->setMetadata('federativeEntityId', '1');
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->setPluginSubsiteId($subsite->id);

        $controller = $this->controller();
        $controller->data = ['id' => $opp->id];
        $payload1 = $this->callJson(fn() => $controller->callGetIntegrationOpportunities());
        $this->assertTrue($payload1['success']);

        // altera o nome diretamente no banco para provar que a segunda chamada não recalcula
        $this->app->em->getConnection()->executeStatement(
            'UPDATE opportunity SET name = :name WHERE id = :id',
            ['name' => 'Nome Alterado No Banco', 'id' => $opp->id]
        );

        $controller2 = $this->controller();
        $controller2->data = ['id' => $opp->id];
        $payload2 = $this->callJson(fn() => $controller2->callGetIntegrationOpportunities());

        $this->assertSame($payload1['data'], $payload2['data']);

        $this->restorePluginSubsiteId();
    }
}
