<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\OportunidadeCultJob;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/**
 * Gravação do histórico de envios pelo OportunidadeCultJob (aba "Logs CultBr").
 *
 * Em modo development (default nesta suíte), AbstractClient::put() devolve o payload sem
 * chamada HTTP — a tentativa é registrada como `simulated`, sem resposta de servidor.
 */
class OportunidadeCultJobLogTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use SendsOpportunityThroughJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** O provider de cada tentativa, na ordem: findByOpportunity não expõe a coluna. */
    private function providers(string $requestUuid): array
    {
        $log = $this->app->repo(CultBrRequestLog::class)->findOneBy(['requestUuid' => $requestUuid]);
        $attempts = $this->app->repo(CultBrRequestLogAttempt::class)
            ->findBy(['log' => $log], ['attempt' => 'ASC']);

        return array_map(fn(CultBrRequestLogAttempt $attempt) => $attempt->provider, $attempts);
    }

    /** Ver OportunidadeCultJobUpdateTest: apaga a linha mantendo o objeto na identity map. */
    private function deleteOpportunityFromDb(int $opportunityId): void
    {
        $this->app->em->getConnection()->executeStatement(
            'DELETE FROM opportunity WHERE id = ?',
            [$opportunityId]
        );
    }

    function testEnvioBemSucedidoRegistraUmLogComUmaTentativa()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($opp->id);

        $this->assertCount(1, $rows, 'Deve haver um envio registrado');
        $this->assertEquals(CultBrRequestLog::RESULT_SUCCESS, $rows[0]['status']);
        $this->assertCount(1, $rows[0]['attempts']);
        $this->assertEquals(1, $rows[0]['attempts'][0]['attempt']);
        $this->assertEquals(
            CultBrRequestLogAttempt::RESULT_SIMULATED,
            $rows[0]['attempts'][0]['status'],
            'Em modo development a tentativa é simulada'
        );
    }

    /** Com duas implementações, o log precisa dizer para qual API o envio foi. */
    function testCadaTentativaGravaOProvedorQueAAtendeu()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $uuid = $this->logs($opp->id)[0]['requestUuid'];

        $this->assertSame([Provider::Gestao->value], $this->providers($uuid));
    }

    /** Envio retomado sob outra configuração: cada tentativa guarda a API que a atendeu. */
    function testTentativasEmProvedoresDiferentesSaoDistinguiveisNoLog()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enqueueUpdateJob($opp);
        $this->processJobs(number_of_jobs: 1);

        $uuid = $this->logs($opp->id)[0]['requestUuid'];

        $this->comProviderConfigurado(Provider::Conecta->value, function () use ($opp, $uuid) {
            $this->enqueueUpdateJob($opp, ['attempt' => 2, 'requestUuid' => $uuid]);
            $this->processJobs(number_of_jobs: 1);
        });

        $this->assertSame([Provider::Gestao->value, Provider::Conecta->value], $this->providers($uuid));
    }

    /** Provedor irresolvível impede o envio: não há tentativa a registrar, e nada fecha como sucesso. */
    function testProvedorIrresolvivelNaoEnviaENaoFechaComoSucesso()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProviderConfigurado('nao-existe', function () use ($opp) {
            $this->enqueueUpdateJob($opp);
            $this->processJobs(number_of_jobs: 1);
        });

        $rows = $this->logs($opp->id);

        $this->assertCount(0, $rows[0]['attempts'], 'Sem provedor não houve chamada a registrar');
        $this->assertNotEquals(CultBrRequestLog::RESULT_SUCCESS, $rows[0]['status']);
    }

    /**
     * A tentativa é gravada a partir do que a operação devolveu, e não de estado deixado numa
     * instância de client: o dublê não registra recorder nenhum.
     */
    function testTentativaVemDoDesfechoDevolvidoPeloProvedor()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProvedorDuble(
            fn() => $this->desfecho(['endpoint' => 'http://conecta.invalid/oportunidades/77', 'httpStatus' => 201]),
            function () use ($opp) {
                $this->enqueueUpdateJob($opp);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        $rows = $this->logs($opp->id);
        $tentativa = $rows[0]['attempts'][0];

        $this->assertSame([(int) $opp->id], FakeIntegrationProvider::$enviados);
        $this->assertEquals('http://conecta.invalid/oportunidades/77', $tentativa['endpoint']);
        $this->assertEquals(201, $tentativa['httpStatus']);
        $this->assertEquals(SendResult::Success->value, $tentativa['status']);
        $this->assertEquals(Provider::Conecta->value, $this->providers($rows[0]['requestUuid'])[0]);
    }

    /** O envio que falha registra a tentativa com o que a API respondeu — era o que se perdia. */
    function testEnvioQueFalhaRegistraATentativaComARespostaDaApi()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProvedorDuble(
            function () {
                throw new SendFailed(
                    $this->desfecho([
                        'result' => SendResult::Error,
                        'httpStatus' => 500,
                        'response' => 'Internal Server Error',
                    ]),
                    IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error')
                );
            },
            function () use ($opp) {
                $this->enqueueUpdateJob($opp);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        $rows = $this->logs($opp->id);

        $this->assertCount(1, $rows[0]['attempts'], 'A falha precisa deixar a tentativa registrada');
        $tentativa = $rows[0]['attempts'][0];
        $this->assertEquals(500, $tentativa['httpStatus']);
        $this->assertEquals(SendResult::Error->value, $tentativa['status']);
        $this->assertEquals('Erro HTTP 500', $tentativa['errorMessage']);
        $this->assertStringContainsString('Internal Server Error', json_encode($tentativa['response']));
    }

    /** Dois envios no mesmo processo: cada log recebe a sua tentativa, e só a sua. */
    function testDoisEnviosNoMesmoProcessoNaoMisturamLog()
    {
        $user = $this->userDirector->createUser();
        $primeira = $this->createOpportunity($user);
        $segunda = $this->createOpportunity($user);

        $this->comProvedorDuble(
            fn(OpportunityId $id) => $this->desfecho(['endpoint' => "http://conecta.invalid/oportunidades/{$id->id}"]),
            function () use ($primeira, $segunda) {
                // Um envio de cada vez, no mesmo processo: é entre eles que o estado vazava.
                $this->enqueueUpdateJob($primeira);
                $this->processJobs(number_of_jobs: 1);

                $this->enqueueUpdateJob($segunda);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        foreach ([$primeira, $segunda] as $opp) {
            $rows = $this->logs($opp->id);
            $this->assertCount(1, $rows, "A oportunidade {$opp->id} deve ter um envio");
            $this->assertCount(1, $rows[0]['attempts'], "A oportunidade {$opp->id} deve ter uma tentativa");
            $this->assertEquals(
                "http://conecta.invalid/oportunidades/{$opp->id}",
                $rows[0]['attempts'][0]['endpoint'],
                'A tentativa gravada é a do próprio envio'
            );
        }
    }

    /**
     * A retentativa precisa entrar como tentativa 2 do MESMO envio — é isso que dá
     * a leitura "Tentativa 2/3" sob um único uuid na aba.
     */
    function testRetentativaEntraNoMesmoEnvio()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows, 'Falha não pode criar um segundo envio');
        $uuidPrimeiraTentativa = $rows[0]['requestUuid'];
        $this->assertEquals(
            CultBrRequestLog::RESULT_PENDING,
            $rows[0]['status'],
            'Com retentativa pendente o envio segue em andamento'
        );

        // Executa o job de retry enfileirado pela falha anterior.
        $this->app->executeJob('2100-01-01 00:00');

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows, 'Retentativa não pode criar novo envio');
        $this->assertEquals($uuidPrimeiraTentativa, $rows[0]['requestUuid']);
    }

    /**
     * Quem salvou a oportunidade fica registrado no envio: App::enqueueJob grava o usuário
     * logado em Job::$user, e a retentativa (que roda sem sessão) preserva esse autor.
     */
    function testEnvioRegistraQuemDisparouEPreservaNaRetentativa()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);
        $this->processJobs(number_of_jobs: 1);

        $rows = $this->logs($oppId);
        $this->assertCount(1, $rows);
        $this->assertEquals($user->id, $rows[0]['user']['id'] ?? null, 'Autor do envio deve ser quem salvou');

        // A retentativa roda no worker, sem usuário logado.
        $this->app->executeJob('2100-01-01 00:00');

        $rows = $this->logs($oppId);
        $this->assertEquals($user->id, $rows[0]['user']['id'] ?? null, 'Retentativa não pode perder o autor');
    }

    /** Esgotadas as 3 tentativas, o envio fecha como falha — hoje o job engole a exceção. */
    function testEnvioFechaComoFalhaAoEsgotarTentativas()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());
        $oppId = $opp->id;

        $this->enqueueUpdateJob($opp);
        $this->deleteOpportunityFromDb($oppId);

        for ($i = 0; $i < 5; $i++) {
            $this->app->executeJob('2100-01-01 00:00');
        }

        $rows = $this->logs($oppId);

        $this->assertCount(1, $rows);
        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $rows[0]['status']);
    }
}
