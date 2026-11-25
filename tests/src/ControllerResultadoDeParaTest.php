<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Exceptions\IntegrationError;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Exceptions\Halt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** GET/POST /aldirblanc/resultadoDePara — colunas do modelo, fontes possíveis e persistência do de/para. */
class ControllerResultadoDeParaTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use LoadsConectaResponses;
    use SendsOpportunityThroughJob;

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

    private function addCampo(Opportunity $opp, string $title, string $type): int
    {
        $this->app->disableAccessControl();
        $campo = new RegistrationFieldConfiguration();
        $campo->owner = $opp;
        $campo->title = $title;
        $campo->fieldType = $type;
        $campo->displayOrder = 1;
        $campo->save(true);
        $this->app->enableAccessControl();

        return (int) $campo->id;
    }

    private function getDePara(Opportunity $raiz): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id];

        return $this->callJson(fn() => $controller->GET_resultadoDePara());
    }

    private function postDePara(Opportunity $raiz, array $mapeamento, mixed $equivalencias = []): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id, 'mapeamento' => $mapeamento, 'equivalencias' => $equivalencias];

        return $this->callJson(fn() => $controller->POST_resultadoDePara());
    }

    private function comModelo(?callable $modelo, callable $exercicio): void
    {
        FakeIntegrationProvider::reset();
        FakeIntegrationProvider::$aoBuscarModelo = $modelo;

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['integration']['formularioCacheTTL'] = 0;

                return $config;
            },
            fn() => $this->comProviderConfigurado(FakeIntegrationProvider::class, $exercicio),
        );
    }

    /** Edital do gestor logado, já integrado ao CultBR ou não. */
    private function edital(bool $comIdDoPar = true): Opportunity
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->createOpportunity($owner);

        if ($comIdDoPar) {
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        }

        $this->login($owner);

        return $raiz;
    }

    public function testGetDevolveAsColunasDoModeloSemMapeamento(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $raiz = $this->edital();
            $this->addCampo($raiz, 'CEP do espaço', 'text');

            $payload = $this->getDePara($raiz);

            $this->assertSame(
                ['possui' => true, 'motivo' => null, 'modelo' => 'pontos_de_cultura', 'nomeModelo' => 'Meta 2, Ação 2.1: Fomento a projetos de Pontos de Cultura'],
                $payload['planilha'],
            );
            $this->assertCount(10, $payload['colunas']);
            $this->assertSame(['cnpj', 'cpf'], array_column(array_filter($payload['colunas'], fn(array $coluna) => $coluna['porTipoDeProponente']), 'coluna'));
            $this->assertSame('CEP do espaço', $payload['campos'][0]['title']);
            $this->assertSame(['inscricao:tipo', 'inscricao:faixa', 'inscricao:valor'], array_column($payload['dadosDaInscricao'], 'fonte'));
            $this->assertContains('Pessoa Física', $payload['tiposDeProponente']);
            $this->assertSame([], $payload['mapeamento']);
            $this->assertStringContainsString('"mapeamento":{},"equivalencias":{},"valoresSemOpcao":{}', (string) $this->app->response->getBody(), 'vazio como [] viraria lista no cliente');
        });
    }

    public function testPostPersisteEOGetPassaADevolverOGravado(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $raiz = $this->edital();
            $cep = $this->addCampo($raiz, 'CEP do espaço', 'text');

            $salvo = $this->postDePara($raiz, ['cep_espaco_cultural' => "campo:{$cep}", 'cota' => null]);

            $this->assertSame(['cep_espaco_cultural' => "campo:{$cep}"], $salvo['mapeamento']);
            $this->assertSame($salvo['mapeamento'], $this->getDePara($raiz)['mapeamento']);
        });
    }

    public function testPostDevolveOCampoQueNaoServeAoDocumentoComOMotivo(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $raiz = $this->edital();
            $selecao = $this->addCampo($raiz, 'Raça/etnia', 'select');

            $salvo = $this->postDePara($raiz, ['cpf' => ['Pessoa Física' => "campo:{$selecao}"]]);

            $this->assertSame(['cpf.Pessoa Física' => 'O tipo deste campo não serve a este documento.'], $salvo['recusas']);
            $this->assertSame([], $salvo['mapeamento']);
        });
    }

    public function testEquivalenciaDeValorDaFonteEGravadaEDevolvida(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $raiz = $this->edital();
            $equivalencias = ['tipo_agente' => ['MEI' => 'Microempreendedor individual - MEI']];

            $salvo = $this->postDePara($raiz, ['tipo_agente' => 'inscricao:tipo'], $equivalencias + ['cota' => ['a' => 'b']]);

            $this->assertSame($equivalencias, $salvo['equivalencias']);
            $this->assertSame(['tipo_agente'], array_keys($salvo['valoresSemOpcao']));
            $this->assertContains('MEI', $salvo['valoresSemOpcao']['tipo_agente'], 'o valor resolvido segue na lista, para poder ser mudado');

            $lido = $this->getDePara($raiz);
            $this->assertSame([$salvo['equivalencias'], $salvo['valoresSemOpcao']], [$lido['equivalencias'], $lido['valoresSemOpcao']]);
        });
    }

    public function testEquivalenciasQueNaoSaoListaNaoDerrubamOMapeamento(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $salvo = $this->postDePara($this->edital(), ['tipo_agente' => 'inscricao:tipo'], 'texto');

            $this->assertSame(['tipo_agente' => 'inscricao:tipo'], $salvo['mapeamento']);
            $this->assertSame([], $salvo['equivalencias']);
        });
    }

    public function testPostVazioDevolveObjeto(): void
    {
        $this->comModelo(fn() => $this->respostaDaConecta('modelo-pontos-de-cultura'), function () {
            $this->postDePara($this->edital(), ['cota' => null]);

            $this->assertStringContainsString('"mapeamento":{},"equivalencias":{},"valoresSemOpcao":{}', (string) $this->app->response->getBody());
        });
    }

    #[DataProvider('semPlanilha')]
    public function testSemPlanilhaVemOMotivoEOPostRecusa(?callable $modelo, bool $comIdDoPar, ?bool $possui, int $status, string $motivo): void
    {
        $this->comModelo($modelo, function () use ($comIdDoPar, $possui, $status, $motivo) {
            $raiz = $this->edital($comIdDoPar);

            $payload = $this->getDePara($raiz);

            $this->assertSame([], $payload['colunas']);
            $this->assertSame($possui, $payload['planilha']['possui'] ?? null);
            $this->assertSame($comIdDoPar, $payload['planilha'] !== null);

            $recusa = $this->postDePara($raiz, ['cota' => 'inscricao:faixa']);

            $this->assertSame($status, $this->responseStatus());
            $this->assertStringContainsString($motivo, $recusa['data']);
        });
    }

    public static function semPlanilha(): array
    {
        return [
            'ação sem modelo' => [fn() => ['possui_planilha' => false, 'motivo' => 'A ação 1.2 não possui modelo de planilha'], true, false, 400, 'não possui modelo'],
            'CultBR indisponível' => [fn() => throw IntegrationError::transport('Connection timed out', 28), true, null, 502, 'Não foi possível consultar'],
            'edital fora do PAR' => [null, false, null, 400, 'não está integrado'],
        ];
    }

    function testPostSemMapeamentoResponde400()
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->createOpportunity($owner);
        $this->login($owner);

        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id];
        $this->callJson(fn() => $controller->POST_resultadoDePara());

        $this->assertSame(400, $this->responseStatus());
    }

    function testSemControleResponde403()
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->createOpportunity($owner);
        $outro = $this->userDirector->createUser();
        $this->login($outro);

        $this->getDePara($raiz);

        $this->assertSame(403, $this->responseStatus());
    }
}
