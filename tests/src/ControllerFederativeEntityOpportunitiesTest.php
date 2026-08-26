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
 * GET /aldirblanc/federative-entity/{document}/opportunities — validação JWT, guards de entrada,
 * retorno de payload e comportamento de cache.
 */
class ControllerFederativeEntityOpportunitiesTest extends TestCase
{
    use UserDirector;

    private ?array $originalIntegrationConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIntegrationConfig = $this->readPluginConfig()['integration'] ?? [];
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

    private function controller(int $subsiteId = 0): TestableController
    {
        $integration = $this->readPluginConfig()['integration'] ?? [];
        $integration['subsiteId'] = $subsiteId;
        $integration['cacheTTL'] = 300;
        $this->writePluginIntegrationConfig($integration);
        return new TestableController();
    }

    private function setRequest(string $method, string $document = ''): void
    {
        $url = $document
            ? "/aldirblanc/federative-entity/{$document}/opportunities"
            : '/aldirblanc/federative-entity/opportunities';
        $psr7 = new ServerRequest([], [], $url, $method);
        $this->app->request = new Request($psr7, 'aldirblanc', 'integrationFederativeEntityOpportunities', []);
    }

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

    private function eligibleOpportunity(User $user, Subsite $subsite, FederativeEntity $entity, string $name = 'Oportunidade'): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $opportunityClassName = $user->profile->opportunityClassName;
        $opp = new $opportunityClassName();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = $name;
        $opp->shortDescription = $name;
        $opp->subsite = $subsite;
        $opp->status = Opportunity::STATUS_ENABLED;
        $opp->save(true);
        $opp->setMetadata('federativeEntityId', (string) $entity->id);
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    // ===== Autenticação e método HTTP =====

    function testSemJwtRetorna401()
    {
        $this->setRequest('GET', '12345678901234');
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callIntegrationFederativeEntityOpportunities());

        $this->assertSame(401, $this->responseStatus());
        $this->assertTrue($payload['error']);
    }

    function testMetodoPostRetorna405()
    {
        $this->setRequest('POST', '12345678901234');
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callIntegrationFederativeEntityOpportunities());

        $this->assertSame(405, $this->responseStatus());
        $this->assertTrue($payload['error']);
    }

    // ===== Validação de entrada =====

    function testDocumentAusenteRetorna400()
    {
        $this->setRequest('GET');
        $controller = $this->controller();

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame(400, $this->responseStatus());
        $this->assertTrue($payload['error']);
    }

    // ===== Retorno de dados =====

    function testRetorna200ComArrayVazioQuandoNenhumaOportunidade()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Vazio');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']);
    }

    function testRetorna200ComOportunidadesElegiveis()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Com Opps');
        $opp1 = $this->eligibleOpportunity($user, $subsite, $entity, 'Oportunidade A');
        $opp2 = $this->eligibleOpportunity($user, $subsite, $entity, 'Oportunidade B');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame(200, $this->responseStatus());
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']);

        $ids = array_column($payload['data'], 'id');
        $this->assertContains($opp1->id, $ids);
        $this->assertContains($opp2->id, $ids);
    }

    function testPayloadContemEnteFederadoCorretoEmCadaItem()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente CNPJ');
        $this->eligibleOpportunity($user, $subsite, $entity);

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertCount(1, $payload['data']);
        $item = $payload['data'][0];
        $this->assertArrayHasKey('ente_federado', $item);
        $this->assertSame('Ente CNPJ', $item['ente_federado']['name']);
        $this->assertSame('12345678901234', $item['ente_federado']['document']);
    }

    // ===== Valores monetários =====

    function testPayloadContemValorTotalCorretoEmCadaItem()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Com Valor');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Com Valor');
        $this->gravaValorTotal($opp, '100698.85');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame('100698.85', $payload['data'][0]['valor_total_edital']);
    }

    function testPayloadCompletaComZeroOValorTotalDeUmaCasaDecimal()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Uma Casa');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Uma Casa');
        $this->gravaValorTotal($opp, '32692.7');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame('32692.70', $payload['data'][0]['valor_total_edital']);
    }

    function testPayloadSemValorTotalRetornaNul()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Sem Valor');
        $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Sem Valor');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $payload = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertNull($payload['data'][0]['valor_total_edital']);
    }

    /** O payload atravessa o cache entre uma chamada e outra; o valor não pode mudar no caminho. */
    function testValorTotalServidoDoCacheEIgualAoDaPrimeiraConsulta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache Valor');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Cache Valor');
        $this->gravaValorTotal($opp, '100698.85');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $primeira = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());
        $segunda = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertSame('100698.85', $primeira['data'][0]['valor_total_edital']);
        $this->assertSame($primeira['data'][0]['valor_total_edital'], $segunda['data'][0]['valor_total_edital']);
    }

    function testPayloadContemOsDemaisValoresMonetariosDoEdital()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Monetario');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Monetaria');

        $this->app->disableAccessControl();
        $opp->registrationRanges = [['label' => 'Categoria A', 'limit' => 5, 'value' => 81817.82]];
        $opp->setMetadata('reservaVagasCotas', json_encode([
            ['label' => 'Cota duas casas', 'vagas' => 2, 'valorDestinado' => 32727.12, 'naoAplicavel' => false],
            ['label' => 'Cota uma casa', 'vagas' => 1, 'valorDestinado' => 16363.5, 'naoAplicavel' => false],
        ]));
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $item = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities())['data'][0];

        $this->assertSame(81817.82, $item['categorias_edital'][0]['value']);
        $this->assertSame('32727.12', $item['reserva_vagas_cotas'][0]['valor_destinado']);
        $this->assertSame('16363.50', $item['reserva_vagas_cotas'][1]['valor_destinado']);
    }

    function testPayloadContemOsRecursosDeOutrasFontesDoEdital()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Outras Fontes');
        $opp = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Outras Fontes');

        $this->app->disableAccessControl();
        $opp->setMetadata('recursosOutrasFontes', json_encode([
            'houveUtilizacao' => 'sim',
            'recursosProprios' => 47011.05,
            'conveniosParcerias' => 11573.28,
            'emendasParlamentares' => 9992.87,
            'remanescentesCiclo1' => 24611.6,
            'outrasFontes' => [
                ['nomeFonte' => 'Fonte A', 'valor' => 1862.19],
                ['nomeFonte' => 'Fonte B', 'valor' => 5849.1],
            ],
        ]));
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $recursos = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities())['data'][0]['recursos_outras_fontes'];

        $this->assertSame('47011.05', $recursos['recursos_proprios']);
        $this->assertSame('11573.28', $recursos['convenios_parcerias']);
        $this->assertSame('9992.87', $recursos['emendas_parlamentares']);
        $this->assertSame('24611.60', $recursos['remanescentes_ciclo_1']);
        $this->assertSame('1862.19', $recursos['outras_fontes'][0]['valor']);
        $this->assertSame('5849.10', $recursos['outras_fontes'][1]['valor']);
    }

    /** O endpoint devolve uma lista: cada edital precisa sair com o próprio valor, sem contaminar o vizinho. */
    function testCadaItemDaListaCarregaOProprioValorTotal()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Dois Editais');
        $opp1 = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Valor Um');
        $opp2 = $this->eligibleOpportunity($user, $subsite, $entity, 'Opp Valor Dois');
        $this->gravaValorTotal($opp1, '100698.85');
        $this->gravaValorTotal($opp2, '32692.7');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $data = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities())['data'];
        $valores = array_column($data, 'valor_total_edital', 'id');

        $this->assertSame('100698.85', $valores[$opp1->id]);
        $this->assertSame('32692.70', $valores[$opp2->id]);
    }

    private function gravaValorTotal(Opportunity $opportunity, string $valor): void
    {
        $this->app->disableAccessControl();
        $opportunity->setMetadata('totalResource', $valor);
        $opportunity->save(true);
        $this->app->enableAccessControl();
    }

    // ===== Cache =====

    function testCachePopuladoAposConsulta()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache');

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);

        $cacheKey = "aldirblanc:integration_opportunities:federative_entity:{$subsite->id}:document:{$entity->document}";
        $this->assertFalse($this->app->cache->contains($cacheKey));

        $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());

        $this->assertTrue($this->app->cache->contains($cacheKey));
    }

    function testSegundaChamadaRetornaDoCacheSemReexecutarQuery()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity = $this->federativeEntity('12345678901234', 'Ente Cache Hit');
        $this->eligibleOpportunity($user, $subsite, $entity);

        $this->setRequest('GET', $entity->document);
        $controller = $this->controller($subsite->id);
        $cacheKey = "aldirblanc:integration_opportunities:federative_entity:{$subsite->id}:document:{$entity->document}";

        $result1 = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());
        $this->assertCount(1, $result1['data']);

        // Substitui o cache por uma sentinela — se a segunda chamada bater no banco, não encontrará essa sentinela
        $tamperedResponse = ['success' => true, 'data' => ['cache_sentinel']];
        $this->app->cache->save($cacheKey, $tamperedResponse, 300);

        $result2 = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());
        $this->assertSame(['cache_sentinel'], $result2['data'], 'Segunda chamada deve retornar do cache, não reexecutar a query');
    }

    function testCacheIsoladoPorDocument()
    {
        $user = $this->userDirector->createUser();
        $subsite = $this->subsite($user);
        $entity1 = $this->federativeEntity('11111111111111', 'Ente Isolado Um');
        $entity2 = $this->federativeEntity('22222222222222', 'Ente Isolado Dois');
        $this->eligibleOpportunity($user, $subsite, $entity2, 'Opp do Ente Dois');

        $controller = $this->controller($subsite->id);

        // Consulta entity1 (sem oportunidades) — popula cache vazio para entity1
        $this->setRequest('GET', $entity1->document);
        $result1 = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());
        $this->assertSame([], $result1['data']);

        // Consulta entity2 — não deve usar o cache de entity1
        $this->setRequest('GET', $entity2->document);
        $result2 = $this->callJson(fn() => $controller->callGetIntegrationFederativeEntityOpportunities());
        $this->assertCount(1, $result2['data'], 'Cache de entity1 não deve ser reutilizado para entity2');
    }
}
