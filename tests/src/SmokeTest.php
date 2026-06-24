<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\AgentRelation;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Smoke test de validação do add-on de testes do plugin AldirBlanc
 * (config + autoload + fixture, ver tests/docker-compose.yml deste plugin).
 */
class SmokeTest extends TestCase
{
    use UserDirector;

    // ===== Asserts genéricos (sem App::i(), sem banco) =====

    function testParActionFromArrayRoundTrip()
    {
        $action = ParAction::fromArray(['nome_acao' => '1.1 Fomento Cultural']);

        $this->assertSame('1.1 Fomento Cultural', $action->label);
        $this->assertSame('1.1 Fomento Cultural', $action->value);
        $this->assertSame(
            ['value' => '1.1 Fomento Cultural', 'label' => '1.1 Fomento Cultural', 'raw' => ['nome_acao' => '1.1 Fomento Cultural']],
            $action->toArray()
        );
    }

    function testParActionFromArrayWithoutNomeAcaoIsEmptyLabel()
    {
        $action = ParAction::fromArray([]);

        $this->assertSame('', $action->label);
    }

    // ===== Teste de banco de dados =====

    function testFederativeEntityAssociationIsPersisted()
    {
        $app = $this->app;

        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $this->assertFalse(UserAccessService::isGestorCultBr());

        // Persistência direta via EntityManager (mesmo padrão usado por
        // GestorCultJob::associateFederativeEntities — evita checagens de permissão do ->save()).
        $entity = new FederativeEntity();
        $entity->name = 'Prefeitura Fictícia';
        $entity->document = '12345678901234';
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();

        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;

        $app->em->persist($entity);
        $app->em->persist($relation);
        $app->em->flush();
        $app->em->clear();

        $persistedEntity = $app->repo(FederativeEntity::class)->findOneBy(['document' => '12345678901234']);
        $this->assertNotNull($persistedEntity, 'A FederativeEntity deve estar persistida no banco de testes');
        $this->assertSame('Prefeitura Fictícia', $persistedEntity->name);

        $persistedRelation = $app->repo(FederativeEntityAgentRelation::class)->findOneBy(['owner' => $persistedEntity]);
        $this->assertNotNull($persistedRelation, 'A FederativeEntityAgentRelation deve estar persistida no banco de testes');
        $this->assertEquals($agent->id, $persistedRelation->agent->id);
    }
}
