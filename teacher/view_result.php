<?php
// teacher/view_result.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';

requireRole('teacher');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher info
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    $teacherId = $teacherInfo['teacher_id'];

    // Get assessment and student IDs from URL
    $assessmentId = isset($_GET['assessment']) ? (int)$_GET['assessment'] : 0;
    $studentId = isset($_GET['student']) ? (int)$_GET['student'] : 0;

    if (!$assessmentId || !$studentId) {
        throw new Exception('Invalid request parameters');
    }

    // Get assessment details and verify teacher access
    $stmt = $db->prepare(
        "SELECT 
            a.*,
            c.class_name,
            s.subject_id,
            s.subject_name,
            r.score as student_score,
            r.status as submission_status,
            r.created_at as submission_time,
            st.first_name,
            st.last_name,
            p.program_name
         FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Classes c ON ac.class_id = c.class_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         JOIN TeacherClassAssignments tca ON (c.class_id = tca.class_id AND s.subject_id = tca.subject_id)
         JOIN Students st ON st.student_id = ?
         JOIN Programs p ON c.program_id = p.program_id
         LEFT JOIN Results r ON (a.assessment_id = r.assessment_id AND r.student_id = ?)
         WHERE a.assessment_id = ?
         AND tca.teacher_id = ?
         AND st.class_id = c.class_id"
    );
    $stmt->execute([$studentId, $studentId, $assessmentId, $teacherId]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found or access denied');
    }

    // Get assessment attempt details
    $stmt = $db->prepare(
        "SELECT * FROM AssessmentAttempts 
         WHERE assessment_id = ? AND student_id = ?"
    );
    $stmt->execute([$assessmentId, $studentId]);
    $attempt = $stmt->fetch();

    // Get all questions and student answers - MODIFIED QUERY to include image data
    $stmt = $db->prepare(
        "SELECT 
            q.*,
            sa.answer_id,
            sa.answer_text as student_answer,
            sa.answer_metadata as student_answer_metadata,
            sa.score as answer_score,
            CASE WHEN sa.score > 0 THEN 1 ELSE 0 END as is_correct,
            GROUP_CONCAT(DISTINCT CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '||') as mcq_data,
            GROUP_CONCAT(DISTINCT CASE WHEN mcq.is_correct = 1 THEN CONCAT(mcq.mcq_id, ':', mcq.answer_text) END SEPARATOR '||') as correct_options,
            img.filename as image_filename,
            img.original_filename as original_filename
         FROM Questions q
         LEFT JOIN MCQQuestions mcq ON q.question_id = mcq.question_id
         LEFT JOIN StudentAnswers sa ON (q.question_id = sa.question_id AND sa.student_id = ? AND sa.assessment_id = ?)
         LEFT JOIN assessment_images img ON q.image_id = img.image_id
         WHERE q.assessment_id = ?
         GROUP BY 
            q.question_id,
            q.assessment_id,
            q.question_text,
            q.question_type,
            q.max_score,
            q.answer_count,
            q.answer_mode,
            q.multiple_answers_allowed,
            q.created_at,
            q.updated_at,
            q.correct_answer,
            q.image_id,
            sa.answer_id,
            sa.answer_text,
            sa.score,
            sa.answer_metadata,
            img.filename,
            img.original_filename
         ORDER BY q.question_id"
    );
    $stmt->execute([$studentId, $assessmentId, $assessmentId]);
    $questions = $stmt->fetchAll();

    // Calculate statistics
    $stats = [
        'total_questions' => count($questions),
        'answered_questions' => 0,
        'correct_answers' => 0,
        'mcq_correct' => 0,
        'mcq_total' => 0,
        'short_correct' => 0,
        'short_total' => 0,
        'total_points' => 0,
        'earned_points' => 0
    ];

    foreach ($questions as $question) {
        $stats['total_points'] += (float)$question['max_score'];
        
        if ($question['student_answer'] !== null) {
            $stats['answered_questions']++;
            $stats['earned_points'] += (float)$question['answer_score'];
            
            if ($question['is_correct']) {
                $stats['correct_answers']++;
                if ($question['question_type'] === 'MCQ') {
                    $stats['mcq_correct']++;
                } else {
                    $stats['short_correct']++;
                }
            }
        }
        
        if ($question['question_type'] === 'MCQ') {
            $stats['mcq_total']++;
        } else {
            $stats['short_total']++;
        }
    }
} catch (Exception $e) {
    logError("View result error: " . $e->getMessage());
    $error = $e->getMessage();
}

$pageTitle = 'View Assessment Result';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <!-- Result Header -->
        <div class="header-gradient mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 text-white mb-1">
                        <?php echo htmlspecialchars($assessment['title']); ?>
                    </h1>
                    <p class="text-white-50 mb-0">
                        <?php echo htmlspecialchars($assessment['first_name'] . ' ' . $assessment['last_name']); ?> |
                        <?php echo htmlspecialchars($assessment['program_name']); ?> |
                        <?php echo htmlspecialchars($assessment['class_name']); ?> |
                        <?php echo htmlspecialchars($assessment['subject_name']); ?>
                    </p>
                </div>
                <a href="student_result.php?student=<?php echo $studentId; ?>&subject=<?php echo $assessment['subject_id']; ?>"
                    class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Results
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                <i class="fas fa-percentage fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Overall Score</h6>
                                <h4 class="mb-0"><?php echo !empty($assessment['student_score']) ? $assessment['student_score'] : '0'; ?>%</h4>
                                <small class="text-muted">
                                    <?php echo number_format($stats['earned_points'], 1); ?>/<?php echo number_format($stats['total_points'], 1); ?> points
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                <i class="fas fa-check-circle fa-2x text-success"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Correct Answers</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['correct_answers']; ?>/<?php echo $stats['total_questions']; ?>
                                </h4>
                                <small class="text-muted">
                                    <?php echo $stats['answered_questions']; ?> questions answered
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3">
                                <i class="fas fa-list fa-2x text-info"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">MCQ Score</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['mcq_correct']; ?>/<?php echo $stats['mcq_total']; ?>
                                </h4>
                                <?php if ($stats['mcq_total'] > 0): ?>
                                <small class="text-muted">
                                    <?php echo round(($stats['mcq_correct'] / $stats['mcq_total']) * 100); ?>% correct
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-pen fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Short Answer Score</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['short_correct']; ?>/<?php echo $stats['short_total']; ?>
                                </h4>
                                <?php if ($stats['short_total'] > 0): ?>
                                <small class="text-muted">
                                    <?php echo round(($stats['short_correct'] / $stats['short_total']) * 100); ?>% correct
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Session Information -->
        <?php if (!empty($attempt)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-gradient py-3">
                <h5 class="card-title mb-0">Assessment Session</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <p class="mb-1 text-muted">Started</p>
                        <p class="fw-bold">
                            <?php echo !empty($attempt['start_time']) ? date('M d, Y g:i A', strtotime($attempt['start_time'])) : 'N/A'; ?>
                        </p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1 text-muted">Finished</p>
                        <p class="fw-bold">
                            <?php echo !empty($attempt['end_time']) ? date('M d, Y g:i A', strtotime($attempt['end_time'])) : 'N/A'; ?>
                        </p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1 text-muted">Time Spent</p>
                        <?php 
                        if (!empty($attempt['start_time']) && !empty($attempt['end_time'])) {
                            $start = new DateTime($attempt['start_time']);
                            $end = new DateTime($attempt['end_time']);
                            $diff = $start->diff($end);
                            $timeSpent = $diff->format('%H:%I:%S');
                        } else {
                            $timeSpent = 'N/A';
                        }
                        ?>
                        <p class="fw-bold"><?php echo $timeSpent; ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1 text-muted">Status</p>
                        <p class="fw-bold">
                            <span class="badge <?php echo !empty($attempt['status']) && $attempt['status'] === 'completed' ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo !empty($attempt['status']) ? ucfirst($attempt['status']) : 'Unknown'; ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Questions and Answers -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-gradient py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Questions & Answers</h5>
                    <div>
                        <?php if (!empty($assessment['submission_time'])): ?>
                            <span class="badge bg-light text-dark me-2">
                                Submitted: <?php echo date('M d, Y g:i A', strtotime($assessment['submission_time'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark me-2">
                                <i class="fas fa-hourglass-half me-1"></i> In Progress
                            </span>
                        <?php endif; ?>
                        <button onclick="exportResults()" class="btn btn-light btn-sm">
                            <i class="fas fa-download me-2"></i>Export
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-card mb-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="mb-0">
                                Question <?php echo $index + 1; ?>
                                <span class="badge bg-secondary ms-2">
                                    <?php echo $question['question_type']; ?>
                                </span>
                                <span class="badge bg-dark ms-2">
                                    <?php echo $question['max_score']; ?> points
                                </span>
                            </h5>
                            <div>
                                <span class="badge <?php echo $question['is_correct'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $question['is_correct'] ? 'Correct' : 'Incorrect'; ?>
                                </span>
                                <span class="badge bg-info ms-2">
                                    Score: <?php echo number_format($question['answer_score'] ?? 0, 1); ?>/<?php echo number_format($question['max_score'], 1); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Display question content with image if available -->
                        <div class="row mb-3">
                            <?php if (!empty($question['image_filename'])): ?>
                                <div class="col-md-8">
                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="question-image">
                                        <img src="<?php echo BASE_URL . '/assets/assessment_images/' . $question['image_filename']; ?>" 
                                             alt="Question Image" 
                                             class="img-fluid rounded shadow-sm" 
                                             data-bs-toggle="modal" 
                                             data-bs-target="#imageModal<?php echo $question['question_id']; ?>">
                                        <div class="text-center mt-2">
                                            <small class="text-muted"><?php echo htmlspecialchars($question['original_filename'] ?? 'Question Image'); ?></small>
                                            <button class="btn btn-sm btn-outline-primary d-block mt-1 w-100" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#imageModal<?php echo $question['question_id']; ?>">
                                                <i class="fas fa-search-plus me-1"></i> View Full Size
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Image Modal -->
                                    <div class="modal fade" id="imageModal<?php echo $question['question_id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Question Image</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <img src="<?php echo BASE_URL . '/assets/assessment_images/' . $question['image_filename']; ?>" 
                                                         alt="Question Image" 
                                                         class="img-fluid">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($question['question_type'] === 'MCQ'): ?>
                            <div class="options-list mb-3">
                                <?php 
                                // Process MCQ data to get id:text:is_correct triplets
                                $mcqOptions = [];
                                if (!empty($question['mcq_data'])) {
                                    $mcqData = explode('||', $question['mcq_data']);
                                    foreach ($mcqData as $mcqItem) {
                                        if (empty($mcqItem)) continue;
                                        $parts = explode(':', $mcqItem, 3);
                                        if (count($parts) >= 3) {
                                            list($id, $text, $isCorrect) = $parts;
                                            $mcqOptions[$id] = [
                                                'text' => $text,
                                                'is_correct' => (bool)$isCorrect
                                            ];
                                        }
                                    }
                                }
                                
                                // Process student answer - could be ID or text
                                $studentAnswer = $question['student_answer'] ?? '';
                                $studentAnswerMetadata = !empty($question['student_answer_metadata']) ? 
                                    json_decode($question['student_answer_metadata'], true) : [];
                                $studentSelectedId = isset($studentAnswerMetadata['selected_option_id']) ? 
                                    $studentAnswerMetadata['selected_option_id'] : $studentAnswer;
                                
                                foreach ($mcqOptions as $optionId => $option): 
                                    // Determine if this is the student's selected answer
                                    $isStudentAnswer = ($studentSelectedId == $optionId);
                                    $isCorrectOption = $option['is_correct'];
                                    
                                    // Determine the appropriate class based on student answer and correctness
                                    $optionClass = '';
                                    if ($isStudentAnswer && $isCorrectOption) {
                                        $optionClass = 'student-correct';
                                    } else if ($isStudentAnswer && !$isCorrectOption) {
                                        $optionClass = 'student-incorrect';
                                    } else if (!$isStudentAnswer && $isCorrectOption) {
                                        $optionClass = 'correct-answer';
                                    }
                                ?>
                                    <div class="option-item <?php echo $optionClass; ?>">
                                        <i class="fas <?php echo $isStudentAnswer ? 'fa-check-circle' : 'fa-circle text-muted'; ?> me-2"></i>
                                        <?php echo htmlspecialchars($option['text']); ?>
                                        
                                        <?php if ($isStudentAnswer): ?>
                                            <span class="badge bg-info ms-2">Student Selected</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($isCorrectOption): ?>
                                            <span class="badge bg-success ms-2">Correct Answer</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="short-answer mb-3">
                                <div class="student-answer p-3 bg-light rounded">
                                    <h6 class="mb-2">Student's Answer:</h6>
                                    <?php 
                                    if ($question['answer_mode'] === 'any_match' && !empty($question['answer_count']) && 
                                        $question['answer_count'] > 1 && !empty($question['student_answer'])) {
                                        // Display multi-answer responses as a list
                                        $answers = explode("\n", $question['student_answer']);
                                        echo '<ol class="mb-0">';
                                        foreach ($answers as $answer) {
                                            if (trim($answer) !== '') {
                                                echo '<li>' . htmlspecialchars(trim($answer)) . '</li>';
                                            }
                                        }
                                        echo '</ol>';
                                    } else {
                                        echo nl2br(htmlspecialchars($question['student_answer'] ?? 'No answer provided'));
                                    }
                                    ?>
                                </div>
                                
                                <?php if (isset($question['answer_mode']) && $question['answer_mode'] === 'any_match'): ?>
                                    <div class="correct-answer p-3 mt-2 bg-success bg-opacity-10 rounded">
                                        <h6 class="mb-2">Valid Answers (Student needed to provide <?php echo $question['answer_count']; ?>):</h6>
                                        <?php 
                                        $validAnswers = !empty($question['correct_answer']) ? 
                                            json_decode($question['correct_answer'], true) : [];
                                        if (is_array($validAnswers) && !empty($validAnswers)) {
                                            echo '<ul class="mb-0">';
                                            foreach ($validAnswers as $answer) {
                                                echo '<li>' . htmlspecialchars($answer) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo '<p class="mb-0">No valid answers defined</p>';
                                        }
                                        ?>
                                    </div>
                                <?php else: ?>
                                    <div class="correct-answer p-3 mt-2 bg-success bg-opacity-10 rounded">
                                        <h6 class="mb-2">Correct Answer:</h6>
                                        <?php echo nl2br(htmlspecialchars($question['correct_answer'] ?? 'Not provided')); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($index < count($questions) - 1): ?>
                        <hr class="my-4">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<style>
    :root {
    --primary-yellow: #ffd700;
    --dark-yellow: #ccac00;
    --light-yellow: #fff9e6;
    --primary-black: #000000;
    --primary-white: #ffffff;
}

/* Clean and Professional Layout */

/* Header Section */
.header-gradient {
    background: var(--primary-black);
    padding: 1.5rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
}

.header-gradient h1 {
    color: var(--primary-yellow);
    font-weight: 600;
}

.header-gradient .text-white-50 {
    color: var(--primary-white) !important;
    opacity: 0.8;
}

/* Statistics Cards */
.card {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    box-shadow: none;
    transition: box-shadow 0.2s;
}

.card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transform: none;
}

.card-body {
    padding: 1.25rem;
}

.rounded-circle {
    background-color: var(--light-yellow) !important;
}

.rounded-circle i {
    color: var(--dark-yellow) !important;
}

/* Card Header */
.card-header.bg-gradient {
    background: var(--primary-black);
    border-radius: 4px 4px 0 0;
    padding: 1rem;
    color: var(--primary-white);
}

.card-header .card-title {
    color: var(--primary-yellow);
}

/* Questions Section */
.question-card {
    background: var(--primary-white);
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.question-card h5 {
    color: var(--primary-black);
    font-weight: 500;
}

.question-text {
    color: #333;
    background-color: #f8f9fa;
    padding: 1rem;
    border-radius: 4px;
    border-left: 3px solid var(--primary-yellow);
    margin-bottom: 1rem;
}

/* Question Image Styling */
.question-image {
    background-color: #f8f9fa;
    padding: 0.75rem;
    border-radius: 4px;
    text-align: center;
    border: 1px solid #e0e0e0;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.question-image img {
    max-height: 250px;
    object-fit: contain;
    margin: 0 auto;
    cursor: pointer;
    transition: transform 0.2s;
}

.question-image img:hover {
    transform: scale(1.05);
}

/* Options and Answers */
.options-list {
    margin: 1rem 0;
}

.option-item {
    background: var(--primary-white);
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 0.75rem 1rem;
    margin-bottom: 0.5rem;
    transition: all 0.2s ease;
}

/* Improved MCQ Option Styling */
.option-item.student-correct {
    background-color: #d4edda;
    border-left: 3px solid #28a745;
}

.option-item.student-incorrect {
    background-color: #f8d7da;
    border-left: 3px solid #dc3545;
}

.option-item.correct-answer {
    background-color: #e2f0da;
    border-left: 3px solid #28a745;
    border-style: dashed;
}

.option-item:hover {
    background: var(--light-yellow);
}

.option-item i.fa-check-circle {
    color: #dc3545;
}

.option-item.student-correct i.fa-check-circle {
    color: #28a745;
}

/* Short Answer Sections */
.student-answer, .correct-answer {
    background: var(--primary-white);
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 1rem;
    margin-top: 0.75rem;
}

.student-answer {
    background-color: #f8f9fa;
}

.correct-answer {
    background: var(--light-yellow);
    border-color: var(--dark-yellow);
}

/* Badges */
.badge {
    padding: 0.4rem 0.8rem;
    font-weight: normal;
    border-radius: 4px;
}

.badge.bg-success {
    background: #28a745 !important;
}

.badge.bg-danger {
    background: #dc3545 !important;
}

.badge.bg-secondary {
    background: var(--primary-black) !important;
}

.badge.bg-info {
    background: #17a2b8 !important;
}

/* Export Button */
.btn-light {
    background: var(--primary-white);
    border: 1px solid var(--dark-yellow);
    color: var(--primary-black);
    padding: 0.4rem 1rem;
    border-radius: 4px;
}

.btn-light:hover {
    background: var(--light-yellow);
    border-color: var(--dark-yellow);
}

/* Button styles for image view */
.btn-outline-primary {
    color: var(--primary-black);
    border-color: var(--primary-yellow);
    background: transparent;
    transition: all 0.3s ease;
}

.btn-outline-primary:hover {
    background: var(--light-yellow);
    color: var(--primary-black);
    border-color: var(--dark-yellow);
}

/* Modal styling for image view */
.modal-content {
    border: none;
    border-radius: 8px;
    overflow: hidden;
}

.modal-header {
    background: var(--primary-black);
    color: var(--primary-yellow);
    border-bottom: none;
}

.modal-body {
    padding: 1.5rem;
    background: #f8f9fa;
}

.modal-footer {
    border-top: none;
    background: #f8f9fa;
}

.btn-secondary {
    background: var(--primary-black);
    border: none;
    color: var(--primary-white);
}

.btn-secondary:hover {
    background: #333;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container-fluid {
        padding: 1rem;
    }
    
    .header-gradient {
        padding: 1rem;
    }
    
    .question-card {
        padding: 1rem;
    }
    
    .card-body {
        padding: 1rem;
    }

    .question-image img {
        max-height: 200px;
    }
    
    .option-item {
        padding: 0.5rem 0.75rem;
    }
}
</style>

<script>
    // Export results function
    function exportResults() {
        const studentName = document.querySelector('.text-white-50').textContent.split('|')[0].trim();
        const assessmentTitle = document.querySelector('h1').textContent.trim();
        const score = document.querySelector('.card-body h4').textContent.trim();
        
        // Get submission date/time (with null check)
        let submissionTime = 'Not yet submitted';
        const submissionBadge = document.querySelector('.badge.bg-light.text-dark');
        if (submissionBadge) {
            submissionTime = submissionBadge.textContent.replace('Submitted:', '').trim();
        }

        let csv = 'Assessment Result Report\n';
        csv += `Student: ${studentName}\n`;
        csv += `Assessment: ${assessmentTitle}\n`;
        csv += `Overall Score: ${score}\n`;
        csv += `Submission Time: ${submissionTime}\n\n`;

        csv += 'Question Number,Question Type,Question,Student Answer,Correct Answer,Result,Points,Has Image\n';

        const questions = document.querySelectorAll('.question-card');
        questions.forEach((question, index) => {
            const questionNumber = index + 1;
            const questionType = question.querySelector('.badge').textContent.trim();
            const questionText = question.querySelector('.question-text').textContent.trim();
            const scoreText = question.querySelector('.badge.bg-info').textContent.replace('Score:', '').trim();
            let studentAnswer = '';
            let correctAnswer = '';
            let result = question.querySelector('.badge:nth-child(1)').textContent.trim();
            // Check if question has an image
            const hasImage = question.querySelector('.question-image') ? 'Yes' : 'No';

            if (questionType === 'MCQ') {
                // Find the student's selected answer
                const studentSelectedOption = question.querySelector('.option-item.student-correct, .option-item.student-incorrect');
                if (studentSelectedOption) {
                    studentAnswer = studentSelectedOption.textContent.replace('Student Selected', '').replace('Correct Answer', '').trim();
                }
                
                // Find the correct answer(s)
                const correctOptions = question.querySelectorAll('.option-item.student-correct, .option-item.correct-answer');
                let correctAnswers = [];
                correctOptions.forEach(option => {
                    const optionText = option.textContent.replace('Student Selected', '').replace('Correct Answer', '').trim();
                    correctAnswers.push(optionText);
                });
                correctAnswer = correctAnswers.join('; ');
            } else {
                const studentAnswerEl = question.querySelector('.student-answer');
                if (studentAnswerEl) {
                    studentAnswer = studentAnswerEl.textContent.replace('Student\'s Answer:', '').trim();
                }
                
                const correctAnswerEl = question.querySelector('.correct-answer');
                if (correctAnswerEl) {
                    correctAnswer = correctAnswerEl.textContent.replace('Correct Answer:', '').replace('Valid Answers (Student needed to provide', '').trim();
                }
            }

            // Escape any commas in the text fields
            const escapeCsv = (text) => `"${text.replace(/"/g, '""')}"`;

            csv += `${questionNumber},${escapeCsv(questionType)},${escapeCsv(questionText)},${escapeCsv(studentAnswer)},${escapeCsv(correctAnswer)},${result},${scoreText},${hasImage}\n`;
        });

        const blob = new Blob([csv], {
            type: 'text/csv;charset=utf-8;'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', `${assessmentTitle}_${studentName}_Result.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });

        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            }, 5000);
        });
        
        // Highlight student answers more prominently
        document.querySelectorAll('.option-item').forEach(option => {
            if (option.classList.contains('student-incorrect') || option.classList.contains('student-correct')) {
                option.style.boxShadow = '0 0 0 2px rgba(0, 0, 0, 0.1)';
                option.style.transform = 'translateY(-2px)';
            }
        });
        
        // Add image zoom effect
        document.querySelectorAll('.question-image img').forEach(img => {
            img.addEventListener('mouseover', function() {
                this.style.transform = 'scale(1.05)';
            });
            
            img.addEventListener('mouseout', function() {
                this.style.transform = 'scale(1)';
            });
        });
    });
</script>