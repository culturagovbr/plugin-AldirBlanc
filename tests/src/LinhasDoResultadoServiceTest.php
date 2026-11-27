<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\LinhasDoResultadoService;
use MapasCulturais\Entities\Registration;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\CapturesLog;
use Tests\Traits\UserDirector;

/** Planilha de contemplados do envio: só vai quando o conjunto inteiro passa no que o validador exige. */
class LinhasDoResultadoServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;
    use CapturesLog;

    private function service(): LinhasDoResultadoService
    {
        return new LinhasDoResultadoService($this->planilha());
    }

    public function testMontaUmaLinhaPorContempladaComONumeroDaInscricao(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contempladaCompleta($raiz, $fase);

        $montado = $this->service()->montar($raiz);

        $this->assertEquals([self::CELULAS_COMPLETAS + ['cpf' => '52998224725']], $montado['linhas']);
        $this->assertSame([(string) $contemplada->number], $montado['inscricoes']);
        $this->assertSame([0, []], [$montado['totalPendentes'], $montado['pendentes']]);
        $this->assertSame('Raça/etnia', $montado['colunas']['raca_etnia']);
    }

    #[DataProvider('ordens')]
    public function testUmaContempladaPendenteSeguraAPlanilhaInteira(bool $isPendentePrimeiro): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $completas = $isPendentePrimeiro ? [] : [$this->contempladaCompleta($raiz, $fase)];
        $pendente = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $isPendentePrimeiro && $this->contempladaCompleta($raiz, $fase);
        $this->numera($pendente, $isPendentePrimeiro ? 'on-1' : 'on-9999999999');

        $montado = $this->service()->montar($raiz);

        $this->assertSame([[], []], [$montado['linhas'], $montado['inscricoes']], 'o validador é atômico: linha pendente derrubaria o resultado');
        $this->assertSame(1, $montado['totalPendentes']);
        $this->assertSame([(string) $pendente->number], array_values(array_unique(array_column($montado['pendentes'], 'inscricao'))));
        $this->assertSame(
            ['tipo' => 'linha', 'indice' => null, 'inscricao' => (string) $pendente->number, 'coluna' => 'Tipo de agente responsável', 'regra' => null, 'descricao' => 'Preenchimento obrigatório.'],
            $montado['pendentes'][0],
        );
    }

    public static function ordens(): array
    {
        return ['pendente primeiro' => [true], 'pendente por último' => [false]];
    }

    /** A montagem segue a ordem do número da inscrição, nas duas fases. */
    private function numera(Registration $contemplada, string $numero): void
    {
        $conn = $this->app->em->getConnection();
        $conn->executeStatement('UPDATE registration SET number = ? WHERE number = ?', [$numero, $contemplada->number]);
        $this->app->em->refresh($contemplada);
    }

    public function testTotalContaTodasAsPendentesEAListaSoAsPrimeiras(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        foreach (range(1, 21) as $ignorado) {
            $this->contemplada($raiz, $fase, []);
        }

        $montado = $this->service()->montar($raiz);

        $this->assertSame(21, $montado['totalPendentes']);
        $this->assertCount(20, array_unique(array_column($montado['pendentes'], 'inscricao')));
    }

    public function testLogDasPendentesNaoIdentificaNinguem(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $pendente = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $registros = $this->capturandoLog(fn() => $this->service()->montar($raiz))->getRecords();
        $log = implode(' ', array_map(fn(LogRecord $registro) => $registro->message, $registros));

        $this->assertStringContainsString('1 contemplada(s) com pendência', $log);
        $this->assertStringNotContainsString((string) $pendente->number, $log);
        $this->assertStringNotContainsString('11144477735', $log);

        [$semPendente, $fase] = $this->editalComCategorias();
        $this->contempladaCompleta($semPendente, $fase);
        $this->assertSame([], $this->capturandoLog(fn() => $this->service()->montar($semPendente))->getRecords());
    }

    public function testEditalSemContempladaNaoTemLinhas(): void
    {
        [$raiz] = $this->editalComCategorias();

        $montado = $this->service()->montar($raiz);

        $this->assertSame([[], 0], [$montado['linhas'], $montado['totalPendentes']]);
    }

    public function testLinhasSaemNaOrdemDoNumeroDaInscricao(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $cpfPorNumero = [
            (string) $this->contempladaCompleta($raiz, $fase, '111.444.777-35')->number => '11144477735',
            (string) $this->contempladaCompleta($raiz, $fase, '529.982.247-25')->number => '52998224725',
        ];
        ksort($cpfPorNumero, SORT_STRING);

        $this->assertSame(array_values($cpfPorNumero), array_column($this->service()->montar($raiz)['linhas'], 'cpf'), 'ordem instável geraria retificado sem nada ter mudado');
    }
}
