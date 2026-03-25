<?php

declare(strict_types=1);

namespace AldirBlanc\Services;

use AldirBlanc\Enum\Role;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Zera cache/contadores de sync CultBR, última sync no perfil, e (recuperação admin)
 * remove o bloqueio de consolidação quando a API retornou vazio em dev: isNotGestorCultBr + papel GestorCultBr.
 */
final class GestorCultBrSyncLimitResetService
{
    /**
     * Alvo elegível: gestor no subsite atual OU marcado como "API sem entes" após sync vazio (fixture/real).
     */
    public static function isEligibleTarget(App $app, User $targetUser): bool
    {
        $profile = $targetUser->profile;
        if ($profile === null) {
            return false;
        }

        $subsiteId = $app->getCurrentSubsiteId();
        if ($targetUser->is(Role::GESTOR_CULT_BR, $subsiteId)) {
            return true;
        }

        return (bool) $profile->getMetadata('isNotGestorCultBr');
    }

    public static function clearForUser(App $app, User $targetUser): void
    {
        $profile = $targetUser->profile;
        if ($profile === null) {
            return;
        }

        $subsiteId = $app->getCurrentSubsiteId();
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
        if (!$targetUser->is(Role::GESTOR_CULT_BR, $subsiteId)) {
            $targetUser->addRole(Role::GESTOR_CULT_BR, $subsiteId);
        }
        $app->enableAccessControl();
    }
}
