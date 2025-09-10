<?php
// student/view_result.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// WAEC Grading System Function
function getWAECGrade($percentage) {
    if ($percentage >= 75) {
        return ['grade' => 'A1', 'description' => 'Excellent', 'class' => 'grade-a1'];
    } elseif ($percentage >= 70) {
        return ['grade' => 'B2', 'description' => 'Very Good', 'class' => 'grade-b2'];
    } elseif ($percentage >= 65) {
        return ['grade' => 'B3', 'description' => 'Good', 'class' => 'grade-b3'];
    } elseif ($percentage >= 60) {
        return ['grade' => 'C4', 'description' => 'Credit', 'class' => 'grade-c4'];
    } elseif ($percentage >= 55) {
        return ['grade' => 'C5', 'description' => 'Credit', 'class' => 'grade-c5'];
    } elseif ($percentage >= 50) {
        return ['grade' => 'C6', 'description' => 'Credit', 'class' => 'grade-c6'];
    } elseif ($percentage >= 45) {
        return ['grade' => 'D7', 'description' => 'Pass', 'class' => 'grade-d7'];
    } elseif ($percentage >= 40) {
        return ['grade' => 'E8', 'description' => 'Pass', 'class' => 'grade-e8'];
    } else {
        return ['grade' => 'F9', 'description' => 'Fail', 'class' => 'grade-f9'];
    }
}

function getGradeColor($gradeClass) {
    $colors = [
        'grade-a1' => '#28a745', // Green
        'grade-b2' => '#20c997', // Teal
        'grade-b3' => '#17a2b8', // Info blue
        'grade-c4' => '#ffc107', // Warning yellow
        'grade-c5' => '#fd7e14', // Orange
        'grade-c6' => '#e83e8c', // Pink
        'grade-d7' => '#6f42c1', // Purple
        'grade-e8' => '#dc3545', // Danger red
        'grade-f9' => '#343a40'  // Dark gray
    ];
    
    return $colors[$gradeClass] ?? '#6c757d';
}

try {
    if (!$assessmentId) {
        throw new Exception('Invalid assessment ID');
    }

    $db = DatabaseConfig::getInstance()->getConnection();

    // Get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id 
         FROM students s 
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Get assessment details with result and attempt info, including question pool settings
    $stmt = $db->prepare(
        "SELECT 
            a.*,
            s.subject_name,
            CONCAT(a.date, ' ', a.end_time) as assessment_end_datetime,
            NOW() as current_datetime,
            r.score,
            aa.start_time,
            aa.end_time,
            aa.status as attempt_status,
            aa.question_order
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN results r ON (
            a.assessment_id = r.assessment_id 
            AND r.student_id = ?
         )
         LEFT JOIN assessmentattempts aa ON (
            a.assessment_id = aa.assessment_id 
            AND aa.student_id = ?
         )
         WHERE a.assessment_id = ? AND ac.class_id = ?"
    );
    $stmt->execute([
        $studentInfo['student_id'],
        $studentInfo['student_id'],
        $assessmentId,
        $studentInfo['class_id']
    ]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found or not assigned to your class');
    }

    // Ensure score is numeric - handle NULL case
    $assessment['score'] = is_null($assessment['score']) ? 0 : (float)$assessment['score'];
    
    // Check if assessment end time has passed
    $assessmentTimeEnded = (strtotime($assessment['current_datetime']) > strtotime($assessment['assessment_end_datetime']));

    // Determine if detailed review should be shown
    $showDetailedReview = $assessmentTimeEnded || $assessment['status'] === 'completed';

    // Determine which questions to get based on question pooling
    $selectedQuestionIds = [];
    $isQuestionPooling = $assessment['use_question_limit'] && $assessment['questions_to_answer'];
    
    if ($isQuestionPooling && !empty($assessment['question_order'])) {
        $selectedQuestionIds = json_decode($assessment['question_order'], true);
        if (!is_array($selectedQuestionIds)) {
            $selectedQuestionIds = [];
        }
    }

    // Get questions with student answers and scores - only the questions this student actually saw
    if ($isQuestionPooling && !empty($selectedQuestionIds)) {
        // Question pooling is enabled and we have selected questions
        $placeholders = str_repeat('?,', count($selectedQuestionIds) - 1) . '?';
        $stmt = $db->prepare(
            "SELECT 
                q.*,
                sa.answer_text as student_answer,
                sa.answer_text as original_answer_text,
                sa.score as question_score,
                CASE 
                    WHEN q.question_type = 'MCQ' THEN (
                        SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                        FROM mcqquestions mcq 
                        WHERE mcq.question_id = q.question_id
                    )
                    ELSE NULL
                END as mcq_data
             FROM questions q
             LEFT JOIN studentanswers sa ON (
                q.question_id = sa.question_id 
                AND sa.student_id = ? 
                AND sa.assessment_id = ?
             )
             WHERE q.question_id IN ($placeholders)
             ORDER BY FIELD(q.question_id, " . implode(',', $selectedQuestionIds) . ")"
        );
        $stmt->execute(array_merge([$studentInfo['student_id'], $assessmentId], $selectedQuestionIds));
        $questions = $stmt->fetchAll();
    } else {
        // Normal assessment or no question pooling - get all questions
        $stmt = $db->prepare(
            "SELECT 
                q.*,
                sa.answer_text as student_answer,
                sa.answer_text as original_answer_text,
                sa.score as question_score,
                CASE 
                    WHEN q.question_type = 'MCQ' THEN (
                        SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                        FROM mcqquestions mcq 
                        WHERE mcq.question_id = q.question_id
                    )
                    ELSE NULL
                END as mcq_data
             FROM questions q
             LEFT JOIN studentanswers sa ON (
                q.question_id = sa.question_id 
                AND sa.student_id = ? 
                AND sa.assessment_id = ?
             )
             WHERE q.assessment_id = ?
             ORDER BY q.question_id"
        );
        $stmt->execute([
            $studentInfo['student_id'],
            $assessmentId,
            $assessmentId
        ]);
        $questions = $stmt->fetchAll();
    }

    // Process questions for display and ensure values are numeric
    foreach ($questions as &$question) {
        // Ensure question_score is numeric
        $question['question_score'] = is_null($question['question_score']) ? 0 : (float)$question['question_score'];
        
        if ($question['question_type'] === 'MCQ' && $question['mcq_data']) {
            $options = [];
            $mcqData = explode('|', $question['mcq_data']);
            foreach ($mcqData as $data) {
                list($id, $text, $isCorrect) = explode(':', $data);
                $options[] = [
                    'id' => $id,
                    'text' => $text,
                    'is_correct' => $isCorrect,
                    'is_selected' => ($question['student_answer'] == $id)
                ];
            }
            $question['options'] = $options;
        } elseif ($question['question_type'] === 'Short Answer') {
            if ($question['answer_mode'] === 'any_match') {
                $question['valid_answers'] = json_decode($question['correct_answer'], true);
                $question['original_answers'] = array_map('trim', explode("\n", $question['original_answer_text'] ?? ''));
                $question['student_answers'] = array_values(array_unique(array_map('strtolower', $question['original_answers'])));
                $answerCount = array_count_values(array_map('strtolower', $question['original_answers']));
                $question['duplicates'] = array_filter($answerCount, function ($count) {
                    return $count > 1;
                });
            }
        }
    }
    unset($question);

    // Calculate total max score - ONLY for questions this student actually saw
    $totalMaxScore = array_sum(array_column($questions, 'max_score'));
    $scorePercentage = $totalMaxScore > 0
        ? ($assessment['score'] / $totalMaxScore) * 100
        : 0;

    // Get WAEC Grade
    $waecGrade = getWAECGrade($scorePercentage);

   // Calculate attempt duration
$duration = null;
if ($assessment['start_time'] && $assessment['end_time']) {
    // Check if this assessment has been reset and get the reset type
    $stmt = $db->prepare(
        "SELECT reset_type FROM assessmentresets 
         WHERE assessment_id = ? AND student_id = ?
         ORDER BY reset_time DESC LIMIT 1"
    );
    $stmt->execute([$assessmentId, $studentInfo['student_id']]);
    $resetType = $stmt->fetchColumn();
    
    if ($resetType === 'partial') {
        // For partial resets, use the full duration of the assessment
        if ($assessment['duration']) {
            // Use the configured test duration (in minutes)
            $durationInMinutes = $assessment['duration'];
            $hours = floor($durationInMinutes / 60);
            $minutes = $durationInMinutes % 60;
            $seconds = 0;
            
            // Create a duration object for display consistency
            $duration = new DateInterval("PT{$hours}H{$minutes}M{$seconds}S");
        } else {
            // If no configured duration, try to get time from first attempt to last completion
            $stmt = $db->prepare(
                "SELECT MIN(start_time) as first_start
                 FROM assessmentattempts 
                 WHERE assessment_id = ? AND student_id = ?"
            );
            $stmt->execute([$assessmentId, $studentInfo['student_id']]);
            $firstStart = $stmt->fetchColumn();
            
            if ($firstStart) {
                $start = new DateTime($firstStart);
                $end = new DateTime($assessment['end_time']);
                $duration = $end->diff($start);
            }
        }
    } else {
        // For full resets or no resets, use the current attempt duration
        $start = new DateTime($assessment['start_time']);
        $end = new DateTime($assessment['end_time']);
        $duration = $end->diff($start);
    }
}
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("View result error: " . $e->getMessage());
}

$pageTitle = 'Assessment Result';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="result-container">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <!-- Result Header -->
        <div class="result-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><?php echo htmlspecialchars($assessment['title']); ?></h1>
                    <p class="text-light mb-0">
                        <?php echo htmlspecialchars($assessment['subject_name']); ?> |
                        <?php echo date('F d, Y', strtotime($assessment['date'])); ?>
                        <?php if ($isQuestionPooling): ?>
                            | <span class="badge bg-warning text-dark ms-2">
                                <i class="fas fa-layer-group me-1"></i>Question Pool
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="assessments.php" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Assessments
                </a>
            </div>
        </div>

        <!-- Question Pool Info -->
        <?php if ($isQuestionPooling): ?>
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Question Pool Assessment:</strong> You answered 
                <strong><?php echo count($questions); ?> randomly selected questions</strong> 
                from a pool of questions for this assessment.
            </div>
        <?php endif; ?>

        <!-- Score Overview -->
        <div class="score-overview">
            <div class="row g-4">
                <div class="col-md-3 col-6">
                    <div class="score-card h-100">
                        <div class="score-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <h6 class="text-muted mb-2">Final Score</h6>
                        <h3 class="mb-0">
                            <?php echo number_format($assessment['score'], 1); ?> /
                            <?php echo number_format($totalMaxScore, 1); ?>
                        </h3>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="score-card h-100">
                        <div class="score-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h6 class="text-muted mb-2">Duration</h6>
                        <h3 class="mb-0">
                            <?php
                            if ($duration) {
                                echo sprintf(
                                    '%02d:%02d:%02d',
                                    ($duration->days * 24) + $duration->h,
                                    $duration->i,
                                    $duration->s
                                );
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </h3>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="score-card h-100">
                        <div class="score-icon waec-grade-icon" style="background-color: <?php echo getGradeColor($waecGrade['class']); ?>;">
                            <i class="fas fa-award"></i>
                        </div>
                        <h6 class="text-muted mb-2">WAEC Grade</h6>
                        <h3 class="mb-0 waec-grade-text" style="color: <?php echo getGradeColor($waecGrade['class']); ?>;">
                            <?php echo $waecGrade['grade']; ?>
                            <small class="d-block mt-1" style="font-size: 0.7rem; color: #6c757d;">
                                <?php echo $waecGrade['description']; ?>
                            </small>
                        </h3>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="score-card h-100">
                        <div class="score-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h6 class="text-muted mb-2">Questions Answered</h6>
                        <h3 class="mb-0"><?php echo count($questions); ?></h3>
                        <?php if ($isQuestionPooling): ?>
                            <small class="text-muted">
                                (from pool of <?php echo $assessment['questions_to_answer']; ?> selected)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grade Performance Indicator -->
        <div class="grade-performance-card mb-4">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="card-title mb-2">
                                <i class="fas fa-trophy text-warning me-2"></i>
                                Performance Summary
                            </h5>
                            <p class="mb-2">
                                You scored <strong><?php echo number_format($scorePercentage, 1); ?>%</strong> 
                                and achieved a <strong class="waec-grade-highlight" style="color: <?php echo getGradeColor($waecGrade['class']); ?>;">
                                    <?php echo $waecGrade['grade']; ?> (<?php echo $waecGrade['description']; ?>)
                                </strong> grade.
                            </p>
                            <?php if ($scorePercentage >= 50): ?>
                                <p class="text-success mb-0">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Congratulations! You have successfully passed this assessment.
                                </p>
                            <?php else: ?>
                                <p class="text-danger mb-0">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    You need to improve your performance. Consider reviewing the material and trying again.
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="grade-circle" style="border-color: <?php echo getGradeColor($waecGrade['class']); ?>;">
                                <div class="grade-content" style="color: <?php echo getGradeColor($waecGrade['class']); ?>;">
                                    <div class="grade-letter"><?php echo $waecGrade['grade']; ?></div>
                                    <div class="grade-percentage"><?php echo number_format($scorePercentage, 1); ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($showDetailedReview): ?>
            <div class="detailed-results">
                <h4 class="section-title">Question Review</h4>
                <?php if ($isQuestionPooling): ?>
                    <div class="alert alert-secondary mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> This shows only the questions you were randomly assigned from the question pool.
                    </div>
                <?php endif; ?>

                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-card">
                        <div class="question-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="question-number">Question <?php echo $index + 1; ?></span>
                                <div class="d-flex align-items-center">
                                    <span class="question-score me-2">
                                        <?php echo number_format($question['question_score'], 1); ?>/
                                        <?php echo number_format($question['max_score'], 1); ?>
                                    </span>
                                    <?php
                                    $questionMaxScore = (float)$question['max_score'];
                                    $questionPercentage = $questionMaxScore > 0 
                                        ? ($question['question_score'] / $questionMaxScore) * 100 
                                        : 0;
                                    $questionGrade = getWAECGrade($questionPercentage);
                                    ?>
                                    <span class="score-badge" style="background-color: <?php echo getGradeColor($questionGrade['class']); ?>;">
                                        <?php echo $questionGrade['grade']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="question-content">
                            <div class="question-text mb-3">
                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                            </div>

                            <?php if ($question['question_type'] === 'MCQ'): ?>
                                <div class="mcq-options">
                                    <?php foreach ($question['options'] as $option): ?>
                                        <div class="mcq-option <?php
                                                                echo $option['is_selected'] ? 'selected' : '';
                                                                echo $option['is_correct'] ? ' correct' : '';
                                                                ?>">
                                            <i class="fas <?php
                                                            echo $option['is_selected'] ?
                                                                ($option['is_correct'] ? 'fa-check-circle' : 'fa-times-circle') :
                                                                'fa-circle';
                                                            ?>"></i>
                                            <?php echo htmlspecialchars($option['text']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="short-answer">
                                    <?php if (isset($question['answer_mode']) && $question['answer_mode'] === 'any_match'): ?>
                                        <div class="answer-section">
                                            <h6>Your Answers:</h6>
                                            <ul class="answer-list">
                                                <?php foreach ($question['original_answers'] as $index => $answer): ?>
                                                    <?php
                                                    $lowerAnswer = strtolower($answer);
                                                    $isDuplicate = isset($question['duplicates'][$lowerAnswer]);
                                                    $isValid = in_array($lowerAnswer, array_map('strtolower', $question['valid_answers']));
                                                    ?>
                                                    <li class="<?php
                                                                echo $isValid ? 'correct' : 'incorrect';
                                                                echo $isDuplicate ? ' duplicate' : '';
                                                                ?>">
                                                        <?php echo htmlspecialchars($answer); ?>
                                                        <?php if ($isDuplicate): ?>
                                                            <span class="duplicate-badge" title="This answer was submitted multiple times">
                                                                <i class="fas fa-copy"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>

                                            <?php if (!empty($question['duplicates'])): ?>
                                                <div class="alert alert-warning mt-2">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    Note: Duplicate answers were submitted but only counted once for grading.
                                                </div>
                                            <?php endif; ?>

                                            <h6 class="mt-3">Valid Answers:</h6>
                                            <ul class="answer-list">
                                                <?php if (isset($question['valid_answers']) && is_array($question['valid_answers'])): ?>
                                                    <?php foreach ($question['valid_answers'] as $answer): ?>
                                                        <li class="valid"><?php echo htmlspecialchars($answer); ?></li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <li class="valid">No valid answers defined</li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <div class="answer-section">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6>Your Answer:</h6>
                                                    <div class="answer-box">
                                                        <?php echo htmlspecialchars($question['student_answer'] ?? 'No answer provided'); ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6>Correct Answer:</h6>
                                                    <div class="answer-box correct">
                                                        <?php echo htmlspecialchars($question['correct_answer'] ?? 'Not available'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="review-restricted">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <h5 class="alert-heading">Detailed question review not available yet</h5>
                    <p>The detailed question review will be available once the assessment time has ended.</p>

                    <?php
                    // Calculate time remaining until assessment ends
                    $endDateTime = new DateTime($assessment['assessment_end_datetime']);
                    $currentDateTime = new DateTime($assessment['current_datetime']);
                    $timeRemaining = $currentDateTime->diff($endDateTime);

                    // Format remaining time
                    $daysLeft = $timeRemaining->days;
                    $hoursLeft = $timeRemaining->h;
                    $minutesLeft = $timeRemaining->i;
                    $secondsLeft = $timeRemaining->s;
                    ?>

                    <div class="time-remaining-section mt-3 mb-2">
                        <h6>Time remaining until review is available:</h6>
                        <div class="countdown-timer" id="countdown-timer">
                            <div class="time-units">
                                <span class="time-value"><?php echo $daysLeft; ?></span>
                                <span class="time-label">Days</span>
                            </div>
                            <div class="time-units">
                                <span class="time-value"><?php echo str_pad($hoursLeft, 2, '0', STR_PAD_LEFT); ?></span>
                                <span class="time-label">Hours</span>
                            </div>
                            <div class="time-units">
                                <span class="time-value"><?php echo str_pad($minutesLeft, 2, '0', STR_PAD_LEFT); ?></span>
                                <span class="time-label">Minutes</span>
                            </div>
                            <div class="time-units">
                                <span class="time-value"><?php echo str_pad($secondsLeft, 2, '0', STR_PAD_LEFT); ?></span>
                                <span class="time-label">Seconds</span>
                            </div>
                        </div>
                    </div>

                    <p class="mb-0">Please wait until the assessment end time to view your detailed results.</p>

                    <script>
                        // Add client-side countdown timer
                        document.addEventListener('DOMContentLoaded', function() {
                            // Set the assessment end time (in UTC to avoid timezone issues)
                            const endTime = new Date('<?php echo $assessment['assessment_end_datetime']; ?>').getTime();
                            
                            // Get the server time to calculate offset
                            const serverTime = new Date('<?php echo $assessment['current_datetime']; ?>').getTime();
                            const clientTime = new Date().getTime();
                            const timeOffset = clientTime - serverTime; // Difference between client and server time

                            // Update countdown every second
                            const countdownTimer = setInterval(function() {
                                const now = new Date().getTime();
                                // Adjust for server-client time difference
                                const timeLeft = endTime - (now - timeOffset);

                                if (timeLeft <= 0) {
                                    clearInterval(countdownTimer);
                                    document.getElementById('countdown-timer').innerHTML =
                                        '<div class="alert alert-success">Time is up! Refresh the page to view your detailed results.</div>';
                                } else {
                                    const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
                                    const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                                    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);

                                    // Update the countdown display
                                    document.querySelector('#countdown-timer .time-units:nth-child(1) .time-value').textContent = days;
                                    document.querySelector('#countdown-timer .time-units:nth-child(2) .time-value').textContent =
                                        hours.toString().padStart(2, '0');
                                    document.querySelector('#countdown-timer .time-units:nth-child(3) .time-value').textContent =
                                        minutes.toString().padStart(2, '0');
                                    document.querySelector('#countdown-timer .time-units:nth-child(4) .time-value').textContent =
                                        seconds.toString().padStart(2, '0');
                                }
                            }, 1000);
                        });
                    </script>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
    .result-container {
        padding: 1rem;
        max-width: 100%;
        margin: 0 auto;
        background: #ffffff;
    }

    .result-header {
        background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
        padding: 1.5rem;
        border-radius: 8px;
        color: white;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .score-overview {
        margin-bottom: 2rem;
    }

    .score-card {
        background: white;
        border-radius: 8px;
        padding: 1.5rem;
        text-align: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border-top: 4px solid #ffd700;
        transition: transform 0.2s;
    }

    .score-card:hover {
        transform: translateY(-5px);
    }

    .score-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 1rem;
        background: linear-gradient(45deg, #000000, #ffd700);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
    }

    /* WAEC Grade specific styling */
    .waec-grade-icon {
        background: linear-gradient(45deg, #ffffff, #f8f9fa) !important;
        color: #000000 !important;
        border: 3px solid;
    }

    .waec-grade-text {
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .waec-grade-highlight {
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Grade Performance Card */
    .grade-performance-card .card {
        border: none;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        overflow: hidden;
    }

    .grade-circle {
        width: 120px;
        height: 120px;
        border: 6px solid;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        background: linear-gradient(45deg, #f8f9fa, #ffffff);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .grade-content {
        text-align: center;
        font-weight: bold;
    }

    .grade-letter {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1;
        margin-bottom: 4px;
        text-transform: uppercase;
    }

    .grade-percentage {
        font-size: 0.9rem;
        font-weight: 600;
        opacity: 0.8;
    }

    /* WAEC Grade Colors */
    .grade-a1 { color: #28a745; }
    .grade-b2 { color: #20c997; }
    .grade-b3 { color: #17a2b8; }
    .grade-c4 { color: #ffc107; }
    .grade-c5 { color: #fd7e14; }
    .grade-c6 { color: #e83e8c; }
    .grade-d7 { color: #6f42c1; }
    .grade-e8 { color: #dc3545; }
    .grade-f9 { color: #343a40; }

    .section-title {
        color: #000000;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #ffd700;
    }

    .question-card {
        background: white;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    .question-header {
        background: #f8f9fa;
        padding: 1rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }

    .question-number {
        font-weight: 500;
        color: #000000;
    }

    .question-score {
        font-weight: 500;
    }

    .score-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 15px;
        font-size: 0.875rem;
        font-weight: 600;
        color: white;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .question-content {
        padding: 1.5rem;
    }

    .question-text {
        font-size: 1.1rem;
        color: #333;
        line-height: 1.5;
    }

    .mcq-options {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .mcq-option {
        padding: 0.75rem;
        border: 1px solid rgba(0, 0, 0, 0.1);
        border-radius: 6px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .mcq-option i {
        font-size: 1.25rem;
    }

    .mcq-option.selected {
        background: #f8f9fa;
    }

    .mcq-option.selected.correct {
        background: #d4edda;
        border-color: #28a745;
    }

    .mcq-option.selected:not(.correct) {
        background: #f8d7da;
        border-color: #dc3545;
    }

    .mcq-option.correct:not(.selected) {
        background: #fff3cd;
        border-color: #ffc107;
    }

    .fa-check-circle {
        color: #28a745;
    }

    .fa-times-circle {
        color: #dc3545;
    }

    .answer-section {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 6px;
    }

    .answer-section h6 {
        color: #333;
        margin-bottom: 1rem;
    }

    .answer-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .answer-list li {
        padding: 0.5rem;
        margin-bottom: 0.5rem;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .answer-list li::before {
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        margin-right: 0.5rem;
    }

    .answer-list li.correct {
        background: #d4edda;
        color: #155724;
    }

    .answer-list li.correct::before {
        content: "\f00c";
        color: #28a745;
    }

    .answer-list li.incorrect {
        background: #f8d7da;
        color: #721c24;
    }

    .answer-list li.incorrect::before {
        content: "\f00d";
        color: #dc3545;
    }

    .answer-list li.valid {
        background: #fff3cd;
        color: #856404;
    }

    .answer-list li.valid::before {
        content: "\f058";
        color: #ffc107;
    }

    .answer-list li.duplicate {
        border: 1px dashed #856404;
    }

    .duplicate-badge {
        margin-left: auto;
        color: #856404;
        font-size: 0.875rem;
    }

    .alert-warning {
        background-color: #fff3cd;
        border-color: #ffeeba;
        color: #856404;
        padding: 0.75rem 1.25rem;
        border-radius: 0.25rem;
        font-size: 0.875rem;
    }

    .answer-box {
        background: white;
        padding: 1rem;
        border-radius: 4px;
        border: 1px solid rgba(0, 0, 0, 0.1);
        min-height: 80px;
    }

    .answer-box.correct {
        background: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .review-restricted {
        margin-top: 2rem;
    }

    .alert-info {
        background-color: #cce5ff;
        border-color: #b8daff;
        color: #004085;
        padding: 1.25rem;
        border-radius: 0.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .alert-info h5 {
        color: #002752;
        margin-bottom: 1rem;
    }

    .alert-info ul {
        margin-left: 1.5rem;
        margin-bottom: 1rem;
    }

    .countdown-timer {
        display: flex;
        justify-content: center;
        gap: 15px;
        margin: 15px 0;
    }

    .time-units {
        display: flex;
        flex-direction: column;
        align-items: center;
        min-width: 60px;
    }

    .time-value {
        font-size: 24px;
        font-weight: bold;
        background-color: #f8f9fa;
        border-radius: 4px;
        padding: 5px 10px;
        min-width: 50px;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .time-label {
        font-size: 12px;
        color: #6c757d;
        margin-top: 5px;
    }

    .alert-info .fa-info-circle {
        font-size: 1.25rem;
        margin-right: 0.5rem;
    }

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .result-container {
            padding: 0.5rem;
        }

        .result-header {
            padding: 1rem;
            margin: -0.5rem -0.5rem 1rem -0.5rem;
            border-radius: 0;
        }

        .result-header h1 {
            font-size: 1.25rem;
        }

        .score-card {
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .score-icon {
            width: 36px;
            height: 36px;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .score-card h3 {
            font-size: 1.5rem;
        }

        .grade-circle {
            width: 100px;
            height: 100px;
            border-width: 4px;
        }

        .grade-letter {
            font-size: 1.5rem;
        }

        .grade-percentage {
            font-size: 0.8rem;
        }

        .question-card {
            margin-bottom: 1rem;
        }

        .question-header {
            padding: 0.75rem;
        }

        .question-content {
            padding: 1rem;
        }

        .question-text {
            font-size: 1rem;
        }

        .mcq-option {
            padding: 0.5rem;
        }

        .answer-section {
            padding: 1rem;
        }

        .row>[class*="col-"] {
            padding-right: calc(var(--bs-gutter-x) * .25);
            padding-left: calc(var(--bs-gutter-x) * .25);
        }
    }

    /* Extra small devices */
    @media (max-width: 375px) {
        .score-card h6 {
            font-size: 0.75rem;
        }

        .score-card h3 {
            font-size: 1.25rem;
        }

        .question-number,
        .question-score {
            font-size: 0.875rem;
        }

        .score-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
        }

        .grade-circle {
            width: 80px;
            height: 80px;
        }

        .grade-letter {
            font-size: 1.2rem;
        }
    }

    /* Print styles */
    @media print {
        .result-container {
            padding: 0;
            background: none;
        }

        .score-card,
        .question-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .result-header,
        .score-icon {
            background: none !important;
            color: #000 !important;
            box-shadow: none !important;
        }

        .btn-light {
            display: none;
        }

        .waec-grade-icon {
            background: none !important;
            border: 2px solid #000 !important;
        }

        .grade-circle {
            border-color: #000 !important;
        }
    }

    /* Animation for grade reveal */
    @keyframes gradeReveal {
        0% {
            opacity: 0;
            transform: scale(0.8);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }

    .waec-grade-text,
    .grade-circle {
        animation: gradeReveal 0.6s ease-out;
    }

    /* Hover effects for better interactivity */
    .score-card:hover .waec-grade-icon {
        transform: rotate(10deg);
        transition: transform 0.3s ease;
    }

    .grade-circle:hover {
        transform: scale(1.05);
        transition: transform 0.3s ease;
    }
</style>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>