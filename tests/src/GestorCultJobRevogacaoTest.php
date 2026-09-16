<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\UserAccessService;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Doubles\FakeTransport;
use Tests\AldirBlanc\Doubles\TestableGestorCultJob;
use Tests\Traits\UserDirector;

/**
 * A revogação apaga o papel do gestor e todas as relações dele, e só volta com um sync bom
 * depois. Por isso só pode acontecer quando a API de fato respondeu que não há ente nenhum.
 */
class GestorCultJobRevogacaoTest extends TestCase
{
    use ConfiguresPlugin;

    use UserDirector;

    private const HOST = 'http://cultbr.invalid';

    private static int $proximoDocumento = 90000000001;

    private function comModoReal(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['mode'] = 'live';
                $config['client']['host'] = self::HOST;

                return $config;
            },
            $exercicio
        );
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
    private function sincronizarCom(FakeTransport $transporte): ?\Throwable
    {
        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
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

    private function assertPapelERelacoesIntactos(object $user): void
    {
        $this->assertTrue(UserAccessService::isGestorCultBr(), 'o papel não podia ter sido revogado');
        $this->assertCount(
            1,
            $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]),
            'as relações não podiam ter sido apagadas',
        );
        $this->assertSame('api_unavailable', $_SESSION['gestor_cult_sync_error'] ?? null);
    }

    /** Corpo `null` num 200 chegava ao gate como "este gestor não tem ente nenhum". */
    function testCorpoNuloNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(200, 'null'));

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('corpo da resposta é null', $falha->getMessage());
            $this->assertPapelERelacoesIntactos($user);
        });
    }

    function testDocumentoNaoEncontradoNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(404, '{"detail":"Pessoa não encontrada"}'));

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('Pessoa não encontrada', $falha->getMessage());
            $this->assertPapelERelacoesIntactos($user);
        });
    }

    function testFalhaDeTransporteNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(FakeTransport::falhaDeTransporte());

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('Connection timed out', $falha->getMessage());
            $this->assertPapelERelacoesIntactos($user);
        });
    }

    function testCorpoIlegivelNaoRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(200, '<html>erro do proxy</html>'));

            $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
            $this->assertStringContainsString('não é um JSON válido', $falha->getMessage());
            $this->assertPapelERelacoesIntactos($user);
        });
    }

    /** Documento que a origem não conhece não é "gestor sem entes": não pode revogar nada. */
    function testDocumentoDesconhecidoPelaOrigemNaoRevoga()
    {
        $user = $this->gestorLogadoComUmEnte();

        $job = new TestableGestorCultJob(new GestorDocument((string) self::$proximoDocumento++));
        $job->setGestorResponse(null);
        $falha = null;

        try {
            $job->sync();
        } catch (\Throwable $e) {
            $falha = $e;
        }

        $this->app->em->clear();

        $this->assertNotNull($falha, 'a falha precisa subir para o controller tratar');
        $this->assertPapelERelacoesIntactos($user);
    }

    /** O caminho legítimo continua revogando: a API respondeu, e não há ente nenhum. */
    function testListaVaziaBemFormadaRevoga()
    {
        $this->comModoReal(function () {
            $user = $this->gestorLogadoComUmEnte();

            $falha = $this->sincronizarCom(new FakeTransport(200, '{"entes_federados":[]}'));

            $this->assertFalse(UserAccessService::isGestorCultBr());
            $this->assertCount(
                0,
                $this->app->repo(FederativeEntityAgentRelation::class)->findBy(['agent' => $user->profile]),
            );
            $this->assertArrayNotHasKey('gestor_cult_sync_error', $_SESSION);
        });
    }
}
