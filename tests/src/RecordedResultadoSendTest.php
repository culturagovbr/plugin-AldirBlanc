<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\RecordedResultadoSend;
use AldirBlanc\Integration\ResultadoSender;
use Tests\Abstract\TestCase;

/** O envio de resultado guardando o que aconteceu, com o client substituído. */
class RecordedResultadoSendTest extends TestCase
{
    private function client(?callable $aoCriar = null): ResultadoSender
    {
        return new class ($aoCriar) implements ResultadoSender {
            /** @var null|callable */
            private $aoCriar;

            /** @var null|callable */
            private $recorder = null;

            public function __construct(?callable $aoCriar)
            {
                $this->aoCriar = $aoCriar;
            }

            public function setExchangeRecorder(?callable $recorder): void
            {
                $this->recorder = $recorder;
            }

            public function create(ParEditalId $edital, Resultado $payload): mixed
            {
                if ($this->aoCriar !== null) {
                    ($this->aoCriar)($this->recorder);
                }

                return [];
            }
        };
    }

    private function payload(): Resultado
    {
        return new Resultado(id_tipo: 2, id_status: 3);
    }

    /** A falha antes de a chamada sair não tem tentativa a registrar: o erro original sobe intacto. */
    function testFalhaAntesDoExchangeRelancaOErroOriginal()
    {
        $client = $this->client(function () {
            throw IntegrationError::configuration('conecta.criarResultadoEndpoint', 'endpoint não declarado');
        });

        try {
            RecordedResultadoSend::perform($client, new ParEditalId(7), $this->payload(), Provider::Conecta);
            $this->fail('Esperava o IntegrationError original');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONFIGURATION, $e->kind());
        }
    }

    /** Client que devolve sem registrar nada não pode virar envio bem-sucedido. */
    function testEnvioQueNaoRegistraNadaViraErroDeContrato()
    {
        $client = $this->client();

        try {
            RecordedResultadoSend::perform($client, new ParEditalId(7), $this->payload(), Provider::Conecta);
            $this->fail('Esperava erro de contrato');
        } catch (IntegrationError $e) {
            $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
        }
    }
}
