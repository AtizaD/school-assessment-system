/**
 * Assessment UI Module
 * Handles timer, progress tracking, form submission, and UI interactions
 */

class AssessmentUIManager {
    constructor(config) {
        this.assessmentId = config.assessmentId;
        this.attemptId = config.attemptId;
        this.csrfToken = config.csrfToken;
        this.baseUrl = config.baseUrl;
        this.totalQuestions = config.totalQuestions;
        this.timeLeft = config.timeLeft;
        this.duration = config.duration;

        this.hasSubmitted = false;
        this.lastSavedInputs = {};
        this.answeredQuestions = 0;
        this.timeOffset = 0;

        // Timer variables
        this.timerInterval = null;
        this.isTimerWarning = false;
        this.isTimerDanger = false;
        this.timerSync = true;

        // Constants
        this.FIVE_MINUTES = 5 * 60;
        this.ONE_MINUTE = 60;

        // DOM elements
        this.form = document.getElementById('assessmentForm');
        this.submitBtn = document.getElementById('submitBtn');
        this.confirmSubmitBtn = document.getElementById('confirmSubmit');
        this.inputs = document.querySelectorAll('.question-input');
        this.questionsContainers = document.querySelectorAll('.question-section');
        this.answerCounter = document.getElementById('answeredCount');
        this.modalAnswerCounter = document.getElementById('modalAnsweredCount');
        this.timeUpAnswerCounter = document.getElementById('timeUpAnsweredCount');
        this.progressBar = document.getElementById('progressBar');
        this.progressCount = document.getElementById('progressCount');
        this.timeDisplayEl = document.getElementById('timeDisplay');
        this.modalTimeRemaining = document.getElementById('modalTimeRemaining');
        this.timeOffsetInput = document.getElementById('timeOffset');

        // Modals
        this.submitModal = new bootstrap.Modal(document.getElementById('submitModal'));
        this.timeUpModal = new bootstrap.Modal(document.getElementById('timeUpModal'));

        // Toast
        this.saveToast = new bootstrap.Toast(document.getElementById('saveToast'), { delay: 2000 });
        this.saveMessage = document.getElementById('saveMessage');

        this.init();
    }

    init() {
        this.createScrollToTopButton();
        this.highlightAnsweredQuestions();
        this.updateAnsweredCount();
        this.updateSubmitButton();

        // Event listeners
        window.addEventListener('scroll', this.debounce(() => this.trackVisibleQuestions(), 100));

        this.inputs.forEach(input => {
            if (input.type === 'text' || input.type === 'textarea') {
                input.addEventListener('input', this.debounce((e) => this.handleInputChange(e), 800));
                input.addEventListener('blur', (e) => this.handleInputChange(e));
            } else if (input.type === 'radio') {
                input.addEventListener('change', (e) => this.handleInputChange(e));
            }
        });

        this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        this.submitBtn.addEventListener('click', () => this.showSubmitConfirmation());
        this.confirmSubmitBtn.addEventListener('click', () => this.confirmSubmit());

        // Start timer if duration is set
        if (this.timeLeft !== null) {
            const timerEl = document.getElementById('timer');
            if (timerEl) {
                this.syncTime();
                this.startTimer();

                // Sync time periodically
                setInterval(() => {
                    if (this.timeLeft > 0 && this.timerSync) {
                        this.syncTime();
                    }
                }, 30000);
            }
        }

        // Request notification permission
        this.requestNotificationPermission();
    }

    handleInputChange(e) {
        const input = e.target;
        const questionId = this.getQuestionIdFromInput(input);
        const questionContainer = document.getElementById(`question-${questionId}`);

        if (!questionId) return;

        let answerText = '';

        if (input.type === 'radio') {
            if (input.checked) {
                answerText = input.value;
            } else {
                return;
            }
        } else if (input.type === 'text' || input.type === 'textarea') {
            if (input.name.includes('[]')) {
                const allInputs = document.querySelectorAll(`input[name="answer[${questionId}][]"]`);
                const values = Array.from(allInputs).map(inp => inp.value.trim()).filter(val => val);
                answerText = values.join("\n");
            } else {
                answerText = input.value.trim();
            }
        }

        if (this.lastSavedInputs[questionId] === answerText) {
            return;
        }

        this.lastSavedInputs[questionId] = answerText;

        if (answerText) {
            questionContainer.classList.add('question-answered');
        } else {
            questionContainer.classList.remove('question-answered');
        }

        this.updateAnsweredCount();
        this.updateSubmitButton();

        // Trigger callback for answer change
        if (this.onAnswerChanged) {
            this.onAnswerChanged(questionId, answerText);
        }
    }

    updateAnsweredCount() {
        const answered = document.querySelectorAll('.question-answered').length;
        this.answeredQuestions = answered;

        this.answerCounter.textContent = answered;
        this.modalAnswerCounter.textContent = answered;
        this.timeUpAnswerCounter.textContent = answered;
        this.progressCount.textContent = answered;

        const percentage = (answered / this.totalQuestions) * 100;
        this.progressBar.style.width = `${percentage}%`;
    }

    updateSubmitButton() {
        if (this.submitBtn) {
            this.submitBtn.disabled = this.answeredQuestions !== this.totalQuestions;

            if (!this.submitBtn.disabled) {
                this.submitBtn.classList.add('pulse-animation');
            } else {
                this.submitBtn.classList.remove('pulse-animation');
            }
        }
    }

    highlightAnsweredQuestions() {
        this.questionsContainers.forEach(container => {
            const questionId = container.dataset.questionId;
            const inputs = container.querySelectorAll('.question-input');
            let isAnswered = false;

            inputs.forEach(input => {
                if (input.type === 'radio' && input.checked) {
                    isAnswered = true;
                } else if ((input.type === 'text' || input.type === 'textarea') && input.value.trim()) {
                    isAnswered = true;
                }
            });

            if (isAnswered) {
                container.classList.add('question-answered');

                const questionInputs = container.querySelectorAll('.question-input');
                if (questionInputs.length > 0) {
                    if (questionInputs[0].type === 'radio') {
                        const checkedInput = container.querySelector('.question-input:checked');
                        if (checkedInput) {
                            this.lastSavedInputs[questionId] = checkedInput.value;
                        }
                    } else if (questionInputs[0].name.includes('[]')) {
                        const allInputs = document.querySelectorAll(`input[name="answer[${questionId}][]"]`);
                        const values = Array.from(allInputs).map(inp => inp.value.trim()).filter(val => val);
                        this.lastSavedInputs[questionId] = values.join("\n");
                    } else {
                        this.lastSavedInputs[questionId] = questionInputs[0].value.trim();
                    }
                }
            } else {
                container.classList.remove('question-answered');
            }
        });
    }

    showSubmitConfirmation() {
        // Check for unsynced answers via callback
        if (this.getUnsyncedCount) {
            const unsyncedCount = this.getUnsyncedCount();

            if (unsyncedCount > 0) {
                if (!confirm(`You have ${unsyncedCount} answer(s) that are not synced to the server.\n\nPlease wait for all answers to sync before submitting.\n\nWould you like to wait and try syncing now?`)) {
                    return;
                }

                this.showToast('Syncing pending answers...');

                // Try to sync via callback
                if (this.syncAllAnswers) {
                    this.syncAllAnswers();
                }

                // Check again after 5 seconds
                setTimeout(() => {
                    const stillUnsynced = this.getUnsyncedCount();
                    if (stillUnsynced > 0) {
                        alert(`Still ${stillUnsynced} answer(s) not synced.\n\nPlease ensure you have a stable internet connection and try again.`);
                    } else {
                        this.showSubmitConfirmation();
                    }
                }, 5000);

                return;
            }
        }

        this.modalAnswerCounter.textContent = this.answeredQuestions;

        if (this.timeLeft !== null) {
            this.modalTimeRemaining.textContent = this.formatTime(this.timeLeft);
        }

        this.submitModal.show();
    }

    confirmSubmit() {
        if (this.hasSubmitted) return;

        // Mark as submitted to allow navigation
        this.hasSubmitted = true;
        window.assessmentSubmitted = true;
        this.submitModal.hide();

        this.confirmSubmitBtn.disabled = true;
        this.confirmSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Submitting...';

        const formData = new FormData(this.form);

        fetch(`${this.baseUrl}/api/take_assessment.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.href = data.data.redirect_url;
            } else {
                alert('Error submitting assessment: ' + data.message);
                this.hasSubmitted = false;
                window.assessmentSubmitted = false;
                this.confirmSubmitBtn.disabled = false;
                this.confirmSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Assessment';
            }
        })
        .catch(error => {
            alert('Network error during submission. Please check your connection and try again.');
            this.hasSubmitted = false;
            window.assessmentSubmitted = false;
            this.confirmSubmitBtn.disabled = false;
            this.confirmSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Assessment';
        });
    }

    handleFormSubmit(e) {
        e.preventDefault();

        if (this.hasSubmitted) return;

        this.showSubmitConfirmation();
    }

    autoSubmit() {
        if (this.hasSubmitted) return;

        this.hasSubmitted = true;
        window.assessmentSubmitted = true;

        this.timeUpModal.show();

        const formData = new FormData();
        formData.append('assessment_id', this.assessmentId);
        formData.append('attempt_id', this.attemptId);
        formData.append('csrf_token', this.csrfToken);
        formData.append('action', 'autosubmit');

        fetch(`${this.baseUrl}/api/take_assessment.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                setTimeout(() => {
                    window.location.href = data.data.redirect_url;
                }, 3000);
            } else {
                alert('There was an error submitting your assessment. Please try again or contact support.');
            }
        })
        .catch(error => {
            alert('Network error during submission. Please check your connection and try again.');
        });
    }

    // Timer functions
    startTimer() {
        this.timerInterval = setInterval(() => {
            this.timeLeft--;

            if (this.timeLeft <= 0) {
                clearInterval(this.timerInterval);
                this.timeLeft = 0;
                this.autoSubmit();
            }

            this.updateTimerDisplay();
        }, 1000);

        this.updateTimerDisplay();
    }

    updateTimerDisplay() {
        const timerEl = document.getElementById('timer');
        if (!timerEl || !this.timeDisplayEl) return;

        if (this.timeLeft <= 0) {
            this.timeDisplayEl.textContent = '00:00:00';
            timerEl.classList.remove('warning');
            timerEl.classList.add('danger');
            return;
        }

        this.timeDisplayEl.textContent = this.formatTime(this.timeLeft);

        if (this.timeLeft <= this.FIVE_MINUTES && !this.isTimerWarning) {
            timerEl.classList.add('warning');
            this.isTimerWarning = true;

            if (Notification.permission === 'granted') {
                new Notification('5 Minutes Remaining!', {
                    body: 'You have 5 minutes left to complete your assessment.',
                    icon: `${this.baseUrl}/assets/images/logo.png`
                });
            }
        }

        if (this.timeLeft <= this.ONE_MINUTE && !this.isTimerDanger) {
            timerEl.classList.remove('warning');
            timerEl.classList.add('danger');
            this.isTimerDanger = true;

            if (Notification.permission === 'granted') {
                new Notification('1 Minute Remaining!', {
                    body: 'You have only 1 minute left to complete your assessment!',
                    icon: `${this.baseUrl}/assets/images/logo.png`
                });
            }
        }
    }

    syncTime() {
        const clientTime = Math.floor(Date.now() / 1000);

        fetch(`${this.baseUrl}/api/take_assessment.php?action=sync&client_time=${clientTime}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    this.timeOffset = data.data.offset;
                    if (this.timeOffsetInput) {
                        this.timeOffsetInput.value = this.timeOffset;
                    }
                }
            })
            .catch(error => {
                this.timerSync = false;
            });
    }

    formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        return [
            hours.toString().padStart(2, '0'),
            minutes.toString().padStart(2, '0'),
            secs.toString().padStart(2, '0')
        ].join(':');
    }

    // UI Helper functions
    trackVisibleQuestions() {
        this.questionsContainers.forEach(container => {
            const rect = container.getBoundingClientRect();
            const inView = (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );

            if (inView) {
                container.classList.add('question-highlight');
            } else {
                container.classList.remove('question-highlight');
            }
        });

        const scrollButton = document.querySelector('.scroll-to-top');
        if (scrollButton) {
            if (window.scrollY > 300) {
                scrollButton.classList.add('active');
            } else {
                scrollButton.classList.remove('active');
            }
        }
    }

    createScrollToTopButton() {
        const button = document.createElement('div');
        button.classList.add('scroll-to-top');
        button.innerHTML = '<i class="fas fa-arrow-up"></i>';
        button.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        document.body.appendChild(button);
    }

    getQuestionIdFromInput(input) {
        const name = input.name;
        const match = name.match(/answer\[(\d+)\]/);
        return match ? match[1] : null;
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    requestNotificationPermission() {
        if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }

    showToast(message) {
        this.saveMessage.textContent = message;
        this.saveToast.show();
    }
}

// Export for use in main script
window.AssessmentUIManager = AssessmentUIManager;
