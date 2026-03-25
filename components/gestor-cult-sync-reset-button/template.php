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
        @click.prevent="clearLimits"
    >{{ text('buttonLabel') }}</button>
    <p
        v-show="feedbackVisible"
        :id="messageDomId"
        class="aldirblanc-gestor-cult-sync-reset__feedback"
        role="status"
        aria-live="polite"
        :style="{ color: feedbackColor }"
    >{{ feedbackText }}</p>
</div>
