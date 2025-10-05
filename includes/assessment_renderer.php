<?php
/**
 * Assessment Renderer Helper
 * Functions to render assessment HTML components
 */

/**
 * Render assessment header section
 */
function renderAssessmentHeader($assessment, $attempt, $questions, $timeLeft, $isResetAttempt) {
    ?>
    <div class="assessment-header-wrapper mb-4" id="headerWrapper">
        <div class="assessment-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="text-gold"><?php echo htmlspecialchars($assessment['title']); ?></h1>
                    <div class="assessment-meta">
                        <span class="badge bg-dark me-2"><i class="fas fa-users"></i><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <span class="badge bg-dark me-2"><i class="fas fa-book me-1"></i><?php echo htmlspecialchars($assessment['subject_name']); ?></span>
                        <span class="badge bg-dark me-2"><i class="fas fa-calendar me-1"></i><?php echo date('F d, Y', strtotime($assessment['date'])); ?></span>
                        <?php if ($assessment['use_question_limit'] && $assessment['questions_to_answer']): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-layer-group me-1"></i><?php echo $assessment['questions_to_answer']; ?> Questions
                            </span>
                        <?php endif; ?>
                        <?php if ($isResetAttempt): ?>
                            <span class="badge bg-info text-white">
                                <i class="fas fa-redo-alt me-1"></i>Reset Assessment
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6 d-flex justify-content-end align-items-center">
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 mt-md-0">
                        <div class="connection-status me-2" id="connectionStatus" title="Online">
                            <i class="fas fa-wifi text-success"></i>
                        </div>
                        <div class="progress-pill me-2">
                            <i class="fas fa-tasks me-1"></i>
                            <span id="answeredCount">0</span>/<span id="totalQuestions"><?php echo count($questions); ?></span>
                        </div>
                        <?php if ($timeLeft !== null): ?>
                            <div class="timer" id="timer" data-time="<?php echo htmlspecialchars($timeLeft); ?>">
                                <i class="fas fa-clock me-1"></i>
                                <span id="timeDisplay">--:--:--</span>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-submit" id="submitBtn" disabled>
                            <i class="fas fa-paper-plane me-1"></i>Submit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render a single question
 */
function renderQuestion($question, $index) {
    ?>
    <div class="question-section mb-4" id="question-<?php echo $question['question_id']; ?>"
        data-question-id="<?php echo $question['question_id']; ?>">
        <div class="section-header">
            <div class="d-flex justify-content-between align-items-center">
                <div class="question-number">
                    Question <?php echo $index + 1; ?>
                    <span class="sync-indicator ms-2"></span>
                </div>
                <div class="question-points">
                    <span class="badge bg-gold text-dark">
                        <i class="fas fa-star me-1"></i> <?php echo $question['max_score']; ?> Points
                    </span>
                </div>
            </div>
        </div>
        <div class="section-content">
            <div class="row">
                <div class="<?php echo !empty($question['image_url']) ? 'col-md-8 order-md-1 order-2' : 'col-12'; ?>">
                    <div class="question-text mb-4">
                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                    </div>

                    <div class="answer-container">
                        <?php if ($question['question_type'] === 'MCQ'): ?>
                            <?php renderMCQOptions($question); ?>
                        <?php else: ?>
                            <?php renderShortAnswerInput($question); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($question['image_url'])): ?>
                    <div class="col-md-4 order-md-2 order-1 mb-3 mb-md-0">
                        <div class="question-image-container">
                            <div class="image-wrap">
                                <img src="<?php echo htmlspecialchars($question['image_url']); ?>"
                                    alt="Question Image"
                                    class="question-image">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render MCQ options
 */
function renderMCQOptions($question) {
    ?>
    <div class="mcq-container">
        <?php foreach ($question['options'] as $option): ?>
            <label class="mcq-option">
                <input type="radio"
                    name="answer[<?php echo $question['question_id']; ?>]"
                    value="<?php echo $option['id']; ?>"
                    class="question-input"
                    <?php echo ($question['student_answer'] == $option['id']) ? 'checked' : ''; ?>>
                <span class="mcq-text"><?php echo htmlspecialchars($option['text']); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Render short answer input fields
 */
function renderShortAnswerInput($question) {
    if ($question['answer_mode'] === 'any_match' && $question['answer_count'] > 1):
        // Multiple answer inputs
        ?>
        <div class="multi-answer-container">
            <div class="answer-info mb-3">
                <i class="fas fa-info-circle me-1 text-gold"></i>
                Please provide <?php echo $question['answer_count']; ?> answers below:
            </div>
            <?php
            $existingAnswers = array_filter(explode("\n", $question['student_answer'] ?? ''));
            for ($i = 0; $i < $question['answer_count']; $i++):
            ?>
                <div class="answer-field mb-3">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-dark text-gold">
                            <i class="fas fa-pencil-alt"></i>
                        </span>
                        <input type="text"
                            class="form-control question-input fancy-input"
                            name="answer[<?php echo $question['question_id']; ?>][]"
                            value="<?php echo htmlspecialchars($existingAnswers[$i] ?? ''); ?>"
                            placeholder="Enter answer <?php echo $i + 1; ?>"
                            maxlength="100">
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    <?php else: ?>
        <!-- Single answer input -->
        <div class="single-answer-container">
            <div class="input-group input-group-lg">
                <span class="input-group-text bg-dark text-gold">
                    <i class="fas fa-pencil-alt"></i>
                </span>
                <input type="text"
                    class="form-control question-input fancy-input"
                    name="answer[<?php echo $question['question_id']; ?>]"
                    value="<?php echo htmlspecialchars($question['student_answer'] ?? ''); ?>"
                    placeholder="Enter your answer here"
                    maxlength="100">
            </div>
        </div>
    <?php endif;
}

/**
 * Render progress container
 */
function renderProgressContainer($totalQuestions) {
    ?>
    <div class="progress-container">
        <div class="card">
            <div class="card-body">
                <div class="progress-wrapper">
                    <div class="progress-info mb-2">
                        <span class="progress-label">Your Progress</span>
                        <span class="progress-percentage">
                            <span id="progressCount">0</span>/<span id="progressTotal"><?php echo $totalQuestions; ?></span> questions answered
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar bg-gold" id="progressBar" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render submission confirmation modal
 */
function renderSubmitModal($assessment, $totalQuestions, $timeLeft, $isResetAttempt) {
    ?>
    <div class="modal fade" id="submitModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Assessment?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="submission-summary">
                        <p><strong>Assessment:</strong> <?php echo htmlspecialchars($assessment['title']); ?></p>
                        <p><strong>Questions Answered:</strong> <span id="modalAnsweredCount">0</span>/<span id="modalTotalQuestions"><?php echo $totalQuestions; ?></span></p>
                        <?php if ($assessment['use_question_limit']): ?>
                            <p class="text-info"><small><i class="fas fa-info-circle me-1"></i>This assessment uses a question pool</small></p>
                        <?php endif; ?>
                        <?php if ($timeLeft !== null): ?>
                            <p><strong>Time Remaining:</strong> <span id="modalTimeRemaining"></span></p>
                        <?php endif; ?>
                        <?php if ($isResetAttempt): ?>
                            <p class="text-info"><small><i class="fas fa-redo-alt me-1"></i>This is a reset assessment</small></p>
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This action cannot be undone. Are you sure you want to submit?
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-gold" id="confirmSubmit">
                        <i class="fas fa-paper-plane me-2"></i>Submit Assessment
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render time's up modal
 */
function renderTimeUpModal($assessment, $totalQuestions) {
    ?>
    <div class="modal fade" id="timeUpModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-clock me-2"></i>Time's Up!</h5>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-hourglass-end fa-3x text-danger mb-3"></i>
                        <h4>Your time for this assessment has ended.</h4>
                        <p>Your answers have been automatically submitted.</p>
                    </div>
                    <div class="submission-summary">
                        <p><strong>Assessment:</strong> <?php echo htmlspecialchars($assessment['title']); ?></p>
                        <p><strong>Questions Answered:</strong> <span id="timeUpAnsweredCount">0</span>/<span id="timeUpTotalQuestions"><?php echo $totalQuestions; ?></span></p>
                    </div>
                    <div class="progress mt-3">
                        <div class="progress-bar bg-danger" role="progressbar" style="width: 100%"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="w-100 text-center">
                        <p class="mb-2">You will be redirected to view your results shortly...</p>
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render save toast notification
 */
function renderSaveToast() {
    ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="saveToast" class="toast align-items-center text-white bg-dark" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-save me-2"></i>
                    <span id="saveMessage">Saving your answer...</span>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>
    <?php
}
