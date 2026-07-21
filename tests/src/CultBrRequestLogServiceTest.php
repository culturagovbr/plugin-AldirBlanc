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

    /** Sem desfecho no exchange não se assume sucesso: o registro nasce como falha. */
    function testRecordAttemptSemStatusRegistraFalha()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $attempt = $service->recordAttempt($log, ['attempt' => 1, 'maxAttempts' => 3]);

        $this->assertEquals(CultBrRequestLogAttempt::RESULT_ERROR, $attempt->result);
    }

    /** Resposta gigante (página de erro, dump) é cortada antes de ir para o banco. */
    function testRecordAttemptTruncaRespostaAcimaDoTeto()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');
        $respostaGigante = str_repeat('a', 70000);

        $attempt = $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'response' => $respostaGigante,
            'status' => CultBrRequestLogAttempt::RESULT_ERROR,
        ]);

        $this->assertTrue($attempt->response['_truncated'] ?? false, 'Resposta deve ser marcada como truncada');
        $this->assertEquals(70000, $attempt->response['_originalLength']);
        $this->assertLessThan(strlen($respostaGigante), strlen($attempt->response['raw']));
    }

    /**
     * O corte é em bytes: se cair no meio de um acento — e as mensagens do CultBR são em
     * português — um substr cru produziria UTF-8 inválido, o json_encode falharia e a resposta
     * seria gravada vazia, justamente no log de um erro grande.
     */
    function testRecordAttemptTruncaSemQuebrarCaractereMultibyte()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');
        // "ç" ocupa 2 bytes e começa exatamente no último byte do limite.
        $respostaGigante = str_repeat('a', 65535) . 'ção' . str_repeat('b', 100);

        $attempt = $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'response' => $respostaGigante,
            'status' => CultBrRequestLogAttempt::RESULT_ERROR,
        ]);

        // Igualdade exata: com substr o último byte viraria U+FFFD (ou a resposta se perderia),
        // e a asserção de "não vazio" sozinha não distinguiria os dois casos.
        $this->assertSame(str_repeat('a', 65535), $attempt->response['raw']);
        $this->assertTrue(mb_check_encoding($attempt->response['raw'], 'UTF-8'));
    }

    /** Resposta fora de UTF-8 (binário, latin1) não pode quebrar a gravação na coluna JSONB. */
    function testRecordAttemptGravaRespostaComBytesInvalidos()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $attempt = $service->recordAttempt($log, [
            'attempt' => 1,
            'maxAttempts' => 3,
            'response' => "erro na integra\xE7\xE3o",
            'status' => CultBrRequestLogAttempt::RESULT_ERROR,
        ]);

        $this->assertNotEmpty($attempt->response['raw'] ?? '');
        $this->assertTrue(mb_check_encoding($attempt->response['raw'], 'UTF-8'));
    }

    function testFinishAtualizaResultadoDoEnvio()
    {
        $service = $this->service();
        $log = $service->startOrResume($this->opportunityId(), 'update');

        $service->finish($log, CultBrRequestLog::RESULT_ERROR);

        $this->assertEquals(CultBrRequestLog::RESULT_ERROR, $log->result);
        $this->assertNotNull($log->updateTimestamp);
    }

    /**
     * O job de retry é descartado quando um novo save enfileira o mesmo id: sem fechar o envio
     * anterior, ele apareceria "em andamento" para sempre na aba.
     */
    function testNovoEnvioAbandonaOsPendentesDaMesmaOportunidade()
    {
        $opportunityId = $this->opportunityId();
        $service = $this->service();

        $anterior = $service->startOrResume($opportunityId, 'update');
        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $anterior->result);

        $novo = $service->startOrResume($opportunityId, 'update');

        // O abandono é um UPDATE atômico (não passa pelo UnitOfWork), então o objeto em
        // memória precisa ser recarregado para refletir o banco.
        $this->app->em->refresh($anterior);

        $this->assertEquals(CultBrRequestLog::RESULT_ABANDONED, $anterior->result);
        $this->assertEquals(CultBrRequestLog::RESULT_PENDING, $novo->result, 'O envio novo não pode se abandonar');
    }

    /** updateTimestamp é preenchido pelo Entity do core; só vira updatedAt depois do finish. */
    function testUpdatedAtSoAparecerDepoisDeFinalizarOEnvio()
    {
        $opportunityId = $this->opportunityId();
        $service = $this->service();
        $log = $service->startOrResume($opportunityId, 'update');

        $this->assertNull($service->findByOpportunity($opportunityId)[0]['updatedAt']);

        $service->finish($log, CultBrRequestLog::RESULT_SUCCESS);

        $this->assertNotNull($service->findByOpportunity($opportunityId)[0]['updatedAt']);
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
