<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\LinhasBeneficiariosService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Exceptions\Halt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** GET/POST dos beneficiários: página da grade e gravação das células digitadas pelo gestor. */
class ControllerResultadoBeneficiariosTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;
    use SendsOpportunityThroughJob;

    private ?\Closure $modelo = null;

    private function callJson(callable $callback): array
    {
        $this->app->response = new Response();
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarModelo = $this->modelo ?? fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, function () use ($callback) {
                try {
                    $callback();
                    $this->fail('Esperava que o controller encerrasse a resposta com Halt');
                } catch (Halt) {
                }
            }),
        );

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
            ['key' => Controller::REGISTRATION_META_CULT_BR_RESULTADO_PLANILHA_LINHA, 'raiz' => (int) $raiz->id, 'number' => $number],
        );

        return is_string($bruto) ? json_decode($bruto, true)['celulas'] : [];
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
        $this->assertCount(10, $payload['colunas']);
        $this->assertSame('11144477735', $payload['data'][0]['celulas']['cpf']['valor']);
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
        $this->assertCount(10, $payload['colunas']);
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
            'celulas' => ['raca_etnia' => 'Parda'],
        ]);

        $this->assertSame('Parda', $payload['linha']['celulas']['raca_etnia']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $payload['linha']['celulas']['raca_etnia']['fonte']);
        $this->assertSame('Parda', $this->getPagina($raiz)['data'][0]['celulas']['raca_etnia']['valor'], 'o valor persiste entre requisições');
    }

    function testPostApagaACelulaQuandoOValorVemVazio()
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['raca_etnia' => 'Parda']]);
        $payload = $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['raca_etnia' => '']]);

        $this->assertNull($payload['linha']['celulas']['raca_etnia']['valor']);
        $this->assertArrayHasKey('raca_etnia', $payload['linha']['pendencias'], 'apagada, a coluna obrigatória volta a pender');
        $this->assertSame(['raca_etnia' => ''], $this->overrideGravado($raiz, (string) $contemplada->number), 'o vazio fica gravado, para a ficha não repor o que o gestor tirou');
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

        $this->postLinha($raiz, ['registrationId' => $aprovadaNaRaiz->id, 'celulas' => ['raca_etnia' => 'Parda']]);

        $this->assertSame(404, $this->responseStatus(), 'quem endereça a linha é a inscrição da fase final, não uma aprovada de outra fase');
    }

    public function testPostDeixaOGestorCorrigirTodaColunaDoModelo(): void
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $payload = $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['cpf' => '529.982.247-25']]);

        $this->assertSame('52998224725', $payload['linha']['celulas']['cpf']['valor']);
    }

    #[DataProvider('semPlanilha')]
    public function testSemModeloDePlanilhaAGradeRespondeOMotivo(callable $modelo, int $status, string $motivo): void
    {
        [$raiz, $fase, $owner] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);
        $this->modelo = $modelo(...);

        foreach ([$this->getPagina($raiz), $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['raca_etnia' => 'Parda']])] as $resposta) {
            $this->assertSame($status, $this->responseStatus());
            $this->assertStringContainsString($motivo, $resposta['data']);
        }

        $this->assertSame([], $this->overrideGravado($raiz, (string) $contemplada->number));
    }

    public static function semPlanilha(): array
    {
        return [
            'ação sem modelo' => [fn() => ['possui_planilha' => false, 'motivo' => 'A ação 1.2 não possui modelo de planilha'], 400, 'não possui modelo'],
            'CultBR indisponível' => [fn() => throw IntegrationError::transport('Connection timed out', 28), 502, 'Não foi possível consultar'],
        ];
    }

    public function testEditalForaDoParNaoTemGrade(): void
    {
        [$raiz, , $owner] = $this->editalComCategorias();
        $this->login($owner);
        $this->app->em->getConnection()->executeStatement('DELETE FROM opportunity_meta WHERE object_id = :id AND key = :key', [
            'id' => (int) $raiz->id,
            'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID,
        ]);

        $resposta = $this->getPagina($raiz);

        $this->assertSame(400, $this->responseStatus());
        $this->assertStringContainsString('não está integrado', $resposta['data']);
    }

    function testPostEmEditalComResultadoNaoPublicadoResponde409()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Publicacao ' . uniqid());
        $raiz = $this->raizElegivel($owner, $subsite);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        $fase = $this->faseFinal($raiz, publicada: false);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->login($owner);

        $this->postLinha($raiz, ['registrationId' => $contemplada->id, 'celulas' => ['raca_etnia' => 'Parda']]);

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

        $this->postLinha($raiz, ['registrationId' => $deOutroEdital->id, 'celulas' => ['raca_etnia' => 'Parda']]);

        $this->assertSame(404, $this->responseStatus());
    }
}
