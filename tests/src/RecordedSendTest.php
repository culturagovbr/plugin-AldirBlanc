<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Entities\CultBrRequestLogAttempt;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Integration\OpportunitySender;
use AldirBlanc\Integration\RecordedSend;
use Tests\Abstract\TestCase;

/** O envio guardando o que aconteceu, com o client substituído. */
class RecordedSendTest extends TestCase
{
    private function client(?callable $aoAtualizar = null): OpportunitySender
    {
        return new class ($aoAtualizar) implements OpportunitySender {
            public ?array $exchange = null;
            public bool $recorderAtivo = false;

            /** @var null|callable */
            private $aoAtualizar;

            public function __construct(?callable $aoAtualizar)
            {
                $this->aoAtualizar = $aoAtualizar;
            }

            public function setExchangeRecorder(?callable $recorder): void
            {
                $this->recorderAtivo = $recorder !== null;
                $this->recorder = $recorder;
            }

            public function update(OpportunityDto $payload)
            {
                if ($this->aoAtualizar !== null) {
                    ($this->aoAtualizar)($this->recorder);
                }

                return [];
            }

            /** @var null|callable */
            private $recorder = null;
        };
    }

    private function exchange(string $status): array
    {
        return [
            'method' => 'PUT',
            'endpoint' => 'http://cultbr.invalid/integracao/oportunidades/7',
            'payload' => ['id' => 7],
            'status' => $status,
            'httpStatus' => $status === CultBrRequestLogAttempt::RESULT_SUCCESS ? 200 : 500,
            'sentAt' => new \DateTime(),
            'durationMs' => 7,
        ];
    }

    function testDesfechoVemDoExchangeQueOClientRegistrou()
    {
        $client = $this->client(fn(callable $recorder) => $recorder($this->exchange(CultBrRequestLogAttempt::RESULT_SUCCESS)));

        $outcome = RecordedSend::perform($client, new OpportunityDto(id: 7), Provider::Gestao);

        $this->assertSame(SendResult::Success, $outcome->result);
        $this->assertSame(200, $outcome->httpStatus);
        $this->assertSame(Provider::Gestao, $outcome->provider);
    }

    /**
     * Client que devolve sem registrar nada não pode virar envio bem-sucedido: é a porta por onde
     * uma implementação nova entraria fechando o log como sucesso sem nenhuma tentativa.
     */
    function testEnvioQueNaoRegistraNadaViraErroDeContrato()
    {
        try {
            RecordedSend::perform($this->client(), new OpportunityDto(id: 7), Provider::Gestao);
            $this->fail('Esperava erro de contrato');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
            $this->assertStringContainsString('não registrou', $e->getMessage());
        }
    }

    /** A falha depois da chamada sair carrega o desfecho, para a tentativa ser registrada. */
    function testFalhaComExchangeRegistradoCarregaODesfecho()
    {
        $client = $this->client(function (callable $recorder) {
            $recorder($this->exchange(CultBrRequestLogAttempt::RESULT_ERROR));

            throw IntegrationError::http('Erro HTTP 500', 500, 'Internal Server Error');
        });

        try {
            RecordedSend::perform($client, new OpportunityDto(id: 7), Provider::Conecta);
            $this->fail('Esperava SendFailed');
        } catch (SendFailed $e) {
            $this->assertSame(500, $e->outcome()->httpStatus);
            $this->assertSame(SendResult::Error, $e->outcome()->result);
            $this->assertSame(Provider::Conecta, $e->outcome()->provider);
        }
    }

    /** Falha antes de a chamada sair não inventa tentativa: sobe o erro original. */
    function testFalhaSemExchangeSobeOErroOriginal()
    {
        $client = $this->client(function () {
            throw IntegrationError::configuration('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT', 'sem valor');
        });

        try {
            RecordedSend::perform($client, new OpportunityDto(id: 7), Provider::Gestao);
            $this->fail('Esperava o erro de configuração');
        } catch (SendFailed $e) {
            $this->fail('Sem tentativa registrada não há desfecho a carregar');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
        }
    }

    /** O recorder não pode sobreviver ao envio: sob client reusado, ele misturaria dois logs. */
    function testRecorderEhDesregistradoAoFinal()
    {
        $client = $this->client(fn(callable $recorder) => $recorder($this->exchange(CultBrRequestLogAttempt::RESULT_SUCCESS)));

        RecordedSend::perform($client, new OpportunityDto(id: 7), Provider::Gestao);

        $this->assertFalse($client->recorderAtivo, 'O recorder deve ser removido do client ao final do envio');
    }
}
