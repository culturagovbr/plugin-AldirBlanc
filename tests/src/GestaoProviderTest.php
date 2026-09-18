<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;
use AldirBlanc\Integration\Gestao\GestaoProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Doubles\FakeTransport;

/** A API de Gestão satisfazendo o contrato, com o transporte substituído. */
class GestaoProviderTest extends TestCase
{
    use ConfiguresPlugin;

    private const HOST = 'http://cultbr.invalid';
    private const DOCUMENTO = '06575305300';

    private function emModoReal(array $valores, callable $exercicio): void
    {
        $trocas = $valores + ['mode' => 'live', 'host' => self::HOST];

        $this->comConfigDoPlugin(
            function (array $config) use ($trocas) {
                return $this->comValoresDoCliente($config, $trocas);
            },
            $exercicio
        );
    }

    private function comResposta(string $json, callable $exercicio): void
    {
        $this->emModoReal([], fn() => $exercicio(new FakeTransport(200, $json)));
    }

    function testSeIdentificaComoAGestao()
    {
        $this->assertSame(Provider::Gestao, (new GestaoProvider())->provider());
    }

    /** O valor curto da configuração passa a ter destino real. */
    function testOValorCurtoGestaoResolveParaEstaImplementacao()
    {
        $provider = (new \AldirBlanc\Integration\ProviderResolver('gestao'))->resolve();

        $this->assertInstanceOf(GestaoProvider::class, $provider);
        $this->assertSame(Provider::Gestao, $provider->provider());
    }

    function testCamposDoGestorViramOContratoEmIngles()
    {
        $json = json_encode([
            'nome' => 'Fulano de Tal',
            'rg' => '123456',
            'cep' => '57300000',
            'celular' => '82999990000',
            'numero' => '10',
            'complemento' => 'Sala 2',
            'entes_federados' => [],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $snapshot = (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));

            $this->assertSame('Fulano de Tal', $snapshot->name());
            $this->assertSame('123456', $snapshot->rg());
            $this->assertSame('57300000', $snapshot->cep());
            $this->assertSame('82999990000', $snapshot->cellphone());
            $this->assertSame('10', $snapshot->number());
            $this->assertSame('Sala 2', $snapshot->complement());
        });
    }

    /** Campo nulo na resposta é diferente de campo que não veio, e a fronteira precisa preservar isso. */
    function testCampoNuloNaRespostaChegaComoPresenteENulo()
    {
        $json = json_encode(['nome' => null, 'entes_federados' => []]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $snapshot = (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));

            $this->assertTrue($snapshot->hasName());
            $this->assertNull($snapshot->name());
            $this->assertFalse($snapshot->hasRg());
        });
    }

    function testEntesVemDoEnvelopeComAGrafiaDaColuna()
    {
        $json = json_encode([
            'entes_federados' => [
                ['name' => 'Arapiraca', 'document' => '12198693000158', 'exercicios' => [['id' => 307]]],
                ['name' => 'Maceió', 'document' => '12200135000180'],
            ],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $entes = (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO))->entities();

            $this->assertCount(2, $entes);
            $this->assertSame('Arapiraca', $entes[0]->name);
            $this->assertSame([['id' => 307]], $entes[0]->exercices);
            $this->assertTrue($entes[0]->hasParData());
            $this->assertFalse($entes[1]->hasParData());
        });
    }

    /** O formato antigo, em que a resposta é a própria lista, segue aceito pela Gestão. */
    function testListaNuaTambemEhAceitaPelaGestao()
    {
        $json = json_encode([['name' => 'Arapiraca', 'document' => '12198693000158']]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $entes = (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO))->entities();

            $this->assertCount(1, $entes);
            $this->assertSame('12198693000158', $entes[0]->document);
        });
    }

    /** Resposta sem a chave e que não é lista falha hoje; a fronteira não pode silenciar isso. */
    function testRespostaSemEntesFederadosENaoListaViraErroDeContrato()
    {
        $this->comResposta(json_encode(['nome' => 'Fulano']), function (FakeTransport $transporte) {
            try {
                (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));
                $this->fail('Esperava erro de contrato');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
                $this->assertStringContainsString('entes_federados', $e->getMessage());
            }
        });
    }

    function testEntesFederadosComFormaErradaViraErroDeContrato()
    {
        $json = json_encode(['entes_federados' => 'nenhum']);

        $this->comResposta($json, function (FakeTransport $transporte) {
            try {
                (new GestaoProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));
                $this->fail('Esperava erro de contrato');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
            }
        });
    }

    function testCatalogoViraPaginaComItensEPaginacao()
    {
        $json = json_encode([
            'pagination' => ['skip' => 0, 'limit' => 1000, 'total' => 3],
            'data' => [
                ['nome_acao' => '1.1 Fomento Cultural'],
                ['nome_acao' => '1.2 Contratação de serviços diretos'],
            ],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $pagina = (new GestaoProvider($transporte))->listParActions(0, 1000);

            $this->assertCount(2, $pagina->items);
            $this->assertSame('1.1 Fomento Cultural', $pagina->items[0]->label);
            $this->assertSame(3, $pagina->total);
            $this->assertTrue($pagina->hasMore());
        });
    }

    function testCatalogoSemAChaveDataViraErroDeContrato()
    {
        $this->comResposta(json_encode(['pagination' => []]), function (FakeTransport $transporte) {
            try {
                (new GestaoProvider($transporte))->listParActions(0, 1000);
                $this->fail('Esperava erro de contrato');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
            }
        });
    }

    /** Modo simulado não é sucesso: o desfecho precisa dizer que nada saiu pela rede. */
    function testEnvioEmModoSimuladoSeDeclaraSimulado()
    {
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['mode'] = 'development';

                return $config;
            },
            function () {
                $outcome = (new GestaoProvider())->sendOpportunity(new OpportunityId(7), new OpportunityDto(id: 7));

                $this->assertSame(SendResult::Simulated, $outcome->result);
                $this->assertNull($outcome->httpStatus);
            }
        );
    }

    /**
     * Falha sobe como exceção tipada carregando o desfecho: sem ele, a tentativa que o client
     * chegou a registrar se perderia e o envio falho ficaria sem linha na aba.
     */
    function testEnvioComErroDoServidorPropagaExcecaoComODesfecho()
    {
        $this->emModoReal(['updateOportunidadeEndpoint' => 'integracao/oportunidades/{id}'], function () {
            $transporte = new FakeTransport(500, 'Internal Server Error');

            try {
                (new GestaoProvider($transporte))
                    ->sendOpportunity(new OpportunityId(7), new OpportunityDto(id: 7));
                $this->fail('Esperava exceção no envio com 500');
            } catch (SendFailed $e) {
                $this->assertSame(IntegrationError::KIND_HTTP, $e->kind());
                $this->assertSame(500, $e->httpStatus());
                $this->assertTrue($e->isRetryable());

                $outcome = $e->outcome();
                $this->assertSame(SendResult::Error, $outcome->result);
                $this->assertSame(500, $outcome->httpStatus);
                $this->assertSame(Provider::Gestao, $outcome->provider);
                $this->assertSame('Internal Server Error', $outcome->response);
                $this->assertSame(self::HOST . '/integracao/oportunidades/7', $outcome->endpoint);
            }
        });
    }

    function testEnvioDevolveODesfechoComOProvedorEOStatus()
    {
        $this->emModoReal(['updateOportunidadeEndpoint' => 'integracao/oportunidades/{id}'], function () {
            $transporte = new FakeTransport(200, '{"ok":true}');

            $outcome = (new GestaoProvider($transporte))
                ->sendOpportunity(new OpportunityId(7), new OpportunityDto(id: 7));

            $this->assertSame(Provider::Gestao, $outcome->provider);
            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame('PUT', $outcome->method);
            $this->assertSame(200, $outcome->httpStatus);
            $this->assertSame(self::HOST . '/integracao/oportunidades/7', $outcome->endpoint);
            $this->assertSame(['PUT'], $transporte->metodos());
        });
    }
}
