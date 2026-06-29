<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\FederativeEntityService;
use MapasCulturais\Entities\AgentRelation;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class FederativeEntityServiceTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['selectedFederativeEntity']);
    }

    private function persistFederativeEntity(string $document, string $name, array $exercices = []): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = $exercices;
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

    /** Exercices com estrutura válida contendo duas ações distintas. */
    private function exercicesWithActions(): array
    {
        return [
            [
                'metas' => [
                    [
                        'acoes' => [
                            ['id' => 'acao-1', 'nome' => 'Fomento Cultural'],
                            ['id' => 'acao-2', 'nome' => '  Ação com espaços  '],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ===== getSelectedFederativeEntityIdFromSession =====

    function testGetSelectedIdNaoGestorRetornaNull()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $entity = $this->persistFederativeEntity('00000000000001', 'Ente Um');
        $this->selectEntityInSession($entity);

        $this->assertNull(FederativeEntityService::getSelectedFederativeEntityIdFromSession());
    }

    function testGetSelectedIdGestorSemSelecaoNaSessaoRetornaNull()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        // $SESSION não tem 'selectedFederativeEntity'

        $this->assertNull(FederativeEntityService::getSelectedFederativeEntityIdFromSession());
    }

    function testGetSelectedIdJsonInvalidoNaSessaoRetornaNull()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $_SESSION['selectedFederativeEntity'] = 'nao-e-json';

        $this->assertNull(FederativeEntityService::getSelectedFederativeEntityIdFromSession());
    }

    function testGetSelectedIdJsonSemCampoIdRetornaNull()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $_SESSION['selectedFederativeEntity'] = json_encode(['name' => 'sem id']);

        $this->assertNull(FederativeEntityService::getSelectedFederativeEntityIdFromSession());
    }

    function testGetSelectedIdJsonValidoRetornaIdComoInt()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('00000000000002', 'Ente Dois');
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getSelectedFederativeEntityIdFromSession();

        $this->assertSame($entity->id, $result);
        $this->assertIsInt($result);
    }

    // ===== getParExerciciosForFederativeEntityId =====

    function testGetParExerciciosIdInexistenteRetornaArrayVazio()
    {
        $result = FederativeEntityService::getParExerciciosForFederativeEntityId(999999999);

        $this->assertSame([], $result);
    }

    function testGetParExerciciosEnteSemExerciciosRetornaArrayVazio()
    {
        $entity = $this->persistFederativeEntity('00000000000003', 'Ente Três');

        $result = FederativeEntityService::getParExerciciosForFederativeEntityId($entity->id);

        $this->assertSame([], $result);
    }

    function testGetParExerciciosEnteComExerciciosRetornaArray()
    {
        $exercices = [['id' => 1, 'ano' => 2025, 'metas' => []]];
        $entity = $this->persistFederativeEntity('00000000000004', 'Ente Quatro', $exercices);

        $result = FederativeEntityService::getParExerciciosForFederativeEntityId($entity->id);

        $this->assertSame($exercices, $result);
    }

    // ===== getParExerciciosForSessionSelectedEntity =====

    function testGetParExercicios_SemSelecaoNaSessaoRetornaVazio()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        // sem selectedFederativeEntity na sessão

        $result = FederativeEntityService::getParExerciciosForSessionSelectedEntity();

        $this->assertSame([], $result);
    }

    function testGetParExercicios_ComSelecaoValidaRetornaExerciciosDoEnte()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $exercices = [['id' => 2, 'ano' => 2024, 'metas' => []]];
        $entity = $this->persistFederativeEntity('00000000000005', 'Ente Cinco', $exercices);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParExerciciosForSessionSelectedEntity();

        $this->assertSame($exercices, $result);
    }

    // ===== getParActionNameByAcaoId =====

    function testGetParActionNameAcaoEncontradaRetornaNoмeComTrimAplicado()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('00000000000006', 'Ente Seis', $this->exercicesWithActions());
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNameByAcaoId('acao-2');

        $this->assertSame('Ação com espaços', $result);
    }

    function testGetParActionNameAcaoNaoEncontradaRetornaNull()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('00000000000007', 'Ente Sete', $this->exercicesWithActions());
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNameByAcaoId('acao-inexistente');

        $this->assertNull($result);
    }

    function testGetParActionNameExerciceSemMetasNaoQuebra()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('00000000000008', 'Ente Oito', [['sem_metas' => true]]);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNameByAcaoId('acao-1');

        $this->assertNull($result);
    }

    function testGetParActionNameMetaSemAcoesNaoQuebra()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $exercices = [['metas' => [['sem_acoes' => true]]]];
        $entity = $this->persistFederativeEntity('00000000000009', 'Ente Nove', $exercices);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNameByAcaoId('acao-1');

        $this->assertNull($result);
    }

    // ===== getParActionNamesForSessionSelectedEntity =====

    function testGetParActionNamesSemSelecaoRetornaVazio()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $result = FederativeEntityService::getParActionNamesForSessionSelectedEntity();

        $this->assertSame([], $result);
    }

    function testGetParActionNamesDeduplica()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $exercices = [
            [
                'metas' => [
                    [
                        'acoes' => [
                            ['id' => 'a1', 'nome' => 'Fomento Cultural'],
                            ['id' => 'a2', 'nome' => 'Fomento Cultural'],
                            ['id' => 'a3', 'nome' => 'Apoio a Festivais'],
                        ],
                    ],
                ],
            ],
        ];
        $entity = $this->persistFederativeEntity('00000000000010', 'Ente Dez', $exercices);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNamesForSessionSelectedEntity();

        $this->assertCount(2, $result);
        $this->assertContains('Fomento Cultural', $result);
        $this->assertContains('Apoio a Festivais', $result);
    }

    function testGetParActionNamesOrdenaPorStrnatcasecmp()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $exercices = [
            [
                'metas' => [
                    [
                        'acoes' => [
                            ['id' => 'z1', 'nome' => 'Zebra'],
                            ['id' => 'a1', 'nome' => 'Abacaxi'],
                            ['id' => 'm1', 'nome' => 'Manga'],
                        ],
                    ],
                ],
            ],
        ];
        $entity = $this->persistFederativeEntity('00000000000011', 'Ente Onze', $exercices);
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $result = FederativeEntityService::getParActionNamesForSessionSelectedEntity();

        $this->assertSame(['Abacaxi', 'Manga', 'Zebra'], $result);
    }
}
