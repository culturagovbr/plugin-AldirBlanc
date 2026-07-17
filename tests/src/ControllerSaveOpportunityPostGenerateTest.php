<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

/**
 * POST_saveOpportunityPostGenerate: persistência pós "usar modelo" (shortDescription + PAR)
 * e enfileiramento do job de update (PUT) via hook update:finish.
 */
class ControllerSaveOpportunityPostGenerateTest extends TestCase
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

    /**
     * Loga como $user antes de criar: o hook que gera a fase "Publicação final do resultado"
     * (OpportunityPhases\Module) não herda o owner do pai — cai no fallback de
     * EntityOwnerAgent::getOwner(), que usa App::i()->user->profile do usuário autenticado
     * no momento do save(). Sem login prévio, esse fallback é guest (sem profile) e o insert
     * da fase quebra por null em agent_id.
     */
    private function opportunity(User $user, string $name = 'Oportunidade de teste'): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->name = $name;
        $opportunity->shortDescription = $name;
        $opportunity->save(true);
        $this->app->enableAccessControl();
        return $opportunity;
    }

    /**
     * O id real do job é md5("{$slug}:{$id-interno}") — ver JobType::generateId().
     */
    private function findUpdateJob(int $opportunityId)
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    // ===== Validações de entrada =====

    function testOpportunityIdAusenteRetorna400()
    {
        $this->login($this->userDirector->createUser());
        $controller = $this->controller();
        $controller->data = ['shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('opportunityId', $payload['data']);
    }

    function testOpportunityIdInvalidoRetorna400()
    {
        $this->login($this->userDirector->createUser());
        $controller = $this->controller();
        $controller->data = ['opportunityId' => '0', 'shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('opportunityId', $payload['data']);
    }

    function testShortDescriptionAusenteRetorna400()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('shortDescription', $payload['data']);
    }

    function testShortDescriptionSoComEspacosRetorna400()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => '   '];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('shortDescription', $payload['data']);
    }

    function testOpportunityInexistenteRetorna404()
    {
        $this->login($this->userDirector->createUser());
        $controller = $this->controller();
        $controller->data = ['opportunityId' => 999999999, 'shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(404, $this->responseStatus());
        $this->assertArrayHasKey('opportunityId', $payload['data']);
    }

    function testUsuarioSemPermissaoControlRetorna403()
    {
        $owner = $this->userDirector->createUser();
        $opportunity = $this->opportunity($owner);

        $this->login($this->userDirector->createUser());
        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(403, $this->responseStatus());
    }

    // ===== Persistência de shortDescription =====

    function testGravaShortDescriptionSemCamposPar()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => ' Nova descrição ',
        ];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertSame($opportunity->id, $payload['id']);

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertSame('Nova descrição', $refreshed->shortDescription);
    }

    function testGravaCamposParApenasQuandoPresentesNoPayload()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => 'desc',
            'parExercicioId' => '2024',
            'parAcaoId' => '99',
        ];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertSame('2024', $refreshed->getMetadata('parExercicioId'));
        $this->assertSame('99', $refreshed->getMetadata('parAcaoId'));
        $this->assertNull($refreshed->getMetadata('parMetaId'));
        $this->assertNull($refreshed->getMetadata('parAtividadeId'));
    }

    function testCampoParVazioOuNuloNormalizaParaNull()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $this->app->disableAccessControl();
        $opportunity->setMetadata('parExercicioId', '2023');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => 'desc',
            'parExercicioId' => '',
            'parMetaId' => null,
        ];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertNull($refreshed->getMetadata('parExercicioId'));
        $this->assertNull($refreshed->getMetadata('parMetaId'));
    }

    function testNaoSobrescreveParQuandoNenhumCampoParVemNoRequest()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $this->app->disableAccessControl();
        $opportunity->setMetadata('parExercicioId', '2023');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => 'desc',
        ];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertSame('2023', $refreshed->getMetadata('parExercicioId'));
    }

    // ===== 500 genérico ao salvar (caso original da lista, sem achado novo) =====

    function testFalhaAoSalvarRetorna500ELoga()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->setSaveAfterPostGenerateException(new \RuntimeException('falha de banco simulada'));
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(500, $this->responseStatus());
        $this->assertTrue($payload['error'] ?? null);
    }

    // ===== Achado 1: guest é Halt/401, não um JSON de 403 =====

    function testGuestRecebeHalt401NaoJson403()
    {
        $this->logout();
        $psr7 = (new ServerRequest([], [], '/aldirblanc/saveOpportunityPostGenerate', 'POST'))
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->app->request = new Request($psr7, 'aldirblanc', 'saveOpportunityPostGenerate', []);

        $controller = $this->controller();
        $controller->data = ['opportunityId' => 1, 'shortDescription' => 'desc'];

        $this->app->response = new Response();
        try {
            $controller->callSaveOpportunityPostGenerate();
            $this->fail('Esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(401, $this->responseStatus());
    }

    // ===== Achado 2: shortDescription com tipo inválido (array) não deve persistir "Array" =====

    function testShortDescriptionComoArrayRetorna400ENaoPersisteArray()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => ['a', 'b'],
        ];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(400, $this->responseStatus());
        $this->assertArrayHasKey('shortDescription', $payload['data']);

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertNotSame('Array', $refreshed->shortDescription);
    }

    // ===== Achado 3: checkPermission lançando exceção genérica (não PermissionDenied) =====

    function testCheckPermissionComExcecaoGenericaRetorna500NaoHaltSemLog()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);
        $controller = $this->controller();
        $controller->setControlPermissionException(new \RuntimeException('falha de ACL simulada'));
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(500, $this->responseStatus());
    }

    // ===== Enfileiramento do job de update (via hook update:finish) =====

    function testPostGenerateEnfileiraJobDeUpdateQuandoOportunidadeElegivel()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Pnab PostGenerate';
        $subsite->url = 'subsite-pnab-postgen-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', '1');
        $opportunity->save(true);
        $this->app->enableAccessControl();
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $controller = $this->controller();
        $controller->data = [
            'opportunityId' => $opportunity->id,
            'shortDescription' => 'desc',
            'parExercicioId' => '10',
            'parMetaId' => '20',
            'parAcaoId' => '30',
            'parAtividadeId' => '40',
        ];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertNotNull(
            $this->findUpdateJob($opportunity->id),
            'saveOpportunityPostGenerate deve enfileirar o job de update (PUT) via hook'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

}
