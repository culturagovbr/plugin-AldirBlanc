<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use AldirBlanc\Enum\Role;
use MapasCulturais\Entities\AgentRelation;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Tier A6: hooks do Theme.php (Pnab) que reagem à seleção de Ente Federado.
 * Cobre só os hooks cuja checagem de papel/permissão é avaliada *dentro* do corpo do
 * closure (UserAccessService::is*() chamado ao vivo) — não os que capturam $canAccess
 * via `use ($canAccess)` no momento do boot do tema (ver achado em analysis.md:
 * essa captura é congelada no primeiro App::i() do processo de testes, antes de
 * qualquer login de teste, e por isso não reflete o usuário logado durante o teste).
 */
class ThemeFederativeEntityHooksTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['selectedFederativeEntity']);
        unset($_SESSION['federative_entity_redirect_uri']);
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = $name;
        $entity->document = $document;
        $entity->exercices = [];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        return $entity;
    }

    private function persistRelation($agent, FederativeEntity $entity): void
    {
        $relation = new FederativeEntityAgentRelation();
        $relation->agent = $agent;
        $relation->owner = $entity;
        $relation->hasControl = false;
        $relation->status = AgentRelation::STATUS_ENABLED;
        $this->app->em->persist($relation);
        $this->app->em->flush();
    }

    private function selectEntityInSession(FederativeEntity $entity): void
    {
        $_SESSION['selectedFederativeEntity'] = json_encode([
            'id' => $entity->id,
            'name' => $entity->name,
            'document' => $entity->document,
        ]);
    }

    // ===== entity(<<*>>).save:before — stamping de federativeEntityId =====

    function testSaveEstampaFederativeEntityIdQuandoGestorTemSelecao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('11111111111111', 'Ente Um');
        $this->persistRelation($user->profile, $entity);
        $this->selectEntityInSession($entity);

        $this->app->disableAccessControl();
        $user->profile->save(true);
        $this->app->enableAccessControl();

        $this->assertSame($entity->id, $user->profile->getMetadata('federativeEntityId'));
    }

    function testSaveNaoEstampaQuandoNaoHaSelecao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $this->app->disableAccessControl();
        $user->profile->save(true);
        $this->app->enableAccessControl();

        $this->assertNull($user->profile->getMetadata('federativeEntityId'));
    }

    function testSaveNaoEstampaParaUsuarioComumMesmoComSessaoSuja()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $entity = $this->persistFederativeEntity('22222222222222', 'Ente Dois');
        // sessão "suja": chave presente, mas o usuário não é GestorCultBr
        $this->selectEntityInSession($entity);

        $this->app->disableAccessControl();
        $user->profile->save(true);
        $this->app->enableAccessControl();

        $this->assertNull($user->profile->getMetadata('federativeEntityId'));
    }

    // ===== API.find(opportunity).params =====

    private function applyOpportunityParamsHook(array $api_params): array
    {
        $this->app->applyHook('API.find(opportunity).params', [&$api_params]);
        return $api_params;
    }

    function testFindOpportunityAbaComPermissaoRemoveFiltroDeEnteMesmoComFederativeEntityId()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $params = $this->applyOpportunityParamsHook([
            '@permissions' => '@control',
            'user' => '!EQ(@me)',
            'federativeEntityId' => 'EQ(123)',
        ]);

        $this->assertArrayNotHasKey('federativeEntityId', $params);
        $this->assertSame('!EQ(@me)', $params['user'], 'Outros filtros da aba "Com permissão" devem ser preservados');
    }

    function testFindOpportunityGestorSemFederativeEntityIdNaoAplicaFiltroExtra()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $params = $this->applyOpportunityParamsHook(['user' => 'EQ(@me)']);

        $this->assertSame('EQ(@me)', $params['user'], 'Sem federativeEntityId (query nem sessão), comportamento padrão da API deve ser mantido');
        $this->assertArrayNotHasKey('id', $params);
    }

    function testFindOpportunityNaoGestorIgnoraFederativeEntityIdNaSessao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $entity = $this->persistFederativeEntity('33333333333333', 'Ente Três');
        $this->selectEntityInSession($entity);

        $params = $this->applyOpportunityParamsHook(['user' => 'EQ(@me)']);

        $this->assertSame('EQ(@me)', $params['user']);
        $this->assertArrayNotHasKey('id', $params);
    }

    function testFindOpportunityGestorComSelecaoNaSessaoFiltraPorOpportunityMeta()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('44444444444444', 'Ente Quatro');
        $outraEntity = $this->persistFederativeEntity('55555555555555', 'Ente Cinco');
        $this->persistRelation($user->profile, $entity);

        // Cria a oportunidade "de outro ente" SEM seleção ativa, pra evitar que o hook de
        // stamping (entity(<<*>>).save:before) sobrescreva o federativeEntityId manual com o
        // da sessão. Só seleciona o ente sob teste depois, para a chamada do hook em si.
        $this->app->disableAccessControl();
        $opportunityDeOutroEnte = $this->opportunity($user, 'De Outro Ente');
        $opportunityDeOutroEnte->setMetadata('federativeEntityId', $outraEntity->id);
        $opportunityDeOutroEnte->save(true);

        $this->selectEntityInSession($entity);
        $opportunityDoEnte = $this->opportunity($user, 'Da Entidade Selecionada');
        $opportunityDoEnte->setMetadata('federativeEntityId', $entity->id);
        $opportunityDoEnte->save(true);
        $this->app->enableAccessControl();

        $params = $this->applyOpportunityParamsHook(['user' => 'EQ(@me)']);

        $this->assertArrayNotHasKey('user', $params, 'user/owner devem ser removidos ao filtrar por ente');
        $this->assertStringContainsString((string) $opportunityDoEnte->id, $params['id']);
        $this->assertStringNotContainsString((string) $opportunityDeOutroEnte->id, $params['id']);
    }

    private function opportunity($user, string $name): Opportunity
    {
        $opportunityClassName = $user->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $user->profile;
        $opportunity->ownerEntity = $user->profile;
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->name = $name;
        $opportunity->shortDescription = $name;
        $opportunity->save(true);
        return $opportunity;
    }

    // ===== GET(opportunity.findOpportunitiesModels):after =====

    private function applyFindModelsAfterHook(array $result): array
    {
        $this->app->applyHook('GET(opportunity.findOpportunitiesModels):after', [&$result]);
        return $result;
    }

    function testFindModelsAfterNaoGestorNaoFiltra()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $result = $this->applyFindModelsAfterHook([
            ['id' => 1, 'modelIsOfficial' => false],
            ['id' => 2, 'modelIsOfficial' => true],
        ]);

        $this->assertCount(2, $result);
    }

    function testFindModelsAfterGestorSemSelecaoNaoFiltra()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $result = $this->applyFindModelsAfterHook([
            ['id' => 1, 'modelIsOfficial' => false],
            ['id' => 2, 'modelIsOfficial' => true],
        ]);

        $this->assertCount(2, $result);
    }

    function testFindModelsAfterGestorComSelecaoMantemSoModelosDoEnteEOficiais()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);
        $entity = $this->persistFederativeEntity('66666666666666', 'Ente Seis');
        $this->persistRelation($user->profile, $entity);

        // Mesma cautela do teste de API.find(opportunity).params: cria o modelo "de outro ente"
        // sem seleção ativa, senão o hook de stamping sobrescreve com o ente da sessão.
        $this->app->disableAccessControl();
        $modeloDeOutroEnte = $this->opportunity($user, 'Modelo De Outro Ente');
        $modeloDeOutroEnte->setMetadata('isModel', '1');
        $modeloDeOutroEnte->setMetadata('federativeEntityId', 999999);
        $modeloDeOutroEnte->save(true);

        $this->selectEntityInSession($entity);
        $modeloDoEnte = $this->opportunity($user, 'Modelo Do Ente');
        $modeloDoEnte->setMetadata('isModel', '1');
        $modeloDoEnte->setMetadata('federativeEntityId', $entity->id);
        $modeloDoEnte->save(true);
        $this->app->enableAccessControl();

        $result = $this->applyFindModelsAfterHook([
            ['id' => $modeloDoEnte->id, 'modelIsOfficial' => false],
            ['id' => $modeloDeOutroEnte->id, 'modelIsOfficial' => false],
            ['id' => 999, 'modelIsOfficial' => true],
        ]);

        $ids = array_column($result, 'id');
        $this->assertContains($modeloDoEnte->id, $ids, 'Modelo do próprio ente deve permanecer');
        $this->assertContains(999, $ids, 'Modelo oficial deve permanecer mesmo sem federativeEntityId');
        $this->assertNotContains($modeloDeOutroEnte->id, $ids, 'Modelo de outro ente deve ser removido');
    }

    // ===== panel.nav =====
    // Só a parte avaliada ao vivo (UserAccessService::isGestorCultBr()/isSaasSuperAdmin() chamados
    // dentro do corpo do hook). O trecho que usa `$canAccess` direto (linha ~700, sem closure
    // aninhada) sofre da mesma limitação de captura congelada e não é testável aqui.

    private function baseNav(): array
    {
        return [
            'opportunities' => [
                'items' => [
                    ['route' => 'panel/opportunities', 'label' => 'Minhas Oportunidades'],
                    ['route' => 'panel/validations', 'label' => 'Minhas Validações'],
                ],
            ],
        ];
    }

    private function applyPanelNavHook(array $nav): array
    {
        // panel.nav é compartilhado por vários plugins/features do core (avaliações, inscrições etc.),
        // todos registrados no mesmo hook — applyHook roda TODOS os listeners, não só o do Pnab.
        // Por isso buscamos os itens por `route`, nunca por índice fixo.
        $this->app->applyHook('panel.nav', [&$nav]);
        return $nav;
    }

    private function findNavItemByRoute(array $items, string $route): ?array
    {
        foreach ($items as $item) {
            if (($item['route'] ?? null) === $route) {
                return $item;
            }
        }
        return null;
    }

    function testPanelNavUsuarioComumMantemMenusPadrao()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);

        $nav = $this->applyPanelNavHook($this->baseNav());

        // Para usuário comum, o hook do Pnab não deve criar o menu "Ente Federado" nem remover
        // os itens padrão (a remoção é só para GestorCultBr — ver próximo teste). Não comparamos
        // o restante de `$nav['opportunities']['items']` porque outros plugins/features do core
        // também escutam `panel.nav` e podem alterar esses mesmos itens por motivos próprios.
        $this->assertArrayNotHasKey('federativeEntity', $nav);
    }

    function testPanelNavGestorNaoAdminCriaMenuEnteFederadoERemoveMenusPadrao()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR]);
        $this->login($user);

        $nav = $this->applyPanelNavHook($this->baseNav());

        $this->assertArrayHasKey('federativeEntity', $nav);
        $routes = array_column($nav['federativeEntity']['items'], 'route');
        $this->assertContains('panel/opportunities', $routes);
        $this->assertContains('panel/federativeEntityAgents', $routes);
        $this->assertContains('panel/validations', $routes);

        // "Minhas Oportunidades"/"Minhas Validações" do grupo padrão ficam com condition => false
        $oportunidades = $this->findNavItemByRoute($nav['opportunities']['items'], 'panel/opportunities');
        $validacoes = $this->findNavItemByRoute($nav['opportunities']['items'], 'panel/validations');
        $this->assertFalse($oportunidades['condition']());
        $this->assertFalse($validacoes['condition']());
    }

    function testPanelNavGestorQueTambemEhSaasSuperAdminNaoRecebeMenuCustomizado()
    {
        $user = $this->userDirector->createUser([Role::GESTOR_CULT_BR, Role::SAAS_SUPER_ADMIN]);
        $this->login($user);

        $nav = $this->applyPanelNavHook($this->baseNav());

        $this->assertArrayNotHasKey('federativeEntity', $nav);
    }
}
