<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use AldirBlanc\Entities\FederativeEntity;

class FederativeEntityService
{
    private const EXERCICES_METAS_KEY = 'metas';
    private const EXERCICES_ACOES_KEY = 'acoes';
    private const PAR_ACTION_NAME_KEY = 'nome';

    public static function getSelectedFederativeEntityIdFromSession(): ?int
    {
        if (!UserAccessService::isGestorCultBr() || !isset($_SESSION['selectedFederativeEntity'])) {
            return null;
        }

        $selected = json_decode($_SESSION['selectedFederativeEntity'], true);
        if (!is_array($selected) || empty($selected['id'])) {
            return null;
        }

        return (int) $selected['id'];
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function getParExerciciosForFederativeEntityId(int $id): array
    {
        $ente = App::i()->em->getRepository(FederativeEntity::class)->find($id);
        if (!$ente instanceof FederativeEntity) {
            return [];
        }

        $ex = $ente->exercices;
        return is_array($ex) ? $ex : [];
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function getParExerciciosForSessionSelectedEntity(): array
    {
        $id = self::getSelectedFederativeEntityIdFromSession();
        return $id !== null ? self::getParExerciciosForFederativeEntityId($id) : [];
    }

    /**
     * @return string[]
     */
    public static function getParActionNamesForSessionSelectedEntity(): array
    {
        $actions = [];

        foreach (self::getParExerciciosForSessionSelectedEntity() as $exercice) {
            if (!is_array($exercice) || empty($exercice[self::EXERCICES_METAS_KEY]) || !is_array($exercice[self::EXERCICES_METAS_KEY])) {
                continue;
            }

            foreach ($exercice[self::EXERCICES_METAS_KEY] as $meta) {
                if (!is_array($meta) || empty($meta[self::EXERCICES_ACOES_KEY]) || !is_array($meta[self::EXERCICES_ACOES_KEY])) {
                    continue;
                }

                foreach ($meta[self::EXERCICES_ACOES_KEY] as $action) {
                    if (!is_array($action) || empty($action[self::PAR_ACTION_NAME_KEY])) {
                        continue;
                    }

                    $actions[] = trim((string) $action[self::PAR_ACTION_NAME_KEY]);
                }
            }
        }

        $actions = array_values(array_unique(array_filter($actions)));
        usort($actions, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $actions;
    }
}
