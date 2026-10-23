<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\LinhasBeneficiariosService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\Traits\UserDirector;

/** GET/POST dos beneficiários: página da grade e gravação das células digitadas pelo gestor. */
class ControllerResultadoBeneficiariosTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;

    private function callJson(callable $callback): array
    {
        $this->app->response = new Response();

        try {
            $callback();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function getPagina(Opportunity $raiz, array $extra = []): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id] + $extra;

        return $this->callJson(fn() => $controller->GET_resultadoBeneficiarios());
    }

    /** O que está gravado de fato na inscrição de origem, sem passar pelo merge. */
    private function overrideGravado(Opportunity $raiz, string $number): array
    {
        $bruto = $this->app->em->getConnection()->fetchOne(
            'SELECT value FROM registration_meta WHERE key = :key AND object_id = (SELECT id FROM registration WHERE opportunity_id = :raiz AND number = :number)',
            ['key' => Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA, 'raiz' => (int) $raiz->id, 'number' => $number],
        );

        return is_string($bruto) ? (array) json_decode($bruto, true) : [];
    }

    /** Grava o override sem passar pelo endpoint, para montar o estado que antecede a regra. */
    private function gravaDireto(Opportunity $raiz, string $number, array $override): void
    {
        $ancora = $this->app->repo('Registration')->findOneBy(['opportunity' => $raiz, 'number' => $number]);

        $this->app->disableAccessControl();
        $ancora->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA} = $override;
        $ancora->saveMetadata(true);
        $this->app->enableAccessControl();
    }

    private function postLinha(Opportunity $raiz, array $dados): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id] + $dados;

        return $this->callJson(fn() => $controller->POST_resultadoBeneficiarioLinha());
    }

    function testGetDevolveAPaginaComAsColunasDaPlanilha()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $payload = $this->getPagina($raiz);

        $this->assertCount(1, $payload['data']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame(0, $payload['skip']);
        $this->assertCount(count(LinhasBeneficiariosService::COLUNAS), $payload['colunas']);
        $this->assertSame('11144477735', $payload['data'][0]['celulas']['cpf_responsavel_execucao']['valor']);
    }

    function testPostRecusaDataQueQuebraAOrdemDaExecucao()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => (int) $contemplada->id, 'celulas' => ['data_inicio_execucao' => '01/02/2026']]);
        $this->postLinha($raiz, ['registrationId' => (int) $contemplada->id, 'celulas' => ['data_termino_execucao' => '01/01/2026']]);

        $this->assertSame(400, $this->responseStatus(), 'término anterior ao início não chega a ser gravado');

        $linha = $this->getPagina($raiz)['data'][0];
        $this->assertNull($linha['celulas']['data_termino_execucao']['valor'] ?? null, 'e a célula recusada continua vazia');
    }

    function testDataJaForaDaOrdemNaoImpedeEditarAsDemaisColunas()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        // Deixa a linha inconsistente por fora do endpoint, como as gravadas antes de a regra existir.
        $this->postLinha($raiz, ['registrationId' => (int) $contemplada->id, 'celulas' => ['data_inicio_execucao' => '01/02/2026']]);
        $this->gravaDireto($raiz, (string) $contemplada->number, ['data_inicio_execucao' => '01/02/2026', 'data_termino_execucao' => '01/01/2026']);

        $payload = $this->postLinha($raiz, ['registrationId' => (int) $contemplada->id, 'celulas' => ['cep_local_execucao' => '57072-900']]);

        $this->assertSame('57072-900', $payload['linha']['celulas']['cep_local_execucao']['valor'], 'o defeito antigo não pode travar a linha inteira');
    }

    function testGetPorListaDeInscricoesDevolveSoAsPedidas()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $pedida = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');
        $this->login($owner);

        $payload = $this->getPagina($raiz, ['registrationIds' => [(int) $pedida->id]]);

        $this->assertCount(1, $payload['data']);
        $this->assertSame((int) $pedida->id, $payload['data'][0]['registrationId']);
        $this->assertCount(count(LinhasBeneficiariosService::COLUNAS), $payload['colunas']);
        $this->assertSame((int) $fase->id, $payload['faseFinal'], 'é dela que a tabela lista as contempladas');
    }

    function testGetPorListaAceitaIdentificadoresSeparadosPorVirgula()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $pedida = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $payload = $this->getPagina($raiz, ['registrationIds' => "{$pedida->id},0,abc"]);

        $this->assertCount(1, $payload['data'], 'o que não for identificador é descartado em vez de derrubar a consulta');
        $this->assertSame((int) $pedida->id, $payload['data'][0]['registrationId']);
    }

    function testGetPorListaSemControleResponde403()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($this->userDirector->createUser());

        $this->getPagina($raiz, ['registrationIds' => [(int) $contemplada->id]]);

        $this->assertSame(403, $this->responseStatus());
    }

    function testGetLimitaOTamanhoDaPagina()
    {
        [$raiz, , $owner] = $this->editalComCategorias();
        $this->login($owner);

        $this->assertSame(200, $this->getPagina($raiz, ['limit' => 5000])['limit'], 'página gigante derrubaria a tela');
        $this->assertSame(1, $this->getPagina($raiz, ['limit' => 0])['limit']);
    }

    function testGetSemControleResponde403()
    {
        [$raiz] = $this->editalComCategorias();
        $this->login($this->userDirector->createUser());

        $this->getPagina($raiz);

        $this->assertSame(403, $this->responseStatus());
    }

    function testPostGravaACelulaDigitadaEDevolveALinha()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $payload = $this->postLinha($raiz, [
            'registrationId' => $contemplada->id,
            'celulas' => ['data_recebimento_recurso' => '10/01/2026'],
        ]);

        $this->assertSame('10/01/2026', $payload['linha']['celulas']['data_recebimento_recurso']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $payload['linha']['celulas']['data_recebimento_recurso']['fonte']);
        $this->assertSame('10/01/2026', $this->getPagina($raiz)['data'][0]['celulas']['data_recebimento_recurso']['valor'], 'o valor persiste entre requisições');
    }

    function testPostApagaACelulaQuandoOValorVemVazio()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['data_recebimento_recurso' => '10/01/2026']]);
        $payload = $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['data_recebimento_recurso' => '']]);

        $this->assertNull($payload['linha']['celulas']['data_recebimento_recurso']['valor']);
        $this->assertContains('data_recebimento_recurso', $payload['linha']['pendencias'], 'apagada, a coluna obrigatória volta a pender');
        $this->assertSame(['data_recebimento_recurso' => ''], $this->overrideGravado($raiz, (string) $contemplada->number), 'o vazio fica gravado, para a ficha não repor o que o gestor tirou');
    }

    function testPostDeAprovadaForaDaFaseFinalResponde404()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->app->disableAccessControl();
        $aprovadaNaRaiz = $this->inscricao($raiz, ['owner' => ['cpf' => '529.982.247-25']], 'Pessoa Física', '');
        $this->mudaStatus($aprovadaNaRaiz, Registration::STATUS_APPROVED);
        $this->app->enableAccessControl();
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $aprovadaNaRaiz->id, 'celulas' => ['data_recebimento_recurso' => '10/01/2026']]);

        $this->assertSame(404, $this->responseStatus(), 'quem endereça a linha é a inscrição da fase final, não uma aprovada de outra fase');
    }

    function testPostNaoDeixaOGestorEscreverColunaQueOSistemaResolve()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $payload = $this->postLinha($raiz, [
            'registrationId' => $contemplada->id,
            'celulas' => ['cpf_responsavel_execucao' => '00000000000'],
        ]);

        $this->assertSame('11144477735', $payload['linha']['celulas']['cpf_responsavel_execucao']['valor'], 'o CPF continua vindo do cadastro do agente');
    }

    function testPostEmEditalComResultadoNaoPublicadoResponde409()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Publicacao ' . uniqid());
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['data_recebimento_recurso' => '10/01/2026']]);

        $this->assertSame(409, $this->responseStatus(), 'a inscrição existe; o que falta é o resultado publicado');
    }

    function testPostSemCelulasResponde400()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $contemplada->id]);

        $this->assertSame(400, $this->responseStatus());
    }

    function testPostDeInscricaoDeOutroEditalResponde404()
    {
        [$raiz, , $owner] = $this->editalComCategorias();
        [$outraRaiz, $outraFase] = $this->editalComCategorias();
        $deOutroEdital = $this->contempladaComDocumento($outraRaiz, $outraFase, '529.982.247-25');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $deOutroEdital->id, 'celulas' => ['data_recebimento_recurso' => '10/01/2026']]);

        $this->assertSame(404, $this->responseStatus());
    }
}
