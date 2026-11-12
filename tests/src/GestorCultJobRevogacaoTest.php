<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\UserAccessService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Role as MapasRole;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;
use Tests\Traits\UserDirector;

/**
 * A origem dizer que não há ente marca os vínculos; o papel só cai quando o último deles expira.
 */
class GestorCultJobRevogacaoTest extends TestCase
{
    use CapturesLog;
    use ConfiguresPlugin;

    use UserDirector;

    private const SYNC_KEYS = [
        'gestor_cult_sync_started',
        'gestor_cult_sync_completed',
        'gestor_cult_sync_started_at',
        'gestor_cult_sync_error',
        'gestor_cult_sync_error_message',
    ];

    private const HOST = 'http://cultbr.invalid';

    private static int $proximoDocumento = 90000000001;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::SYNC_KEYS as $key) {
            unset($_SESSION[$key]);
        }

        // O save do edital troca o ente pelo selecionado na sessão, que outro teste pode ter deixado.
        unset($_SESSION['selectedFederativeEntity']);
    }

    private function comModoReal(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['mode'] = 'live';

                foreach (Provider::cases() as $provedor) {
                    $config['client']['providers'][$provedor->value]['host'] = self::HOST;
                    $config['client']['providers'][$provedor->value]['token'] = 'token-de-teste';
                }

                return $config;
            },
            $exercicio
        );
    }

    /** A revogação é destrutiva demais para valer só num provedor: cada caminho corre nos dois. */
    private function emCadaProvedor(callable $cenario): void
    {
        foreach (Provider::cases() as $provedor) {
            $this->comModoReal(fn() => $cenario($provedor));
        }
    }

    private function gestorLogadoComUmEnte(): object
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->app->disableAccessControl();
        $this->app->user->addRole(Role::GESTOR_CULT_BR);
        $this->app->enableAccessControl();

        $entity = new FederativeEntity();
        $entity->name = 'Ente de Teste';
        $entity->document = '77111111111111';
        $entity->exercices = [['id' => 1, 'ano' => 2026, 'metas' => []]];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();

        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $user->profile;
        $relation->owner = $entity;
        $this->app->em->persist($relation);
        $this->app->em->flush();

        return $user;
    }

    /**
     * O sync relança depois de marcar a sessão; quem trata é POST_startSync, no controller.
     * Documento distinto por chamada: o lock do sync vive no cache, que não tem rollback.
     */
    private function sincronizarCom(FakeTransport $transporte, Provider $provedor = Provider::Gestao): ?\Throwable
    {
        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
        $job->useProvider($provedor);
        $job->useTransport($transporte);
        $falha = null;

        try {
            $job->sync();
        } catch (\Throwable $e) {
            $falha = $e;
        }

        $this->app->em->clear();

        return $falha;
    }

    private function assertPapelERelacoesIntactos(object $user, ?Provider $provedor = null): void
    {
        $onde = $provedor === null ? '' : " ({$provedor->value})";

        $this->assertTrue(UserAccessService::isGestorCultBr(), "o papel não podia ter sido revogado{$onde}");
        $this->assertCount(
            1,
            $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]),
            "as relações não podiam ter sido apagadas{$onde}",
        );
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false, "a tela precisa destravar{$onde}");
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION, "classificar a causa é do controller{$onde}");
    }

    private function assertPapelMantidoEVinculoMarcado(object $user, ?Provider $provedor = null): void
    {
        $onde = $provedor === null ? '' : " ({$provedor->value})";

        $this->assertTrue(UserAccessService::isGestorCultBr(), "o papel não podia ter sido revogado{$onde}");
        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(1, $relations, "o vínculo não podia ter sido apagado{$onde}");
        $this->assertArrayHasKey('missingSince', (array) $relations[0]->metadata, "o vínculo precisava ser marcado{$onde}");
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false, "a tela precisa destravar{$onde}");
    }

    private function segundoEnteDoGestor(object $user): FederativeEntityAgentRelation
    {
        $entity = new FederativeEntity();
        $entity->name = 'Segundo Ente de Teste';
        $entity->document = '77222222222222';
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);

        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $user->profile;
        $relation->owner = $entity;
        $this->app->em->persist($relation);
        $this->app->em->flush();

        return $relation;
    }

    private function marcarVinculoComoSumidoHa(object $user, int $dias): void
    {
        $relation = $this->app->repo(FederativeEntityAgentRelation::class)->findOneBy(['agent' => $user->profile]);
        $relation->metadata = ['missingSince' => (new \DateTime("-{$dias} days"))->format('Y-m-d H:i:s')];
        $this->app->em->persist($relation);
        $this->app->em->flush();
    }

    private function oportunidadeNoEnteDoGestor(object $gestor, object $dono): void
    {
        $relation = $this->app->repo(FederativeEntityAgentRelation::class)->findOneBy(['agent' => $gestor->profile]);

        $this->app->disableAccessControl();
        $opportunityClassName = $dono->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $dono->profile;
        $opportunity->ownerEntity = $dono->profile;
        $opportunity->name = 'Edital no ente';
        $opportunity->shortDescription = 'Edital no ente';
        $opportunity->status = Opportunity::STATUS_DRAFT;
        $opportunity->save(true);
        $opportunity->setMetadata('federativeEntityId', (string) $relation->owner->id);
        $opportunity->save(true);
        $this->app->enableAccessControl();
    }

    private function sincronizarComListaVazia(?\Throwable $falhaAntesDoFlush = null): ?\Throwable
    {
        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
        $job->setGestorResponse(['entes_federados' => []]);
        if ($falhaAntesDoFlush) {
            $job->setBeforeFlushException($falhaAntesDoFlush);
        }

        $falha = null;
        try {
            $job->sync();
        } catch (\Throwable $e) {
            $falha = $e;
        }

        $this->app->em->clear();

        return $falha;
    }

    private function papelGravado(object $user): int
    {
        return count($this->app->repo(MapasRole::class)->findBy(['userId' => $user->id, 'name' => Role::GESTOR_CULT_BR]));
    }

    private function assertPapelERelacoesRevogados(object $user, ?Provider $provedor = null): void
    {
        $onde = $provedor === null ? '' : " ({$provedor->value})";

        $this->assertFalse(UserAccessService::isGestorCultBr(), "o papel precisava ter sido revogado{$onde}");
        $this->assertCount(
            0,
            $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]),
            "as relações precisavam ter sido apagadas{$onde}",
        );
        $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION, "revogar não é falha{$onde}");
        $this->assertTrue($_SESSION['gestor_cult_sync_completed'] ?? false, "a tela precisa destravar{$onde}");
    }

    /** Corpo `null` num 200 chegava ao gate como "este gestor não tem ente nenhum". */
    function testCorpoNuloNaoRevoga()
    {
        $this->emCadaProvedor(function (Provider $provedor) {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(200, 'null'), $provedor);

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('corpo da resposta é null', $falha->getMessage(), $provedor->value);
            $this->assertPapelERelacoesIntactos($user, $provedor);
        });
    }

    /** A Gestão não distingue documento inexistente de instabilidade, e lá o 404 segue sendo erro. */
    function testDocumentoNaoEncontradoNaGestaoNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(404, '{"detail":"Pessoa não encontrada"}'), Provider::Gestao);

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('Pessoa não encontrada', $falha->getMessage());
            $this->assertPapelERelacoesIntactos($user, Provider::Gestao);
        });
    }

    /** Na Conecta o 404 com o texto da aplicação é resposta sobre a pessoa: o vínculo fica marcado. */
    public function testCpfDesconhecidoPelaConectaMarcaOVinculoEMantemOPapel(): void
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $corpo = '{"detail":"Pessoa com o CPF fornecido não encontrada no sistema."}';
            $falha = $this->sincronizarCom(new FakeTransport(404, $corpo), Provider::Conecta);

            $this->assertNull($falha, 'documento inexistente não é falha de integração');
            $this->assertPapelMantidoEVinculoMarcado($user, Provider::Conecta);
        });
    }

    /** Endereço errado também responde 404, e não pode custar o papel de quem logar. */
    function testRotaInexistenteNaConectaNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(404, '{"detail":"Not Found"}'), Provider::Conecta);

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertPapelERelacoesIntactos($user, Provider::Conecta);
        });
    }

    function testFalhaDeTransporteNaoRevoga()
    {
        $this->emCadaProvedor(function (Provider $provedor) {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(FakeTransport::falhaDeTransporte(), $provedor);

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('Connection timed out', $falha->getMessage(), $provedor->value);
            $this->assertPapelERelacoesIntactos($user, $provedor);
        });
    }

    function testCorpoIlegivelNaoRevoga()
    {
        $this->emCadaProvedor(function (Provider $provedor) {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(200, '<html>erro do proxy</html>'), $provedor);

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('não é um JSON válido', $falha->getMessage(), $provedor->value);
            $this->assertPapelERelacoesIntactos($user, $provedor);
        });
    }

    public function testDocumentoDesconhecidoPelaOrigemMarcaOVinculo(): void
    {
        $user = $this->gestorLogadoComUmEnte();

        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
        $job->setGestorResponse(null);
        $job->sync();

        $this->app->em->clear();

        $this->assertPapelMantidoEVinculoMarcado($user);
    }

    public function testListaVaziaBemFormadaMarcaOVinculo(): void
    {
        $this->emCadaProvedor(function (Provider $provedor) {
            $user = $this->gestorLogadoComUmEnte();

            $this->sincronizarCom(new FakeTransport(200, '{"entes_federados":[]}'), $provedor);

            $this->assertPapelMantidoEVinculoMarcado($user, $provedor);
        });
    }

    /** A Gestão tolera a lista sem envelope; para a Conecta ela é erro de contrato, e não toca em nada. */
    public function testListaSemEnvelopeSoMarcaOndeElaEhContrato(): void
    {
        $this->comModoReal(function () {
            $this->gestorLogadoComUmEnte();
            $falha = $this->sincronizarCom(new FakeTransport(200, '[]'), Provider::Gestao);

            $this->assertNull($falha, 'a Gestão aceita a lista sem envelope');
            $this->assertTrue(UserAccessService::isGestorCultBr(), 'e zero entes só marca o vínculo');
        });

        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();
            $falha = $this->sincronizarCom(new FakeTransport(200, '[]'), Provider::Conecta);

            $this->assertNotNull($falha, 'a Conecta recusa a lista sem envelope');
            $this->assertPapelERelacoesIntactos($user, Provider::Conecta);
        });
    }

    public function testVinculoSumidoHaSeisDiasEhMantido(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->marcarVinculoComoSumidoHa($user, 6);

        $this->sincronizarComListaVazia();

        $this->assertPapelMantidoEVinculoMarcado($user);
    }

    public function testVinculoSumidoHaOitoDiasSemOportunidadeExpiraERevogaOPapel(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->marcarVinculoComoSumidoHa($user, 8);

        $capturado = $this->capturandoLog(fn() => $this->sincronizarComListaVazia());

        $this->assertPapelERelacoesRevogados($user);
        $this->assertSame(0, $this->papelGravado($user));
        $this->assertTrue($capturado->hasWarningThatContains('dias removido'));
        $this->assertTrue($capturado->hasWarningThatContains('Nenhum vínculo com ente restante, papel GestorCultBr removido'));
    }

    public function testVinculoSumidoHaOitoDiasComOportunidadeDoGestorEhMantido(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->oportunidadeNoEnteDoGestor($user, $user);
        $this->marcarVinculoComoSumidoHa($user, 8);

        $this->sincronizarComListaVazia();

        $this->assertPapelMantidoEVinculoMarcado($user);
    }

    /** A suíte roda dentro de uma transação e o DBAL não usa savepoint: o rollback do job só marca a conexão. */
    public function testOportunidadeDeOutroDonoNoEnteNaoProtegeOVinculo(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->oportunidadeNoEnteDoGestor($user, $this->userDirector->createUser());
        $this->login($user);
        $this->marcarVinculoComoSumidoHa($user, 8);

        $this->sincronizarComListaVazia();

        $this->assertPapelERelacoesRevogados($user);
    }

    public function testFalhaNaExpiracaoDesfazPapelEVinculoNaMesmaTransacao(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->marcarVinculoComoSumidoHa($user, 8);

        $falha = $this->sincronizarComListaVazia(new \RuntimeException('Falha no flush'));

        $this->assertNotNull($falha);
        $this->assertSame(0, $this->papelGravado($user), 'a revogação precisa estar dentro da transação do job');
        $this->assertTrue($this->app->em->getConnection()->isRollbackOnly(), 'e essa transação precisa ser desfeita');
    }

    public function testGestorSemNenhumVinculoPerdeOPapelComListaVazia(): void
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $this->app->user->addRole(Role::GESTOR_CULT_BR);
        $this->app->enableAccessControl();

        $this->sincronizarComListaVazia();

        $this->assertSame(0, $this->papelGravado($user));
    }

    public function testUsuarioSemPapelNaoGanhaPapelComListaVazia(): void
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $this->sincronizarComListaVazia();

        $this->assertSame(0, $this->papelGravado($user));
    }

    public function testListaVaziaNaoAtualizaOPerfilNemADataDaSincronizacao(): void
    {
        $user = $this->gestorLogadoComUmEnte();

        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
        $job->setGestorResponse(['nome' => 'Nome Vindo Da Origem', 'entes_federados' => []]);
        $job->sync();
        $this->app->em->clear();

        $agent = $this->app->repo(\MapasCulturais\Entities\Agent::class)->find($user->profile->id);
        $this->assertNull($agent->getMetadata('gestorCultBrLastSyncedAt'));
        $this->assertNotSame('Nome Vindo Da Origem', $agent->getMetadata('nomeCompleto'));
    }

    public function testListaVaziaMarcaTodosOsVinculosEMantemOPapel(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->segundoEnteDoGestor($user);

        $this->sincronizarComListaVazia();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(2, $relations);
        foreach ($relations as $relation) {
            $this->assertArrayHasKey('missingSince', (array) $relation->metadata);
        }
        $this->assertSame(1, $this->papelGravado($user));
    }

    public function testVinculoExpiradoComOutroRestanteMantemOPapel(): void
    {
        $user = $this->gestorLogadoComUmEnte();
        $this->marcarVinculoComoSumidoHa($user, 8);
        $restante = $this->segundoEnteDoGestor($user);

        $this->sincronizarComListaVazia();

        $relations = $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]);
        $this->assertCount(1, $relations, 'só o vínculo vencido sai');
        $this->assertSame($restante->id, $relations[0]->id);
        $this->assertSame(1, $this->papelGravado($user), 'com vínculo restante o papel fica');
    }
}
