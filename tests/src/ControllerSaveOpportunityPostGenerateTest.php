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
 * e enfileiramento condicional do job de create na API CultBr.
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
    private function findJob(int $opportunityId, string $action = 'create')
    {
        $internalId = "oportunidade-cult-{$action}:{$opportunityId}";
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

    // ===== Persistência de shortDescription + flags =====

    function testGravaShortDescriptionEIsGeneratedFromModelSemCamposPar()
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
        $this->assertSame('1', $refreshed->getMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL));
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

    // ===== Enfileiramento do job de create =====

    function testEnfileiraJobDeCreateQuandoAindaNaoSincronizado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Pnab';
        $subsite->url = 'subsite-pnab-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $this->app->enableAccessControl();
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', '123');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $job = $this->findJob($opportunity->id);
        $this->assertNotNull($job);
        $this->assertSame('create', $job->action);

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    function testNaoReenfileiraCreateQuandoJaSincronizado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $this->app->disableAccessControl();
        $opportunity->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $job = $this->findJob($opportunity->id);
        $this->assertNull($job);
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

    // ===== Elegibilidade para job de create (guards) =====

    function testNaoEnfileiraCreateJobQuandoFederativeEntityIdAusente()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Guard Test';
        $subsite->url = 'subsite-guard-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opportunity->subsite = $subsite;
        // federativeEntityId deliberadamente não setado
        $opportunity->save(true);
        $this->app->enableAccessControl();
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];
        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertNull($this->findJob($opportunity->id), 'Não deve enfileirar sem federativeEntityId');

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    function testNaoEnfileiraCreateJobQuandoSubsiteDaOportunidadeNaoCoincideComPnab()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsiteOpp = new \MapasCulturais\Entities\Subsite();
        $subsiteOpp->name = 'Subsite Da Oportunidade';
        $subsiteOpp->url = 'subsite-opp-' . uniqid();
        $this->app->disableAccessControl();
        $subsiteOpp->save(true);
        $opportunity->subsite = $subsiteOpp;
        $opportunity->setMetadata('federativeEntityId', '1');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        // ALDIRBLANC_SUBSITE_ID aponta para subsite diferente
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) ($subsiteOpp->id + 9999);

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];
        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertNull(
            $this->findJob($opportunity->id),
            'Não deve enfileirar quando subsite da oportunidade não coincide com ALDIRBLANC_SUBSITE_ID'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    function testNaoEnfileiraCreateJobQuandoALDIRBLANCSubsiteIdNaoConfigurado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Sem Env';
        $subsite->url = 'subsite-sem-env-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', '1');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        // ALDIRBLANC_SUBSITE_ID deliberadamente não configurado
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];
        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertNull(
            $this->findJob($opportunity->id),
            'Não deve enfileirar quando ALDIRBLANC_SUBSITE_ID não está configurado'
        );
    }

    function testNaoEnfileiraCreateJobQuandoOportunidadeTemParent()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $main = $this->opportunity($user, 'Oportunidade principal');

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Parent Guard';
        $subsite->url = 'subsite-parent-guard-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);

        $opportunityClassName = $user->profile->opportunityClassName;
        $child = new $opportunityClassName();
        $child->parent = $main;
        $child->owner = $user->profile;
        $child->ownerEntity = $user->profile;
        $child->name = 'Fase filha';
        $child->shortDescription = 'fase';
        $child->status = Opportunity::STATUS_DRAFT;
        $child->subsite = $subsite;
        $child->setMetadata('federativeEntityId', '1');
        $child->save(true);
        $this->app->enableAccessControl();

        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $child->id, 'shortDescription' => 'desc'];
        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertNull(
            $this->findJob($child->id),
            'Oportunidade com parent não deve enfileirar job de create'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    function testNaoEnfileiraCreateJobQuandoStatusEhStatusPhase()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Phase Guard';
        $subsite->url = 'subsite-phase-guard-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', '1');
        $opportunity->status = Opportunity::STATUS_PHASE;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $controller = $this->controller();
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];
        $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertNull(
            $this->findJob($opportunity->id),
            'Oportunidade com status STATUS_PHASE não deve enfileirar job de create'
        );

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
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

    // ===== Achado 4: falha ao enfileirar não deve impedir a resposta de sucesso =====

    function testFalhaAoEnfileirarJobNaoImpedeRespostaDeSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $opportunity = $this->opportunity($user);

        // eligibilidade mínima para que enqueueOportunidadeCreateJob seja chamado
        $subsite = new \MapasCulturais\Entities\Subsite();
        $subsite->name = 'Subsite Pnab Falha';
        $subsite->url = 'subsite-pnab-falha-' . uniqid();
        $this->app->disableAccessControl();
        $subsite->save(true);
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', '1');
        $opportunity->save(true);
        $this->app->enableAccessControl();
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $controller = $this->controller();
        $controller->setEnqueueCreateJobException(new \RuntimeException('fila indisponível'));
        $controller->data = ['opportunityId' => $opportunity->id, 'shortDescription' => 'desc'];

        $payload = $this->callJson(fn() => $controller->callSaveOpportunityPostGenerate());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);

        $refreshed = $this->app->repo('Opportunity')->find($opportunity->id);
        $this->assertSame('desc', $refreshed->shortDescription);

        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }
}
