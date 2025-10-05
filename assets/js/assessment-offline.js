/**
 * Assessment Offline Support Module
 * Handles localStorage caching, sync queue, and offline functionality
 */

class AssessmentOfflineManager {
    constructor(config) {
        this.assessmentId = config.assessmentId;
        this.attemptId = config.attemptId;
        this.csrfToken = config.csrfToken;
        this.baseUrl = config.baseUrl;

        this.isOnline = navigator.onLine;
        this.syncQueue = new Set();
        this.activeSyncs = new Map();

        // localStorage keys
        this.STORAGE_KEY = `assessment_${this.assessmentId}_attempt_${this.attemptId}`;
        this.SYNC_STATUS_KEY = `${this.STORAGE_KEY}_sync_status`;

        this.init();
    }

    init() {
        // Monitor online/offline status
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());

        // Prevent page refresh/close when offline or has unsynced answers
        window.addEventListener('beforeunload', (e) => this.handleBeforeUnload(e));

        // Initial status
        this.updateConnectionStatus();

        // Periodic sync attempt (every 30 seconds)
        setInterval(() => {
            if (this.isOnline && this.syncQueue.size > 0) {
                this.syncAllPendingAnswers();
            }
        }, 30000);

        // Restore local answers on page load
        this.restoreLocalAnswers();
    }

    handleOnline() {
        this.isOnline = true;
        this.updateConnectionStatus();
        this.hideOfflineBanner();

        // Show notification via callback
        if (this.onConnectionRestored) {
            this.onConnectionRestored();
        }

        // Sync all pending answers
        this.syncAllPendingAnswers();
    }

    handleOffline() {
        this.isOnline = false;
        this.updateConnectionStatus();
        this.showOfflineBanner();

        // Show notification via callback
        if (this.onConnectionLost) {
            this.onConnectionLost();
        }
    }

    handleBeforeUnload(e) {
        // Check if assessment is already submitted
        if (window.assessmentSubmitted) {
            return; // Allow navigation after successful submission
        }

        const unsyncedCount = this.getUnsyncedCount();

        // Warn if offline or has unsynced answers
        if (!this.isOnline) {
            e.preventDefault();
            e.returnValue = 'You are currently offline. Refreshing the page will prevent you from continuing this assessment. Your answers are saved locally but you cannot reload the questions while offline.';
            return e.returnValue;
        }

        if (unsyncedCount > 0) {
            e.preventDefault();
            e.returnValue = `You have ${unsyncedCount} answer(s) not synced to the server. Please wait for syncing to complete before leaving this page.`;
            return e.returnValue;
        }

        // Also warn if there are any answered questions (general safety)
        const answeredCount = document.querySelectorAll('.question-answered').length;
        if (answeredCount > 0) {
            e.preventDefault();
            e.returnValue = 'Your assessment is in progress. Are you sure you want to leave?';
            return e.returnValue;
        }
    }

    showOfflineBanner() {
        // Check if banner already exists
        if (document.getElementById('offlineBanner')) return;

        const banner = document.createElement('div');
        banner.id = 'offlineBanner';
        banner.className = 'offline-banner';
        banner.innerHTML = `
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>OFFLINE MODE:</strong> You are currently offline. Your answers are being saved locally.
            Do not refresh the page or you will lose access to the questions.
            <i class="fas fa-exclamation-triangle ms-2"></i>
        `;
        document.body.prepend(banner);
    }

    hideOfflineBanner() {
        const banner = document.getElementById('offlineBanner');
        if (banner) {
            banner.remove();
        }
    }

    updateConnectionStatus() {
        const statusEl = document.getElementById('connectionStatus');
        if (statusEl) {
            if (this.isOnline) {
                statusEl.innerHTML = '<i class="fas fa-wifi text-success"></i>';
                statusEl.title = 'Online';
            } else {
                statusEl.innerHTML = '<i class="fas fa-wifi-slash text-warning"></i>';
                statusEl.title = 'Offline - Answers saved locally';
            }
        }
    }

    saveAnswer(questionId, answerText) {
        // Save to localStorage immediately
        this.saveToLocalStorage(questionId, answerText);

        // Update sync indicator
        this.updateSyncIndicator(questionId, 'local');

        // Try to sync to server
        this.syncAnswerToServer(questionId, answerText);
    }

    saveToLocalStorage(questionId, answerText) {
        try {
            const localAnswers = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');
            const syncStatus = JSON.parse(localStorage.getItem(this.SYNC_STATUS_KEY) || '{}');

            localAnswers[questionId] = {
                answer: answerText,
                timestamp: Date.now()
            };

            // Mark as unsynced if not already synced
            if (!syncStatus[questionId] || syncStatus[questionId] !== 'synced') {
                syncStatus[questionId] = 'local';
                this.syncQueue.add(questionId);
            }

            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(localAnswers));
            localStorage.setItem(this.SYNC_STATUS_KEY, JSON.stringify(syncStatus));
        } catch (e) {
            console.error('Error saving to localStorage:', e);
        }
    }

    syncAnswerToServer(questionId, answerText) {
        // Don't start new sync if one is already in progress for this question
        if (this.activeSyncs.has(questionId)) return;

        // Don't try to sync if offline
        if (!this.isOnline) {
            this.updateSyncIndicator(questionId, 'local');
            return;
        }

        this.activeSyncs.set(questionId, true);
        this.updateSyncIndicator(questionId, 'syncing');

        const formData = new FormData();
        formData.append('assessment_id', this.assessmentId);
        formData.append('question_id', questionId);
        formData.append('answer_text', answerText);
        formData.append('csrf_token', this.csrfToken);
        formData.append('action', 'save');

        fetch(`${this.baseUrl}/api/take_assessment.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Mark as synced
                const syncStatus = JSON.parse(localStorage.getItem(this.SYNC_STATUS_KEY) || '{}');
                syncStatus[questionId] = 'synced';
                localStorage.setItem(this.SYNC_STATUS_KEY, JSON.stringify(syncStatus));

                this.syncQueue.delete(questionId);
                this.updateSyncIndicator(questionId, 'synced');

                // Trigger success callback
                if (this.onAnswerSynced) {
                    this.onAnswerSynced(questionId);
                }
            } else {
                // Keep as local on error
                this.updateSyncIndicator(questionId, 'local');
            }

            this.activeSyncs.delete(questionId);
        })
        .catch(error => {
            // Network error - keep as local
            this.updateSyncIndicator(questionId, 'local');
            this.activeSyncs.delete(questionId);
        });
    }

    restoreLocalAnswers() {
        try {
            const localAnswers = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');
            const syncStatus = JSON.parse(localStorage.getItem(this.SYNC_STATUS_KEY) || '{}');

            Object.keys(localAnswers).forEach(questionId => {
                const status = syncStatus[questionId] || 'local';

                // Add to sync queue if not synced
                if (status !== 'synced') {
                    this.syncQueue.add(questionId);
                }

                // Update sync indicator
                this.updateSyncIndicator(questionId, status);
            });

            // Try to sync pending answers
            if (this.isOnline && this.syncQueue.size > 0) {
                setTimeout(() => this.syncAllPendingAnswers(), 1000);
            }
        } catch (e) {
            console.error('Error restoring local answers:', e);
        }
    }

    syncAllPendingAnswers() {
        if (!this.isOnline || this.syncQueue.size === 0) return;

        const localAnswers = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');

        this.syncQueue.forEach(questionId => {
            if (localAnswers[questionId] && !this.activeSyncs.has(questionId)) {
                this.syncAnswerToServer(questionId, localAnswers[questionId].answer);
            }
        });
    }

    updateSyncIndicator(questionId, status) {
        const indicator = document.querySelector(`#question-${questionId} .sync-indicator`);
        if (!indicator) return;

        indicator.className = 'sync-indicator';

        switch(status) {
            case 'local':
                indicator.innerHTML = '<i class="fas fa-save text-warning"></i>';
                indicator.title = 'Saved locally (not synced to server)';
                indicator.classList.add('sync-local');
                break;
            case 'syncing':
                indicator.innerHTML = '<i class="fas fa-sync-alt fa-spin text-info"></i>';
                indicator.title = 'Syncing to server...';
                indicator.classList.add('sync-syncing');
                break;
            case 'synced':
                indicator.innerHTML = '<i class="fas fa-check-circle text-success"></i>';
                indicator.title = 'Synced to server';
                indicator.classList.add('sync-synced');
                setTimeout(() => {
                    if (indicator.classList.contains('sync-synced')) {
                        indicator.innerHTML = '';
                    }
                }, 3000);
                break;
        }
    }

    getUnsyncedCount() {
        return this.syncQueue.size;
    }

    clearLocalData() {
        localStorage.removeItem(this.STORAGE_KEY);
        localStorage.removeItem(this.SYNC_STATUS_KEY);
    }
}

// Export for use in main script
window.AssessmentOfflineManager = AssessmentOfflineManager;
