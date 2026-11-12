<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Enum\Role;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\CountingParSituationProvider;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\Traits\UserDirector;

class ThemePanelEntityTabsTest extends TestCase
{
    use UserDirector;
    use ConfiguresPlugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login($this->userDirector->createUser([Role::GESTOR_CULT_BR]));
        unset($_SESSION['selectedFederativeEntity']);
        CountingParSituationProvider::$chamadas = 0;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['selectedFederativeEntity']);
        parent::tearDown();
    }

    private function parReasonDasAbas(): ?string
    {
        $view = $this->app->view;
        $controllerAnterior = $view->controller;
        $view->controller = $this->app->controller('panel');

        try {
            $view->import('panel--entity-tabs');
            // printJsObject inteiro esbarra nas dependências de script da suíte; o hook é o que roda os init.php.
            $this->app->applyHookBoundTo($view, 'mapas.printJsObject:before');
        } finally {
            $view->controller = $controllerAnterior;
        }

        return $view->jsObject['config']['panelEntityTabsPnab']['parReason'];
    }

    private function parReasonComProvedorQueConta(): ?string
    {
        $plugin = Plugin::getInstance();
        $parReason = null;

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = CountingParSituationProvider::class;

                return $config;
            },
            function () use ($plugin, &$parReason) {
                $plugin->resetIntegrationProvider();
                try {
                    $parReason = $this->parReasonDasAbas();
                } finally {
                    $plugin->resetIntegrationProvider();
                }
            }
        );

        return $parReason;
    }

    private function selecionaEnteSemArvore(string $checkedAt): void
    {
        $entity = new FederativeEntity();
        $entity->name = 'Ente Sem Árvore';
        $entity->document = '55555555000157';
        $entity->exercices = [];
        $entity->parSituation = ['checkedAt' => (new \DateTime($checkedAt))->format('Y-m-d H:i:s'), 'cadastros' => []];
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $_SESSION['selectedFederativeEntity'] = json_encode(['id' => $entity->id, 'name' => $entity->name, 'document' => $entity->document]);
    }

    public function testSituacaoValidaLevaOMotivoAsAbas(): void
    {
        $this->selecionaEnteSemArvore('-1 hour');

        $this->assertSame('Sem PAR cadastrado', $this->parReasonComProvedorQueConta());
        $this->assertSame(0, CountingParSituationProvider::$chamadas);
    }

    public function testSituacaoVencidaFicaSemMotivoENaoConsultaAConecta(): void
    {
        $this->selecionaEnteSemArvore('-2 days');

        $this->assertNull($this->parReasonComProvedorQueConta());
        $this->assertSame(0, CountingParSituationProvider::$chamadas);
    }

    public function testSemEnteNaSessaoFicaSemMotivo(): void
    {
        $this->assertNull($this->parReasonDasAbas());
    }
}
