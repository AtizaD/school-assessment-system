<?php
/**
 * Student Assessment Taking Page
 * Refactored version with modular structure
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/assessment_renderer.php';
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

    // Check for any in-progress assessment before allowing new attempts
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

    // Check if current assessment is valid and available
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

    // Check for existing attempt and handle timer start for reset attempts
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
        // Check if this is a reset attempt that needs start_time update
        if (!empty($attempt['answer_metadata'])) {
            $metadata = json_decode($attempt['answer_metadata'], true);
            if (is_array($metadata) && isset($metadata['reset_type']) && $metadata['reset_type'] === 'partial') {
                if (!isset($metadata['timer_started']) || !$metadata['timer_started']) {
                    $metadata['timer_started'] = true;
                    $metadata['actual_start_time'] = date('Y-m-d H:i:s');

                    $stmt = $db->prepare(
                        "UPDATE AssessmentAttempts
                         SET start_time = CURRENT_TIMESTAMP, answer_metadata = ?
                         WHERE attempt_id = ?"
                    );
                    $stmt->execute([json_encode($metadata), $attempt['attempt_id']]);

                    $stmt = $db->prepare("SELECT * FROM AssessmentAttempts WHERE attempt_id = ?");
                    $stmt->execute([$attempt['attempt_id']]);
                    $attempt = $stmt->fetch();

                    logSystemActivity(
                        'Assessment Timer Started',
                        "Timer started for reset assessment ID: $assessmentId, student ID: {$studentInfo['student_id']}",
                        'INFO'
                    );
                }
            }
        }
    }

    // Handle question pooling
    $selectedQuestionIds = [];
    if ($assessment['use_question_limit'] && $assessment['questions_to_answer']) {
        if (empty($attempt['question_order'])) {
            $stmt = $db->prepare("SELECT question_id FROM questions WHERE assessment_id = ?");
            $stmt->execute([$assessmentId]);
            $allQuestionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($allQuestionIds) < $assessment['questions_to_answer']) {
                throw new Exception('Insufficient questions available for this assessment');
            }

            shuffle($allQuestionIds);
            $selectedQuestionIds = array_slice($allQuestionIds, 0, $assessment['questions_to_answer']);

            $stmt = $db->prepare("UPDATE AssessmentAttempts SET question_order = ? WHERE attempt_id = ?");
            $stmt->execute([json_encode($selectedQuestionIds), $attempt['attempt_id']]);
            $attempt['question_order'] = json_encode($selectedQuestionIds);
        } else {
            $selectedQuestionIds = json_decode($attempt['question_order'], true);
        }

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

    // Handle question shuffling
    if ($assessment['shuffle_questions'] && !($assessment['use_question_limit'] && !empty($attempt['question_order']))) {
        if (empty($attempt['question_order'])) {
            $questionIds = array_column($questions, 'question_id');
            shuffle($questionIds);

            $stmt = $db->prepare("UPDATE AssessmentAttempts SET question_order = ? WHERE attempt_id = ?");
            $stmt->execute([json_encode($questionIds), $attempt['attempt_id']]);

            $stmt = $db->prepare("SELECT * FROM AssessmentAttempts WHERE attempt_id = ?");
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

    // Process question images and options
    foreach ($questions as &$question) {
        if (!empty($question['image_id'])) {
            $stmt = $db->prepare("SELECT filename FROM assessment_images WHERE image_id = ?");
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
                $optionsList[] = ['id' => $id, 'text' => $text];
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

                    $stmt = $db->prepare("UPDATE AssessmentAttempts SET option_orders = ? WHERE attempt_id = ?");
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

    // Calculate time remaining
    $timeLeft = null;
    $duration = null;
    $isResetAttempt = false;

    if ($assessment['duration']) {
        $duration = $assessment['duration'];

        if (!empty($attempt['answer_metadata'])) {
            $metadata = json_decode($attempt['answer_metadata'], true);
            if (is_array($metadata)) {
                if (isset($metadata['reset_type']) && $metadata['reset_type'] === 'partial') {
                    $isResetAttempt = true;

                    if (isset($metadata['custom_duration_minutes'])) {
                        $duration = (int)$metadata['custom_duration_minutes'];
                    }
                } elseif (isset($metadata['original_duration_minutes'])) {
                    $duration = (int)$metadata['original_duration_minutes'];
                }
            }
        }

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

<!-- Include Assessment CSS -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/assessment.css">

<div class="container-fluid py-4">
    <?php if (isset($_SESSION['assessment_redirect_message'])): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Assessment Redirect:</strong> <?php echo htmlspecialchars($_SESSION['assessment_redirect_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['assessment_redirect_message']); ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <?php renderAssessmentHeader($assessment, $attempt, $questions, $timeLeft, $isResetAttempt); ?>

        <form id="assessmentForm" method="POST" action="<?php echo BASE_URL; ?>/api/take_assessment.php">
            <input type="hidden" name="assessment_id" value="<?php echo $assessmentId; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo $attempt['attempt_id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="time_offset" id="timeOffset" value="0">

            <div class="row">
                <div class="col-12">
                    <?php foreach ($questions as $index => $question): ?>
                        <?php renderQuestion($question, $index); ?>
                    <?php endforeach; ?>

                    <?php renderProgressContainer(count($questions)); ?>
                </div>
            </div>
        </form>

        <?php renderSubmitModal($assessment, count($questions), $timeLeft, $isResetAttempt); ?>
        <?php renderTimeUpModal($assessment, count($questions)); ?>
        <?php renderSaveToast(); ?>
    <?php endif; ?>
</div>

<!-- Include JavaScript Modules -->
<script src="<?php echo BASE_URL; ?>/assets/js/assessment-offline.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/assessment-ui.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const ASSESSMENT_ID = <?php echo $assessmentId; ?>;
    const ATTEMPT_ID = <?php echo $attempt['attempt_id']; ?>;
    const CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
    const TOTAL_QUESTIONS = <?php echo count($questions); ?>;
    const TIME_LEFT = <?php echo $timeLeft !== null ? $timeLeft : 'null'; ?>;
    const DURATION = <?php echo $duration !== null ? $duration : 'null'; ?>;

    // Initialize offline manager
    const offlineManager = new AssessmentOfflineManager({
        assessmentId: ASSESSMENT_ID,
        attemptId: ATTEMPT_ID,
        csrfToken: CSRF_TOKEN,
        baseUrl: BASE_URL
    });

    // Initialize UI manager
    const uiManager = new AssessmentUIManager({
        assessmentId: ASSESSMENT_ID,
        attemptId: ATTEMPT_ID,
        csrfToken: CSRF_TOKEN,
        baseUrl: BASE_URL,
        totalQuestions: TOTAL_QUESTIONS,
        timeLeft: TIME_LEFT,
        duration: DURATION
    });

    // Wire up callbacks between managers
    offlineManager.onConnectionRestored = () => {
        uiManager.showToast('Connection restored. Syncing answers...');
    };

    offlineManager.onConnectionLost = () => {
        uiManager.showToast('Working offline. Answers saved locally.');
    };

    offlineManager.onAnswerSynced = (questionId) => {
        const questionContainer = document.getElementById(`question-${questionId}`);
        if (questionContainer) {
            questionContainer.classList.add('saving');
            setTimeout(() => {
                questionContainer.classList.remove('saving');
            }, 1000);
        }
    };

    uiManager.onAnswerChanged = (questionId, answerText) => {
        offlineManager.saveAnswer(questionId, answerText);
    };

    uiManager.getUnsyncedCount = () => {
        return offlineManager.getUnsyncedCount();
    };

    uiManager.syncAllAnswers = () => {
        offlineManager.syncAllPendingAnswers();
    };

    // Handle redirect message auto-dismiss
    const alertWarning = document.querySelector('.alert-warning');
    if (alertWarning && alertWarning.textContent.includes('Assessment Redirect')) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alertWarning);
            bsAlert.close();
        }, 8000);
    }
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>
