<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\AgentRelation;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;
use Tests\Traits\UserDirector;

class GestorCultJobAssociationTest extends TestCase
{
    use UserDirector;

    private function job(): TestableGestorCultJob
    {
        return new TestableGestorCultJob(new GestorDocument('12345678901'));
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

    private function persistRelation($agent, FederativeEntity $entity): FederativeEntityAgentRelation
    {
        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
        return $relation;
    }

    private function parMinimo(): array
    {
        return [['id' => 1, 'ano' => 2025, 'metas' => []]];
    }

    private function makeFederativeEntities(int $count, int $start = 1): array
    {
        $entities = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $start + $i;
            $entities[] = [
                'document' => sprintf('9000000000%04d', $n),
                'name' => "Ente {$n}",
                'exercicios' => [
                    [
                        'id' => $n,
                        'ano' => 2025,
                        'metas' => [
                            [
                                'id' => $n * 10,
                                'nome' => "Meta {$n}",
                                'valor' => $n * 100,
                                'acoes' => [
                                    [
                                        'id' => $n * 100,
                                        'nome' => "1.{$n} Ação {$n}",
                                        'valor' => $n * 100,
                                        'atividades' => [
                                            ['id' => $n * 1000, 'nome' => "Atividade {$n}", 'valor' => $n * 100],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $entities;
    }

    private function assertContractViolationDoesNotPersist(array $payload, string $document): void
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $beforeRelations = count($this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));

        try {
            $this->job()->callAssociateFederativeEntities($user->profile, $payload);
            $this->fail('Esperava erro de contrato');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('Resposta da API CultBr fora do contrato esperado', $e->getMessage());
        }

        $afterRelations = count($this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
        $this->assertSame($beforeRelations, $afterRelations);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => $document]));
    }

    // ===== associateFederativeEntities =====

    function testDocumentoIneditoCriaFederativeEntityERelation()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $this->job()->callAssociateFederativeEntities($agent, [
            ['document' => '11111111111111', 'name' => 'Ente Novo', 'exercicios' => $this->parMinimo()],
        ]);
        $this->app->em->clear();

        $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '11111111111111']);
        $this->assertNotNull($entity);
        $this->assertSame('Ente Novo', $entity->name);

        $relation = $this->app->repo(FederativeEntityAgentRelation::class)->findOneBy(['owner' => $entity]);
        $this->assertNotNull($relation);
        $this->assertEquals($agent->id, $relation->agent->id);
        $this->assertFalse($relation->hasControl);
        $this->assertEquals(AgentRelation::STATUS_ENABLED, $relation->status);
    }

    function testDocumentoJaAssociadoAOutroAgenteReaproveitaEntityComNovaRelation()
    {
        $userA = $this->userDirector->createUser();
        $userB = $this->userDirector->createUser();

        $this->login($userA);
        $entity = $this->persistFederativeEntity('22222222222222', 'Ente Compartilhado');
        $this->persistRelation($userA->profile, $entity);

        $this->login($userB);
        $this->job()->callAssociateFederativeEntities($userB->profile, [
            ['document' => '22222222222222', 'name' => 'Ente Compartilhado', 'exercicios' => $this->parMinimo()],
        ]);
        $this->app->em->clear();

        $entities = $this->app->repo(FederativeEntity::class)->findBy(['document' => '22222222222222']);
        $this->assertCount(1, $entities, 'Não deve duplicar a FederativeEntity para o mesmo documento');

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['owner' => $entities[0]]);
        $this->assertCount(2, $relations, 'Deve existir uma relation por agente, sem remover a do outro agente');
    }

    function testEnteJaAssociadoComNomeEExerciciosDiferentesAtualizaSoOQueMudou()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $entity = $this->persistFederativeEntity('33333333333333', 'Nome Antigo', ['ano' => 2025]);
        $this->persistRelation($agent, $entity);
        $entityId = $entity->id;
        $novoPar = [['id' => 2, 'ano' => 2026, 'metas' => []]];

        $this->job()->callAssociateFederativeEntities($agent, [
            ['document' => '33333333333333', 'name' => 'Nome Novo', 'exercicios' => $novoPar],
        ]);
        $this->app->em->clear();

        $updated = $this->app->repo(FederativeEntity::class)->find($entityId);
        $this->assertSame('Nome Novo', $updated->name);
        $this->assertSame($novoPar, $updated->exercices);

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['owner' => $updated]);
        $this->assertCount(1, $relations, 'Não deve criar uma segunda relation para o mesmo agente/documento');
    }

    function testEnteQueSaiuDaRespostaTemRelationRemovida()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $entityQueSai = $this->persistFederativeEntity('44444444444444', 'Vai Sair');
        $this->persistRelation($agent, $entityQueSai);

        // API só retorna um ente diferente: o anterior deve ter a relation removida (diff)
        $this->job()->callAssociateFederativeEntities($agent, [
            ['document' => '55555555555555', 'name' => 'Ente Atual', 'exercicios' => $this->parMinimo()],
        ]);
        $this->app->em->clear();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['owner' => $entityQueSai]);
        $this->assertCount(0, $relations, 'A relation do ente que saiu da resposta deve ser removida');

        $novaEntidade = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '55555555555555']);
        $this->assertNotNull($novaEntidade);
    }

    // ===== Contrato, PAR vazio e volume =====

    function testEnteSemExerciciosOuComExerciciosInvalidosPersisteSemDadosPar()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->job()->callAssociateFederativeEntities($user->profile, [
            ['document' => '66111111111111', 'name' => 'Sem Chave Exercicios'],
            ['document' => '66111111111112', 'name' => 'Exercicios Null', 'exercicios' => null],
            ['document' => '66111111111113', 'name' => 'Exercicios String', 'exercicios' => 'sem par'],
            ['document' => '66111111111114', 'name' => 'Exercicios Objeto', 'exercicios' => (object) ['ano' => 2025]],
            ['document' => '66111111111115', 'name' => 'Exercicios Vazio', 'exercicios' => []],
        ]);
        $this->app->em->clear();

        foreach (['66111111111111', '66111111111112', '66111111111113', '66111111111114', '66111111111115'] as $document) {
            $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => $document]);
            $this->assertNotNull($entity, "Ente $document deve ser criado mesmo sem dados PAR");
            $this->assertEmpty($entity->exercices);
        }

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(5, $relations);
    }

    function testEnteComExerciciosGrandePersisteEstruturaCompleta()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $largeExercises = $this->makeFederativeEntities(1, 70)[0]['exercicios'];
        $largeExercises[] = [
            'id' => 999,
            'ano' => 2026,
            'metas' => [
                ['id' => 9991, 'nome' => 'Meta extra', 'valor' => 5000, 'acoes' => []],
            ],
        ];

        $this->job()->callAssociateFederativeEntities($user->profile, [
            ['document' => '66222222222222', 'name' => 'PAR Grande', 'exercicios' => $largeExercises],
        ]);
        $this->app->em->clear();

        $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '66222222222222']);
        $this->assertEquals($largeExercises, $entity->exercices);
    }

    function testPayloadComCinquentaEntesCriaEAssociaTodos()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $payload = $this->makeFederativeEntities(50, 100);

        $this->job()->callAssociateFederativeEntities($user->profile, $payload);
        $this->app->em->clear();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(50, $relations);

        foreach ($payload as $data) {
            $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => $data['document']]));
        }
    }

    function testMudancaDeDezParaQuinzeEntesTomaApiComoFonteDaVerdade()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->job()->callAssociateFederativeEntities($user->profile, $this->makeFederativeEntities(10, 200));
        $this->job()->callAssociateFederativeEntities($user->profile, $this->makeFederativeEntities(15, 200));
        $this->app->em->clear();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(15, $relations);

        foreach ($this->makeFederativeEntities(15, 200) as $data) {
            $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => $data['document']]));
        }
    }

    function testMudancaDeQuinzeParaDezEntesRemoveSomenteRelationsDoAgenteAtual()
    {
        $userA = $this->userDirector->createUser();
        $userB = $this->userDirector->createUser();

        $this->login($userA);
        $payload15 = $this->makeFederativeEntities(15, 300);
        $this->job()->callAssociateFederativeEntities($userA->profile, $payload15);

        $removedDocument = $payload15[14]['document'];
        $removedEntity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => $removedDocument]);
        $this->persistRelation($userB->profile, $removedEntity);

        $this->login($userA);
        $this->job()->callAssociateFederativeEntities($userA->profile, $this->makeFederativeEntities(10, 300));
        $this->app->em->clear();

        $relationsA = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $userA->profile]);
        $relationsB = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $userB->profile]);
        $entityStillExists = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => $removedDocument]);

        $this->assertCount(10, $relationsA);
        $this->assertCount(1, $relationsB);
        $this->assertNotNull($entityStillExists);
    }

    function testPayloadMistoPersisteTodosEntesMesmoSemPar()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $comPar = $this->makeFederativeEntities(5, 400);
        $semPar = [
            ['document' => '66600000000001', 'name' => 'Sem PAR 1'],
            ['document' => '66600000000002', 'name' => 'Sem PAR 2', 'exercicios' => []],
            ['document' => '66600000000003', 'name' => 'Sem PAR 3', 'exercicios' => null],
            ['document' => '66600000000004', 'name' => 'Sem PAR 4', 'exercicios' => 'sem par'],
            ['document' => '66600000000005', 'name' => 'Sem PAR 5', 'exercicios' => (object) ['ano' => 2025]],
        ];

        $this->job()->callAssociateFederativeEntities($user->profile, array_merge($comPar, $semPar));
        $this->app->em->clear();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(10, $relations);

        foreach ($comPar as $data) {
            $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => $data['document']]);
            $this->assertNotNull($entity);
            $this->assertNotEmpty($entity->exercices);
        }

        foreach ($semPar as $data) {
            $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => $data['document']]);
            $this->assertNotNull($entity, "Ente {$data['document']} deve ser criado mesmo sem PAR");
            $this->assertEmpty($entity->exercices);
        }
    }

    function testEnteJaAssociadoRetornadoSemParMantemRelation()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $entity = $this->persistFederativeEntity('66777777777777', 'Perdeu PAR', $this->parMinimo());
        $this->persistRelation($user->profile, $entity);

        $this->job()->callAssociateFederativeEntities($user->profile, [
            ['document' => '66777777777777', 'name' => 'Perdeu PAR', 'exercicios' => []],
        ]);
        $this->app->em->clear();

        $entityStillExists = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '66777777777777']);
        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);

        $this->assertNotNull($entityStillExists);
        $this->assertEmpty($entityStillExists->exercices);
        $this->assertCount(1, $relations);
    }

    function testSyncComTodosEntesSemParMantémRoleEAssociacoes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $this->app->disableAccessControl();
        $this->app->user->addRole(Role::GESTOR_CULT_BR);
        $this->app->enableAccessControl();

        $entity = $this->persistFederativeEntity('66888888888888', 'Sem PAR No Sync', $this->parMinimo());
        $this->persistRelation($agent, $entity);
        $this->assertTrue(UserAccessService::isGestorCultBr());

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorResponse([
            'nome' => 'Gestor Teste',
            'entes_federados' => [
                ['document' => '66888888888888', 'name' => 'Sem PAR No Sync', 'exercicios' => []],
                ['document' => '66888888888889', 'name' => 'Sem Chave PAR'],
            ],
        ]);
        $job->sync();
        $this->app->em->clear();

        $this->assertTrue(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertCount(2, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $agent]));
        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '66888888888889']));
    }

    function testDocumentoDuplicadoNoPayloadLancaErroDeContratoSemPersistenciaParcial()
    {
        $this->assertContractViolationDoesNotPersist([
            ['document' => '66333333333333', 'name' => 'Primeiro', 'exercicios' => $this->parMinimo()],
            ['document' => '66333333333333', 'name' => 'Duplicado', 'exercicios' => $this->parMinimo()],
        ], '66333333333333');
    }

    function testItemSemDocumentOuNameLancaErroDeContratoSemPersistenciaParcial()
    {
        $this->assertContractViolationDoesNotPersist([
            ['document' => '66444444444444', 'name' => 'Valido Antes', 'exercicios' => $this->parMinimo()],
            ['name' => 'Sem Document', 'exercicios' => $this->parMinimo()],
        ], '66444444444444');

        $this->assertContractViolationDoesNotPersist([
            ['document' => '66444444444445', 'name' => 'Valido Antes', 'exercicios' => $this->parMinimo()],
            ['document' => '66444444444446', 'exercicios' => $this->parMinimo()],
        ], '66444444444445');
    }

    function testChavesRenomeadasLancaErroDeContratoSemPersistenciaParcial()
    {
        $this->assertContractViolationDoesNotPersist([
            ['document' => '66555555555555', 'name' => 'Valido Antes', 'exercicios' => $this->parMinimo()],
            ['cnpj' => '66555555555556', 'nome' => 'Chaves Renomeadas', 'exercices' => []],
        ], '66555555555555');

        $this->assertContractViolationDoesNotPersist([
            ['document' => '66555555555557', 'name' => 'Chave Exercices', 'exercices' => []],
        ], '66555555555557');
    }

    function testItemNaoArrayEmEntesFederadosLancaErroDeContratoSemPersistenciaParcial()
    {
        $this->assertContractViolationDoesNotPersist([
            ['document' => '66999999999999', 'name' => 'Valido Antes', 'exercicios' => $this->parMinimo()],
            'abc',
        ], '66999999999999');
    }

    function testDocumentENameComEspacosSaoPersistidosTrimados()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->job()->callAssociateFederativeEntities($user->profile, [
            ['document' => ' 66000000000001 ', 'name' => ' Nome Com Espacos ', 'exercicios' => $this->parMinimo()],
        ]);
        $this->app->em->clear();

        $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '66000000000001']);
        $this->assertNotNull($entity);
        $this->assertSame('66000000000001', $entity->document);
        $this->assertSame('Nome Com Espacos', $entity->name);
    }

    function testDocumentNumericoEhPersistidoComoString()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->job()->callAssociateFederativeEntities($user->profile, [
            ['document' => 66000000000002, 'name' => 'Documento Numerico', 'exercicios' => $this->parMinimo()],
        ]);
        $this->app->em->clear();

        $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '66000000000002']);
        $this->assertNotNull($entity);
        $this->assertSame('66000000000002', $entity->document);
    }

    function testExerciciosAssociativoEstranhoLancaErroDeContratoSemPersistenciaParcial()
    {
        $this->assertContractViolationDoesNotPersist([
            [
                'document' => '66000000000003',
                'name' => 'Exercicios Associativo',
                'exercicios' => ['id' => 1, 'ano' => 2025, 'metas' => []],
            ],
        ], '66000000000003');
    }

    // ===== updateAgentFromGestorResponse =====

    function testUpdateAgentFromGestorResponsePersisteSoCamposDiferentes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $agent->setMetadata('rgNumero', 'RG-ATUAL');
        $agent->save(true);

        $this->job()->callUpdateAgentFromGestorResponse($agent, [
            'rg' => 'RG-ATUAL', // igual ao atual -> não deve sobrescrever (mas também não causa erro)
            'cep' => '01001-000',
            'nome' => 'Nome Completo Atualizado',
            'celular' => '11999998888',
            'numero' => '100',
            'complemento' => 'Apto 1',
        ]);

        $this->assertSame('RG-ATUAL', $agent->getMetadata('rgNumero'));
        $this->assertSame('01001-000', $agent->getMetadata('En_CEP'));
        $this->assertSame('Nome Completo Atualizado', $agent->getMetadata('nomeCompleto'));
        $this->assertSame('11999998888', $agent->getMetadata('telefone1'));
        $this->assertSame('100', $agent->getMetadata('En_Num'));
        $this->assertSame('Apto 1', $agent->getMetadata('En_Complemento'));
    }

    // ===== sync() ponta a ponta (modo development, fixture fixa) =====

    function testSyncFelizComFixtureCriaEntesConcedeRoleEGravaLastSyncedAt()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $agent = $user->profile;

        $this->assertFalse(UserAccessService::isGestorCultBr());

        (new TestableGestorCultJob(new GestorDocument('16217309050')))->sync();
        $this->app->em->clear();

        $this->assertTrue(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);

        $entity = $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '12345678901234']);
        $this->assertNotNull($entity, 'Fixture de development deve criar o primeiro Ente Federado');

        $reloadedAgent = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($agent->id);
        $this->assertNotNull($reloadedAgent->getMetadata('gestorCultBrLastSyncedAt'));
    }

    function testSyncChamadoDuasVezesNaoDuplicaAssociacoes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        // Sem em->clear() entre as duas chamadas: no fluxo real, $app->user permanece o mesmo
        // objeto carregado durante toda a requisição (sem detach no meio) em ambas as chamadas.
        (new TestableGestorCultJob(new GestorDocument('16217309050')))->sync();
        $countAfterFirst = count($this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));

        (new TestableGestorCultJob(new GestorDocument('16217309050')))->sync();
        $countAfterSecond = count($this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Rodar sync() de novo com a mesma resposta não deve duplicar relations');

        $entities = $this->app->repo(FederativeEntity::class)->findBy(['document' => '12345678901234']);
        $this->assertCount(1, $entities, 'Rodar sync() de novo não deve duplicar a FederativeEntity');
    }
}
