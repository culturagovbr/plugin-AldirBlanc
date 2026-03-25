<?php

/**
 * Inserido em template(panel.user-detail.user-mail):begin pelo Plugin AldirBlanc.
 *
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use AldirBlanc\Services\GestorCultBrSyncLimitResetService;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

$app = App::i();

if (!class_exists('Pnab\\Theme') || !$app->view instanceof \Pnab\Theme) {
    return;
}

if (!$app->user->is('superAdmin') && !$app->user->is('saasSuperAdmin')) {
    return;
}

$targetUserId = (int) ($this->controller->data['id'] ?? 0);
if ($targetUserId <= 0) {
    return;
}

/** @var User|null $targetUser */
$targetUser = $app->repo('User')->find($targetUserId);
if ($targetUser === null || !GestorCultBrSyncLimitResetService::isEligibleTarget($targetUser)) {
    return;
}

$postUrl = $app->createUrl('aldirblanc', 'clearGestorCultBrSyncLimits');
$messageDomId = 'aldirblanc-gestor-cult-sync-feedback-' . $targetUserId;

$this->import('
    mc-modal
    mc-teleport-multiple
    gestor-cult-sync-reset-button
');
?>
<gestor-cult-sync-reset-button
    :user-id="<?= (int) $targetUserId ?>"
    post-url="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"
    message-dom-id="<?= htmlspecialchars($messageDomId, ENT_QUOTES, 'UTF-8') ?>"
></gestor-cult-sync-reset-button>
