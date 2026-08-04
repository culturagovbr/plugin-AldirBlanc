<?php

namespace Tests\AldirBlanc;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
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

}
