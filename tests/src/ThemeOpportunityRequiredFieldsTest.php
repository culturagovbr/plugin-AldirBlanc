<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Hook entity(Opportunity).validationErrors do Theme.php (Pnab): "Total de vagas" e
 * "Valor total" são obrigatórios no save do edital, para que a oportunidade não chegue
 * ao CultBR sem quantidade nem valor. A exigência vale só na oportunidade raiz e segue
 * as mesmas isenções dos demais campos obrigatórios do tema.
 */
class ThemeOpportunityRequiredFieldsTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        // blockAccessOnError (Theme.php) intercepta POST(<<*>>):before pra qualquer usuário
        // logado sem sync concluído — irrelevante para o que está sendo testado aqui.
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        parent::tearDown();
    }

    private function newOpportunity(User $user, string $name): Opportunity
    {
        $opportunityClassName = $user->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->name = $name;
        $opportunity->shortDescription = $name;
        return $opportunity;
    }

    private function opportunity(User $user, array $metadata = []): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunity = $this->newOpportunity($user, 'Edital de teste');
        foreach ($metadata as $key => $value) {
            $opportunity->setMetadata($key, $value);
        }
        $opportunity->save(true);
        $this->app->enableAccessControl();
        return $opportunity;
    }

    /** Executa o PATCH da oportunidade como a tela faz e devolve os erros retornados. */
    private function patch(Opportunity $opportunity, array $payload, bool $expectErrors = true): array
    {
        $url = "/opportunity/single/{$opportunity->id}";
        $psr7 = (new ServerRequest([], [], $url, 'PATCH'))->withParsedBody($payload);
        $this->app->request = new Request($psr7, 'opportunity', 'single', ['id' => $opportunity->id]);
        $_SERVER['REQUEST_URI'] = $url;
        $this->app->response = new Response();

        $controller = $this->app->controller('opportunity');
        $controller->action = 'single';
        $controller->setRequestData(['id' => $opportunity->id]);
        $controller->postData = $payload;

        try {
            $this->app->applyHookBoundTo($controller, 'PATCH(opportunity.single):before');
            $controller->PATCH_single();
            if ($expectErrors) {
                $this->fail('Esperava que o PATCH bloqueasse, mas ele passou.');
            }
        } catch (Halt) {
        }

        $body = json_decode((string) $this->app->response->getBody(), true) ?? [];
        return $body['data'] ?? [];
    }

    private function assertCamposExigidos(Opportunity $opportunity): void
    {
        $errors = $opportunity->validationErrors;
        $this->assertArrayHasKey('vacancies', $errors);
        $this->assertArrayHasKey('totalResource', $errors);
    }

    private function assertCamposLiberados(Opportunity $opportunity): void
    {
        $errors = $opportunity->validationErrors;
        $this->assertArrayNotHasKey('vacancies', $errors);
        $this->assertArrayNotHasKey('totalResource', $errors);
    }

    function testBloqueiaSalvarSemVagasESemValor()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);

        $this->assertCamposExigidos($opportunity);
    }

    /** Zero não é preenchimento válido: um edital sem vagas nem recurso não deve ser sincronizado. */
    function testBloqueiaSalvarComVagasEValorZerados()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user, ['vacancies' => 0, 'totalResource' => 0]);

        $this->assertCamposExigidos($opportunity);
    }

    function testLiberaSalvarComVagasEValorPreenchidos()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user, ['vacancies' => 10, 'totalResource' => 50000.75]);

        $this->assertCamposLiberados($opportunity);
    }

    /** O metadado é do tipo float, sem desserializador: o valor chega como string do banco. */
    function testLiberaSalvarComValorEmPontoDecimal()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user, ['vacancies' => 10, 'totalResource' => '105886.34']);

        $this->assertCamposLiberados($opportunity);
    }

    /** Salvar sem tocar nos campos: nenhum vai no payload, e os dois erros voltam mesmo assim. */
    function testPatchSemOsCamposNoPayloadRetornaOsDoisErros()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);

        $errors = $this->patch($opportunity, []);

        $this->assertArrayHasKey('vacancies', $errors);
        $this->assertArrayHasKey('totalResource', $errors);
    }

    /**
     * Com "Total de vagas" no payload, a validação de cotas interrompe o PATCH antes da
     * validação da entidade. O erro do "Valor total" tem que vir junto, senão a tela só
     * mostra o erro de cotas — que não tem campo próprio — e nada indica o campo em branco.
     */
    function testPatchComVagasNoPayloadAindaRetornaOErroDoValorTotal()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);

        $errors = $this->patch($opportunity, ['vacancies' => 9]);

        $this->assertArrayHasKey('totalResource', $errors);
        $this->assertArrayNotHasKey('vacancies', $errors);
    }

    /**
     * O bloqueio de cotas roda antes da validação da entidade e não conhece as isenções:
     * os campos obrigatórios não podem entrar de carona na resposta para quem é isento.
     */
    function testPatchNaoAcrescentaOsCamposParaSaasSuperAdmin()
    {
        $user = $this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]);
        $opportunity = $this->opportunity($user);

        $errors = $this->patch($opportunity, ['vacancies' => 9], expectErrors: false);

        $this->assertArrayNotHasKey('vacancies', $errors);
        $this->assertArrayNotHasKey('totalResource', $errors);
    }

    /** Fases filhas só respondem pelos campos próprios da fase; os do edital ficam na raiz. */
    function testNaoExigeEmFaseFilha()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);

        $this->app->disableAccessControl();
        $phase = $this->newOpportunity($user, 'Fase de coleta');
        $phase->parent = $opportunity;
        $phase->save(true);
        $this->app->enableAccessControl();

        $this->assertCamposLiberados($phase);
    }

    /** Mesma isenção dos demais campos obrigatórios do tema (@see UserAccessService::isSaasSuperAdmin). */
    function testNaoExigeDeSaasSuperAdmin()
    {
        $user = $this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]);
        $opportunity = $this->opportunity($user);

        $this->assertCamposLiberados($opportunity);
    }

    /** Na criação a fase 1 ainda não existe na tela, e o edital é salvo sem esses campos. */
    function testNaoExigeNaCriacao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->assertCamposLiberados($this->newOpportunity($user, 'Edital novo'));
    }
}
