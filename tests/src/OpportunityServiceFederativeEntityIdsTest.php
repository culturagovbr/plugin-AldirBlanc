<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Services\OpportunityService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * OpportunityService::findFederativeEntityIdsWithOpportunities — quais entes o gestor já usou.
 *
 * É o dado que decide se um ente sem dados do PAR continua listado na tela de seleção.
 */
class OpportunityServiceFederativeEntityIdsTest extends TestCase
{
    use UserDirector;

    private OpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpportunityService();
    }

    private function subsite(User $owner): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Pnab ' . uniqid();
        $subsite->url = 'subsite-pnab-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function federativeEntity(string $document, string $name): FederativeEntity
    {
        $this->app->disableAccessControl();
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $this->app->enableAccessControl();
        return $entity;
    }

    private function opportunity(
        User $user,
        ?Subsite $subsite,
        ?FederativeEntity $entity,
        int $status = Opportunity::STATUS_ENABLED,
        ?Opportunity $parent = null,
    ): Opportunity {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;
        $opportunity->name = 'Oportunidade ' . uniqid();
        $opportunity->shortDescription = 'Oportunidade de teste';
        $opportunity->subsite = $subsite;
        $opportunity->status = $status;
        if ($parent) {
            $opportunity->parent = $parent;
        }
        $opportunity->save(true);
        if ($entity) {
            $opportunity->setMetadata('federativeEntityId', (string) $entity->id);
            $opportunity->save(true);
        }
        $this->app->enableAccessControl();
        return $opportunity;
    }

    private function idsFor(User $user, Subsite $subsite): array
    {
        return $this->service->findFederativeEntityIdsWithOpportunities($user->profile, $subsite->id);
    }

    function testSemOportunidadeRetornaVazio()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeRaizContaOEnte()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('11111111111111', 'Ente Um');
        $this->opportunity($user, $subsite, $entity);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }

    function testEntesRepetidosAparecemUmaVezSo()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('88888888888888', 'Ente Oito');
        $this->opportunity($user, $subsite, $entity);
        $this->opportunity($user, $subsite, $entity);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }

    function testFaseContaOEnte()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('22222222222222', 'Ente Dois');
        $raiz = $this->opportunity($user, $subsite, null);
        $this->opportunity($user, $subsite, $entity, Opportunity::STATUS_PHASE, $raiz);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }

    function testRascunhoContaOEnte()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('33333333333333', 'Ente Três');
        $this->opportunity($user, $subsite, $entity, Opportunity::STATUS_DRAFT);

        $this->assertSame([$entity->id], $this->idsFor($user, $subsite));
    }

    function testOportunidadeNaLixeiraNaoConta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('55555555555555', 'Ente Cinco');
        $this->opportunity($user, $subsite, $entity, Opportunity::STATUS_TRASH);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeArquivadaNaoConta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('66666666666666', 'Ente Seis');
        $this->opportunity($user, $subsite, $entity, Opportunity::STATUS_ARCHIVED);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeDeOutroSubsiteNaoConta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $outroSubsite = $this->subsite($user);
        $entity = $this->federativeEntity('44444444444444', 'Ente Quatro');
        $this->opportunity($user, $outroSubsite, $entity);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeSemSubsiteNaoContaParaSubsiteValido()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12121212121212', 'Ente Doze');
        $this->opportunity($user, null, $entity);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testSubsiteInvalidoRetornaVazio()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('99999999999999', 'Ente Nove');
        $this->opportunity($user, $subsite, $entity);

        $this->assertSame([], $this->service->findFederativeEntityIdsWithOpportunities($user->profile, 0));
    }

    function testSubsiteInvalidoNaoAlcancaOportunidadeSemSubsite()
    {
        $user = $this->userDirector->createUser();
        $entity = $this->federativeEntity('10101010101010', 'Ente Dez');
        $this->opportunity($user, null, $entity);

        $this->assertSame([], $this->service->findFederativeEntityIdsWithOpportunities($user->profile, 0));
    }

    function testOportunidadeDeOutroUsuarioNaoConta()
    {
        $user = $this->userDirector->createUser();
        $outroUser = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('77777777777777', 'Ente Sete');
        $this->opportunity($outroUser, $subsite, $entity);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }

    function testOportunidadeSemOMetadadoNaoConta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $this->opportunity($user, $subsite, null);

        $this->assertSame([], $this->idsFor($user, $subsite));
    }
}
