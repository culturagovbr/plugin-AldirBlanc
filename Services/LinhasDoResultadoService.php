<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;

/** Linhas da planilha para o envio do resultado, montadas do mesmo merge que alimenta a grade. */
class LinhasDoResultadoService
{
    /** Edital que não manda planilha. */
    public const SEM_PLANILHA = ['linhas' => [], 'inscricoes' => [], 'colunas' => [], 'totalPendentes' => 0, 'pendentes' => []];

    private const TAMANHO_DO_LOTE = 200;

    /** Contempladas pendentes que o gestor vê listadas; as demais entram só no total. */
    private const PENDENTES_LISTADAS = 20;

    private BeneficiariosService $beneficiarios;
    private LinhasBeneficiariosService $linhas;

    /** @var array<string, string> nome por coluna */
    private array $colunas;

    /** @param array{modelo: ?string, colunas: list<array>} $planilha modelo de planilha do edital */
    public function __construct(array $planilha)
    {
        $this->beneficiarios = new BeneficiariosService($planilha);
        $this->linhas = new LinhasBeneficiariosService($planilha['colunas']);
        $this->colunas = array_column($planilha['colunas'], 'nome', 'coluna');
    }

    /**
     * Todas as linhas, ou só as pendentes: o validador recusa o conjunto inteiro por uma delas.
     *
     * @return array{linhas: list<array>, inscricoes: list<string>, colunas: array<string, string>, totalPendentes: int, pendentes: list<array>}
     */
    public function montar(Opportunity $raiz): array
    {
        $montado = ['colunas' => $this->colunas] + self::SEM_PLANILHA;

        $this->beneficiarios->emLotes($raiz, self::TAMANHO_DO_LOTE, function (array $lote) use (&$montado) {
            foreach ($lote as $linha) {
                $montado = $linha['pendencias'] === [] ? $this->comPronta($montado, $linha) : $this->comPendente($montado, $linha);
            }
        });

        if ($montado['totalPendentes'] > 0) {
            App::i()->log->info(sprintf('[CultBR] Edital %d: %d contemplada(s) com pendência na planilha', (int) $raiz->id, $montado['totalPendentes']));
        }

        return $montado;
    }

    private function comPronta(array $montado, array $linha): array
    {
        if ($montado['totalPendentes'] === 0) {
            $montado['linhas'][] = $this->linhas->paraOEnvio($linha['celulas']);
            $montado['inscricoes'][] = $linha['number'];
        }

        return $montado;
    }

    private function comPendente(array $montado, array $linha): array
    {
        $montado['linhas'] = $montado['inscricoes'] = [];
        $montado['totalPendentes']++;

        if ($montado['totalPendentes'] > self::PENDENTES_LISTADAS) {
            return $montado;
        }

        foreach ($linha['pendencias'] as $coluna => $motivo) {
            $montado['pendentes'][] = [
                'tipo' => 'linha',
                'indice' => null,
                'inscricao' => $linha['number'],
                'coluna' => $this->colunas[$coluna],
                'regra' => null,
                'descricao' => $motivo,
            ];
        }

        return $montado;
    }
}
