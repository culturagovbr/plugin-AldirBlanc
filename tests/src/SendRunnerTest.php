<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Integration\SendRunner;
use AldirBlanc\Services\CultBrRequestLogService;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;

/** A regra que nenhum teste de job alcança: o histórico é acessório e sua falha não altera o envio. */
class SendRunnerTest extends TestCase
{
    private function logServiceQueFalha(): CultBrRequestLogService
    {
        return new class extends CultBrRequestLogService {
            public function startOrResume(int $opportunityId, string $action, ?string $requestUuid = null, ?User $user = null): \AldirBlanc\Entities\CultBrRequestLog
            {
                throw new \RuntimeException('banco do histórico indisponível');
            }
        };
    }

    private function desfechoDeSucesso(): SendOutcome
    {
        return new SendOutcome(
            provider: Provider::Conecta,
            result: SendResult::Success,
            method: 'POST',
            endpoint: 'http://cultbr.invalid/editais/7/resultado',
            payload: ['id_tipo' => 2],
            sentAt: new \DateTime(),
            durationMs: 7,
            response: '{}',
            httpStatus: 201,
        );
    }

    function testFalhaAoGravarOHistoricoNaoImpedeOEnvioNemODesfecho()
    {
        $runner = new SendRunner('JobDeTeste', 3, $this->logServiceQueFalha());
        $enviou = false;
        $sucessoTratado = false;

        $runner->run(
            opportunityId: 7,
            action: 'resultado',
            requestUuid: null,
            user: null,
            attempt: 1,
            send: function () use (&$enviou) {
                $enviou = true;

                return $this->desfechoDeSucesso();
            },
            onSuccess: function () use (&$sucessoTratado) {
                $sucessoTratado = true;
            },
            scheduleRetry: fn() => $this->fail('sucesso não agenda retentativa'),
        );

        $this->assertTrue($enviou, 'o envio acontece mesmo com o histórico fora do ar');
        $this->assertTrue($sucessoTratado, 'o pós-sucesso roda mesmo sem log gravado');
    }
}
