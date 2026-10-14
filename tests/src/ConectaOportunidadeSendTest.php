<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Enum\SendAction;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\SequencedTransport;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;

/** O PUT da Conecta não cria: quem nunca foi enviado precisa do POST, e é isso que se trava aqui. */
class ConectaOportunidadeSendTest extends TestCase
{
    use ConfiguresPlugin;

    private const HOST = 'http://cultbr.invalid';

    private const NAO_ENCONTRADO = '{"detail":"Oportunidade não encontrada"}';

    private function comConecta(callable $exercicio): void
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'live'],
                'conecta',
            ),
            $exercicio,
        );
    }

    private function payload(): OpportunityDto
    {
        return OpportunityDto::fromArray(['id' => 7283, 'numero_e_titulo_edital' => 'Edital de teste']);
    }

    private function envia(SequencedTransport $transporte, SendAction $action)
    {
        return (new ConectaProvider($transporte))->sendOpportunity(new OpportunityId(7283), $this->payload(), $action);
    }

    function testPrimeiroEnvioCriaPeloPostNoEndpointSemIdentificador()
    {
        $this->comConecta(function () {
            $transporte = new SequencedTransport([[200, '{}']]);

            $outcome = $this->envia($transporte, SendAction::Create);

            $this->assertSame(['POST'], $transporte->metodos(), 'criação não pode gastar um PUT que já se sabe que falha');
            $this->assertSame(self::HOST . '/oportunidades', $transporte->ultimaUrl());
            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame('POST', $outcome->method, 'o log precisa dizer o verbo que de fato saiu');
        });
    }

    function testEnvioDeOportunidadeJaConhecidaUsaPut()
    {
        $this->comConecta(function () {
            $transporte = new SequencedTransport([[200, '{}']]);

            $outcome = $this->envia($transporte, SendAction::Update);

            $this->assertSame(['PUT'], $transporte->metodos());
            $this->assertSame(self::HOST . '/oportunidades/7283', $transporte->ultimaUrl());
            $this->assertSame('PUT', $outcome->method);
        });
    }

    /** O estado local pode mentir: edital removido na origem volta 404 e precisa nascer de novo. */
    function testPutQueNaoEncontraOEditalCaiParaCriacao()
    {
        $this->comConecta(function () {
            $transporte = new SequencedTransport([[404, self::NAO_ENCONTRADO], [200, '{}']]);

            $outcome = $this->envia($transporte, SendAction::Update);

            $this->assertSame(['PUT', 'POST'], $transporte->metodos());
            $this->assertSame(SendResult::Success, $outcome->result, 'o desfecho é o do POST, não o do 404 de descoberta');
            $this->assertSame('POST', $outcome->method);
        });
    }

    function testErroQueNaoEhAusenciaDoEditalNaoDisparaCriacao()
    {
        $this->comConecta(function () {
            $transporte = new SequencedTransport([[500, '{"detail":"erro interno"}']]);

            try {
                $this->envia($transporte, SendAction::Update);
                $this->fail('Esperava a falha do PUT, não uma criação silenciosa');
            } catch (SendFailed $e) {
                $this->assertSame(['PUT'], $transporte->metodos(), 'criar depois de 500 duplicaria o edital');
                $this->assertSame(SendResult::Error, $e->outcome()->result);
            }
        });
    }

    /** Sem registrar o exchange, o envio criaria na origem e o log diria que falhou. */
    function testCriacaoQueFalhaRegistraATentativaComoErro()
    {
        $this->comConecta(function () {
            $transporte = new SequencedTransport([[422, '{"detail":[{"msg":"campo inválido"}]}']]);

            try {
                $this->envia($transporte, SendAction::Create);
                $this->fail('Esperava SendFailed com a tentativa registrada');
            } catch (SendFailed $e) {
                $this->assertSame(SendResult::Error, $e->outcome()->result);
                $this->assertSame('POST', $e->outcome()->method);
                $this->assertSame(422, $e->outcome()->httpStatus);
            }
        });
    }

    function testCriacaoEmModoSimuladoRegistraComoSimulada()
    {
        $this->comConfigDoPlugin(
            fn(array $config) => $this->comValoresDoCliente(
                $config,
                ['host' => self::HOST, 'token' => 'token-de-teste', 'mode' => 'development'],
                'conecta',
            ),
            function () {
                $transporte = new SequencedTransport([]);

                $outcome = $this->envia($transporte, SendAction::Create);

                $this->assertSame([], $transporte->metodos(), 'modo simulado não sai pela rede');
                $this->assertSame(SendResult::Simulated, $outcome->result);
                $this->assertSame('POST', $outcome->method);
            },
        );
    }
}
