<?php
// teacher/question-bank.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';

requireRole('teacher');

$error = '';
$success = '';
$searchQuery = sanitizeInput($_GET['search'] ?? '');
$subjectFilter = sanitizeInput($_GET['subject'] ?? '');
$typeFilter = sanitizeInput($_GET['type'] ?? '');

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherId = $stmt->fetch()['teacher_id'];

    // Get teacher's subjects
    $stmt = $db->prepare(
        "SELECT DISTINCT s.subject_id, s.subject_name 
         FROM Subjects s
         JOIN TeacherClassAssignments tca ON s.subject_id = tca.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY s.subject_name"
    );
    $stmt->execute([$teacherId]);
    $subjects = $stmt->fetchAll();

    // Get question statistics
    $stmt = $db->prepare(
        "SELECT 
            COUNT(*) as total_questions,
            SUM(CASE WHEN question_type = 'MCQ' THEN 1 ELSE 0 END) as mcq_count,
            SUM(CASE WHEN question_type = 'Short Answer' THEN 1 ELSE 0 END) as short_answer_count
         FROM QuestionBank 
         WHERE teacher_id = ?"
    );
    $stmt->execute([$teacherId]);
    $questionStats = $stmt->fetch();

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        switch ($_POST['action']) {
            case 'create':
                $questionText = sanitizeInput($_POST['question_text']);
                $questionType = sanitizeInput($_POST['question_type']);
                $subjectId = sanitizeInput($_POST['subject_id']);
                $correctAnswer = sanitizeInput($_POST['correct_answer'] ?? '');
                $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);

                $db->beginTransaction();

                try {
                    // Insert question
                    $stmt = $db->prepare(
                        "INSERT INTO QuestionBank (
                            teacher_id, 
                            subject_id,
                            question_text,
                            question_type,
                            correct_answer,
                            max_score
                        ) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $teacherId,
                        $subjectId,
                        $questionText,
                        $questionType,
                        $correctAnswer,
                        $maxScore
                    ]);

                    $questionId = $db->lastInsertId();

                    // Handle MCQ options if applicable
                    if ($questionType === 'MCQ' && isset($_POST['options'])) {
                        $stmt = $db->prepare(
                            "INSERT INTO QuestionBankOptions (
                                question_id,
                                option_text,
                                is_correct
                            ) VALUES (?, ?, ?)"
                        );

                        foreach ($_POST['options'] as $index => $option) {
                            $isCorrect = isset($_POST['correct_option']) &&
                                $_POST['correct_option'] == $index ? 1 : 0;
                            $stmt->execute([$questionId, sanitizeInput($option), $isCorrect]);
                        }
                    }

                    $db->commit();
                    $success = 'Question added successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;

            case 'edit':
                $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
                $questionText = sanitizeInput($_POST['question_text']);
                $questionType = sanitizeInput($_POST['question_type']);
                $subjectId = sanitizeInput($_POST['subject_id']);
                $correctAnswer = sanitizeInput($_POST['correct_answer'] ?? '');
                $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);

                $db->beginTransaction();

                try {
                    // Update question
                    $stmt = $db->prepare(
                        "UPDATE QuestionBank 
             SET subject_id = ?,
                 question_text = ?,
                 correct_answer = ?,
                 max_score = ?
             WHERE question_id = ? AND teacher_id = ?"
                    );
                    $stmt->execute([
                        $subjectId,
                        $questionText,
                        $correctAnswer,
                        $maxScore,
                        $questionId,
                        $teacherId
                    ]);

                    // Handle MCQ options if applicable
                    if ($questionType === 'MCQ') {
                        // Delete existing options
                        $stmt = $db->prepare("DELETE FROM QuestionBankOptions WHERE question_id = ?");
                        $stmt->execute([$questionId]);

                        // Insert new options
                        if (isset($_POST['options'])) {
                            $stmt = $db->prepare(
                                "INSERT INTO QuestionBankOptions (
                        question_id,
                        option_text,
                        is_correct
                    ) VALUES (?, ?, ?)"
                            );

                            foreach ($_POST['options'] as $index => $option) {
                                $isCorrect = isset($_POST['correct_option']) &&
                                    $_POST['correct_option'] == $index ? 1 : 0;
                                $stmt->execute([$questionId, sanitizeInput($option), $isCorrect]);
                            }
                        }
                    }

                    $db->commit();
                    $success = 'Question updated successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;

            case 'delete':
                $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
                if (!$questionId) {
                    throw new Exception('Invalid question ID');
                }

                $stmt = $db->prepare(
                    "DELETE FROM QuestionBank 
                     WHERE question_id = ? AND teacher_id = ?"
                );
                if ($stmt->execute([$questionId, $teacherId])) {
                    $success = 'Question deleted successfully';
                } else {
                    throw new Exception('Failed to delete question');
                }
                break;
        }
    }

    // Build query for questions
    $query = "SELECT qb.*, s.subject_name,
    (SELECT COUNT(*) FROM Questions q 
    WHERE q.question_text = qb.question_text) as usage_count,
    (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
            'option_text', qbo.option_text,
            'is_correct', qbo.is_correct
        )
    )
    FROM QuestionBankOptions qbo
    WHERE qbo.question_id = qb.question_id) as options
    FROM QuestionBank qb
    JOIN Subjects s ON qb.subject_id = s.subject_id
    WHERE qb.teacher_id = ?";
    $params = [$teacherId];

    if ($searchQuery) {
        $query .= " AND (qb.question_text LIKE ? OR s.subject_name LIKE ?)";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
    }

    if ($subjectFilter) {
        $query .= " AND qb.subject_id = ?";
        $params[] = $subjectFilter;
    }

    if ($typeFilter) {
        $query .= " AND qb.question_type = ?";
        $params[] = $typeFilter;
    }

    $query .= " ORDER BY qb.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Question bank error: " . $e->getMessage());
}

$pageTitle = 'Question Bank';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Question Bank</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
            <i class="fas fa-plus-circle me-2"></i>Add Question
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Question Statistics -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase mb-2">Total Questions</h6>
                            <h4 class="mb-0"><?php echo $questionStats['total_questions']; ?></h4>
                        </div>
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                            <i class="fas fa-question-circle fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase mb-2">Multiple Choice</h6>
                            <h4 class="mb-0"><?php echo $questionStats['mcq_count']; ?></h4>
                        </div>
                        <div class="rounded-circle bg-success bg-opacity-10 p-3">
                            <i class="fas fa-list fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase mb-2">Short Answer</h6>
                            <h4 class="mb-0"><?php echo $questionStats['short_answer_count']; ?></h4>
                        </div>
                        <div class="rounded-circle bg-info bg-opacity-10 p-3">
                            <i class="fas fa-pencil-alt fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="question-bank.php" class="row g-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="search" class="form-control"
                            placeholder="Search questions..."
                            value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="subject" class="form-select">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['subject_id']; ?>"
                                <?php echo $subjectFilter == $subject['subject_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="MCQ" <?php echo $typeFilter === 'MCQ' ? 'selected' : ''; ?>>
                            Multiple Choice
                        </option>
                        <option value="Short Answer" <?php echo $typeFilter === 'Short Answer' ? 'selected' : ''; ?>>
                            Short Answer
                        </option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Questions List -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($questions)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No questions found</h5>
                    <?php if ($searchQuery || $subjectFilter || $typeFilter): ?>
                        <p class="text-muted">Try adjusting your search filters</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Score</th>
                                <th>Times Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($question['question_text']); ?></td>
                                    <td><?php echo htmlspecialchars($question['subject_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                                                echo $question['question_type'] === 'MCQ' ? 'success' : 'info';
                                                                ?>">
                                            <?php echo $question['question_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $question['max_score']; ?> points</td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo $question['usage_count']; ?> time(s)
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="editQuestion(<?php
                                                                        $questionData = $question;
                                                                        if ($question['options']) {
                                                                            $questionData['options'] = json_decode($question['options'], true);
                                                                        }
                                                                        echo htmlspecialchars(json_encode($questionData));
                                                                        ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="deleteQuestion(<?php echo $question['question_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Edit Question Modal -->
    <div class="modal fade" id="editQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="question-bank.php" id="editQuestionForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="question_id" id="editQuestionId">
                    <input type="hidden" name="question_type" id="editQuestionTypeHidden">

                    <div class="modal-header">
                        <h5 class="modal-title">Edit Question</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <select name="subject_id" id="editSubjectId" class="form-select" required>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Question Type</label>
                            <input type="text" id="editQuestionType" class="form-control" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <textarea name="question_text" id="editQuestionText" class="form-control" rows="3" required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Points</label>
                            <input type="number" name="max_score" id="editMaxScore" class="form-control"
                                min="0.5" max="100" step="0.5" required>
                        </div>

                        <!-- Short Answer Settings -->
                        <div id="editShortAnswer" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Answer Type</label>
                                <select name="answer_mode" id="editAnswerMode"
                                    class="form-select" onchange="toggleEditAnswerOptions(this.value)">
                                    <option value="exact">Exact Match</option>
                                    <option value="any_match">Any Valid Answer</option>
                                </select>
                            </div>

                            <div id="editAnswerCountContainer" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Number of Required Answers</label>
                                    <input type="number" name="answer_count" id="editAnswerCount"
                                        class="form-control" min="1" value="1"
                                        onchange="updateAnswerFields(this.value)">
                                    <div class="form-text">
                                        How many answers should the student provide?
                                    </div>
                                </div>
                            </div>

                            <div id="editValidAnswersContainer" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label">Valid Answers</label>
                                    <div class="valid-answers" id="editValidAnswers"></div>
                                    <div class="d-flex justify-content-end mt-2">
                                        <button type="button" class="btn btn-outline-primary btn-sm"
                                            onclick="addValidAnswer('edit')">
                                            <i class="fas fa-plus me-1"></i>Add Answer
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div id="editCorrectAnswerContainer">
                                <div class="mb-3">
                                    <label class="form-label">Correct Answer</label>
                                    <textarea name="correct_answer" id="editCorrectAnswer" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- MCQ Options -->
                        <div id="editMcqOptions" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Options</label>
                                <div id="editOptionsContainer"></div>
                                <div class="d-flex justify-content-end mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                        onclick="addOption('edit')">
                                        <i class="fas fa-plus me-1"></i>Add Option
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<!-- Add Question Modal -->
<div class="modal fade" id="addQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="question-bank.php" id="addQuestionForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">

                <div class="modal-header">
                    <h5 class="modal-title">Add New Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-select" required>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Question Type -->
                    <div class="mb-3">
                        <label class="form-label">Question Type</label>
                        <select name="question_type" class="form-select" required
                            onchange="toggleQuestionOptions(this.value)">
                            <option value="MCQ">Multiple Choice</option>
                            <option value="Short Answer">Short Answer</option>
                        </select>
                    </div>

                    <!-- Question Text -->
                    <div class="mb-3">
                        <label class="form-label">Question Text</label>
                        <textarea name="question_text" class="form-control" rows="3" required></textarea>
                    </div>

                    <!-- Points -->
                    <div class="mb-3">
                        <label class="form-label">Points</label>
                        <input type="number" name="max_score" class="form-control"
                            min="0.5" max="100" step="0.5" required>
                    </div>

                    <!-- Short Answer Settings -->
                    <div id="shortAnswer" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Answer Type</label>
                            <select name="answer_mode" class="form-select" onchange="toggleAnswerOptions(this.value)">
                                <option value="exact">Exact Match</option>
                                <option value="any_match">Any Valid Answer</option>
                            </select>
                        </div>

                        <div id="answerCountContainer" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Number of Required Answers</label>
                                <input type="number" name="answer_count" class="form-control"
                                    min="1" value="1" onchange="updateAnswerFields(this.value)">
                                <div class="form-text">
                                    How many answers should the student provide?
                                </div>
                            </div>
                        </div>

                        <div id="validAnswersContainer" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Valid Answers</label>
                                <div class="valid-answers"></div>
                                <div class="d-flex justify-content-end mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                        onclick="addValidAnswer()">
                                        <i class="fas fa-plus me-1"></i>Add Answer
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div id="correctAnswerContainer">
                            <div class="mb-3">
                                <label class="form-label">Correct Answer</label>
                                <textarea name="correct_answer" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- MCQ Options -->
                    <div id="mcqOptions">
                        <div class="mb-3">
                            <label class="form-label">Options</label>
                            <div id="optionsContainer"></div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                    onclick="addOption()">
                                    <i class="fas fa-plus me-1"></i>Add Option
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Question Modal -->
<div class="modal fade" id="deleteQuestionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="question-bank.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="question_id" id="deleteQuestionId">

                <div class="modal-header">
                    <h5 class="modal-title">Delete Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone! The question will be removed from the question bank,
                        but it will remain in any assessments where it has already been used.
                    </div>
                    <p class="text-center">Are you sure you want to delete this question?</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Initialize delete modal
    const deleteQuestionModal = new bootstrap.Modal(document.getElementById('deleteQuestionModal'));

    // Handle question type toggle
    function toggleQuestionOptions(type) {
        const mcqOptions = document.getElementById('mcqOptions');
        const textAnswer = document.getElementById('textAnswer');

        if (type === 'MCQ') {
            mcqOptions.style.display = 'block';
            textAnswer.style.display = 'none';
            // Add initial option if none exist
            if (document.getElementById('optionsContainer').children.length === 0) {
                addOption();
            }
        } else {
            mcqOptions.style.display = 'none';
            textAnswer.style.display = 'block';
        }
    }

    // Add MCQ option
    function addOption() {
        const container = document.getElementById('optionsContainer');
        const index = container.children.length;

        const optionDiv = document.createElement('div');
        optionDiv.className = 'input-group mb-2';
        optionDiv.innerHTML = `
        <div class="input-group-text">
            <input type="radio" name="correct_option" value="${index}" required>
        </div>
        <input type="text" name="options[]" class="form-control" required>
        <button type="button" class="btn btn-outline-danger" onclick="removeOption(this)">
            <i class="fas fa-times"></i>
        </button>
    `;

        container.appendChild(optionDiv);
    }

    // Remove MCQ option
    function removeOption(button) {
        const container = button.closest('.input-group').parentElement;
        if (container.children.length > 1) {
            button.closest('.input-group').remove();
            // Update radio button values
            container.querySelectorAll('input[type="radio"]').forEach((radio, index) => {
                radio.value = index;
            });
        }
    }

    // Delete question function
    function deleteQuestion(questionId) {
        document.getElementById('deleteQuestionId').value = questionId;
        deleteQuestionModal.show();
    }

    // Initialize edit modal
    const editQuestionModal = new bootstrap.Modal(document.getElementById('editQuestionModal'));

    // Edit question function
    function editQuestion(question) {
        document.getElementById('editQuestionId').value = question.question_id;
        document.getElementById('editSubjectId').value = question.subject_id;
        document.getElementById('editQuestionType').value = question.question_type;
        document.getElementById('editQuestionTypeHidden').value = question.question_type;
        document.getElementById('editQuestionText').value = question.question_text;
        document.getElementById('editMaxScore').value = question.max_score;

        // Show/hide appropriate sections based on question type
        if (question.question_type === 'MCQ') {
            document.getElementById('editMcqOptions').style.display = 'block';
            document.getElementById('editShortAnswer').style.display = 'none';
            document.getElementById('editOptionsContainer').innerHTML = ''; // Clear existing options

            // Add options if they exist
            if (question.options && Array.isArray(question.options)) {
                question.options.forEach((option, index) => {
                    addEditOption(option.option_text, option.is_correct === '1' || option.is_correct === 1);
                });
            } else {
                // Add one empty option if no options exist
                addEditOption();
            }
        } else {
            document.getElementById('editMcqOptions').style.display = 'none';
            document.getElementById('editShortAnswer').style.display = 'block';
            document.getElementById('editCorrectAnswer').value = question.correct_answer || '';
        }

        editQuestionModal.show();
    }

    // Add option in edit modal
    function addEditOption(value = '', isCorrect = false) {
        const container = document.getElementById('editOptionsContainer');
        const index = container.children.length;

        const optionDiv = document.createElement('div');
        optionDiv.className = 'input-group mb-2';
        optionDiv.innerHTML = `
        <div class="input-group-text">
            <input type="radio" name="correct_option" value="${index}" 
                   ${isCorrect ? 'checked' : ''} ${document.getElementById('editQuestionType').value === 'MCQ' ? 'required' : ''}>
        </div>
        <input type="text" name="options[]" class="form-control" 
               value="${value ? escapeHtml(value) : ''}" 
               ${document.getElementById('editQuestionType').value === 'MCQ' ? 'required' : ''}>
        <button type="button" class="btn btn-outline-danger" onclick="removeOption(this)">
            <i class="fas fa-times"></i>
        </button>
    `;

        container.appendChild(optionDiv);
    }

    // Modify the toggleQuestionOptions function
    function toggleQuestionOptions(type) {
        const mcqOptions = document.getElementById('mcqOptions');
        const textAnswer = document.getElementById('textAnswer');
        const correctOptionInputs = document.querySelectorAll('input[name="correct_option"]');
        const optionsInputs = document.querySelectorAll('input[name="options[]"]');

        if (type === 'MCQ') {
            mcqOptions.style.display = 'block';
            textAnswer.style.display = 'none';
            correctOptionInputs.forEach(input => input.required = true);
            optionsInputs.forEach(input => input.required = true);
            if (document.getElementById('optionsContainer').children.length === 0) {
                addOption();
            }
        } else {
            mcqOptions.style.display = 'none';
            textAnswer.style.display = 'block';
            correctOptionInputs.forEach(input => input.required = false);
            optionsInputs.forEach(input => input.required = false);
        }
    }

    // Helper function to escape HTML
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    // Initialize page elements
    document.addEventListener('DOMContentLoaded', function() {
        // Setup form validation
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });

        // Initialize question type toggle
        toggleQuestionOptions('MCQ');




        // Auto-submit filter form when selects change
        document.querySelectorAll('select[name="subject"], select[name="type"]').forEach(select => {
            select.addEventListener('change', () => select.form.submit());
        });

        // Setup search input debounce
        const searchInput = document.querySelector('input[name="search"]');
        let timeout;
        searchInput.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                searchInput.form.submit();
            }, 500);
        });
    });

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>