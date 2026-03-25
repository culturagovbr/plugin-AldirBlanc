const SYNC_MESSAGE_STEP_MS = 1400;
const SYNC_MIN_OVERLAY_MS = 4 * SYNC_MESSAGE_STEP_MS;
/** Ocultar mensagem de sucesso do servidor após este tempo (ms). */
const FEEDBACK_SUCCESS_HIDE_MS = 6000;

app.component('gestor-cult-sync-reset-button', {
    template: $TEMPLATES['gestor-cult-sync-reset-button'],

    setup() {
        const text = Utils.getTexts('gestor-cult-sync-reset-button');
        return { text };
    },

    props: {
        userId: {
            type: Number,
            required: true,
        },
        postUrl: {
            type: String,
            required: true,
        },
        messageDomId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            loading: false,
            transportShow: false,
            syncMessageStepMs: SYNC_MESSAGE_STEP_MS,
            feedbackVisible: false,
            feedbackText: '',
            feedbackColor: '',
            feedbackHideTimerId: null,
        };
    },

    unmounted() {
        this.clearFeedbackHideTimer();
    },

    computed: {
        syncStepMessages() {
            return [
                this.text('verifyingRestrictions'),
                this.text('clearingUserCache'),
                this.text('clearingMetadata'),
                this.text('enablingSync'),
            ];
        },
    },

    methods: {
        clearFeedbackHideTimer() {
            if (this.feedbackHideTimerId != null) {
                clearTimeout(this.feedbackHideTimerId);
                this.feedbackHideTimerId = null;
            }
        },

        scheduleSuccessFeedbackAutoHide() {
            const self = this;
            this.clearFeedbackHideTimer();
            this.feedbackHideTimerId = setTimeout(function () {
                self.feedbackHideTimerId = null;
                self.feedbackVisible = false;
                self.feedbackText = '';
                self.feedbackColor = '';
            }, FEEDBACK_SUCCESS_HIDE_MS);
        },

        async clearLimits() {
            const uid = Number(this.userId);
            if (!Number.isFinite(uid) || uid <= 0) {
                this.clearFeedbackHideTimer();
                this.feedbackVisible = true;
                this.feedbackText = this.text('invalidUserId');
                this.feedbackColor = 'var(--mc-danger-700, #b42318)';
                return;
            }

            this.clearFeedbackHideTimer();
            this.loading = true;
            this.feedbackVisible = false;
            this.feedbackText = '';
            this.transportShow = true;

            const overlayStartedAt = Date.now();
            let feedbackIsSuccess = false;

            try {
                const response = await fetch(this.postUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ userId: uid }),
                });
                const body = await response.json().catch(function () {
                    return {};
                });

                if (response.ok && body.ok) {
                    feedbackIsSuccess = true;
                    this.feedbackText = body.message || '';
                    this.feedbackColor = 'var(--mc-success-700, #0d6832)';
                } else {
                    this.feedbackText =
                        (body && body.message) || this.text('errorGeneric');
                    this.feedbackColor = 'var(--mc-danger-700, #b42318)';
                }
            } catch (e) {
                this.feedbackText = this.text('networkError');
                this.feedbackColor = 'var(--mc-danger-700, #b42318)';
            } finally {
                const elapsed = Date.now() - overlayStartedAt;
                const remaining = Math.max(0, SYNC_MIN_OVERLAY_MS - elapsed);
                if (remaining > 0) {
                    await new Promise(function (resolve) {
                        setTimeout(resolve, remaining);
                    });
                }
                this.transportShow = false;
                this.loading = false;
            }

            this.feedbackVisible = true;

            if (feedbackIsSuccess) {
                this.scheduleSuccessFeedbackAutoHide();
            }
        },
    },
});
