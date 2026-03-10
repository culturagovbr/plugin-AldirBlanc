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
        $fraction = $value - $floor;

        return $fraction >= self::ROUND_THRESHOLD ? $floor + 1 : $floor;
    }

    /**
     * Mínimos obrigatórios de vagas por cota da lei (regra geral 25/10/5).
     *
     * @param int $totalVacancies Total de vagas (campo "Total de vagas")
     * @return int[] [min_negras, min_indigenas, min_pcd]
     */
    public static function getNormalMinimums(int $totalVacancies): array
    {
        if ($totalVacancies <= 0) {
            return [0, 0, 0];
        }
        $minBlack = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::BLACK->percent() / 100)
        );
        $minIndigenous = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::INDIGENOUS->percent() / 100)
        );
        $minPcd = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::PCD->percent() / 100)
        );

        return [$minBlack, $minIndigenous, $minPcd];
    }

    /**
     * Mínimos obrigatórios de vagas por cota da lei NO CENÁRIO EXCEPCIONAL DO § 4º:
     * todas as categorias (faixas) com apenas 1 vaga cada, e os percentuais mínimos
     * são aplicados sobre o total de vagas do edital: 25% negras, 10% indígenas, 10% PCD.
     *
     * @param int $totalVacancies Total de vagas (campo "Total de vagas")
     * @return int[] [min_negras, min_indigenas, min_pcd]
     */
    public static function getExceptionCategoryMinimums(int $totalVacancies): array
    {
        if ($totalVacancies <= 0) {
            return [0, 0, 0];
        }

        $minBlack = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::BLACK->percent() / 100)
        );
        $minIndigenous = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::INDIGENOUS->percent() / 100)
        );
        $minPcd = self::roundQuota(
            $totalVacancies * (InMincQuotaPercent::PCD_EXCEPTIONAL->percent() / 100)
        );

        return [$minBlack, $minIndigenous, $minPcd];
    }

    /**
     * Valida o metadado reservaVagasCotas da primeira fase.
     *
     * @param \MapasCulturais\Entities\Opportunity $entity
     * @param array $postData
     * @return array|false Array de erros no formato [ 'reservaVagasCotas' => [msg] ] ou false
     */
    public static function validateQuotasReservation(\MapasCulturais\Entities\Opportunity $entity, array $postData)
    {
        if (empty($entity->id) || !$entity->isFirstPhase) {
            return false;
        }

        $quotas = self::ensureArray($postData['reservaVagasCotas'] ?? ($entity->reservaVagasCotas ?? null));
        $minQuotas = 4; // 3 da lei + Ampla concorrência (sempre última)
        if (count($quotas) < $minQuotas) {
            return ['reservaVagasCotas' => [i::__('Configure todas as cotas ou marque como Não aplicável.')]];
        }

        $allLawQuotasNotApplicable = true;
        foreach ([0, 1, 2] as $lawIndex) {
            $quota = self::ensureArray($quotas[$lawIndex] ?? []);
            if (empty($quota['naoAplicavel'])) {
                $allLawQuotasNotApplicable = false;
                break;
            }
        }
        if ($allLawQuotasNotApplicable) {
            return false;
        }

        $totalVacancies = (int) ($postData['vacancies'] ?? $entity->vacancies ?? 0);

        $registrationRanges = $postData['registrationRanges'] ?? ($entity->registrationRanges ?? []);
        $isParagraph4Exception = false;
        if (is_array($registrationRanges) && !empty($registrationRanges)) {
            $isParagraph4Exception = true;
            foreach ($registrationRanges as $range) {
                $rangeItem = self::ensureArray($range);
                $limit = isset($rangeItem['limit']) && $rangeItem['limit'] !== '' && is_numeric($rangeItem['limit'])
                    ? (int) $rangeItem['limit']
                    : 0;
                if ($limit !== 1) {
                    $isParagraph4Exception = false;
                    break;
                }
            }
        }

        $lawQuotaNames = [
            i::__('Pessoas negras (pretas e pardas)'),
            i::__('Pessoas indígenas'),
            i::__('Pessoas com deficiência'),
        ];
        foreach ([0, 1, 2] as $lawIndex) {
            $quota = self::ensureArray($quotas[$lawIndex] ?? []);
            $notApplicable = !empty($quota['naoAplicavel']);
            if ($notApplicable) {
                $vagasNotApplicable = isset($quota['vagas']) && $quota['vagas'] !== '' && is_numeric($quota['vagas']) ? (int) $quota['vagas'] : 0;
                $valorNotApplicable = isset($quota['valorDestinado']) && $quota['valorDestinado'] !== '' && is_numeric($quota['valorDestinado']) ? (float) $quota['valorDestinado'] : 0.0;

                if ($vagasNotApplicable > 0 || $valorNotApplicable > 0.0) {
                    return ['reservaVagasCotas' => [i::__('Quando a opção "Não aplicável" estiver marcada para uma cota obrigatória, o Número de vagas e o Valor destinado devem ser iguais a zero.')]];
                }

                continue;
            }
            $vagas = isset($quota['vagas']) && $quota['vagas'] !== '' && is_numeric($quota['vagas']) ? (int) $quota['vagas'] : null;
            $valor = isset($quota['valorDestinado']) && $quota['valorDestinado'] !== '' && is_numeric($quota['valorDestinado']) ? (float) $quota['valorDestinado'] : null;
            if ($vagas === null || $valor === null || $vagas < 0 || $valor < 0) {
                return ['reservaVagasCotas' => [i::__('Configure todas as cotas ou marque como Não aplicável.')]];
            }
        }

        if ($totalVacancies >= 1) {
            $vacanciesSum = 0;
            foreach ($quotas as $quota) {
                $quotaItem = self::ensureArray($quota);
                $vacancyCount = isset($quotaItem['vagas']) && $quotaItem['vagas'] !== '' && is_numeric($quotaItem['vagas']) ? (int) $quotaItem['vagas'] : 0;
                $vacanciesSum += $vacancyCount;
            }
            if ($vacanciesSum !== $totalVacancies) {
                return [
                    'reservaVagasCotas' => [
                        sprintf(
                            i::__('A soma das vagas reservadas às cotas deve ser igual ao Total de vagas (Total de vagas: %d; soma informada: %d).'),
                            $totalVacancies,
                            $vacanciesSum
                        ),
                    ],
                ];
            }
        }

        if ($totalVacancies >= 1) {
            if ($isParagraph4Exception) {
                [$minBlack, $minIndigenous, $minPcd] = self::getExceptionCategoryMinimums($totalVacancies);
            } else {
                [$minBlack, $minIndigenous, $minPcd] = self::getNormalMinimums($totalVacancies);
            }
            $minimums = [$minBlack, $minIndigenous, $minPcd];

            $percentByIndex = [
                0 => InMincQuotaPercent::BLACK,
                1 => InMincQuotaPercent::INDIGENOUS,
                2 => $isParagraph4Exception ? InMincQuotaPercent::PCD_EXCEPTIONAL : InMincQuotaPercent::PCD,
            ];

            foreach ([0, 1, 2] as $lawIndex) {
                $quota = self::ensureArray($quotas[$lawIndex] ?? []);
                $notApplicable = !empty($quota['naoAplicavel']);
                if ($notApplicable) {
                    continue;
                }
                $vagas = isset($quota['vagas']) && $quota['vagas'] !== '' && is_numeric($quota['vagas']) ? (int) $quota['vagas'] : 0;
                $minimum = $minimums[$lawIndex];
                if ($vagas < $minimum) {
                    $enumPercent = $percentByIndex[$lawIndex] ?? null;
                    $percentLabel = $enumPercent instanceof InMincQuotaPercent
                        ? sprintf('%.0f%%', $enumPercent->percent())
                        : '';

                    return [
                        'reservaVagasCotas' => [
                            sprintf(
                                i::__('Conforme IN-MinC-10/2023, o número mínimo de vagas para "%s" é %s (Total de vagas: %d; valor informado: %d).'),
                                $lawQuotaNames[$lawIndex],
                                $percentLabel,
                                $totalVacancies,
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

