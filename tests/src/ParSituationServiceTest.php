<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Integration\FetchesParSituation;
use AldirBlanc\Services\ParSituationService;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;

class ParSituationServiceTest extends TestCase
{
    use CapturesLog;
    use ConfiguresPlugin;

    private function ente(array $exercices = [], ?array $parSituation = null): FederativeEntity
    {
        $entity = new FederativeEntity();
        $entity->name = 'Ente da Situação';
        $entity->document = '18174562000460';
        $entity->exercices = $exercices;
        $entity->parSituation = $parSituation;
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();

        return $entity;
    }

    /** @param list<array>|\Throwable $resposta */
    private function provedor(array|\Throwable $resposta): FetchesParSituation
    {
        return new class ($resposta) implements FetchesParSituation {
            public int $chamadas = 0;

            public function __construct(private array|\Throwable $resposta)
            {
            }

            public function fetchParSituation(string $cnpj): array
            {
                $this->chamadas++;
                if ($this->resposta instanceof \Throwable) {
                    throw $this->resposta;
                }

                return $this->resposta;
            }
        };
    }

    private function consultadaHa(string $intervalo, array $cadastros): array
    {
        return ['checkedAt' => (new \DateTime($intervalo))->format('Y-m-d H:i:s'), 'cadastros' => $cadastros];
    }

    public function testEnteComArvoreNaoConsultaAConecta(): void
    {
        $provedor = $this->provedor([]);

        $this->assertNull((new ParSituationService($provedor))->reasonFor($this->ente([['id' => 1, 'ano' => 2025]])));
        $this->assertSame(0, $provedor->chamadas);
    }

    public function testEnteSemCadastroNoParDizQueNaoTemPar(): void
    {
        $entity = $this->ente();

        $this->assertSame('Sem PAR cadastrado', (new ParSituationService($this->provedor([])))->reasonFor($entity));
        $this->app->em->clear();
        $gravada = $this->app->repo(FederativeEntity::class)->find($entity->id)->parSituation;
        $this->assertSame([], $gravada['cadastros']);
        $this->assertNotEmpty($gravada['checkedAt']);
    }

    public function testMotivoUsaOCadastroMaisRecenteQueNaoFoiExcluido(): void
    {
        $provedor = $this->provedor([
            ['ano' => 2025, 'situacao' => 'Alteração solicitada pelo MinC', 'excluido' => false],
            ['ano' => 2027, 'situacao' => 'Habilitado', 'excluido' => true],
            ['ano' => 2026, 'situacao' => 'Em complementação pelo ente', 'excluido' => false],
        ]);

        $this->assertSame('PAR 2026: Em complementação pelo ente', (new ParSituationService($provedor))->reasonFor($this->ente()));
    }

    public function testCadastroSemSituacaoInformadaDizIsso(): void
    {
        $provedor = $this->provedor([['ano' => 2026, 'situacao' => null, 'excluido' => false]]);

        $this->assertSame('PAR 2026 sem situação informada', (new ParSituationService($provedor))->reasonFor($this->ente()));
    }

    public function testSoCadastrosExcluidosContamComoSemPar(): void
    {
        $provedor = $this->provedor([['ano' => 2025, 'situacao' => 'Habilitado', 'excluido' => true]]);

        $this->assertSame('Sem PAR cadastrado', (new ParSituationService($provedor))->reasonFor($this->ente()));
    }

    public function testCacheDentroDoPrazoNaoConsultaAConecta(): void
    {
        $provedor = $this->provedor([]);
        $entity = $this->ente([], $this->consultadaHa('-23 hours', [['ano' => 2026, 'situacao' => 'Em análise', 'excluido' => false]]));

        $this->assertSame('PAR 2026: Em análise', (new ParSituationService($provedor))->reasonFor($entity));
        $this->assertSame(0, $provedor->chamadas);
    }

    public function testCacheVencidoConsultaDeNovoEGravaOResultado(): void
    {
        $provedor = $this->provedor([['ano' => 2026, 'situacao' => 'Habilitado', 'excluido' => false]]);
        $entity = $this->ente([], $this->consultadaHa('-25 hours', []));

        $this->assertSame('PAR 2026: Habilitado', (new ParSituationService($provedor))->reasonFor($entity));
        $this->assertSame(1, $provedor->chamadas);
        $this->app->em->clear();
        $this->assertSame('Habilitado', $this->app->repo(FederativeEntity::class)->find($entity->id)->parSituation['cadastros'][0]['situacao']);
    }

    public function testFalhaDaConectaPreservaOGravadoEFicaSemMotivo(): void
    {
        $gravada = $this->consultadaHa('-25 hours', [['ano' => 2025, 'situacao' => 'Em análise', 'excluido' => false]]);
        $entity = $this->ente([], $gravada);
        $service = new ParSituationService($this->provedor(new \RuntimeException('Conecta fora do ar')));

        $capturado = $this->capturandoLog(fn() => $this->assertNull($service->reasonFor($entity)));

        $this->assertTrue($capturado->hasWarningThatContains('Situação do PAR indisponível | Ente: 18174562000460'));
        $this->app->em->clear();
        $depois = $this->app->repo(FederativeEntity::class)->find($entity->id)->parSituation;
        $this->assertEquals($gravada['cadastros'], $depois['cadastros']);
        $this->assertSame($gravada['checkedAt'], $depois['checkedAt']);
        $this->assertNotEmpty($depois['failedAt'] ?? null, 'a hora da falha segura novas tentativas');
    }

    public function testFalhaRecenteNaoConsultaDeNovo(): void
    {
        $provedor = $this->provedor([]);
        $entity = $this->ente([], ['failedAt' => (new \DateTime('-30 minutes'))->format('Y-m-d H:i:s')]);

        $this->assertNull((new ParSituationService($provedor))->reasonFor($entity));
        $this->assertSame(0, $provedor->chamadas);
    }

    public function testFalhaAntigaPermiteConsultarDeNovo(): void
    {
        $provedor = $this->provedor([]);
        $entity = $this->ente([], ['failedAt' => (new \DateTime('-2 hours'))->format('Y-m-d H:i:s')]);

        $this->assertSame('Sem PAR cadastrado', (new ParSituationService($provedor))->reasonFor($entity));
        $this->assertSame(1, $provedor->chamadas);
    }

    public function testLeituraSoDoGravadoNuncaConsultaAConecta(): void
    {
        $provedor = $this->provedor([]);
        $service = new ParSituationService($provedor);

        $this->assertSame('Sem PAR cadastrado', $service->storedReasonFor($this->ente([], $this->consultadaHa('-1 hour', []))));
        $this->assertNull($service->storedReasonFor($this->ente([], $this->consultadaHa('-25 hours', []))), 'vencido não vira motivo');
        $this->assertNull($service->storedReasonFor($this->ente()), 'sem nada gravado não consulta');
        $this->assertNull($service->storedReasonFor($this->ente([['id' => 1]], $this->consultadaHa('-1 hour', []))), 'ente com árvore não tem motivo');
        $this->assertSame(0, $provedor->chamadas);
    }

    public function testProvedorQueNaoInformaASituacaoNaoGeraAviso(): void
    {
        $entity = $this->ente();

        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = 'gestao';
                return $config;
            },
            function () use ($entity) {
                $capturado = $this->capturandoLog(fn() => $this->assertNull((new ParSituationService())->reasonFor($entity)));

                $this->assertFalse($capturado->hasWarningThatContains('Situação do PAR indisponível'));
            },
        );
    }
}
