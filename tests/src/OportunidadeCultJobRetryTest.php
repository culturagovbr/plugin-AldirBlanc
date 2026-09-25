<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Jobs\OportunidadeCultJob;
use AldirBlanc\Plugin;
use MapasCulturais\Entities\Job;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/**
 * Quando o envio ao CultBR merece outra tentativa. Retentar o que não muda de resultado gasta
 * três PUT e três alertas por oportunidade — e a fila é a mesma de todo o Mapas.
 */
class OportunidadeCultJobRetryTest extends TestCase
{
    use UserDirector;
    use CapturesLog;
    use IsolatesJobQueue;
    use SendsOpportunityThroughJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Retentativa pendente é job na fila: enqueueOrReplaceJob reusa o id, então é 0 ou 1. */
    private function retentativasNaFila(): int
    {
        // A coluna `name` da fila é mapeada na propriedade $type (Entities/Job.php).
        return count($this->app->repo(Job::class)->findBy(['type' => OportunidadeCultJob::SLUG]));
    }

    private function falhaDoEnvio(IntegrationError $causa, ?int $httpStatus): callable
    {
        return function () use ($causa, $httpStatus) {
            throw new SendFailed(
                $this->desfecho([
                    'result' => SendResult::Error,
                    'httpStatus' => $httpStatus,
                    'response' => 'corpo da falha',
                ]),
                $causa
            );
        };
    }

    /** Executa o primeiro envio e devolve os dados do log resultante. */
    private function enviaEFalha(callable $aoEnviar): array
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProvedorDuble($aoEnviar, function () use ($opp) {
            $this->enqueueUpdateJob($opp);
            $this->processJobs(number_of_jobs: 1);
        });

        return $this->logs($opp->id)[0];
    }

    /** 404 e demais 4xx não mudam de resposta na tentativa seguinte: o envio encerra na primeira. */
    function testFalhaDeterministicaEncerraSemRetentativa()
    {
        $log = $this->enviaEFalha(
            $this->falhaDoEnvio(IntegrationError::http('Erro HTTP 404', 404, 'Not Found'), 404)
        );

        $this->assertCount(1, $log['attempts'], 'Não pode haver segunda tentativa');
        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log['status']);
        $this->assertSame(0, $this->retentativasNaFila(), '4xx não pode reenfileirar');
    }

    /** O 5xx pode ser transitório, e continua valendo a retentativa. */
    function testFalhaDeServidorReenfileira()
    {
        $log = $this->enviaEFalha(
            $this->falhaDoEnvio(IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error'), 500)
        );

        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $log['status'], 'O envio segue em andamento');
        $this->assertSame(1, $this->retentativasNaFila(), '5xx precisa reenfileirar');
    }

    /** Timeout e DNS não têm status, e são o caso clássico de repetir. */
    function testFalhaDeTransporteReenfileira()
    {
        $log = $this->enviaEFalha(
            $this->falhaDoEnvio(IntegrationError::transport('Operation timed out', 28), null)
        );

        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $log['status']);
        $this->assertSame(1, $this->retentativasNaFila(), 'Erro de transporte precisa reenfileirar');
    }

    /** Sem host ou sem token, repetir só multiplica o alerta: nada foi enviado e nada mudará. */
    function testErroDeConfiguracaoEncerraSemTentativaRegistrada()
    {
        $log = $this->enviaEFalha(function () {
            throw IntegrationError::configuration('PNAB_CULTBR_HOST', 'sem valor na configuração do plugin');
        });

        $this->assertCount(0, $log['attempts'], 'A chamada não chegou a sair');
        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log['status']);
        $this->assertSame(0, $this->retentativasNaFila(), 'Configuração ausente não pode reenfileirar');
    }

    /** Corpo ilegível não ganha sentido na repetição, mesmo sem status para classificar. */
    function testRespostaIlegivelEncerraSemRetentativa()
    {
        $log = $this->enviaEFalha(
            $this->falhaDoEnvio(IntegrationError::parse('resposta não é JSON', null, '<html>'), null)
        );

        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log['status']);
        $this->assertSame(0, $this->retentativasNaFila(), 'Erro de parse não pode reenfileirar');
    }

    /** O que não sabemos classificar mantém a retentativa de hoje, em vez de perder o envio. */
    function testFalhaNaoClassificadaMantemARetentativa()
    {
        $log = $this->enviaEFalha(function () {
            throw new \RuntimeException('falha inesperada');
        });

        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $log['status']);
        $this->assertSame(1, $this->retentativasNaFila());
    }

    /** Esgotado o teto, o 5xx para de voltar para a fila e o envio fecha em falha. */
    function testFalhaDeServidorParaNoTetoDeTentativas()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProvedorDuble(
            $this->falhaDoEnvio(IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error'), 500),
            function () use ($opp) {
                $this->enqueueUpdateJob($opp);

                for ($i = 0; $i < OportunidadeCultJob::MAX_ATTEMPTS; $i++) {
                    $this->app->executeJob('2100-01-01 00:00');
                }
            }
        );

        $log = $this->logs($opp->id)[0];

        $this->assertCount(OportunidadeCultJob::MAX_ATTEMPTS, $log['attempts']);
        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log['status']);
        $this->assertSame(0, $this->retentativasNaFila(), 'O teto encerra a fila');
    }

    /**
     * Um 5xx que se resolve na segunda tentativa não deve acordar ninguém, e um que nunca passa
     * deve alertar uma vez, não três: LOG_HANDLERS pode incluir telegram:CRITICAL.
     */
    function testAlertaSaiUmaVezSo_QuandoNaoRestaTentativa()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $capturado = $this->capturandoLog(function () use ($opp) {
            $this->comProvedorDuble(
                $this->falhaDoEnvio(IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error'), 500),
                function () use ($opp) {
                    $this->enqueueUpdateJob($opp);
                    $this->app->executeJob('2100-01-01 00:00');
                }
            );
        });

        $this->assertFalse(
            $capturado->hasCriticalRecords(),
            'Com retentativa pela frente o alerta ainda não se justifica'
        );

        $capturado = $this->capturandoLog(function () use ($opp) {
            $this->comProvedorDuble(
                $this->falhaDoEnvio(IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error'), 500),
                function () {
                    $this->app->executeJob('2100-01-01 00:00');
                    $this->app->executeJob('2100-01-01 00:00');
                }
            );
        });

        $criticos = array_filter(
            $capturado->getRecords(),
            fn($registro) => $registro->level->getName() === 'CRITICAL'
        );

        $this->assertCount(1, $criticos, 'O envio encerrado alerta uma única vez');
        $this->assertStringContainsString('envio encerrado', reset($criticos)->message);
    }

    /** Recusa nunca foi um desfecho que o envio produzisse: a tela não tem o que exibir. */
    function testEnvioNaoProduzDesfechoDeRecusa()
    {
        $this->assertSame(
            ['success', 'error', 'simulated'],
            array_map(fn(SendResult $result) => $result->value, SendResult::cases())
        );
    }

    /** Provedor sem configuração nenhuma não melhora na tentativa seguinte: encerra sem voltar à fila. */
    function testConfiguracaoAusenteEncerraSemRetentativa()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());
        $plugin = Plugin::getInstance();

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = 'conecta';
                unset($config['client']['providers']['conecta']);

                return $config;
            },
            function () use ($opp, $plugin) {
                $plugin->resetIntegrationProvider();

                try {
                    $this->enqueueUpdateJob($opp);
                    $this->processJobs(number_of_jobs: 1);
                } finally {
                    $plugin->resetIntegrationProvider();
                }
            }
        );

        $this->assertSame(0, $this->retentativasNaFila(), 'Configuração ausente não muda de resultado');
    }

    /** `enqueueOrReplaceJob` recebe string não-nulável: delay vazio é TypeError, não atraso. */
    function testAConfiguracaoEntregaDelayUtilizavelParaOEnfileiramento()
    {
        $integracao = Plugin::getInstance()->config['integration'];

        foreach (['delayJob', 'retryDelayJob'] as $chave) {
            $this->assertIsString($integracao[$chave], "{$chave} precisa ser string");
            $this->assertNotSame('', trim($integracao[$chave]), "{$chave} não pode chegar vazio");
        }
    }
}
