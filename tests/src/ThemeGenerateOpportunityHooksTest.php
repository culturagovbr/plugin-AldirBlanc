<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Hooks do Theme.php (Pnab) acionados durante "criar oportunidade a partir de modelo":
 * validação de compatibilidade do parAcaoId (POST(opportunity.generateopportunity):before)
 * e o gate de integração CultBr (validateIntegrationJob, via insert:finish/update:finish).
 */
class ThemeGenerateOpportunityHooksTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['selectedFederativeEntity']);
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        // blockAccessOnError (Theme.php) intercepta POST(<<*>>):before pra qualquer usuário
        // logado sem sync concluído — irrelevante para o que está sendo testado aqui.
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['selectedFederativeEntity']);
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        parent::tearDown();
    }

    /**
     * blockAccessOnError (Theme.php) redireciona pra completeProfile quando o perfil individual
     * não tem os campos obrigatórios preenchidos — irrelevante para os hooks testados aqui.
     */
    private function fillRequiredProfileFields($profile): void
    {
        $keys = [
            'nomeCompleto', 'cpf', 'emailPrivado', 'telefonePublico',
            'acessouFomentoCultural', 'anosExperienciaAreaCultural', 'eMestreCulturasTradicionais',
            'En_CEP', 'En_Nome_Logradouro', 'En_Num', 'En_Bairro', 'En_Municipio', 'En_Estado',
            'dataDeNascimento', 'genero', 'orientacaoSexual', 'raca', 'renda', 'escolaridade',
            'pessoaDeficiente', 'comunidadesTradicional',
        ];
        $this->app->disableAccessControl();
        foreach ($keys as $key) {
            $profile->$key = $key === 'dataDeNascimento' ? '1990-01-01' : ($key === 'cpf' ? '52998224725' : 'valor-de-teste');
        }
        $profile->save(true);
        $this->app->enableAccessControl();
    }

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

    private function subsite(User $owner, string $name): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = $name;
        $subsite->url = strtolower(str_replace(' ', '-', $name)) . '-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function persistFederativeEntity(string $document, string $name, array $exercices = []): FederativeEntity
    {
        $this->app->disableAccessControl();
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = $exercices;
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $this->app->enableAccessControl();
        return $entity;
    }

    private function selectEntityInSession(FederativeEntity $entity): void
    {
        $_SESSION['selectedFederativeEntity'] = json_encode([
            'id' => $entity->id,
            'name' => $entity->name,
            'document' => $entity->document,
        ]);
    }

    private function findJob(int $opportunityId, string $action)
    {
        $internalId = "oportunidade-cult-{$action}:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    /** Preenche os 4 dados do PAR exigidos por validateIntegrationJob. */
    private function setPar($entity): void
    {
        $entity->setMetadata('parExercicioId', '1');
        $entity->setMetadata('parMetaId', '2');
        $entity->setMetadata('parAcaoId', '3');
        $entity->setMetadata('parAtividadeId', '4');
    }

    // ===== POST(opportunity.generateopportunity):before — validação de compatibilidade do PAR =====

    private function fireGenerateOpportunityBeforeHook(Opportunity $model, ?string $parAcaoId): void
    {
        $psr7 = new ServerRequest([], [], "/opportunity/generateopportunity/{$model->id}", 'POST');
        if ($parAcaoId !== null) {
            $psr7 = $psr7->withParsedBody(['parAcaoId' => $parAcaoId]);
        }
        $this->app->request = new Request($psr7, 'opportunity', 'generateopportunity', []);
        $_SERVER['REQUEST_URI'] = "/opportunity/generateopportunity/{$model->id}";

        $controller = $this->app->controller('opportunity');
        $controller->action = 'generateopportunity';
        $controller->setRequestData(['id' => $model->id]);

        $this->app->response = new Response();
        $this->app->applyHookBoundTo($controller, 'POST(opportunity.generateopportunity):before');
    }

    private function assertHookLibera(Opportunity $model, ?string $parAcaoId): void
    {
        try {
            $this->fireGenerateOpportunityBeforeHook($model, $parAcaoId);
        } catch (Halt) {
            $this->fail('Esperava que o hook liberasse (sem Halt), mas bloqueou.');
        }
        $this->assertSame(200, $this->app->response->getStatusCode());
    }

    private function assertHookBloqueiaCom(Opportunity $model, ?string $parAcaoId, int $expectedStatus): array
    {
        try {
            $this->fireGenerateOpportunityBeforeHook($model, $parAcaoId);
            $this->fail('Esperava Halt (bloqueio), mas o hook liberou.');
        } catch (Halt) {
        }
        $this->assertSame($expectedStatus, $this->app->response->getStatusCode());
        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    function testNaoValidaQuandoUsuarioNaoEhGestorCultBr()
    {
        $user = $this->userDirector->createUser();
        $this->fillRequiredProfileFields($user->profile);
        $model = $this->opportunity($user, 'Modelo com parActions');
        $this->app->disableAccessControl();
        $model->setMetadata('parActions', json_encode(['Ação X']));
        $model->save(true);
        $this->app->enableAccessControl();

        $this->login($user);
        // parAcaoId incompatível, mas como o usuário não é gestor CultBr, o hook nem chega a validar.
        $this->assertHookLibera($model, 'id-qualquer-incompativel');
    }

    function testPassaQuandoParAcaoIdNaoInformado()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $model = $this->opportunity($gestor, 'Modelo com parActions');
        $this->app->disableAccessControl();
        $model->setMetadata('parActions', json_encode(['Ação X']));
        $model->save(true);
        $this->app->enableAccessControl();

        $federativeEntity = $this->persistFederativeEntity('33333333333333', 'Ente Três');
        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);
        $this->assertHookLibera($model, null);
    }

    function testPassaQuandoModeloNaoTemParActions()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $model = $this->opportunity($gestor, 'Modelo sem parActions');

        $federativeEntity = $this->persistFederativeEntity('44444444444444', 'Ente Quatro');
        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);
        $this->assertHookLibera($model, '999');
    }

    function testPassaQuandoParAcaoIdCompativel()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $model = $this->opportunity($gestor, 'Modelo com parActions');
        $this->app->disableAccessControl();
        $model->setMetadata('parActions', json_encode(['Ação Compatível']));
        $model->save(true);
        $this->app->enableAccessControl();

        $federativeEntity = $this->persistFederativeEntity('11111111111111', 'Ente Um', [
            ['metas' => [
                ['acoes' => [
                    ['id' => '42', 'nome' => 'Ação Compatível'],
                ]],
            ]],
        ]);

        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);

        $this->assertHookLibera($model, '42');
    }

    function testBloqueiaQuandoParAcaoIdIncompativel()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $model = $this->opportunity($gestor, 'Modelo com parActions');
        $this->app->disableAccessControl();
        $model->setMetadata('parActions', json_encode(['Ação Compatível']));
        $model->save(true);
        $this->app->enableAccessControl();

        $federativeEntity = $this->persistFederativeEntity('22222222222222', 'Ente Dois', [
            ['metas' => [
                ['acoes' => [
                    ['id' => '42', 'nome' => 'Outra Ação Qualquer'],
                ]],
            ]],
        ]);

        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);

        $payload = $this->assertHookBloqueiaCom($model, '42', 422);
        $this->assertArrayHasKey('parAcaoId', $payload['data']);
    }

    // ===== entity(Opportunity).validationErrors — trava do PAR sem parActions =====

    function testValidationBloqueiaSalvarParSemParActions()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $opportunity = $this->opportunity($gestor, 'Oportunidade sem parActions');

        $federativeEntity = $this->persistFederativeEntity('55555555555555', 'Ente Cinco', [
            ['metas' => [['acoes' => [['id' => '3', 'nome' => 'Ação Qualquer']]]]],
        ]);
        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);

        $errors = $opportunity->validationErrors;
        $this->assertArrayHasKey('parAcaoId', $errors);
        $this->assertStringContainsString('entre em contato com o suporte', $errors['parAcaoId'][0]);
    }

    function testValidationLiberaOParComParActionsEAcaoCompativel()
    {
        $gestor = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->fillRequiredProfileFields($gestor->profile);
        $opportunity = $this->opportunity($gestor, 'Oportunidade com parActions');
        $this->app->disableAccessControl();
        $opportunity->setMetadata('parActions', json_encode(['Ação Compatível']));
        $opportunity->setMetadata('parExercicioId', '1');
        $opportunity->setMetadata('parMetaId', '2');
        $opportunity->setMetadata('parAcaoId', '42');
        $opportunity->setMetadata('parAtividadeId', '4');
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $federativeEntity = $this->persistFederativeEntity('66666666666666', 'Ente Seis', [
            ['metas' => [['acoes' => [['id' => '42', 'nome' => 'Ação Compatível']]]]],
        ]);
        $this->login($gestor);
        $this->selectEntityInSession($federativeEntity);

        $errors = $opportunity->validationErrors;
        $this->assertArrayNotHasKey('parAcaoId', $errors);
    }

    // ===== validateIntegrationJob (gate de integração CultBr), via update:finish =====

    function testDisparaUpdateQuandoTudoCorretoESubsiteCoincide()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', 1);
        $this->setPar($opportunity);
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $job = $this->findJob($opportunity->id, 'update');
        $this->assertNotNull($job);
        $this->assertSame('update', $job->action);
    }

    /**
     * Sem os 4 dados do PAR preenchidos, o hook não deve enfileirar o job de update.
     */
    function testNaoDisparaUpdateQuandoSemDadosDoPar()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab Par');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', 1);
        // PAR deliberadamente ausente
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $this->assertNull(
            $this->findJob($opportunity->id, 'update'),
            'Oportunidade sem os dados do PAR não deve enfileirar job de update'
        );
    }

    /**
     * Sem gate de status: uma oportunidade em rascunho que passa os demais guards
     * também enfileira o job de update (o envio ao CultBR ocorre em qualquer save).
     */
    function testDisparaUpdateEmRascunho()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab Rascunho');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', 1);
        $this->setPar($opportunity);
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $this->assertNotNull(
            $this->findJob($opportunity->id, 'update'),
            'Rascunho elegível também deve enfileirar o job de update'
        );
    }

    /**
     * Com apenas parte dos 4 dados do PAR (3 de 4), o hook não deve enfileirar o job —
     * a regra exige os 4.
     */
    function testNaoDisparaUpdateQuandoPARIncompleto()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab Par Parcial');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', 1);
        $opportunity->setMetadata('parExercicioId', '1');
        $opportunity->setMetadata('parMetaId', '2');
        $opportunity->setMetadata('parAcaoId', '3');
        // parAtividadeId deliberadamente ausente (3 de 4)
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $this->assertNull(
            $this->findJob($opportunity->id, 'update'),
            'PAR incompleto (3 de 4) não deve enfileirar job de update'
        );
    }

    /**
     * Oportunidade em subsite diferente do subsite do Pnab (ALDIRBLANC_SUBSITE_ID)
     * não deve disparar o job de update.
     */
    function testNaoDisparaUpdateQuandoSubsiteDaOportunidadeNaoEhOSubsitePnab()
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsiteDaOportunidade = $this->subsite($user, 'Subsite da Oportunidade');
        $subsitePnab = $this->subsite($user, 'Subsite Pnab Diferente');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsitePnab->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsiteDaOportunidade;
        $opportunity->setMetadata('federativeEntityId', 1);
        $this->setPar($opportunity);
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $this->assertNull($this->findJob($opportunity->id, 'update'));
    }

    /**
     * Oportunidade sem federativeEntityId não deve disparar job de update,
     * mesmo que todos os outros guards passem.
     */
    function testNaoDisparaUpdateQuandoFederativeEntityIdAusente(): void
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab FedId');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $this->setPar($opportunity);
        // federativeEntityId deliberadamente ausente
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $this->assertNull(
            $this->findJob($opportunity->id, 'update'),
            'Oportunidade sem federativeEntityId não deve enfileirar job de update'
        );
    }

    /**
     * Oportunidade filha (com parent) não deve disparar job de update.
     * Fases e oportunidades complementares têm parent definido.
     */
    function testNaoDisparaUpdateQuandoOportunidadeTemParent(): void
    {
        $user = $this->userDirector->createUser();
        $main = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab Parent');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $child = new $className();
        $child->parent = $main;
        $child->owner = $user->profile;
        $child->ownerEntity = $user->profile;
        $child->name = 'Fase filha';
        $child->shortDescription = 'fase';
        $child->subsite = $subsite;
        $child->setMetadata('federativeEntityId', 1);
        $this->setPar($child);
        $child->status = Opportunity::STATUS_ENABLED;
        $child->save(true);
        $this->app->enableAccessControl();

        $this->assertNull(
            $this->findJob($child->id, 'update'),
            'Oportunidade com parent não deve enfileirar job de update'
        );
    }

    /**
     * O delay de enfileiramento do job de update é configurável via ALDIRBLANC_INTEGRATION_DELAY_JOB.
     * Com "+5 minutes", o nextExecutionTimestamp deve ser posterior ao momento atual.
     */
    function testDelayDeEnfileiramentoEhConfiguravelPorVariavelDeAmbiente(): void
    {
        $user = $this->userDirector->createUser();
        $opportunity = $this->opportunity($user);
        $subsite = $this->subsite($user, 'Subsite Pnab Delay');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;
        $_ENV['ALDIRBLANC_INTEGRATION_DELAY_JOB'] = '+5 minutes';

        $this->app->disableAccessControl();
        $opportunity->subsite = $subsite;
        $opportunity->setMetadata('federativeEntityId', 1);
        $this->setPar($opportunity);
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->save(true);
        $this->app->enableAccessControl();

        $job = $this->findJob($opportunity->id, 'update');
        $this->assertNotNull($job, 'Job de update deve ser enfileirado');
        $this->assertGreaterThan(
            new \DateTime(),
            $job->nextExecutionTimestamp,
            'Com delay de +5 minutes, nextExecutionTimestamp deve ser posterior ao momento atual'
        );

        unset($_ENV['ALDIRBLANC_INTEGRATION_DELAY_JOB']);
    }
}
