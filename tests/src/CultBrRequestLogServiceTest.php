<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Services\CultBrRequestLogService;
use Tests\Abstract\TestCase;

/**
 * Histórico de envios ao CultBR: criação do envio, retomada pelo uuid nas retentativas,
 * gravação de tentativa e formato consumido pela aba "Logs CultBr".
 */
class CultBrRequestLogServiceTest extends TestCase
{
    private function service(): CultBrRequestLogService
    {
        return new CultBrRequestLogService();
    }

    /** Id fictício: o serviço não valida existência da oportunidade (quem valida é o endpoint). */
    private function opportunityId(): int
    {
        return random_int(900000, 999999);
    }

    function testStartOrResumeSemUuidCriaEnvioPendenteComUuidGerado()
    {
        $opportunityId = $this->opportunityId();

        $log = $this->service()->startOrResume($opportunityId, 'update');

        $this->assertNotEmpty($log->requestUuid, 'Envio deve nascer com uuid');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $log->requestUuid,
            'uuid deve ser v4'
        );
        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $log->result);
        $this->assertEquals($opportunityId, $log->opportunityId);
    }

    /**
     * A retentativa do job carrega o uuid no payload: o serviço deve reaproveitar o
     * mesmo envio, senão cada tentativa viraria uma linha solta na aba.
     */
    function testStartOrResumeComUuidExistenteReaproveitaOMesmoEnvio()
    {
        $opportunityId = $this->opportunityId();
        $service = $this->service();

        $primeira = $service->startOrResume($opportunityId, 'update');
        $segunda = $service->startOrResume($opportunityId, 'update', $primeira->requestUuid);

        $this->assertEquals($primeira->id, $segunda->id, 'Retentativa não pode criar novo envio');
    }

    function testRecordAttemptGravaTentativaComPayloadRespostaEStatus()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $attempt = $service->recordAttempt($log, [
            'attempt' => 2,
            'maxAttempts' => 3,
            'endpoint' => 'https://cultbr.invalid/oportunidade/1/update',
            'method' => 'PUT',
            'payload' => ['nome' => 'Edital'],
            'response' => '{"id":42}',
            'httpStatus' => 200,
            'status' => CultBrRequestLogAttempt::RESULT_SUCCESS,
            'durationMs' => 120,
        ]);

        $this->assertEquals(2, $attempt->attempt);
        $this->assertEquals(3, $attempt->maxAttempts);
        $this->assertEquals(200, $attempt->httpStatus);
        $this->assertEquals(['nome' => 'Edital'], $attempt->payload);
        $this->assertEquals(['id' => 42], $attempt->response, 'Resposta JSON deve virar array na coluna JSONB');
        $this->assertEquals(CultBrRequestLogAttempt::RESULT_SUCCESS, $attempt->result);
    }

    /** Resposta que não é JSON (HTML de erro, texto puro) precisa caber na coluna JSONB. */
    function testRecordAttemptGuardaRespostaNaoJsonComoRaw()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $attempt = $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'response' => '<html>502 Bad Gateway</html>',
            'status' => CultBrRequestLogAttempt::RESULT_ERROR,
            'error' => 'Erro na API',
        ]);

        $this->assertEquals(['raw' => '<html>502 Bad Gateway</html>'], $attempt->response);
        $this->assertEquals('Erro na API', $attempt->errorMessage);
    }

    function testFinishAtualizaResultadoDoEnvio()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $service->finish($log, CultBrRequestLog::RESULT_ERROR);

        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log->result);
        $this->assertNotNull($log->updateTimestamp);
    }

    /** Formato que a aba consome: envios mais recentes primeiro, tentativas aninhadas. */
    function testFindByOpportunityDevolveEnviosComTentativasAninhadas()
    {
        $opportunityId = $this->opportunityId();
        $service = $this->service();

        $log = $service->startOrResume($opportunityId, 'update');
        $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'status' => CultBrRequestLogAttempt::RESULT_SUCCESS,
        ]);
        $service->finish($log, CultBrRequestLog::RESULT_SUCCESS);

        $rows = $service->findByOpportunity($opportunityId);

        $this->assertCount(1, $rows);
        $this->assertEquals($log->requestUuid, $rows[0]['requestUuid']);
        $this->assertEquals('success', $rows[0]['status']);
        $this->assertCount(1, $rows[0]['attempts']);
        $this->assertEquals(1, $rows[0]['attempts'][0]['attempt']);
        $this->assertEquals(1, $service->countByOpportunity($opportunityId));
    }
}
