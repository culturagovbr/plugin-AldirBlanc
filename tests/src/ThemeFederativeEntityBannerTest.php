<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\Role;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class ThemeFederativeEntityBannerTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login($this->userDirector->createUser([Role::GESTOR_CULT_BR]));
        $_SESSION['selectedFederativeEntity'] = json_encode(['id' => 0, 'name' => 'Ente Selecionado', 'document' => '55555555000155']);
        unset($_SESSION['gestor_cult_sync_degraded']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['selectedFederativeEntity'], $_SESSION['gestor_cult_sync_degraded']);
        parent::tearDown();
    }

    private function renderBanner(): \ArrayObject
    {
        $controllerAnterior = $this->app->view->controller;
        $this->app->view->controller = $this->app->controller('panel');

        ob_start();
        try {
            $this->app->view->part('federative-entity-banner');
        } finally {
            ob_end_clean();
            $this->app->view->controller = $controllerAnterior;
        }

        return $this->app->view->jsObject;
    }

    public function testSessaoDegradadaChegaAoBanner(): void
    {
        $_SESSION['gestor_cult_sync_degraded'] = true;

        $this->assertTrue($this->renderBanner()['config']['cultBrSyncDegraded']);
    }

    public function testSessaoNormalNaoMarcaOBanner(): void
    {
        $this->assertFalse($this->renderBanner()['config']['cultBrSyncDegraded']);
    }
}
