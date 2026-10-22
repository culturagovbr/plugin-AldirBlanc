<?php

namespace Tests\AldirBlanc;

use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** GET/POST /aldirblanc/resultadoDePara — campos da ficha, sugestão e persistência do mapeamento. */
class ControllerResultadoDeParaTest extends TestCase
{
    use UserDirector;
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

    private function postDePara(Opportunity $raiz, array $mapeamento): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id, 'mapeamento' => $mapeamento];

        return $this->callJson(fn() => $controller->POST_resultadoDePara());
    }

    function testGetDevolveCamposColunasESugestaoQuandoNaoHaSalvo()
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->createOpportunity($owner);
        $cep = $this->addCampo($raiz, 'CEP do local de execução', 'text');
        $this->login($owner);

        $payload = $this->getDePara($raiz);

        $this->assertSame('CEP do local de execução', $payload['campos'][0]['title']);
        $this->assertContains('cep_local_execucao', array_column($payload['colunas'], 'chave'));
        $this->assertSame($cep, $payload['mapeamento']['cep_local_execucao'], 'sem mapeamento salvo, vem a sugestão');
        $this->assertFalse($payload['temMapeamentoSalvo']);
    }

    function testPostPersisteEOGetPassaADevolverOSalvo()
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->createOpportunity($owner);
        $cep = $this->addCampo($raiz, 'CEP do local de execução', 'text');
        $this->login($owner);

        $salvo = $this->postDePara($raiz, ['cep_local_execucao' => $cep, 'formato_execucao' => null]);
        $this->assertSame($cep, $salvo['mapeamento']['cep_local_execucao']);

        $relido = $this->getDePara($raiz);
        $this->assertTrue($relido['temMapeamentoSalvo']);
        $this->assertSame($cep, $relido['mapeamento']['cep_local_execucao']);
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
