<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Tier A7: GET_completeProfile, POST_completeProfile e a ordem de gates em blockAccessOnError.
 * [DB]
 */
class ControllerCompleteProfileTest extends TestCase
{
    use UserDirector;

    private const REQUIRED_KEYS = [
        'nomeCompleto', 'cpf', 'emailPrivado', 'telefonePublico',
        'acessouFomentoCultural', 'anosExperienciaAreaCultural', 'eMestreCulturasTradicionais',
        'En_CEP', 'En_Nome_Logradouro', 'En_Num', 'En_Bairro', 'En_Municipio', 'En_Estado',
        'dataDeNascimento', 'genero', 'orientacaoSexual', 'raca', 'renda', 'escolaridade',
        'pessoaDeficiente', 'comunidadesTradicional',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);
    }

    private function controller(): Controller
    {
        return new Controller();
    }

    private function callRedirect(callable $callback): Response
    {
        $this->app->response = new Response();
        try {
            $callback();
            $this->fail('Esperava redirect via Halt');
        } catch (Halt) {
        }
        return $this->app->response;
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

    private function createUserWithoutProfile(): User
    {
        $this->app->disableAccessControl();
        $user = new User();
        $user->setAuthProvider('test');
        $user->authUid = uniqid('test-no-profile-');
        $user->email = uniqid('no-profile-') . '@example.com';
        $user->save(true);
        $this->app->enableAccessControl();
        return $user;
    }

    private static function validValueFor(string $key): string
    {
        if ($key === 'dataDeNascimento') {
            return '1990-01-01';
        }
        if ($key === 'cpf') {
            return '52998224725';
        }
        return 'valor-de-teste';
    }

    private static function validBody(): array
    {
        $body = [];
        foreach (self::REQUIRED_KEYS as $key) {
            $body[$key] = self::validValueFor($key);
        }
        return $body;
    }

    private function fillAllRequiredFields($profile): void
    {
        $this->app->disableAccessControl();
        foreach (self::REQUIRED_KEYS as $key) {
            $profile->$key = self::validValueFor($key);
        }
        $profile->save(true);
        $this->app->enableAccessControl();
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        return $entity;
    }

    private function persistRelation($agent, FederativeEntity $entity): void
    {
        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
    }

    private function selectEntityInSession(FederativeEntity $entity): void
    {
        $_SESSION['selectedFederativeEntity'] = json_encode([
            'id' => $entity->id,
            'name' => $entity->name,
            'document' => $entity->document,
        ]);
    }

    // ===== GET_completeProfile =====

    function testGetCompleteProfileGuestRedirecionaParaLogin()
    {
        $this->logout();
        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('login', $response->getHeaderLine('Location'));
    }

    function testGetCompleteProfileSemProfileRedirecionaParaPainel()
    {
        $user = $this->createUserWithoutProfile();
        $this->login($user);

        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('painel', $response->getHeaderLine('Location'));
    }

    function testGetCompleteProfilePerfilCompletoNaoGestorRedirecionaParaPainel()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('painel', $response->getHeaderLine('Location'));
    }

    function testGetCompleteProfilePerfilCompletoGestorSemEnteRedirecionaParaSelecao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('selectFederativeEntity', $response->getHeaderLine('Location'));
    }

    function testGetCompleteProfilePerfilCompletoGestorComEnteRedirecionaParaPainel()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);
        $entity = $this->persistFederativeEntity('11111111111111', 'Ente Um');
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('painel', $response->getHeaderLine('Location'));
    }

    function testGetCompleteProfilePerfilIncompletoNaoRedireciona()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->response = new Response();

        try {
            $this->controller()->GET_completeProfile();
        } catch (Halt $e) {
            $this->fail('não deveria redirecionar (Halt) com perfil incompleto');
        } catch (\Throwable $e) {
            // Ambiente de teste não monta o pipeline completo de assets/templates do tema —
            // o que importa é confirmar que não houve redirect antes do render().
        }

        $this->assertSame('', $this->app->response->getHeaderLine('Location'));
    }

    function testGetCompleteProfilePerfilIncompletoCalculaRedirectUriCorretoParaGestorSemEnte()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $this->app->response = new Response();

        try {
            $this->controller()->GET_completeProfile();
        } catch (\Throwable $e) {
        }

        $this->assertStringContainsString('selectFederativeEntity', $this->app->view->jsObject['completeProfile']['redirectUri']);
    }

    function testGetCompleteProfilePerfilIncompletoCalculaRedirectUriCorretoParaUsuarioComum()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->response = new Response();

        try {
            $this->controller()->GET_completeProfile();
        } catch (\Throwable $e) {
        }

        $this->assertStringContainsString('painel', $this->app->view->jsObject['completeProfile']['redirectUri']);
    }

    function testGetCompleteProfileTipoNaoIndividualSempreTratadoComoCompleto()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $user->profile->type = 2; // Coletivo
        $user->profile->save(true);
        $this->app->enableAccessControl();
        // metadados individuais ficam vazios de propósito

        $response = $this->callRedirect(fn() => $this->controller()->GET_completeProfile());
        $this->assertStringContainsString('painel', $response->getHeaderLine('Location'));
    }

    // Não há teste para "tipo null tratado como individual": confirmado por reprodução direta que
    // a coluna `agent.type` tem constraint NOT NULL no banco — um Agent persistido nunca tem
    // type=null de fato, então esse branch do código (`$typeId !== null && ...`) é defensivo/morto
    // pra qualquer entidade carregada do banco (que é sempre o caso de $app->user->profile aqui).

    // ===== POST_completeProfile =====

    function testPostCompleteProfileGuestRetorna403()
    {
        $this->logout();
        $payload = $this->callJson(fn() => $this->controller()->POST_completeProfile());
        $this->assertTrue($payload['error']);
    }

    function testPostCompleteProfileSemProfileRetorna400()
    {
        $user = $this->createUserWithoutProfile();
        $this->login($user);

        $this->app->response = new Response();
        try {
            $this->controller()->POST_completeProfile();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }
        $this->assertSame(400, $this->app->response->getStatusCode());
    }

    function testPostCompleteProfileCorpoVazioComPerfilJaCompletoRetornaSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $controller = $this->controller();
        $controller->data = [];
        $payload = $this->callJson(fn() => $controller->POST_completeProfile());

        $this->assertTrue($payload['success']);
        $this->assertStringContainsString('painel', $payload['redirectUri']);
    }

    function testPostCompleteProfileCorpoVazioComPerfilAindaIncompletoNaoDeclaraSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        // perfil permanece incompleto: corpo vazio não preenche nada

        $controller = $this->controller();
        $controller->data = [];
        $payload = $this->callJson(fn() => $controller->POST_completeProfile());

        $this->assertFalse($payload['success'], 'Não deveria declarar sucesso sem o perfil estar de fato completo');
        $this->assertStringContainsString('completeProfile', $payload['redirectUri']);
    }

    function testPostCompleteProfileCorpoParcialInsuficienteNaoDeclaraSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        // perfil começa totalmente vazio; manda só 10 das 21 chaves obrigatórias

        $parcial = array_slice(self::validBody(), 0, 10);

        $controller = $this->controller();
        $controller->data = $parcial;
        $payload = $this->callJson(fn() => $controller->POST_completeProfile());

        $this->assertFalse($payload['success'], 'Corpo parcial não deveria completar o perfil');
        $this->assertStringContainsString('completeProfile', $payload['redirectUri']);

        $this->app->em->clear();
        $reloaded = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($user->profile->id);
        foreach (array_keys($parcial) as $key) {
            $this->assertNotNull($reloaded->getMetadata($key), "Chave '{$key}' enviada deveria ter sido salva mesmo com o perfil ainda incompleto");
        }
    }

    function testPostCompleteProfileValorMalFormatadoRetornaErroSeguroSemQuebrar()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $controller = $this->controller();
        $controller->data = ['dataDeNascimento' => 'data-invalida-xyz'];

        $this->app->response = new Response();
        try {
            $controller->POST_completeProfile();
            $this->fail('esperava Halt');
        } catch (Halt) {
        }

        $this->assertSame(400, $this->app->response->getStatusCode());
        $body = json_decode((string) $this->app->response->getBody(), true);
        $this->assertTrue($body['error']);
    }

    function testPostCompleteProfileGravaSoChavesPresentesNoCorpo()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);
        $this->app->disableAccessControl();
        $user->profile->nomeCompleto = 'Nome Antigo';
        $user->profile->save(true);
        $this->app->enableAccessControl();

        $controller = $this->controller();
        $controller->data = ['nomeCompleto' => 'Nome Novo'];
        $this->callJson(fn() => $controller->POST_completeProfile());

        $this->app->em->clear();
        $reloaded = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($user->profile->id);
        $this->assertSame('Nome Novo', $reloaded->getMetadata('nomeCompleto'));
        $this->assertSame('valor-de-teste', $reloaded->getMetadata('emailPrivado'), 'Chave não enviada no corpo deve permanecer com o valor anterior');
    }

    function testPostCompleteProfileIgnoraChaveForaDaListaPermitida()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $controller = $this->controller();
        $controller->data = ['isAdmin' => '1', 'someRandomField' => 'hack'];
        $this->callJson(fn() => $controller->POST_completeProfile());

        $this->app->em->clear();
        $reloaded = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($user->profile->id);
        $this->assertNull($reloaded->getMetadata('someRandomField'));
    }

    function testPostCompleteProfileValorVazioLimpaCampoEArrayVazioNormalizaParaNull()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->fillAllRequiredFields($user->profile);

        $controller = $this->controller();
        $controller->data = ['nomeCompleto' => '', 'pessoaDeficiente' => []];
        $this->callJson(fn() => $controller->POST_completeProfile());

        $this->app->em->clear();
        $reloaded = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($user->profile->id);
        // A camada de metadado normaliza '' para null na persistência — equivalente pra
        // hasRequiredAgentFieldsFilled, que trata null e '' do mesmo jeito (campo vazio).
        $this->assertNull($reloaded->getMetadata('nomeCompleto'));

        // 'pessoaDeficiente' é multiselect: o setter da metadata persiste null/[] como a STRING
        // '[]' (não null nem array PHP vazio). hasRequiredAgentFieldsFilled reconhece essa string
        // como vazia (corrigido) — o campo deve manter o perfil como incompleto.
        $this->assertSame('[]', $reloaded->getMetadata('pessoaDeficiente'));
        $this->assertFalse($this->app->view->hasRequiredAgentFieldsFilled($reloaded), 'Multiselect vazio deveria manter o perfil como incompleto');
    }

    function testPostCompleteProfileGestorSemEnteAposCompletarRedirecionaParaSelecao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $controller = $this->controller();
        $controller->data = self::validBody();
        $payload = $this->callJson(fn() => $controller->POST_completeProfile());

        $this->assertTrue($payload['success']);
        $this->assertStringContainsString('selectFederativeEntity', $payload['redirectUri']);
    }

    function testPostCompleteProfileChamadoDuasVezesEhIdempotente()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $body = self::validBody();

        $controller1 = $this->controller();
        $controller1->data = $body;
        $payload1 = $this->callJson(fn() => $controller1->POST_completeProfile());

        $controller2 = $this->controller();
        $controller2->data = $body;
        $payload2 = $this->callJson(fn() => $controller2->POST_completeProfile());

        $this->assertSame($payload1, $payload2);
    }
}
