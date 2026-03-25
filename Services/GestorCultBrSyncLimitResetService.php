<?php

declare(strict_types=1);

namespace AldirBlanc\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Zera cache/contadores de sync CultBR e metadados de consolidação no perfil.
 * O papel GestorCultBr é tratado na consolidação de dados, não aqui.
 */
final class GestorCultBrSyncLimitResetService
{
    /**
     * Alvo elegível: qualquer usuário com perfil/agente (permite limpar antes de virar gestor no CultBR).
     */
    public static function isEligibleTarget(User $targetUser): bool
    {
        return $targetUser->profile !== null;
    }

    public static function clearForUser(App $app, User $targetUser): void
    {
        $profile = $targetUser->profile;
        if ($profile === null) {
            return;
        }

        $userId = $targetUser->id;

        $cpfField = $app->auth->getMetadataFieldCpfFromConfig();
        $document = preg_replace('/[^0-9]/', '', (string) $profile->getMetadata($cpfField));

        $cache = $app->cache;
        $cache->delete('gestor_cult_sync:' . $userId . ':' . $document);
        $cache->delete('gestor_cult_sync_lock:' . $userId . ':' . $document);

        $today = date('Y-m-d');
        $cache->delete('gestor_cult_requests:' . $userId . ':' . $today);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $cache->delete('gestor_cult_requests:' . $userId . ':' . $yesterday);

        $app->disableAccessControl();
        $profile->setMetadata('gestorCultBrLastSyncedAt', null);
        $profile->setMetadata('isNotGestorCultBr', false);
        $profile->save(true);

        $app->enableAccessControl();
    }
}
