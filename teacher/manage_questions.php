<?php
// teacher/manage_questions.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Assessment.php';

requireRole('teacher');

$error = '';
$success = '';
$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$assessmentId) {
    redirectTo('assessments.php');
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherId = $stmt->fetch()['teacher_id'];

    // Get assessment details with authorization check including question pool settings
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN classes c ON ac.class_id = c.class_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE a.assessment_id = ? AND tca.teacher_id = ? AND a.status = 'pending'"
    );
    $stmt->execute([$assessmentId, $teacherId]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found, unauthorized, or already completed');
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $db->beginTransaction();

        try {
            switch ($_POST['action']) {
                case 'delete_question':
                    $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
                    
                    // Verify no answers exist
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM studentanswers WHERE question_id = ?"
                    );
                    $stmt->execute([$questionId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete question: students have already submitted answers');
                    }

                    // First delete MCQ options if they exist
                    $stmt = $db->prepare("DELETE FROM mcqquestions WHERE question_id = ?");
                    $stmt->execute([$questionId]);
                    
                    // Then delete the question
                    $stmt = $db->prepare("DELETE FROM questions WHERE question_id = ? AND assessment_id = ?");
                    $stmt->execute([$questionId, $assessmentId]);

                    $success = 'Question deleted successfully';
                    break;
            }

            $db->commit();
            
            // Add this redirect to prevent form resubmission on refresh
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // This is an AJAX request, so just return JSON
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $success
                ]);
                exit;
            } else {
                // This is a regular POST, so redirect to prevent resubmission
                $_SESSION['success'] = $success;
                header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $assessmentId);
                exit;
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Get questions with their options
    $stmt = $db->prepare(
        "SELECT q.*, q.image_id,
                COUNT(DISTINCT sa.answer_id) as answer_count
         FROM questions q
         LEFT JOIN studentanswers sa ON q.question_id = sa.question_id
         WHERE q.assessment_id = ?
         GROUP BY q.question_id
         ORDER BY q.created_at ASC"
    );
    $stmt->execute([$assessmentId]);
    $questions = $stmt->fetchAll();

    // Calculate total points for the assessment
    $totalPoints = 0;

    // Get MCQ options for questions and image info
    foreach ($questions as &$question) {
        $totalPoints += $question['max_score'];
        
        if ($question['question_type'] === 'MCQ') {
            $stmt = $db->prepare(
                "SELECT * FROM mcqquestions 
                 WHERE question_id = ? 
                 ORDER BY mcq_id"
            );
            $stmt->execute([$question['question_id']]);
            $question['options'] = $stmt->fetchAll();
        } elseif ($question['answer_mode'] === 'any_match') {
            // Add error checking when decoding JSON
            $validAnswers = json_decode($question['correct_answer'], true);
            $question['valid_answers'] = is_array($validAnswers) ? $validAnswers : [];
        }
        
        // Get image info if exists
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
    }
    unset($question);

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Edit assessment error: " . $e->getMessage());
}

// Get success message from session if redirected
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Clear any stale error messages from session
if (isset($_SESSION['error'])) {
    unset($_SESSION['error']);
}

$pageTitle = 'Manage Questions';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <!-- Header Section -->
    <div class="header-section mb-4">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h1 class="text-warning mb-0"><?php echo htmlspecialchars($assessment['title']); ?></h1>
                <p class="text-muted mb-1">
                    <i class="fas fa-graduation-cap me-1"></i> <?php echo htmlspecialchars($assessment['class_name']); ?> |
                    <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($assessment['subject_name']); ?> |
                    <i class="fas fa-calendar me-1"></i> <?php echo date('F d, Y', strtotime($assessment['date'])); ?>
                </p>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <div class="assessment-stats">
                        <span class="badge bg-black text-warning me-2">
                            <i class="fas fa-question-circle me-1"></i> <?php echo count($questions); ?> Questions
                        </span>
                        <span class="badge bg-black text-warning me-2">
                            <i class="fas fa-star me-1"></i> <?php echo $totalPoints; ?> Total Points
                        </span>
                        <?php if ($assessment['use_question_limit'] && $assessment['questions_to_answer']): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-layer-group me-1"></i> 
                                Pool: <?php echo $assessment['questions_to_answer']; ?>/<?php echo count($questions); ?> per student
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($assessment['use_question_limit'] && $assessment['questions_to_answer']): ?>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Question Pool Mode:</strong> Students will answer 
                        <strong><?php echo $assessment['questions_to_answer']; ?></strong> randomly selected questions 
                        from the <strong><?php echo count($questions); ?></strong> questions in this pool.
                        
                        <?php if (count($questions) < $assessment['questions_to_answer']): ?>
                            <br><span class="text-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Warning: You need at least <?php echo $assessment['questions_to_answer']; ?> questions for this assessment.
                                Current: <?php echo count($questions); ?> questions.
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-5 text-md-end mt-3 mt-md-0">
                <a href="add_question.php?id=<?php echo $assessmentId; ?>" class="btn btn-warning me-2">
                    <i class="fas fa-plus me-1"></i> Add Question
                </a>
                <a href="assessments.php" class="btn btn-outline-warning">
                    <i class="fas fa-arrow-left me-1"></i> Back to Assessments
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Questions List -->
    <?php if (empty($questions)): ?>
        <div class="no-questions-container text-center p-5">
            <div class="mb-4">
                <i class="fas fa-question-circle fa-5x text-muted"></i>
            </div>
            <h3 class="text-muted">No Questions Added Yet</h3>
            <p class="text-muted mb-4">Get started by adding your first question to this assessment.</p>
            <?php if ($assessment['use_question_limit'] && $assessment['questions_to_answer']): ?>
                <p class="text-info mb-4">
                    <i class="fas fa-info-circle me-1"></i>
                    This assessment uses question pooling. You'll need to add at least 
                    <strong><?php echo $assessment['questions_to_answer']; ?></strong> questions.
                </p>
            <?php endif; ?>
            <a href="add_question.php?id=<?php echo $assessmentId; ?>" class="btn btn-warning btn-lg">
                <i class="fas fa-plus me-1"></i> Add First Question
            </a>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-12">
                <div class="question-list-container">
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-card" id="question-<?php echo $question['question_id']; ?>">
                            <div class="question-header">
                                <div class="question-number">Question <?php echo $index + 1; ?></div>
                                <div class="question-type">
                                    <?php if ($question['question_type'] === 'MCQ'): ?>
                                        <span class="badge bg-dark">
                                            <i class="fas fa-list me-1"></i> Multiple Choice
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-dark">
                                            <i class="fas fa-edit me-1"></i> Short Answer
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="question-points">
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-star me-1"></i> <?php echo $question['max_score']; ?> Points
                                    </span>
                                </div>
                            </div>
                            
                            <div class="question-content">
                                <div class="row">
                                    <?php if (!empty($question['image_url'])): ?>
                                        <div class="col-md-8">
                                            <div class="question-text mb-3">
                                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                            </div>
                                            
                                            <div class="question-answers">
                                                <?php if ($question['question_type'] === 'MCQ'): ?>
                                                    <div class="mcq-options">
                                                        <?php foreach ($question['options'] as $option): ?>
                                                            <div class="option-item <?php echo $option['is_correct'] ? 'is-correct' : ''; ?>">
                                                                <div class="option-marker">
                                                                    <?php echo $option['is_correct'] ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle"></i>'; ?>
                                                                </div>
                                                                <div class="option-text">
                                                                    <?php echo htmlspecialchars($option['answer_text']); ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="short-answer-info">
                                                        <?php if ($question['answer_mode'] === 'any_match' && isset($question['valid_answers']) && is_array($question['valid_answers'])): ?>
                                                            <div class="valid-answers-list">
                                                                <div class="answer-mode-label">
                                                                    <i class="fas fa-check-double me-1"></i> Any of these <?php echo count($question['valid_answers']); ?> answers:
                                                                </div>
                                                                <ul class="list-unstyled">
                                                                    <?php foreach ($question['valid_answers'] as $valid): ?>
                                                                        <li>
                                                                            <i class="fas fa-check text-success me-1"></i>
                                                                            <?php echo htmlspecialchars($valid); ?>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="exact-answer">
                                                                <div class="answer-mode-label">
                                                                    <i class="fas fa-equals me-1"></i> Exact match:
                                                                </div>
                                                                <div class="correct-answer">
                                                                    <?php echo htmlspecialchars($question['correct_answer']); ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="question-image-container">
                                                <img src="<?php echo htmlspecialchars($question['image_url']); ?>" 
                                                     class="question-image" alt="Question Image">
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="col-12">
                                            <div class="question-text mb-3">
                                                <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                            </div>
                                            
                                            <div class="question-answers">
                                                <?php if ($question['question_type'] === 'MCQ'): ?>
                                                    <div class="mcq-options">
                                                        <?php foreach ($question['options'] as $option): ?>
                                                            <div class="option-item <?php echo $option['is_correct'] ? 'is-correct' : ''; ?>">
                                                                <div class="option-marker">
                                                                    <?php echo $option['is_correct'] ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle"></i>'; ?>
                                                                </div>
                                                                <div class="option-text">
                                                                    <?php echo htmlspecialchars($option['answer_text']); ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="short-answer-info">
                                                        <?php if ($question['answer_mode'] === 'any_match' && isset($question['valid_answers']) && is_array($question['valid_answers'])): ?>
                                                            <div class="valid-answers-list">
                                                                <div class="answer-mode-label">
                                                                    <i class="fas fa-check-double me-1"></i> Any of these <?php echo count($question['valid_answers']); ?> answers:
                                                                </div>
                                                                <ul class="list-unstyled">
                                                                    <?php foreach ($question['valid_answers'] as $valid): ?>
                                                                        <li>
                                                                            <i class="fas fa-check text-success me-1"></i>
                                                                            <?php echo htmlspecialchars($valid); ?>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="exact-answer">
                                                                <div class="answer-mode-label">
                                                                    <i class="fas fa-equals me-1"></i> Exact match:
                                                                </div>
                                                                <div class="correct-answer">
                                                                    <?php echo htmlspecialchars($question['correct_answer']); ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="question-footer">
                                <div class="question-meta">
                                    <?php if ($question['answer_count'] > 0): ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-lock me-1"></i> Locked (<?php echo $question['answer_count']; ?> answers)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-unlock me-1"></i> Editable
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="question-actions">
                                    <?php if ($question['answer_count'] == 0): ?>
                                        <a href="add_question.php?id=<?php echo $assessmentId; ?>&edit=<?php echo $question['question_id']; ?>" 
                                           class="btn btn-sm btn-outline-warning me-2">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteQuestion(<?php echo $question['question_id']; ?>)">
                                            <i class="fas fa-trash me-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                            <i class="fas fa-lock me-1"></i> Cannot Edit
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-black text-warning">
                    <h5 class="modal-title">Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmationMessage">Are you sure you want to proceed with this action?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmActionBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form for actions -->
    <form id="actionForm" method="post" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="action" id="actionType" value="">
        <input type="hidden" name="question_id" id="questionId" value="">
    </form>
</div>

<script>
// Function to handle question deletion
function deleteQuestion(questionId) {
    const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
    const confirmMsg = document.getElementById('confirmationMessage');
    const confirmBtn = document.getElementById('confirmActionBtn');
    
    confirmMsg.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-2"></i> Are you sure you want to delete this question? This action cannot be undone.';
    
    confirmBtn.onclick = function() {
        const form = document.getElementById('actionForm');
        document.getElementById('actionType').value = 'delete_question';
        document.getElementById('questionId').value = questionId;
        form.submit();
        modal.hide();
    };
    
    modal.show();
}

// Initialize tooltips and page features
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Add smooth scroll to questions
    document.querySelectorAll('.question-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (!e.target.closest('a') && !e.target.closest('button')) {
                // Highlight the card briefly when clicked
                this.classList.add('question-highlight');
                setTimeout(() => {
                    this.classList.remove('question-highlight');
                }, 1000);
            }
        });
    });
});
</script>

<style>
/* Black and Gold color scheme */
:root {
    --black: #000000;
    --dark-black: #222222;
    --gold: #ffd700;
    --light-gold: #ffeb80;
    --white: #ffffff;
    --light-gray: #f8f9fa;
}

/* Header styling */
.header-section {
    background: linear-gradient(90deg, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.8) 100%);
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.header-section h1 {
    color: var(--gold);
    font-weight: 600;
}

.text-warning {
    color: var(--gold) !important;
}

/* Question Pool Alert Styling */
.alert-info {
    background: linear-gradient(135deg, rgba(13, 202, 240, 0.1) 0%, rgba(255, 215, 0, 0.1) 100%);
    border: 1px solid rgba(13, 202, 240, 0.2);
    border-left: 4px solid var(--gold);
}

/* Question cards styling */
.question-list-container {
    margin-bottom: 30px;
}

.question-card {
    background-color: var(--white);
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}

.question-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.1);
}

.question-highlight {
    background-color: rgba(255, 215, 0, 0.05);
    border-color: var(--gold);
}

.question-header {
    background: linear-gradient(90deg, var(--black) 0%, var(--dark-black) 100%);
    color: var(--gold);
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.question-number {
    font-weight: 600;
    font-size: 1.1rem;
}

.question-content {
    padding: 20px;
    background-color: var(--white);
}

.question-text {
    font-size: 1.1rem;
    background-color: var(--light-gray);
    padding: 15px;
    border-radius: 6px;
    border-left: 4px solid var(--gold);
}

.question-image-container {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    height: 100%;
    padding: 10px;
}

.question-image {
    max-width: 100%;
    max-height: 250px;
    border-radius: 6px;
    border: 1px solid rgba(0,0,0,0.1);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

.question-image:hover {
    transform: scale(1.02);
}

.question-answers {
    margin-top: 15px;
}

.question-footer {
    padding: 12px 20px;
    background-color: rgba(0,0,0,0.02);
    border-top: 1px solid rgba(0,0,0,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* MCQ options styling */
.mcq-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.option-item {
    display: flex;
    align-items: flex-start;
    padding: 10px;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 6px;
    background-color: var(--white);
}

.option-item.is-correct {
    background-color: rgba(40, 167, 69, 0.05);
    border-color: rgba(40, 167, 69, 0.2);
}

.option-marker {
    margin-right: 12px;
    padding-top: 2px;
    color: rgba(0,0,0,0.5);
}

.option-item.is-correct .option-marker {
    color: #28a745;
}

/* Short answer styling */
.short-answer-info {
    background-color: var(--light-gray);
    padding: 15px;
    border-radius: 6px;
}

.answer-mode-label {
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--dark-black);
}

.valid-answers-list ul {
    margin-bottom: 0;
}

.valid-answers-list li {
    padding: 5px 0;
    border-bottom: 1px dashed rgba(0,0,0,0.1);
}

.valid-answers-list li:last-child {
    border-bottom: none;
}

.correct-answer {
    background-color: rgba(255, 215, 0, 0.1);
    padding: 10px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 1.1rem;
}

/* Button styling */
.btn-warning {
    background: linear-gradient(45deg, var(--black) 0%, var(--gold) 100%);
    border: none;
    color: var(--white);
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-warning:hover {
    background: linear-gradient(45deg, var(--gold) 0%, var(--black) 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    color: var(--white);
}

.btn-outline-warning {
    color: var(--black);
    border-color: var(--gold);
    background: transparent;
}

.btn-outline-warning:hover {
    background: linear-gradient(45deg, var(--black) 0%, var(--gold) 100%);
    color: var(--white);
}

/* Badges styling */
.badge {
    padding: 0.5em 0.8em;
    font-weight: 500;
}

.badge.bg-warning {
    background-color: var(--gold) !important;
    color: var(--black);
}

.badge.bg-black {
    background-color: var(--black) !important;
    color: var(--gold);
}

/* Modal styling */
.modal-header.bg-black {
    background: linear-gradient(90deg, var(--black) 0%, var(--dark-black) 100%);
}

.btn-close-white {
    filter: invert(1) brightness(200%);
}

/* Responsive design */
@media (max-width: 767.98px) {
    .header-section {
        padding: 15px;
    }
    
    .question-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .question-content {
        padding: 15px;
    }
    
    .question-footer {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .question-actions {
        width: 100%;
        display: flex;
        justify-content: flex-end;
    }
    
    .question-image-container {
        margin-top: 15px;
    }

    .assessment-stats {
        flex-direction: column;
        align-items: flex-start;
    }

    .assessment-stats .badge {
        margin-bottom: 0.25rem;
    }
}

/* Empty state container */
.no-questions-container {
    background-color: var(--white);
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>