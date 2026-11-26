<?php

namespace Tests\AldirBlanc;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;

/** A criação, o PATCH e o PUT da oportunidade não gravam o que só o plugin escreve. */
class ApiDoCoreSemChavesDoPluginTest extends TestCase
{
    public static function chavesDoPlugin(): array
    {
        return [
            'respostas do formulário' => ['cultBrResultadoFormData'],
            'último envio e trava' => ['cultBrResultadoUltimoEnvio'],
            'de/para da planilha' => ['cultBrResultadoPlanilhaDePara'],
            'edital no PAR' => ['cultBrParEditalId'],
            'tipo de ação no PAR' => ['cultBrParTipoAcaoId'],
            'resultado vigente no PAR' => ['cultBrParEditalResultadoId'],
        ];
    }

    private function dadosDepoisDoGancho(string $metodo, array $dados, string $acao = 'single'): array
    {
        $this->app->applyHookBoundTo($this->app->controller('opportunity'), "{$metodo}(opportunity.{$acao}):data", ['data' => &$dados]);

        return $dados;
    }

    #[DataProvider('chavesDoPlugin')]
    public function testPatchDescartaAChaveEMantemORestante(string $chave): void
    {
        $dados = $this->dadosDepoisDoGancho('PATCH', ['shortDescription' => 'Descrição nova', $chave => null]);

        $this->assertSame(['shortDescription' => 'Descrição nova'], $dados);
    }

    #[DataProvider('chavesDoPlugin')]
    public function testCriacaoDescartaAChaveEMantemORestante(string $chave): void
    {
        $dados = $this->dadosDepoisDoGancho('POST', ['name' => 'Edital novo', $chave => '53790'], 'index');

        $this->assertSame(['name' => 'Edital novo'], $dados);
    }

    #[DataProvider('chavesDoPlugin')]
    public function testPutDescartaAChaveEMantemORestante(string $chave): void
    {
        $dados = $this->dadosDepoisDoGancho('PUT', ['shortDescription' => 'Descrição nova', $chave => '53790']);

        $this->assertSame(['shortDescription' => 'Descrição nova'], $dados);
    }
}
