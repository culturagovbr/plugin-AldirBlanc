<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\UserDirector;

/**
 * GET /aldirblanc/opportunities/{id} — guards de entrada, valores monetários do payload
 * e comportamento de cache.
 */
class ControllerIntegrationOpportunityTest extends TestCase
{
    use UserDirector;

    private ?array $originalIntegrationConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIntegrationConfig = $this->readPluginConfig()['integration'] ?? [];
        $psr7 = new ServerRequest([], [], '/aldirblanc/opportunities', 'GET');
        $this->app->request = new Request($psr7, 'aldirblanc', 'integrationOpportunities', []);
    }

    protected function tearDown(): void
    {
        $this->writePluginIntegrationConfig($this->originalIntegrationConfig);
        parent::tearDown();
    }

    private function readPluginConfig(): array
    {
        $ref = new \ReflectionProperty($this->app->plugins['AldirBlanc'], '_config');
        $ref->setAccessible(true);
        return $ref->getValue($this->app->plugins['AldirBlanc']);
    }

    private function writePluginIntegrationConfig(array $integration): void
    {
        $plugin = $this->app->plugins['AldirBlanc'];
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);
        $config = $ref->getValue($plugin);
        $config['integration'] = $integration;
        $ref->setValue($plugin, $config);
    }

    private function controller(int $subsiteId, mixed $id = null): TestableController
    {
        $integration = $this->readPluginConfig()['integration'] ?? [];
        $integration['subsiteId'] = $subsiteId;
        $integration['cacheTTL'] = 300;
        $this->writePluginIntegrationConfig($integration);

        $controller = new TestableController();
        $controller->data = $id === null ? [] : ['id' => $id];
        return $controller;
    }

    /** O método do controlador é privado; a chamada direta evita passar pela validação de token. */
    private function callJson(TestableController $controller): array
    {
        $this->app->response = new Response();
        $metodo = new \ReflectionMethod($controller, '_getIntegrationOpportunities');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($controller);
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function subsite(User $owner): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Pnab ' . uniqid();
        $subsite->url = 'subsite-pnab-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function federativeEntity(string $document, string $name): FederativeEntity
    {
        $this->app->disableAccessControl();
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $this->app->enableAccessControl();
        return $entity;
    }

    private function opportunity(User $user, Subsite $subsite, ?FederativeEntity $entity, array $metadados = []): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade de Integração';
        $opp->shortDescription = 'desc';
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);

        if ($entity) {
            $opp->setMetadata('federativeEntityId', (string) $entity->id);
        }
        foreach ($metadados as $chave => $valor) {
            $opp->setMetadata($chave, $valor);
        }
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    // ===== Guards de entrada =====

    function testIdAusenteRetorna400()
    {
        $payload = $this->callJson($this->controller(1));

        $this->assertSame(400, $this->responseStatus());
        $this->assertSame('ID da oportunidade não informado', $payload['message']);
    }

    function testIdVazioRetorna400()
    {
        $payload = $this->callJson($this->controller(1, ''));

        $this->assertSame(400, $this->responseStatus());
        $this->assertSame('ID da oportunidade não informado', $payload['message']);
    }

    function testOportunidadeInexistenteRetorna404()
    {
        $payload = $this->callJson($this->controller(1, 999999999));

        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('Oportunidade não encontrada', $payload['message']);
    }

    function testIdNaoNumericoRetorna404()
    {
        $payload = $this->callJson($this->controller(1, 'abc'));

        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('Oportunidade não encontrada', $payload['message']);
    }

    function testOportunidadeDeOutroSubsiteRetorna404()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Outro Subsite');
        $opp = $this->opportunity($user, $subsite, $entity);

        $payload = $this->callJson($this->controller($subsite->id + 1000, $opp->id));

        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('Oportunidade não encontrada no subsite configurado', $payload['message']);
    }

    function testOportunidadeSemEnteFederadoRetorna404()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $opp = $this->opportunity($user, $subsite, null);

        $payload = $this->callJson($this->controller($subsite->id, $opp->id));

        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('Oportunidade não tem o federativeEntityId', $payload['message']);
    }

    // ===== Payload =====

    function testOportunidadeElegivelRetorna200ComPayload()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Elegivel');
        $opp = $this->opportunity($user, $subsite, $entity);

        $payload = $this->callJson($this->controller($subsite->id, $opp->id));

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertSame($opp->id, $payload['data']['id']);
        $this->assertSame('Ente Elegivel', $payload['data']['ente_federado']['name']);
    }

    // ===== Valores monetários =====

    function testPayloadContemValorTotalComDuasCasasDecimais()
    {
        $payload = $this->payloadDeOportunidadeCom(['totalResource' => '100698.85']);

        $this->assertSame('100698.85', $payload['data']['valor_total_edital']);
    }

    function testPayloadCompletaComZeroOValorTotalDeUmaCasaDecimal()
    {
        $payload = $this->payloadDeOportunidadeCom(['totalResource' => '32692.7']);

        $this->assertSame('32692.70', $payload['data']['valor_total_edital']);
    }

    function testPayloadContemValorTotalInteiro()
    {
        $payload = $this->payloadDeOportunidadeCom(['totalResource' => '250']);

        $this->assertSame('250.00', $payload['data']['valor_total_edital']);
    }

    function testPayloadSemValorTotalRetornaNul()
    {
        $payload = $this->payloadDeOportunidadeCom([]);

        $this->assertNull($payload['data']['valor_total_edital']);
    }

    function testPayloadContemValorDestinadoDasCotas()
    {
        $payload = $this->payloadDeOportunidadeCom(['reservaVagasCotas' => json_encode([
            ['label' => 'Cota duas casas', 'vagas' => 2, 'valorDestinado' => 32727.12, 'naoAplicavel' => false],
            ['label' => 'Cota uma casa', 'vagas' => 1, 'valorDestinado' => 16363.5, 'naoAplicavel' => false],
        ])]);

        $cotas = $payload['data']['reserva_vagas_cotas'];

        $this->assertSame('32727.12', $cotas[0]['valor_destinado']);
        $this->assertSame('16363.50', $cotas[1]['valor_destinado']);
    }

    function testPayloadContemOsRecursosDeOutrasFontes()
    {
        $payload = $this->payloadDeOportunidadeCom(['recursosOutrasFontes' => json_encode([
            'houveUtilizacao' => 'sim',
            'recursosProprios' => 47011.05,
            'conveniosParcerias' => 11573.28,
            'emendasParlamentares' => 9992.87,
            'remanescentesCiclo1' => 24611.6,
            'outrasFontes' => [
                ['nomeFonte' => 'Fonte A', 'valor' => 1862.19],
                ['nomeFonte' => 'Fonte B', 'valor' => 5849.1],
            ],
        ])]);

        $recursos = $payload['data']['recursos_outras_fontes'];

        $this->assertSame('47011.05', $recursos['recursos_proprios']);
        $this->assertSame('11573.28', $recursos['convenios_parcerias']);
        $this->assertSame('9992.87', $recursos['emendas_parlamentares']);
        $this->assertSame('24611.60', $recursos['remanescentes_ciclo_1']);
        $this->assertSame('1862.19', $recursos['outras_fontes'][0]['valor']);
        $this->assertSame('5849.10', $recursos['outras_fontes'][1]['valor']);
    }

    function testPayloadContemAsCategoriasDoEditalSemNormalizacao()
    {
        $data = $this->payloadDeOportunidadeCom(
            ['totalResource' => '100698.85'],
            $this->categoriasQueSomamOValorTotal()
        )['data'];

        $this->assertSame(81817.82, $data['categorias_edital'][0]['value']);
        $this->assertSame(18881.03, $data['categorias_edital'][1]['value']);
    }

    /** A soma das categorias precisa continuar batendo com o valor total depois de normalizado. */
    function testValorTotalDoPayloadBateComASomaDasCategorias()
    {
        $data = $this->payloadDeOportunidadeCom(
            ['totalResource' => '100698.85'],
            $this->categoriasQueSomamOValorTotal()
        )['data'];

        $soma = array_sum(array_column($data['categorias_edital'], 'value'));

        $this->assertEqualsWithDelta($soma, (float) $data['valor_total_edital'], 0.001);
    }

    /** Duas categorias cuja soma é exatamente 100698.85, o valor total usado nos testes. */
    private function categoriasQueSomamOValorTotal(): array
    {
        return [
            ['label' => 'Categoria A', 'limit' => 5, 'value' => 81817.82],
            ['label' => 'Categoria B', 'limit' => 1, 'value' => 18881.03],
        ];
    }

    // ===== Cache =====

    function testCachePopuladoAposConsulta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache');
        $opp = $this->opportunity($user, $subsite, $entity, ['totalResource' => '100698.85']);

        $cacheKey = "aldirblanc:integration_opportunity:{$opp->id}";
        $this->assertFalse($this->app->cache->contains($cacheKey));

        $this->callJson($this->controller($subsite->id, $opp->id));

        $this->assertTrue($this->app->cache->contains($cacheKey));
    }

    function testValorTotalServidoDoCacheEIgualAoDaPrimeiraConsulta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache Valor');
        $opp = $this->opportunity($user, $subsite, $entity, ['totalResource' => '100698.85']);

        $primeira = $this->callJson($this->controller($subsite->id, $opp->id));
        $segunda = $this->callJson($this->controller($subsite->id, $opp->id));

        $this->assertSame('100698.85', $primeira['data']['valor_total_edital']);
        $this->assertSame($primeira['data']['valor_total_edital'], $segunda['data']['valor_total_edital']);
    }

    function testSegundaChamadaRetornaDoCacheSemReexecutarQuery()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache Hit');
        $opp = $this->opportunity($user, $subsite, $entity);

        $this->callJson($this->controller($subsite->id, $opp->id));

        $sentinela = ['success' => true, 'data' => 'cache_sentinel'];
        $this->app->cache->save("aldirblanc:integration_opportunity:{$opp->id}", $sentinela, 300);

        $segunda = $this->callJson($this->controller($subsite->id, $opp->id));

        $this->assertSame('cache_sentinel', $segunda['data']);
    }

    function testCacheIsoladoPorOportunidade()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache Isolado');
        $opp1 = $this->opportunity($user, $subsite, $entity, ['totalResource' => '100698.85']);
        $opp2 = $this->opportunity($user, $subsite, $entity, ['totalResource' => '32692.7']);

        $primeiro = $this->callJson($this->controller($subsite->id, $opp1->id));
        $segundo = $this->callJson($this->controller($subsite->id, $opp2->id));

        $this->assertSame('100698.85', $primeiro['data']['valor_total_edital']);
        $this->assertSame('32692.70', $segundo['data']['valor_total_edital']);
    }

    private function payloadDeOportunidadeCom(array $metadados, array $categorias = []): array
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Monetario');
        $opp = $this->opportunity($user, $subsite, $entity, $metadados);

        if ($categorias) {
            $this->app->disableAccessControl();
            $opp->registrationRanges = $categorias;
            $opp->save(true);
            $this->app->enableAccessControl();
        }

        return $this->callJson($this->controller($subsite->id, $opp->id));
    }
}
