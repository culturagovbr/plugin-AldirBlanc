<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use Laminas\Diactoros\Response;
use MapasCulturais\Exceptions\NotFound;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Rota da tela de sincronização (Theme.php do Pnab), restrita a saasSuperAdmin.
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
}
