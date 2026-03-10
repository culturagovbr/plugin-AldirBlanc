<?php

namespace AldirBlanc\Services;

use AldirBlanc\Enum\InMincQuotaPercent;
use MapasCulturais\i;

/**
 * Serviço responsável pelas regras de cotas da IN-MinC-10/2023.
 *
 * Centraliza:
 * - regra de arredondamento;
 * - cálculo de mínimos por total de vagas;
 * - validação do metadado reservaVagasCotas da oportunidade.
 */
class InMincQuotasService
{
    private const ROUND_THRESHOLD = 0.5;

    /**
     * Arredondamento: fração >= 0.5 sobe para o inteiro seguinte; senão, desce.
     */
    public static function roundQuota(float $value): int
    {
        $floor = (int) floor($value);
        $frac  = $value - $floor;

        return $frac >= self::ROUND_THRESHOLD ? $floor + 1 : $floor;
    }

    /**
     * Mínimos obrigatórios de vagas por cota da lei (regra geral 25/10/5).
     *
     * @param int $totalVagas Total de vagas (campo "Total de vagas")
     * @return int[] [min_negras, min_indigenas, min_pcd]
     */
    public static function getMinimosNormais(int $totalVagas): array
    {
        if ($totalVagas <= 0) {
            return [0, 0, 0];
        }
        $minNegras = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::BLACK->percent() / 100)
        );
        $minIndigenas = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::INDIGENOUS->percent() / 100)
        );
        $minPcd = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::PCD->percent() / 100)
        );

        return [$minNegras, $minIndigenas, $minPcd];
    }

    /**
     * Mínimos obrigatórios de vagas por cota da lei NO CENÁRIO EXCEPCIONAL DO § 4º:
     * todas as categorias (faixas) com apenas 1 vaga cada, e os percentuais mínimos
     * são aplicados sobre o total de vagas do edital: 25% negras, 10% indígenas, 10% PCD.
     *
     * @param int $totalVagas Total de vagas (campo "Total de vagas")
     * @return int[] [min_negras, min_indigenas, min_pcd]
     */
    public static function getMinimosExcecaoCategoria(int $totalVagas): array
    {
        if ($totalVagas <= 0) {
            return [0, 0, 0];
        }

        $minNegras = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::BLACK->percent() / 100)
        );
        $minIndigenas = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::INDIGENOUS->percent() / 100)
        );
        $minPcd = self::roundQuota(
            $totalVagas * (InMincQuotaPercent::PCD_EXCEPTIONAL->percent() / 100)
        );

        return [$minNegras, $minIndigenas, $minPcd];
    }

    /**
     * Valida o metadado reservaVagasCotas da primeira fase.
     *
     * @param \MapasCulturais\Entities\Opportunity $entity
     * @param array $postData
     * @return array|false Array de erros no formato [ 'reservaVagasCotas' => [msg] ] ou false
     */
    public static function validateReservaVagasCotas(\MapasCulturais\Entities\Opportunity $entity, array $postData)
    {
        if (empty($entity->id) || !$entity->isFirstPhase) {
            return false;
        }

        $cotas = self::ensureArray($postData['reservaVagasCotas'] ?? ($entity->reservaVagasCotas ?? null));
        $minCotas = 4; // 3 da lei + Ampla concorrência (sempre última)
        if (count($cotas) < $minCotas) {
            return ['reservaVagasCotas' => [i::__('Configure todas as cotas ou marque como Não aplicável.')]];
        }

        // Se as 3 cotas obrigatórias da lei estiverem marcadas como "Não aplicável",
        // o ente está optando por não aplicar essas cotas nesta oportunidade/fase.
        // Nesse caso, não fazemos mais nenhuma validação sobre reservaVagasCotas.
        $todasLeiNaoAplicaveis = true;
        foreach ([0, 1, 2] as $idx) {
            $cota = self::ensureArray($cotas[$idx] ?? []);
            if (empty($cota['naoAplicavel'])) {
                $todasLeiNaoAplicaveis = false;
                break;
            }
        }
        if ($todasLeiNaoAplicaveis) {
            return false;
        }

        $totalVagas = (int) ($postData['vacancies'] ?? $entity->vacancies ?? 0);

        // Verifica se estamos no cenário excepcional do § 4º:
        // todas as categorias (faixas/linhas) com exatamente 1 vaga cada.
        $registrationRanges = $postData['registrationRanges'] ?? ($entity->registrationRanges ?? []);
        $isExcecaoParagrafo4 = false;
        if (is_array($registrationRanges) && !empty($registrationRanges)) {
            $isExcecaoParagrafo4 = true;
            foreach ($registrationRanges as $range) {
                $r = self::ensureArray($range);
                $limit = isset($r['limit']) && $r['limit'] !== '' && is_numeric($r['limit'])
                    ? (int) $r['limit']
                    : 0;
                if ($limit !== 1) {
                    $isExcecaoParagrafo4 = false;
                    break;
                }
            }
        }

        // 1) Para cada cota da lei (índices 0, 1, 2): se aplicável, exige vagas e valor preenchidos
        $nomesCotasLei = [
            i::__('Pessoas negras (pretas e pardas)'),
            i::__('Pessoas indígenas'),
            i::__('Pessoas com deficiência'),
        ];
        foreach ([0, 1, 2] as $idx) {
            $cota = self::ensureArray($cotas[$idx] ?? []);
            $naoAplicavel = !empty($cota['naoAplicavel']);
            if ($naoAplicavel) {
                // Quando marcado como "Não aplicável", Número de vagas e Valor destinado
                // DEVEM ser zero. Qualquer valor positivo indica configuração inválida.
                $vagasNaoAplicavel = isset($cota['vagas']) && $cota['vagas'] !== '' && is_numeric($cota['vagas']) ? (int) $cota['vagas'] : 0;
                $valorNaoAplicavel = isset($cota['valorDestinado']) && $cota['valorDestinado'] !== '' && is_numeric($cota['valorDestinado']) ? (float) $cota['valorDestinado'] : 0.0;

                if ($vagasNaoAplicavel > 0 || $valorNaoAplicavel > 0.0) {
                    return ['reservaVagasCotas' => [i::__('Quando a opção "Não aplicável" estiver marcada para uma cota obrigatória, o Número de vagas e o Valor destinado devem ser iguais a zero.')]];
                }

                continue;
            }
            $vagas = isset($cota['vagas']) && $cota['vagas'] !== '' && is_numeric($cota['vagas']) ? (int) $cota['vagas'] : null;
            $valor = isset($cota['valorDestinado']) && $cota['valorDestinado'] !== '' && is_numeric($cota['valorDestinado']) ? (float) $cota['valorDestinado'] : null;
            if ($vagas === null || $valor === null || $vagas < 0 || $valor < 0) {
                return ['reservaVagasCotas' => [i::__('Configure todas as cotas ou marque como Não aplicável.')]];
            }
        }

        // 2) Soma das vagas de todas as cotas deve ser igual ao Total de vagas
        if ($totalVagas >= 1) {
            $somaVagas = 0;
            foreach ($cotas as $cota) {
                $c = self::ensureArray($cota);
                $v = isset($c['vagas']) && $c['vagas'] !== '' && is_numeric($c['vagas']) ? (int) $c['vagas'] : 0;
                $somaVagas += $v;
            }
            if ($somaVagas !== $totalVagas) {
                return [
                    'reservaVagasCotas' => [
                        sprintf(
                            i::__('A soma das vagas reservadas às cotas deve ser igual ao Total de vagas (Total de vagas: %d; soma informada: %d).'),
                            $totalVagas,
                            $somaVagas
                        ),
                    ],
                ];
            }
        }

        // 3) Mínimos da IN-MinC-10/2023: quando a cota está aplicável, vagas >= mínimo
        if ($totalVagas >= 1) {
            if ($isExcecaoParagrafo4) {
                // Art. 6º, § 4º: totais mínimos 25% negras, 10% indígenas, 10% PCD no edital
                [$minNegras, $minIndigenas, $minPcd] = self::getMinimosExcecaoCategoria($totalVagas);
            } else {
                [$minNegras, $minIndigenas, $minPcd] = self::getMinimosNormais($totalVagas);
            }
            $minimos = [$minNegras, $minIndigenas, $minPcd];

            // Mapeia cada índice de cota da lei para o percentual correspondente
            $percentualPorIndice = [
                0 => InMincQuotaPercent::BLACK,
                1 => InMincQuotaPercent::INDIGENOUS,
                2 => $isExcecaoParagrafo4 ? InMincQuotaPercent::PCD_EXCEPTIONAL : InMincQuotaPercent::PCD,
            ];

            foreach ([0, 1, 2] as $idx) {
                $cota = self::ensureArray($cotas[$idx] ?? []);
                $naoAplicavel = !empty($cota['naoAplicavel']);
                if ($naoAplicavel) {
                    continue;
                }
                $vagas = isset($cota['vagas']) && $cota['vagas'] !== '' && is_numeric($cota['vagas']) ? (int) $cota['vagas'] : 0;
                $minimo = $minimos[$idx];
                if ($vagas < $minimo) {
                    $enumPercent = $percentualPorIndice[$idx] ?? null;
                    $percentLabel = $enumPercent instanceof InMincQuotaPercent
                        ? sprintf('%.0f%%', $enumPercent->percent())
                        : '';

                    return [
                        'reservaVagasCotas' => [
                            sprintf(
                                i::__('Conforme IN-MinC-10/2023, o número mínimo de vagas para "%s" é %s (Total de vagas: %d; valor informado: %d).'),
                                $nomesCotasLei[$idx],
                                $percentLabel,
                                $totalVagas,
                                $vagas
                            ),
                        ],
                    ];
                }
            }
        }

        return false;
    }

    /**
     * Garante valor como array (objeto JSON vindo do banco vira array associativo).
     *
     * @param mixed $value
     * @return array
     */
    private static function ensureArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $decoded = json_decode(json_encode($value), true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

