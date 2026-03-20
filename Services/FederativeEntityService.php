<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use AldirBlanc\Entities\FederativeEntity;

class FederativeEntityService
{
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
}
