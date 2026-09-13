<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\Conecta\ConectaProvider;
use AldirBlanc\Integration\ValidatesCredential;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;

/** A API Conecta satisfazendo o contrato, com as diferenças que ela tem em relação à Gestão. */
class ConectaProviderTest extends TestCase
{
    private const HOST = 'http://conecta.invalid';
    private const DOCUMENTO = '06575305300';

    private const CONFIG_BASE = [
        'mode' => 'live',
        'host' => self::HOST,
        'token' => 'token-de-teste',
        'entesEndpoint' => 'auth/pessoa/{document}/entes',
        'parAcoesEndpoint' => 'par/acoes',
        'oportunidadeEndpoint' => 'oportunidades/{id}',
        'validarTokenEndpoint' => 'validar-token',
    ];

    private function comConfig(array $trocas, callable $exercicio): void
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client']['conecta'] ?? null;
        $config['client']['conecta'] = $trocas + self::CONFIG_BASE;
        $ref->setValue($plugin, $config);

        try {
            $exercicio();
        } finally {
            $config = $ref->getValue($plugin);
            $config['client']['conecta'] = $original;
            $ref->setValue($plugin, $config);
        }
    }

    private function comResposta(string $json, callable $exercicio, int $status = 200): void
    {
        $this->comConfig([], fn() => $exercicio(new FakeTransport($status, $json)));
    }

    function testSeIdentificaComoAConecta()
    {
        $this->comConfig([], function () {
            $this->assertSame(Provider::Conecta, (new ConectaProvider())->provider());
        });
    }

    function testOValorCurtoConectaResolveParaEstaImplementacao()
    {
        $provider = (new \AldirBlanc\Integration\ProviderResolver('conecta'))->resolve();

        $this->assertInstanceOf(ConectaProvider::class, $provider);
        $this->assertSame(Provider::Conecta, $provider->provider());
    }

    function testDeclaraSaberValidarAPropriaCredencial()
    {
        $this->assertInstanceOf(ValidatesCredential::class, new ConectaProvider());
    }

    function testUrlDoGestorUsaARotaDaConectaComOCpf()
    {
        $json = json_encode(['entes_federados' => []]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            (new ConectaProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));

            $this->assertSame(
                self::HOST . '/auth/pessoa/' . self::DOCUMENTO . '/entes',
                $transporte->ultimaUrl(),
            );
        });
    }

    /** A Gestão tem rota de caminho idêntico que devolve a lista nua: aceitá-la zera a cascata. */
    function testListaPlanaEhRecusadaComoErroDeContrato()
    {
        $json = json_encode([
            ['name' => 'ESTADO DO PIAUI', 'document' => '06553481000300'],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            try {
                (new ConectaProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));
                $this->fail('Esperava recusa da lista plana');
            } catch (IntegrationError $e) {
                $this->assertSame(IntegrationError::KIND_CONTRACT, $e->kind());
                $this->assertStringContainsString('entes_federados', $e->getMessage());
            }
        });
    }

    function testEnvelopeDaConectaViraSnapshotComOsEntes()
    {
        $json = json_encode([
            'nome' => 'Fulano',
            'celular' => null,
            'entes_federados' => [
                ['name' => 'ESTADO DO PIAUI', 'document' => '06553481000300', 'exercicios' => []],
            ],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $snapshot = (new ConectaProvider($transporte))->fetchManager(new GestorDocument(self::DOCUMENTO));

            $this->assertSame('Fulano', $snapshot->name());
            $this->assertTrue($snapshot->hasCellphone());
            $this->assertNull($snapshot->cellphone());
            $this->assertCount(1, $snapshot->entities());
            $this->assertFalse($snapshot->entities()[0]->hasParData());
        });
    }

    function testCatalogoUsaARotaSemOPrefixoSefic()
    {
        $json = json_encode([
            'pagination' => ['skip' => 0, 'limit' => 1000, 'total' => 1],
            'data' => [['nome_acao' => '1.1 Fomento Cultural']],
        ]);

        $this->comResposta($json, function (FakeTransport $transporte) {
            $pagina = (new ConectaProvider($transporte))->listParActions(0, 1000);

            $this->assertSame(self::HOST . '/par/acoes?skip=0&limit=1000', $transporte->ultimaUrl());
            $this->assertCount(1, $pagina->items);
            $this->assertFalse($pagina->hasMore());
        });
    }

    function testEnvioUsaARotaDaConectaEDevolveODesfecho()
    {
        $this->comResposta('{"ok":true}', function (FakeTransport $transporte) {
            $outcome = (new ConectaProvider($transporte))
                ->sendOpportunity(new OpportunityId(7), new OpportunityDto(id: 7));

            $this->assertSame(Provider::Conecta, $outcome->provider);
            $this->assertSame(SendResult::Success, $outcome->result);
            $this->assertSame(self::HOST . '/oportunidades/7', $outcome->endpoint);
            $this->assertSame(['PUT'], $transporte->metodos());
        });
    }

    function testCredencialValidaQuandoAApiDizQueEhValida()
    {
        $this->comResposta(json_encode(['valido' => true, 'cnpj' => '12198693000158']), function ($transporte) {
            $check = (new ConectaProvider($transporte))->validateCredential();

            $this->assertTrue($check->verified);
            $this->assertTrue($check->valid);
        });
    }

    function testCredencialInvalidaQuandoAApiDizQueNaoEh()
    {
        $this->comResposta(json_encode(['valido' => false]), function ($transporte) {
            $check = (new ConectaProvider($transporte))->validateCredential();

            $this->assertTrue($check->verified);
            $this->assertFalse($check->valid);
        });
    }

    /** Credencial recusada com 401 é resposta, não indisponibilidade: precisa dizer "inválida". */
    function testCredencialRecusadaPorStatusViraInvalidaENaoExcecao()
    {
        $this->comResposta(json_encode(['detail' => 'Token inválido']), function ($transporte) {
            $check = (new ConectaProvider($transporte))->validateCredential();

            $this->assertTrue($check->verified);
            $this->assertFalse($check->valid);
            $this->assertStringContainsString('Token inválido', (string) $check->reason);
        }, status: 401);
    }

    /** Não conseguir perguntar é diferente de ouvir "não": timeout não condena a credencial. */
    function testFalhaDeTransporteNaoCondenaACredencial()
    {
        $this->comConfig([], function () {
            $transporte = new FakeTransport(0, null);
            $check = (new ConectaProvider($transporte))->validateCredential();

            $this->assertFalse($check->verified);
            $this->assertFalse($check->valid);
        });
    }

    function testErroDoServidorNaValidacaoTambemNaoCondenaACredencial()
    {
        $this->comResposta('Internal Server Error', function ($transporte) {
            $check = (new ConectaProvider($transporte))->validateCredential();

            $this->assertFalse($check->verified);
        }, status: 500);
    }

    /** Sem o curto-circuito, cada processo do phpunit esperaria o connect timeout do host fictício. */
    function testEmModoSimuladoACredencialNaoEhVerificadaENemValida()
    {
        $this->comConfig(['mode' => 'development'], function () {
            $check = (new ConectaProvider())->validateCredential();

            $this->assertFalse($check->verified);
            $this->assertFalse($check->valid);
        });
    }
}
