<?php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');
ob_start();

$error = '';
$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

try {
    if (!$assessmentId) {
        throw new Exception('Invalid assessment ID');
    }

    $db = DatabaseConfig::getInstance()->getConnection();

    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id 
         FROM Students s 
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Check for any in-progress assessment before allowing new attempts (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT aa.assessment_id, aa.attempt_id, a.title, s.subject_name, aa.start_time
         FROM AssessmentAttempts aa
         JOIN Assessments a ON aa.assessment_id = a.assessment_id
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                   AND sc.class_id = ac.class_id 
                                   AND sc.subject_id = ac.subject_id 
                                   AND sc.status = 'active'
         WHERE aa.student_id = ? 
         AND aa.status = 'in_progress'
         AND (ac.class_id = ? OR sc.sp_id IS NOT NULL)
         ORDER BY aa.start_time DESC
         LIMIT 1"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['student_id'], $studentInfo['class_id']]);
    $inProgressAssessment = $stmt->fetch();

    // If there's an in-progress assessment and it's NOT the current one, redirect
    if ($inProgressAssessment && $inProgressAssessment['assessment_id'] != $assessmentId) {
        $message = "You have an assessment '{$inProgressAssessment['title']}' ({$inProgressAssessment['subject_name']}) in progress. Please complete it before starting a new one.";
        
        $_SESSION['assessment_redirect_message'] = $message;
        header("Location: take_assessment.php?id=" . $inProgressAssessment['assessment_id']);
        exit;
    }

    // Check if current assessment is valid and available (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT a.*, s.subject_name, ac.class_id,
                CASE 
                    WHEN sc.sp_id IS NOT NULL THEN 'special'
                    ELSE 'regular'
                END as enrollment_type
         FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                   AND sc.class_id = ac.class_id 
                                   AND sc.subject_id = ac.subject_id 
                                   AND sc.status = 'active'
         WHERE a.assessment_id = ? 
         AND (ac.class_id = ? OR sc.sp_id IS NOT NULL)
         AND a.status IN ('pending', 'completed')
         AND (a.date = CURRENT_DATE OR (a.allow_late_submission = 1 AND a.date >= DATE_SUB(CURRENT_DATE, INTERVAL a.late_submission_days DAY)))"
    );
    $stmt->execute([$studentInfo['student_id'], $assessmentId, $studentInfo['class_id']]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found or not available');
    }

    $stmt = $db->prepare(
        "SELECT status FROM Results 
         WHERE assessment_id = ? AND student_id = ?"
    );
    $stmt->execute([$assessmentId, $studentInfo['student_id']]);
    $result = $stmt->fetch();

    if ($result && $result['status'] === 'completed') {
        header("Location: view_result.php?id=" . $assessmentId);
        exit;
    }

    // FIXED: Check for existing attempt and handle timer start for reset attempts
    $stmt = $db->prepare(
        "SELECT * FROM AssessmentAttempts 
         WHERE assessment_id = ? AND student_id = ? 
         AND status = 'in_progress'"
    );
    $stmt->execute([$assessmentId, $studentInfo['student_id']]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        // Create new attempt
        $stmt = $db->prepare(
            "INSERT INTO AssessmentAttempts (assessment_id, student_id) 
             VALUES (?, ?)"
        );
        $stmt->execute([$assessmentId, $studentInfo['student_id']]);

        $stmt = $db->prepare(
            "SELECT * FROM AssessmentAttempts 
             WHERE assessment_id = ? AND student_id = ?"
        );
        $stmt->execute([$assessmentId, $studentInfo['student_id']]);
        $attempt = $stmt->fetch();
    } else {
        // FIXED: Check if this is a reset attempt that needs start_time update
        if (!empty($attempt['answer_metadata'])) {
            $metadata = json_decode($attempt['answer_metadata'], true);
            if (is_array($metadata) && isset($metadata['reset_type']) && $metadata['reset_type'] === 'partial') {
                // Check if timer has not started yet
                if (!isset($metadata['timer_started']) || !$metadata['timer_started']) {
                    // This is a reset attempt - update start_time to now and mark timer as started
                    $metadata['timer_started'] = true;
                    $metadata['actual_start_time'] = date('Y-m-d H:i:s');
                    
                    $stmt = $db->prepare(
                        "UPDATE AssessmentAttempts 
                         SET start_time = CURRENT_TIMESTAMP, answer_metadata = ? 
                         WHERE attempt_id = ?"
                    );
                    $stmt->execute([json_encode($metadata), $attempt['attempt_id']]);
                    
                    // Refresh attempt data
                    $stmt = $db->prepare(
                        "SELECT * FROM AssessmentAttempts 
                         WHERE attempt_id = ?"
                    );
                    $stmt->execute([$attempt['attempt_id']]);
                    $attempt = $stmt->fetch();
                    
                    // Log the timer start
                    logSystemActivity(
                        'Assessment Timer Started',
                        "Timer started for reset assessment ID: $assessmentId, student ID: {$studentInfo['student_id']}",
                        'INFO'
                    );
                }
            }
        }
    }

    // Handle question pooling - select random subset if enabled
    $selectedQuestionIds = [];
    if ($assessment['use_question_limit'] && $assessment['questions_to_answer']) {
        // Check if questions are already selected for this attempt
        if (empty($attempt['question_order'])) {
            // First time - randomly select questions
            $stmt = $db->prepare("SELECT question_id FROM questions WHERE assessment_id = ?");
            $stmt->execute([$assessmentId]);
            $allQuestionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($allQuestionIds) < $assessment['questions_to_answer']) {
                throw new Exception('Insufficient questions available for this assessment');
            }
            
            // Randomly select the required number of questions
            shuffle($allQuestionIds);
            $selectedQuestionIds = array_slice($allQuestionIds, 0, $assessment['questions_to_answer']);
            
            // Store selected questions in attempt
            $stmt = $db->prepare("UPDATE AssessmentAttempts SET question_order = ? WHERE attempt_id = ?");
            $stmt->execute([json_encode($selectedQuestionIds), $attempt['attempt_id']]);
            
            // Update attempt data
            $attempt['question_order'] = json_encode($selectedQuestionIds);
        } else {
            // Questions already selected - use stored selection
            $selectedQuestionIds = json_decode($attempt['question_order'], true);
        }
        
        // Get only the selected questions
        $placeholders = str_repeat('?,', count($selectedQuestionIds) - 1) . '?';
        $stmt = $db->prepare(
            "SELECT q.*, q.image_id,
                CASE 
                    WHEN q.question_type = 'MCQ' THEN (
                        SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text) SEPARATOR '|')
                        FROM MCQQuestions mcq 
                        WHERE mcq.question_id = q.question_id
                    )
                    ELSE NULL
                END as options,
                (
                    SELECT answer_text 
                    FROM StudentAnswers 
                    WHERE assessment_id = q.assessment_id 
                    AND student_id = ? 
                    AND question_id = q.question_id
                ) as student_answer
             FROM Questions q
             WHERE q.question_id IN ($placeholders)
             ORDER BY FIELD(q.question_id, " . implode(',', $selectedQuestionIds) . ")"
        );
        $stmt->execute(array_merge([$studentInfo['student_id']], $selectedQuestionIds));
        $questions = $stmt->fetchAll();
    } else {
        // Normal mode - get all questions
        $stmt = $db->prepare(
            "SELECT q.*, q.image_id,
                CASE 
                    WHEN q.question_type = 'MCQ' THEN (
                        SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text) SEPARATOR '|')
                        FROM MCQQuestions mcq 
                        WHERE mcq.question_id = q.question_id
                    )
                    ELSE NULL
                END as options,
                (
                    SELECT answer_text 
                    FROM StudentAnswers 
                    WHERE assessment_id = q.assessment_id 
                    AND student_id = ? 
                    AND question_id = q.question_id
                ) as student_answer
             FROM Questions q
             WHERE q.assessment_id = ?
             ORDER BY q.question_id"
        );
        $stmt->execute([$studentInfo['student_id'], $assessmentId]);
        $questions = $stmt->fetchAll();
    }

    // Handle question shuffling (only if not using question pooling with preset order)
    if ($assessment['shuffle_questions'] && !($assessment['use_question_limit'] && !empty($attempt['question_order']))) {
        if (empty($attempt['question_order'])) {
            $questionIds = array_column($questions, 'question_id');
            shuffle($questionIds);

            $stmt = $db->prepare(
                "UPDATE AssessmentAttempts 
                 SET question_order = ? 
                 WHERE attempt_id = ?"
            );
            $stmt->execute([json_encode($questionIds), $attempt['attempt_id']]);

            $stmt = $db->prepare(
                "SELECT * FROM AssessmentAttempts 
                 WHERE attempt_id = ?"
            );
            $stmt->execute([$attempt['attempt_id']]);
            $attempt = $stmt->fetch();
        }

        if (!empty($attempt['question_order'])) {
            $savedOrder = json_decode($attempt['question_order'], true);

            if (is_array($savedOrder)) {
                $questionsById = [];
                foreach ($questions as $q) {
                    $questionsById[$q['question_id']] = $q;
                }

                $orderedQuestions = [];
                foreach ($savedOrder as $qid) {
                    if (isset($questionsById[$qid])) {
                        $orderedQuestions[] = $questionsById[$qid];
                    }
                }

                if (!empty($orderedQuestions)) {
                    $questions = $orderedQuestions;
                }
            }
        }
    }

    foreach ($questions as &$question) {
        if (!empty($question['image_id'])) {
            $stmt = $db->prepare(
                "SELECT filename FROM assessment_images 
                 WHERE image_id = ?"
            );
            $stmt->execute([$question['image_id']]);
            $imageData = $stmt->fetch();
            if ($imageData) {
                $question['image_url'] = BASE_URL . '/assets/assessment_images/' . $imageData['filename'];
            }
        }

        if ($question['question_type'] === 'MCQ' && $question['options']) {
            $optionsList = [];
            $options = explode('|', $question['options']);
            foreach ($options as $option) {
                list($id, $text) = explode(':', $option, 2);
                $optionsList[] = [
                    'id' => $id,
                    'text' => $text
                ];
            }

            if ($assessment['shuffle_options']) {
                $optionOrders = !empty($attempt['option_orders']) ?
                    json_decode($attempt['option_orders'], true) : [];

                if (!is_array($optionOrders)) {
                    $optionOrders = [];
                }

                if (!isset($optionOrders[$question['question_id']])) {
                    shuffle($optionsList);

                    $optionOrders[$question['question_id']] = array_column($optionsList, 'id');

                    $stmt = $db->prepare(
                        "UPDATE AssessmentAttempts 
                         SET option_orders = ? 
                         WHERE attempt_id = ?"
                    );
                    $stmt->execute([json_encode($optionOrders), $attempt['attempt_id']]);
                } else {
                    $savedOptionOrder = $optionOrders[$question['question_id']];

                    $optionsById = [];
                    foreach ($optionsList as $opt) {
                        $optionsById[$opt['id']] = $opt;
                    }

                    $reorderedOptions = [];
                    foreach ($savedOptionOrder as $oid) {
                        if (isset($optionsById[$oid])) {
                            $reorderedOptions[] = $optionsById[$oid];
                        }
                    }

                    if (!empty($reorderedOptions)) {
                        $optionsList = $reorderedOptions;
                    }
                }
            }

            $question['options'] = $optionsList;
        } elseif ($question['question_type'] === 'Short Answer' && $question['answer_mode'] === 'any_match') {
            $question['valid_answers'] = json_decode($question['correct_answer'], true);
        }
    }
    unset($question);

    // FIXED: Calculate time remaining with custom duration support and proper timer handling
    $timeLeft = null;
    $duration = null;
    $isResetAttempt = false;
    $resetMessage = '';

    if ($assessment['duration']) {
        // Get effective duration (handles custom duration from resets)
        $duration = $assessment['duration']; // Default

        if (!empty($attempt['answer_metadata'])) {
            $metadata = json_decode($attempt['answer_metadata'], true);
            if (is_array($metadata)) {
                // Check if this is a reset attempt
                if (isset($metadata['reset_type']) && $metadata['reset_type'] === 'partial') {
                    $isResetAttempt = true;
                    
                    // Check for custom duration from reset
                    if (isset($metadata['custom_duration_minutes'])) {
                        $duration = (int)$metadata['custom_duration_minutes'];
                    }
                    
                    // Display reset information
                    if (isset($metadata['reset_reason'])) {
                        $resetMessage = "Assessment reset: " . $metadata['reset_reason'];
                    }
                }
                // Fallback to original duration if available
                elseif (isset($metadata['original_duration_minutes'])) {
                    $duration = (int)$metadata['original_duration_minutes'];
                }
            }
        }

        // Calculate time remaining
        $startTime = strtotime($attempt['start_time']);
        $endTime = $startTime + ($duration * 60);
        $timeLeft = max(0, $endTime - time());
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Take assessment error: " . $e->getMessage());
}

$pageTitle = 'Take Assessment';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <?php 
    // Display redirect message if set
    if (isset($_SESSION['assessment_redirect_message'])): 
    ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Assessment Redirect:</strong> <?php echo htmlspecialchars($_SESSION['assessment_redirect_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php 
        // Clear the message after displaying
        unset($_SESSION['assessment_redirect_message']); 
        ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>       
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

        <form id="assessmentForm" method="POST" action="<?php echo BASE_URL; ?>/api/take_assessment.php">
            <input type="hidden" name="assessment_id" value="<?php echo $assessmentId; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo $attempt['attempt_id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="time_offset" id="timeOffset" value="0">

            <div class="row">
                <div class="col-12">
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-section mb-4" id="question-<?php echo $question['question_id']; ?>"
                            data-question-id="<?php echo $question['question_id']; ?>">
                            <div class="section-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="question-number">Question <?php echo $index + 1; ?></div>
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
                                            <?php else: ?>
                                                <?php if ($question['answer_mode'] === 'any_match' && $question['answer_count'] > 1): ?>
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
                                                <?php endif; ?>
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
                    <?php endforeach; ?>

                    <div class="progress-container">
                        <div class="card">
                            <div class="card-body">
                                <div class="progress-wrapper">
                                    <div class="progress-info mb-2">
                                        <span class="progress-label">Your Progress</span>
                                        <span class="progress-percentage">
                                            <span id="progressCount">0</span>/<span id="progressTotal"><?php echo count($questions); ?></span> questions answered
                                        </span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-gold" id="progressBar" role="progressbar" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Submission Confirmation Modal -->
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
                            <p><strong>Questions Answered:</strong> <span id="modalAnsweredCount">0</span>/<span id="modalTotalQuestions"><?php echo count($questions); ?></span></p>
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
        
        <!-- Time's up Modal -->
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
                            <p><strong>Questions Answered:</strong> <span id="timeUpAnsweredCount">0</span>/<span id="timeUpTotalQuestions"><?php echo count($questions); ?></span></p>
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
        
        <!-- Saving Indicator -->
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

        <style>
            :root {
                --black: #000000;
                --dark-black: #222222;
                --gold: #ffd700;
                --light-gold: #fff9e6;
                --white: #ffffff;
                --gray: #f8f9fa;
                --dark-gray: #e9ecef;
            }

            .assessment-header-wrapper {
                position: sticky;
                top: 0;
                z-index: 10;
                background: var(--black);
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                transition: all 0.3s ease;
            }

            .assessment-header {
                color: var(--white);
            }

            .text-gold {
                color: var(--gold) !important;
            }

            .bg-gold {
                background-color: var(--gold) !important;
            }

            .timer {
                background: var(--dark-black);
                color: var(--gold);
                padding: 8px 15px;
                border-radius: 50px;
                font-weight: 600;
                font-family: monospace;
                font-size: 1.1rem;
                display: inline-flex;
                align-items: center;
                min-width: 110px;
                justify-content: center;
            }

            .timer.warning {
                animation: pulse-warning 1s infinite;
                background: #ff9800;
                color: var(--black);
            }

            .timer.danger {
                animation: pulse-danger 0.5s infinite;
                background: #f44336;
                color: var(--white);
            }

            @keyframes pulse-warning {
                0% { opacity: 1; }
                50% { opacity: 0.8; }
                100% { opacity: 1; }
            }

            @keyframes pulse-danger {
                0% { opacity: 1; }
                50% { opacity: 0.8; }
                100% { opacity: 1; }
            }

            .progress-pill {
                background: var(--dark-black);
                color: var(--gold);
                padding: 8px 15px;
                border-radius: 50px;
                font-weight: 600;
                font-size: 1.1rem;
                display: inline-flex;
                align-items: center;
                min-width: 80px;
                justify-content: center;
                border: 1px solid var(--gold);
            }

            .btn-submit {
                background: linear-gradient(90deg, var(--black) 0%, var(--gold) 100%);
                color: var(--white);
                padding: 8px 20px;
                border-radius: 50px;
                font-weight: 600;
                border: none;
                transition: all 0.3s ease;
            }

            .btn-submit:hover {
                background: linear-gradient(90deg, var(--gold) 0%, var(--black) 100%);
                transform: translateY(-3px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            }

            .btn-submit:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }

            .question-section {
                background-color: var(--white);
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
                border: 1px solid var(--dark-gray);
            }

            .question-section:hover {
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }

            .section-header {
                background: linear-gradient(90deg, var(--black) 0%, var(--dark-black) 100%);
                color: var(--gold);
                padding: 15px 20px;
                font-weight: 600;
            }

            .section-content {
                padding: 20px;
            }

            .question-text {
                background-color: var(--gray);
                padding: 15px;
                border-radius: 8px;
                border-left: 4px solid var(--gold);
                font-size: 1.05rem;
                line-height: 1.5;
            }

            .question-image-container {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100%;
            }

            .image-wrap {
                border: 2px solid var(--dark-gray);
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                background-color: var(--white);
                padding: 5px;
                transition: all 0.3s ease;
            }

            .image-wrap:hover {
                transform: scale(1.05);
                box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            }

            .question-image {
                max-width: 100%;
                max-height: 300px;
                object-fit: contain;
            }

            .mcq-container {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .mcq-option {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                background-color: var(--gray);
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                margin: 0;
                border: 1px solid transparent;
            }

            .mcq-option:hover {
                background-color: var(--light-gold);
                border-color: var(--gold);
            }

            .mcq-option input[type="radio"] {
                margin-right: 12px;
                width: 18px;
                height: 18px;
                accent-color: var(--gold);
            }

            .mcq-option input[type="radio"]:checked + .mcq-text {
                font-weight: 600;
                color: var(--black);
            }

            .mcq-text {
                font-size: 1rem;
                color: #333;
                transition: all 0.2s ease;
            }

            .fancy-input {
                border: 2px solid var(--dark-gray);
                border-radius: 0 8px 8px 0;
                transition: all 0.3s ease;
                font-size: 1.1rem;
                padding: 12px 15px;
                height: auto;
                background-color: var(--white);
            }

            .fancy-input:focus {
                border-color: var(--gold);
                box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
                background-color: var(--light-gold);
            }

            .input-group-text {
                min-width: 50px;
                justify-content: center;
                border-radius: 8px 0 0 8px;
                border: none;
            }

            .progress-container {
                margin-top: 30px;
                margin-bottom: 50px;
            }

            .progress-container .card {
                background: linear-gradient(145deg, var(--gray) 0%, var(--white) 100%);
                border: none;
                border-radius: 10px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }

            .progress-wrapper {
                padding: 10px;
            }

            .progress-info {
                display: flex;
                justify-content: space-between;
                background: transparent;
                color: var(--black);
                padding: 0;
                min-width: auto;
            }

            .progress-label {
                font-weight: 600;
                color: var(--black);
            }

            .progress-percentage {
                color: #6c757d;
            }

            .progress {
                height: 10px;
                border-radius: 5px;
                background-color: var(--dark-gray);
            }

            .progress-bar {
                border-radius: 5px;
                background: linear-gradient(90deg, var(--black) 0%, var(--gold) 100%);
                transition: all 0.6s ease;
            }

            .modal-content {
                border: none;
                border-radius: 12px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            }

            .modal-header {
                background: linear-gradient(90deg, var(--black) 0%, var(--dark-black) 100%);
                color: var(--gold);
                border-bottom: none;
                border-radius: 12px 12px 0 0;
            }

            .btn-gold {
                background: linear-gradient(90deg, var(--black) 0%, var(--gold) 100%);
                color: var(--white);
                border: none;
                transition: all 0.3s ease;
            }

            .btn-gold:hover {
                background: linear-gradient(90deg, var(--gold) 0%, var(--black) 100%);
                color: var(--white);
                transform: translateY(-2px);
            }

            .toast {
                border: none;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }

            @media (max-width: 768px) {
                .assessment-header-wrapper {
                    padding: 10px;
                }
                
                .timer, .progress-pill {
                    font-size: 0.9rem;
                    padding: 6px 10px;
                    min-width: auto;
                }
                
                .btn-submit {
                    padding: 6px 12px;
                    font-size: 0.9rem;
                }
                
                .question-section {
                    margin-bottom: 15px;
                }
                
                .section-header {
                    padding: 10px 15px;
                }
                
                .section-content {
                    padding: 15px;
                }
                
                .question-text {
                    padding: 10px;
                    font-size: 0.95rem;
                }
                
                .mcq-option {
                    padding: 10px;
                }
                
                .mcq-text {
                    font-size: 0.95rem;
                }
                
                .fancy-input {
                    font-size: 1rem;
                    padding: 8px 12px;
                }
            }

            @keyframes saving-pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; }
                100% { opacity: 1; }
            }

            .saving {
                animation: saving-pulse 1s infinite;
            }

            .question-answered {
                border-left: 5px solid var(--gold);
            }

            .question-highlight {
                border-color: var(--gold);
                box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.25);
            }

            .scroll-to-top {
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--black);
                color: var(--gold);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                opacity: 0;
                transition: all 0.3s ease;
                z-index: 999;
            }

            .scroll-to-top.active {
                opacity: 1;
            }

            .scroll-to-top:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            }

            .answer-info {
                background-color: var(--light-gold);
                padding: 10px 15px;
                border-radius: 8px;
                font-weight: 500;
                border-left: 3px solid var(--gold);
            }

            .alert-warning {
                background-color: #fff3cd;
                border-color: #ffeaa7;
                color: #856404;
                border-left: 4px solid #ffc107;
            }

            .alert-info {
                background-color: #d1ecf1;
                border-color: #bee5eb;
                color: #0c5460;
                border-left: 4px solid #17a2b8;
            }
        </style>
    

<script>
    document.addEventListener('DOMContentLoaded', function() {
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const ASSESSMENT_ID = <?php echo $assessmentId; ?>;
    const ATTEMPT_ID = <?php echo $attempt['attempt_id']; ?>;
    const CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
    const TOTAL_QUESTIONS = <?php echo count($questions); ?>;
    const IS_RESET_ATTEMPT = <?php echo $isResetAttempt ? 'true' : 'false'; ?>;
    
    let timeOffset = 0;
    let hasSubmitted = false;
    let isAutoSaving = false;
    let lastSavedInputs = {};
    let answeredQuestions = 0;
    
    const form = document.getElementById('assessmentForm');
    const submitBtn = document.getElementById('submitBtn');
    const confirmSubmitBtn = document.getElementById('confirmSubmit');
    const inputs = document.querySelectorAll('.question-input');
    const questionsContainers = document.querySelectorAll('.question-section');
    const answerCounter = document.getElementById('answeredCount');
    const modalAnswerCounter = document.getElementById('modalAnsweredCount');
    const timeUpAnswerCounter = document.getElementById('timeUpAnsweredCount');
    const progressBar = document.getElementById('progressBar');
    const progressCount = document.getElementById('progressCount');
    const timeDisplayEl = document.getElementById('timeDisplay');
    const modalTimeRemaining = document.getElementById('modalTimeRemaining');
    const submitModal = new bootstrap.Modal(document.getElementById('submitModal'));
    const timeUpModal = new bootstrap.Modal(document.getElementById('timeUpModal'));
    const saveToast = new bootstrap.Toast(document.getElementById('saveToast'), {
        delay: 2000
    });
    const saveMessage = document.getElementById('saveMessage');
    const timeOffsetInput = document.getElementById('timeOffset');
    
    <?php if ($timeLeft !== null): ?>
    let timeLeft = <?php echo $timeLeft; ?>;
    let timerInterval;
    let isTimerWarning = false;
    let isTimerDanger = false;
    let timerSync = true;
    const timerEl = document.getElementById('timer');
    const FIVE_MINUTES = 5 * 60;
    const ONE_MINUTE = 60;
    
    // Show notification for reset attempts
    if (IS_RESET_ATTEMPT) {
        if (Notification.permission === 'granted') {
            new Notification('Assessment Reset', {
                body: 'Your assessment has been reset. Your timer is now active.',
                icon: `${BASE_URL}/assets/images/logo.png`
            });
        }
    }
    
    syncTime();
    startTimer();
    
    const timerSyncInterval = setInterval(() => {
        if (timeLeft > 0 && timerSync) {
            syncTime();
        }
    }, 30000);
    <?php endif; ?>
    
    init();
    
    inputs.forEach(input => {
        if (input.type === 'text' || input.type === 'textarea') {
            input.addEventListener('input', debounce(handleInputChange, 800));
            input.addEventListener('blur', handleInputChange);
        }
        else if (input.type === 'radio') {
            input.addEventListener('change', handleInputChange);
        }
    });
    
    form.addEventListener('submit', handleFormSubmit);
    submitBtn.addEventListener('click', showSubmitConfirmation);
    confirmSubmitBtn.addEventListener('click', confirmSubmit);


    function init() {
        createScrollToTopButton();
        highlightAnsweredQuestions();
        updateAnsweredCount();
        updateSubmitButton();
        window.addEventListener('scroll', debounce(trackVisibleQuestions, 100));
    }
    
    function handleInputChange(e) {
        const input = e.target;
        const questionId = getQuestionIdFromInput(input);
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
        
        if (lastSavedInputs[questionId] === answerText) {
            return;
        }
        
        lastSavedInputs[questionId] = answerText;
        
        if (answerText) {
            questionContainer.classList.add('question-answered');
        } else {
            questionContainer.classList.remove('question-answered');
        }
        
        updateAnsweredCount();
        updateSubmitButton();
        autoSaveAnswer(questionId, answerText);
    }
    
    function autoSaveAnswer(questionId, answerText) {
        if (isAutoSaving) return;
        
        isAutoSaving = true;
        
        const formData = new FormData();
        formData.append('assessment_id', ASSESSMENT_ID);
        formData.append('question_id', questionId);
        formData.append('answer_text', answerText);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('action', 'save');
        
        fetch(`${BASE_URL}/api/take_assessment.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const questionContainer = document.getElementById(`question-${questionId}`);
                questionContainer.classList.add('saving');
                setTimeout(() => {
                    questionContainer.classList.remove('saving');
                }, 1000);
            } else {
                saveMessage.textContent = 'Error saving answer. Please try again.';
                saveToast.show();
            }
            
            isAutoSaving = false;
        })
        .catch(error => {
            saveMessage.textContent = 'Network error. Please check your connection.';
            saveToast.show();
            isAutoSaving = false;
        });
    }
    
    function updateAnsweredCount() {
        const answered = document.querySelectorAll('.question-answered').length;
        answeredQuestions = answered;
        
        answerCounter.textContent = answered;
        modalAnswerCounter.textContent = answered;
        timeUpAnswerCounter.textContent = answered;
        progressCount.textContent = answered;
        
        const percentage = (answered / TOTAL_QUESTIONS) * 100;
        progressBar.style.width = `${percentage}%`;
    }
    
    function updateSubmitButton() {
        if (submitBtn) {
            submitBtn.disabled = answeredQuestions !== TOTAL_QUESTIONS;
            
            if (!submitBtn.disabled) {
                submitBtn.classList.add('pulse-animation');
            } else {
                submitBtn.classList.remove('pulse-animation');
            }
        }
    }
    
    function highlightAnsweredQuestions() {
        questionsContainers.forEach(container => {
            const questionId = container.dataset.questionId;
            const inputs = container.querySelectorAll('.question-input');
            let isAnswered = false;
            
            inputs.forEach(input => {
                if (input.type === 'radio' && input.checked) {
                    isAnswered = true;
                }
                else if ((input.type === 'text' || input.type === 'textarea') && input.value.trim()) {
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
                            lastSavedInputs[questionId] = checkedInput.value;
                        }
                    } else if (questionInputs[0].name.includes('[]')) {
                        const allInputs = document.querySelectorAll(`input[name="answer[${questionId}][]"]`);
                        const values = Array.from(allInputs).map(inp => inp.value.trim()).filter(val => val);
                        lastSavedInputs[questionId] = values.join("\n");
                    } else {
                        lastSavedInputs[questionId] = questionInputs[0].value.trim();
                    }
                }
            } else {
                container.classList.remove('question-answered');
            }
        });
    }
    
    function showSubmitConfirmation() {
        modalAnswerCounter.textContent = answeredQuestions;
        
        <?php if ($timeLeft !== null): ?>
        modalTimeRemaining.textContent = formatTime(timeLeft);
        <?php endif; ?>
        
        submitModal.show();
    }
    
    function confirmSubmit() {
        if (hasSubmitted) return;
        
        hasSubmitted = true;
        submitModal.hide();
        
        confirmSubmitBtn.disabled = true;
        confirmSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Submitting...';
        
        const formData = new FormData(form);
        
        fetch(`${BASE_URL}/api/take_assessment.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.href = data.data.redirect_url;
            } else {
                alert('Error submitting assessment: ' + data.message);
                hasSubmitted = false;
                confirmSubmitBtn.disabled = false;
                confirmSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Assessment';
            }
        })
        .catch(error => {
            alert('Network error during submission. Please check your connection and try again.');
            hasSubmitted = false;
            confirmSubmitBtn.disabled = false;
            confirmSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Assessment';
        });
    }
    
    function handleFormSubmit(e) {
        e.preventDefault();
        
        if (hasSubmitted) return;
        
        showSubmitConfirmation();
    }
    
    function autoSubmit() {
        if (hasSubmitted) return;
        
        hasSubmitted = true;
        
        timeUpModal.show();
        
        const formData = new FormData();
        formData.append('assessment_id', ASSESSMENT_ID);
        formData.append('attempt_id', ATTEMPT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('action', 'autosubmit');
        
        fetch(`${BASE_URL}/api/take_assessment.php`, {
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
    
    <?php if ($timeLeft !== null): ?>
    function startTimer() {
        timerInterval = setInterval(() => {
            timeLeft--;
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                timeLeft = 0;
                autoSubmit();
            }
            
            updateTimerDisplay();
        }, 1000);
        
        updateTimerDisplay();
    }
    
    function updateTimerDisplay() {
        if (timeLeft <= 0) {
            timeDisplayEl.textContent = '00:00:00';
            timerEl.classList.remove('warning');
            timerEl.classList.add('danger');
            return;
        }
        
        timeDisplayEl.textContent = formatTime(timeLeft);
        
        if (timeLeft <= FIVE_MINUTES && !isTimerWarning) {
            timerEl.classList.add('warning');
            isTimerWarning = true;
            
            if (Notification.permission === 'granted') {
                new Notification('5 Minutes Remaining!', {
                    body: 'You have 5 minutes left to complete your assessment.',
                    icon: `${BASE_URL}/assets/images/logo.png`
                });
            }
        }
        
        if (timeLeft <= ONE_MINUTE && !isTimerDanger) {
            timerEl.classList.remove('warning');
            timerEl.classList.add('danger');
            isTimerDanger = true;
            
            if (Notification.permission === 'granted') {
                new Notification('1 Minute Remaining!', {
                    body: 'You have only 1 minute left to complete your assessment!',
                    icon: `${BASE_URL}/assets/images/logo.png`
                });
            }
        }
    }
    
    function syncTime() {
        const clientTime = Math.floor(Date.now() / 1000);
        
        fetch(`${BASE_URL}/api/take_assessment.php?action=sync&client_time=${clientTime}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    timeOffset = data.data.offset;
                    timeOffsetInput.value = timeOffset;
                }
            })
            .catch(error => {
                timerSync = false;
            });
    }
    <?php endif; ?>
    
    function formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        return [
            hours.toString().padStart(2, '0'),
            minutes.toString().padStart(2, '0'),
            secs.toString().padStart(2, '0')
        ].join(':');
    }
    
    function trackVisibleQuestions() {
        questionsContainers.forEach(container => {
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
    
    function createScrollToTopButton() {
        const button = document.createElement('div');
        button.classList.add('scroll-to-top');
        button.innerHTML = '<i class="fas fa-arrow-up"></i>';
        button.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        document.body.appendChild(button);
    }
    
    function getQuestionIdFromInput(input) {
        const name = input.name;
        const match = name.match(/answer\[(\d+)\]/);
        return match ? match[1] : null;
    }
    
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }
    
    requestNotificationPermission();
    
    const alertWarning = document.querySelector('.alert-warning');
    if (alertWarning && alertWarning.textContent.includes('Assessment Redirect')) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alertWarning);
            bsAlert.close();
        }, 8000);
    }
});
</script>

<?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>