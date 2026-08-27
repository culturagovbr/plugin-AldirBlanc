<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use MapasCulturais\Exceptions\NotFound;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Rota e item de menu da tela de sincronização (Theme.php do Pnab), restritos a saasSuperAdmin.
 */
class ThemeOpportunitiesSyncPanelTest extends TestCase
{
    use UserDirector;

    /** A rota renderiza, então o contexto de view precisa existir: sem ele o render quebra antes da asserção. */
    private function callPanelRoute(): string
    {
        $controller = $this->app->controller('panel');
        $controller->action = 'opportunitiesSync';
        $this->app->view->controller = $controller;
        $this->app->response = new Response();

        $this->app->applyHookBoundTo($controller, 'GET(panel.opportunitiesSync)');

        return (string) $this->app->response->getBody();
    }

    private function applyPanelNavHook(array $nav): array
    {
        $this->app->applyHook('panel.nav', [&$nav]);

        return $nav;
    }

    /** O item do Pnab só é acrescentado quando o grupo admin já existe no menu do core. */
    private function baseNav(): array
    {
        return [
            'admin' => [
                'items' => [
                    ['route' => 'panel/users', 'label' => 'Usuários'],
                ],
            ],
        ];
    }

    private function adminRoutes(array $nav): array
    {
        return array_column($nav['admin']['items'], 'route');
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

    function testRotaPassaAdianteParaUsuarioComum()
    {
        $this->login($this->userDirector->createUser());

        $this->expectException(NotFound::class);
        $this->callPanelRoute();
    }

    function testRotaPassaAdianteParaAdminDoSubsite()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $this->expectException(NotFound::class);
        $this->callPanelRoute();
    }

    function testRotaRenderizaAViewDaSincronizacaoParaSaasSuperAdmin()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $saida = $this->callPanelRoute();

        $this->assertStringContainsString('Sincronização', $saida, 'A view renderizada precisa ser a da sincronização');
        $this->assertStringContainsString('reenviar oportunidades publicadas ao CultBR', $saida);
    }

    function testMenuApareceParaSaasSuperAdminDepoisDeEntesFederados()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $nav = $this->applyPanelNavHook($this->baseNav());
        $rotas = $this->adminRoutes($nav);

        $this->assertContains('panel/opportunitiesSync', $rotas);
        $this->assertGreaterThan(
            array_search('panel/federativeEntities', $rotas, true),
            array_search('panel/opportunitiesSync', $rotas, true),
            'Sincronização vem logo depois de Entes Federados'
        );
    }

    function testMenuTemRotuloEIconeProprios()
    {
        $this->login($this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]));

        $nav = $this->applyPanelNavHook($this->baseNav());
        $item = $this->findNavItemByRoute($nav['admin']['items'], 'panel/opportunitiesSync');

        $this->assertSame('Sincronização', $item['label']);
        $this->assertSame('sync', $item['icon']);
    }

    function testMenuNaoApareceParaAdminDoSubsite()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $nav = $this->applyPanelNavHook($this->baseNav());
        $item = $this->findNavItemByRoute($nav['admin']['items'], 'panel/opportunitiesSync');

        $this->assertNotNull($item, 'O item é acrescentado, mas condicionado ao papel');
        $this->assertFalse(($item['condition'])(), 'admin do subsite não pode ver a Sincronização');
    }
}
