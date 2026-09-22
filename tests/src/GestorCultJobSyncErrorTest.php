<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\Role;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;
use Tests\Traits\UserDirector;

class GestorCultJobSyncErrorTest extends TestCase
{
    use UserDirector;

    private static int $proximoDocumento = 91000000001;

    /** O lock do sync vive no cache, que não tem rollback: cada chamada precisa de documento próprio. */
    private function documentoNovo(): string
    {
        return (string) self::$proximoDocumento++;
    }

    private function jobWithResponse(
        mixed $response,
        Provider $provedor = Provider::Gestao,
        string $documento = '12345678901',
    ): TestableGestorCultJob {
        $job = new TestableGestorCultJob(new GestorDocument($documento));
        $job->useProvider($provedor);
        $job->setGestorResponse($response);
        return $job;
    }

    private function grantGestorRole(): void
    {
        $this->app->disableAccessControl();
        $this->app->user->addRole(Role::GESTOR_CULT_BR);
        $this->app->enableAccessControl();
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [['id' => 1, 'ano' => 2025, 'metas' => []]];
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

    private function enteValido(string $document, string $name): array
    {
        return [
            'document' => $document,
            'name' => $name,
            'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
        ];
    }

    private function respostaComEntes(array $entes): array
    {
        return ['entes_federados' => $entes];
    }

    // ===== descarte de ente fora do contrato =====

    /** Sem transporte, a semântica do parse ainda é do provedor: só a Gestão tolera a lista sem envelope. */
    function testListaSemEnvelopeSegueASemanticaDoProvedorMesmoSemTransporte()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $listaPlana = [$this->enteValido('86666666666666', 'Ente Sem Envelope')];

        $this->jobWithResponse($listaPlana, Provider::Gestao, $this->documentoNovo())->sync();
        $this->assertNotNull(
            $this->app->repo(FederativeEntity::class)->findOneBy(['document' => '86666666666666']),
            'a Gestão aceita a lista sem envelope',
        );

        try {
            $this->jobWithResponse($listaPlana, Provider::Conecta, $this->documentoNovo())->sync();
            $this->fail('Esperava erro de contrato ao dar lista sem envelope à Conecta');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
            $this->assertStringContainsString('entes_federados ausente', $e->getMessage());
        }
    }

    function testEnteMalformadoEntreValidosNaoDerrubaOSync()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $resposta = $this->respostaComEntes([
            $this->enteValido('81111111111111', 'Ente Bom Um'),
            ['document' => '82222222222222', 'name' => 'Ente Ruim', 'exercicios' => [['ano' => 2025]]],
            $this->enteValido('83333333333333', 'Ente Bom Dois'),
        ]);

        $this->jobWithResponse($resposta)->sync();
        $this->app->em->clear();

        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '81111111111111']));
        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '83333333333333']));
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '82222222222222']));
    }

    function testEnteSemNomeEhDescartadoSemDerrubarOSync()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $resposta = $this->respostaComEntes([
            ['document' => '84444444444444', 'name' => '   '],
            $this->enteValido('85555555555555', 'Ente Bom'),
        ]);

        $this->jobWithResponse($resposta)->sync();
        $this->app->em->clear();

        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '85555555555555']));
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '84444444444444']));
    }

    function testTodosOsEntesMalformadosNaoRevogaRoleNemRemoveRelations()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->grantGestorRole();

        $entity = $this->persistFederativeEntity('86666666666666', 'Ente Preservado');
        $this->persistRelation($user->profile, $entity);

        $resposta = $this->respostaComEntes([
            ['document' => '87777777777777', 'name' => 'Ente Ruim', 'exercicios' => [['ano' => 2025]]],
        ]);

        try {
            $this->jobWithResponse($resposta)->sync();
            $this->fail('Esperava erro de contrato quando nenhum ente sobrevive');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('Resposta da API CultBr fora do contrato esperado', $e->getMessage());
        }

        $this->app->em->clear();

        $this->assertTrue(UserAccessService::isGestorCultBr());
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION, 'classificar a causa é do controller');
        $this->assertCount(1, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }

    function testRespostaSemEntesRevogaRoleERemoveRelations()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->grantGestorRole();

        $entity = $this->persistFederativeEntity('77111111111111', 'Ente Removido');
        $this->persistRelation($user->profile, $entity);
        $this->assertTrue(UserAccessService::isGestorCultBr());

        $this->jobWithResponse([])->sync();
        $this->app->em->clear();

        $this->assertFalse(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }

    function testRespostaSemEntesSemRoleEhIdempotente()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->assertFalse(UserAccessService::isGestorCultBr());

        $this->jobWithResponse([])->sync();

        $this->assertFalse(UserAccessService::isGestorCultBr());
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }

    function testErroAoBuscarDadosDestravaATelaERelancaExcecao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorException(new \Exception('Timeout de conexão', 28));

        try {
            $job->sync();
            $this->fail('Esperava exceção do client');
        } catch (\Exception $e) {
            $this->assertSame('Timeout de conexão', $e->getMessage());
        }

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false, 'a tela precisa destravar');
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION, 'classificar a causa é do controller');
    }

    function testLockEhRemovidoAposSyncComSucesso()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $lockKey = "gestor_cult_sync_lock:{$user->id}:12345678901";

        $job = $this->jobWithResponse([]);

        $this->assertTrue($job->sync());
        $this->assertFalse($this->app->cache->contains($lockKey));
    }

    function testLockEhRemovidoAposErroRelancado()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $lockKey = "gestor_cult_sync_lock:{$user->id}:12345678901";

        $job = new TestableGestorCultJob(new GestorDocument('12345678901'));
        $job->setGestorException(new \RuntimeException('Falha no client'));

        try {
            $job->sync();
            $this->fail('Esperava exceção do client');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Falha no client', $exception->getMessage());
        }

        $this->assertFalse($this->app->cache->contains($lockKey));
    }

    /** O job deixou de nomear a causa: ele alerta, destrava a tela e devolve a exceção ao controller. */
    private function sincronizarEsperandoFalha(TestableGestorCultJob $job, string $mensagem): void
    {
        try {
            $job->sync();
            $this->fail("Esperava a falha subir ao controller: {$mensagem}");
        } catch (\RuntimeException $e) {
            $this->assertSame($mensagem, $e->getMessage());
        }

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false, 'a tela precisa destravar');
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION, 'classificar a causa é do controller');
    }

    function testErroAoAssociarDadosDestravaATelaEDevolveAFalha()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Teste',
            'entes_federados' => [
                [
                    'document' => '77222222222222',
                    'name' => 'Ente Com Falha',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setAssociateException(new \RuntimeException('Falha controlada na associação'));

        $this->sincronizarEsperandoFalha($job, 'Falha controlada na associação');
        $this->app->em->clear();

        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77222222222222']));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testErroAoAtualizarAgenteNaoPersisteAssociacoesNemConcedeRole()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Com Falha',
            'entes_federados' => [
                [
                    'document' => '77233333333333',
                    'name' => 'Ente Sem Persistencia Parcial',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setUpdateAgentException(new \RuntimeException('Falha controlada no agente'));

        $this->sincronizarEsperandoFalha($job, 'Falha controlada no agente');
        $this->app->em->clear();

        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77233333333333']));
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testErroAoConcederRoleNaoPersisteAssociacoes()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $job = $this->jobWithResponse([
            'nome' => 'Gestor Sem Role',
            'entes_federados' => [
                [
                    'document' => '77244444444444',
                    'name' => 'Ente Sem Role',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setGrantRoleException(new \RuntimeException('Falha controlada na role'));

        $this->sincronizarEsperandoFalha($job, 'Falha controlada na role');
        $this->app->em->clear();

        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77244444444444']));
        $this->assertCount(0, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
        $this->assertFalse(UserAccessService::isGestorCultBr());
    }

    function testUsuarioSemProfileAbortaSyncSemErro()
    {
        $user = $this->createUserWithoutProfile();
        $this->login($user);

        $this->jobWithResponse([
            'entes_federados' => [
                [
                    'document' => '77333333333333',
                    'name' => 'Nao Deve Persistir',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ])->sync();

        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false);
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77333333333333']));
    }

    function testErroDuranteAssociacaoFazRollbackDoConjuntoAnterior()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $previousEntity = $this->persistFederativeEntity('77444444444444', 'Ente Anterior');
        $this->persistRelation($user->profile, $previousEntity);

        $job = $this->jobWithResponse([
            'entes_federados' => [
                [
                    'document' => '77555555555555',
                    'name' => 'Novo Ente Antes Do Flush',
                    'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
                ],
            ],
        ]);
        $job->setBeforeFlushException(new \RuntimeException('Falha antes do flush'));

        $this->sincronizarEsperandoFalha($job, 'Falha antes do flush');
        $this->app->em->clear();

        $this->assertNotNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77444444444444']));
        $this->assertNull($this->app->repo(FederativeEntity::class)->findOneBy(['document' => '77555555555555']));
        $this->assertCount(1, $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]));
    }
}
