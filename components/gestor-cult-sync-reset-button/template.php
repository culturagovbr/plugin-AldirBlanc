<?php

/**
 * Painel user-detail: sync CultBR — overlay mc-teleport-multiple (tema Pnab).
 *
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

?>
<mc-teleport-multiple
    to="body"
    :show="transportShow"
    :messages="syncStepMessages"
    :message-step-ms="syncMessageStepMs"
></mc-teleport-multiple>
<div class="aldirblanc-user-detail__gestor-cult-reset">
    <button
        type="button"
        class="button button--sm aldirblanc-gestor-cult-sync-reset__btn"
        :disabled="loading"
        @click.prevent="openConfirmModal"
    >{{ text('buttonLabel') }}</button>
    <mc-modal
        ref="confirmModal"
        :title="text('confirmModalTitle')"
        classes="aldirblanc-gestor-cult-sync-reset__modal"
        :button-label="text('buttonLabel')"
    >
        <template #button>
            <span class="aldirblanc-gestor-cult-sync-reset__modal-no-default-trigger" aria-hidden="true"></span>
        </template>
        <template #default="modal">
            <div class="aldirblanc-gestor-cult-sync-reset__confirm">
                <div class="aldirblanc-gestor-cult-sync-reset__confirm-icon-wrap">
                    <span class="aldirblanc-gestor-cult-sync-reset__confirm-icon" aria-hidden="true">⚠</span>
                </div>
                <div class="aldirblanc-gestor-cult-sync-reset__confirm-list-wrap">
                    <p class="aldirblanc-gestor-cult-sync-reset__confirm-list-intro">{{ text('confirmModalListIntro') }}</p>
                    <ul class="aldirblanc-gestor-cult-sync-reset__confirm-list">
                        <li>{{ text('confirmModalListItemCache') }}</li>
                        <li>{{ text('confirmModalListItemMetadata') }}</li>
                    </ul>
                </div>
                <p class="aldirblanc-gestor-cult-sync-reset__confirm-warning" role="alert">
                    <span class="aldirblanc-gestor-cult-sync-reset__confirm-attention">{{ text('confirmModalAttention') }}</span><span class="aldirblanc-gestor-cult-sync-reset__confirm-danger">{{ text('confirmModalNextLoginWarning') }}</span>
                </p>
                <p class="aldirblanc-gestor-cult-sync-reset__confirm-question">{{ text('confirmModalQuestion') }}</p>
            </div>
        </template>
        <template #actions="modal">
            <button
                type="button"
                class="button button--text"
                @click="modal.close()"
            >{{ text('confirmModalCancel') }}</button>
            <button
                type="button"
                class="button button--primary"
                :disabled="loading"
                @click="confirmAndClearLimits(modal)"
            >{{ text('confirmModalConfirm') }}</button>
        </template>
    </mc-modal>
    <p
        v-show="feedbackVisible"
        :id="messageDomId"
        class="aldirblanc-gestor-cult-sync-reset__feedback"
        role="status"
        aria-live="polite"
        :style="{ color: feedbackColor }"
    >{{ feedbackText }}</p>
</div>
