<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\FederativeEntitySnapshot;
use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Enum\SendResult;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\IntegrationProvider;
use AldirBlanc\Integration\ValidatesCredential;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\InMemoryProvider;
use Tests\AldirBlanc\Doubles\InMemoryProviderWithCredential;

/** A fronteira que as duas implementações vão satisfazer, exercitada sem rede. */
class IntegrationContractTest extends TestCase
{
    private const DOCUMENTO = '12345678901';

    private function snapshot(array $campos = [], array $entes = []): ManagerSnapshot
    {
        return new ManagerSnapshot($campos, $entes);
    }

    function testDocumentoDesconhecidoDevolveNuloEmVezDeSnapshotVazio()
    {
        $provider = new InMemoryProvider();

        $this->assertNull($provider->fetchManager(new GestorDocument(self::DOCUMENTO)));
    }

    /** Gestor sem ente é situação diferente de gestor inexistente, e a tela reage diferente a cada uma. */
    function testGestorSemEnteDevolveSnapshotComListaVaziaNaoNulo()
    {
        $provider = new InMemoryProvider([self::DOCUMENTO => $this->snapshot(['name' => 'Fulano'])]);

        $snapshot = $provider->fetchManager(new GestorDocument(self::DOCUMENTO));

        $this->assertInstanceOf(ManagerSnapshot::class, $snapshot);
        $this->assertSame([], $snapshot->entities());
    }

    function testCampoPresenteComValorNuloSeDistingueDeCampoAusente()
    {
        $snapshot = $this->snapshot(['name' => null]);

        $this->assertTrue($snapshot->hasName());
        $this->assertNull($snapshot->name());

        $this->assertFalse($snapshot->hasRg());
        $this->assertNull($snapshot->rg());
    }

    function testSnapshotCarregaOsSeisCamposDoMapaDeMetadados()
    {
        $snapshot = $this->snapshot([
            'name' => 'Fulano',
            'rg' => '123',
            'cep' => '57300000',
            'cellphone' => '82999990000',
            'number' => '10',
            'complement' => 'Sala 2',
        ]);

        $this->assertSame('Fulano', $snapshot->name());
        $this->assertSame('123', $snapshot->rg());
        $this->assertSame('57300000', $snapshot->cep());
        $this->assertSame('82999990000', $snapshot->cellphone());
        $this->assertSame('10', $snapshot->number());
        $this->assertSame('Sala 2', $snapshot->complement());
    }

    function testEnteSemArvoreDoParSeDeclaraSemDadosDoPar()
    {
        $comArvore = new FederativeEntitySnapshot('Arapiraca', '12198693000158', [['exercicio' => 2026]]);
        $semArvore = new FederativeEntitySnapshot('Maceió', '12200135000180');

        $this->assertTrue($comArvore->hasParData());
        $this->assertFalse($semArvore->hasParData());
    }

    function testPaginaDoCatalogoInformaSeHaMaisAlemDaFatiaRetornada()
    {
        $provider = new InMemoryProvider(actions: array_fill(0, 5, ['nome_acao' => '1.1 Fomento Cultural']));

        $primeira = $provider->listParActions(0, 2);
        $ultima = $provider->listParActions(4, 2);

        $this->assertCount(2, $primeira->items);
        $this->assertSame(5, $primeira->total);
        $this->assertTrue($primeira->hasMore());
        $this->assertFalse($ultima->hasMore());
    }

    function testDesfechoDoEnvioVemPreenchidoEIdentificaOProvedor()
    {
        $provider = new InMemoryProvider();

        $outcome = $provider->sendOpportunity(new OpportunityId(7), new OpportunityDto(id: 7));

        $this->assertSame(SendResult::Simulated, $outcome->result);
        $this->assertSame(Provider::Gestao, $outcome->provider);
        $this->assertSame('PUT', $outcome->method);
        $this->assertSame([7], [$outcome->payload['id']]);
    }

    function testProvedorSemACapacidadeDeValidarNaoEhPerguntado()
    {
        $provider = new InMemoryProvider();

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertNotInstanceOf(ValidatesCredential::class, $provider);
    }

    function testProvedorComACapacidadeResponde()
    {
        $valido = (new InMemoryProviderWithCredential())->validateCredential();
        $invalido = (new InMemoryProviderWithCredential(false))->validateCredential();

        $this->assertTrue($valido->verified);
        $this->assertTrue($valido->valid);

        $this->assertTrue($invalido->verified);
        $this->assertFalse($invalido->valid);
        $this->assertSame('token recusado pela API', $invalido->reason);
    }

    /** Ausência de resposta nunca pode ser lida como credencial boa. */
    function testCredencialNaoVerificadaNuncaEhValida()
    {
        $check = \AldirBlanc\Dtos\CredentialCheck::notVerified('provedor não valida credencial');

        $this->assertFalse($check->verified);
        $this->assertFalse($check->valid);
    }

    function testCampoForaDoContratoFalhaEmVezDeVirarAusenteSilencioso()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->snapshot(['cellfone' => '82999990000']);
    }

    /** Forma inesperada chega com a API tendo respondido 200: o status não pode se perder. */
    function testErroDeContratoPreservaOStatusDaRespostaBemSucedida()
    {
        $erro = IntegrationError::contract('lista plana não é aceita', [], 200, '[]');

        $this->assertSame(200, $erro->httpStatus());
        $this->assertSame('[]', $erro->rawBody());
    }

    function testErroDeContratoSeDistingueDeCorpoIlegivel()
    {
        $contrato = IntegrationError::contract('lista plana não é aceita', ['recebido' => 'list']);
        $parse = IntegrationError::parse('Resposta da API não é um JSON válido', 200, '<html>');

        $this->assertSame(IntegrationError::KIND_CONTRACT, $contrato->kind());
        $this->assertSame(['recebido' => 'list'], $contrato->details());
        $this->assertSame(IntegrationError::KIND_PARSE, $parse->kind());
    }

    function testSoTransporteEErroDoServidorSaoRetentaveis()
    {
        $this->assertTrue(IntegrationError::transport('timeout', 28)->isRetryable());
        $this->assertTrue(IntegrationError::http('Erro HTTP 500', 500)->isRetryable());

        $this->assertFalse(IntegrationError::http('Erro HTTP 422', 422)->isRetryable());
        $this->assertFalse(IntegrationError::contract('forma inesperada')->isRetryable());
        $this->assertFalse(IntegrationError::configuration('PNAB_CULTBR_HOST', 'sem valor')->isRetryable());
        $this->assertFalse(IntegrationError::parse('corpo ilegível', 200)->isRetryable());
    }
}
