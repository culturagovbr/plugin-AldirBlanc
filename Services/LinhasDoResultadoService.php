<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;

/**
 * Monta as linhas da planilha de ação cultural para o envio do resultado, a partir do mesmo
 * merge que alimenta a grade. Conjunto incompleto não vai: o validador recusa tudo de uma vez.
 */
class LinhasDoResultadoService
{
    public const TIPO_PLANILHA = 'acao_cultural_realizada';

    private const TAMANHO_DO_LOTE = 200;

    private BeneficiariosService $beneficiarios;
    private LinhasBeneficiariosService $linhas;

    public function __construct()
    {
        $this->beneficiarios = new BeneficiariosService();
        $this->linhas = new LinhasBeneficiariosService();
    }

    /**
     * As linhas prontas para o corpo do resultado, ou null quando alguma contemplada ainda não
     * tem o mínimo que o validador exige — nesse caso o resultado é enviado sem planilha.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function montar(Opportunity $raiz): ?array
    {
        $prontas = [];
        $incompletas = [];

        $this->beneficiarios->emLotes($raiz, self::TAMANHO_DO_LOTE, function (array $lote) use (&$prontas, &$incompletas) {
            foreach ($lote as $linha) {
                $faltas = $this->linhas->faltasParaEnvio($linha['celulas']);

                if ($faltas !== []) {
                    // A varredura segue para contar as incompletas, mas o que estava pronto não vai mais.
                    $incompletas[(string) $linha['number']] = $faltas;
                    $prontas = [];
                    continue;
                }

                if ($incompletas === []) {
                    $prontas[] = $this->linhas->paraOEnvio($linha['celulas']);
                }
            }
        });

        if ($incompletas !== []) {
            $this->registraIncompletas($raiz, $incompletas);

            return null;
        }

        return $prontas === [] ? null : $prontas;
    }

    private function registraIncompletas(Opportunity $raiz, array $incompletas): void
    {
        $amostra = array_slice($incompletas, 0, 5, true);
        $resumo = [];
        foreach ($amostra as $number => $faltas) {
            $resumo[] = $number . ': ' . implode(', ', $faltas);
        }

        App::i()->log->info(sprintf(
            '[CultBR] Edital %d: %d contemplada(s) sem o mínimo para a planilha, resultado segue sem linhas (%s)',
            (int) $raiz->id,
            count($incompletas),
            implode(' | ', $resumo),
        ));
    }
}
