<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Enum\Role;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\CountingParSituationProvider;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\Traits\UserDirector;

class ThemeFederativeEntityBannerTest extends TestCase
{
    use UserDirector;
    use ConfiguresPlugin;

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

    private function selecionaEnteSemArvore(?array $parSituation): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = 'Ente Sem Árvore';
        $entity->document = '55555555000156';
        $entity->exercices = [];
        $entity->parSituation = $parSituation;
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $_SESSION['selectedFederativeEntity'] = json_encode(['id' => $entity->id, 'name' => $entity->name, 'document' => $entity->document]);

        return $entity;
    }

    public function testEnteSemArvoreLevaOMotivoAoBanner(): void
    {
        $this->selecionaEnteSemArvore(['checkedAt' => (new \DateTime())->format('Y-m-d H:i:s'), 'cadastros' => []]);

        $this->assertSame('Sem PAR cadastrado', $this->renderBanner()['selectedFederativeEntity']['parReason']);
    }

    public function testBannerNaoConsultaAConectaComSituacaoVencida(): void
    {
        $this->selecionaEnteSemArvore(['checkedAt' => (new \DateTime('-2 days'))->format('Y-m-d H:i:s'), 'cadastros' => []]);
        CountingParSituationProvider::$chamadas = 0;
        $plugin = Plugin::getInstance();

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = CountingParSituationProvider::class;

                return $config;
            },
            function () use ($plugin) {
                $plugin->resetIntegrationProvider();
                try {
                    $this->assertNull($this->renderBanner()['selectedFederativeEntity']['parReason']);
                } finally {
                    $plugin->resetIntegrationProvider();
                }
            }
        );

        $this->assertSame(0, CountingParSituationProvider::$chamadas);
    }

    public function testEnteInexistenteNaSessaoFicaSemMotivo(): void
    {
        $this->assertNull($this->renderBanner()['selectedFederativeEntity']['parReason']);
    }
}
