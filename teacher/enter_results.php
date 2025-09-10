<?php
// teacher/enter_results.php
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
    $students = [];
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherId = $stmt->fetch()['teacher_id'];

    // Get assessment details with authorization check
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name
         FROM Assessments a
         JOIN Classes c ON a.class_id = c.class_id
         JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
         JOIN Subjects s ON a.subject_id = s.subject_id
         WHERE a.assessment_id = ? AND tca.teacher_id = ?"
    );
    $stmt->execute([$assessmentId, $teacherId]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found or unauthorized');
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $score = filter_var($_POST['score'], FILTER_VALIDATE_FLOAT);
        $feedback = sanitizeInput($_POST['feedback'] ?? '');

        if (!$studentId || !is_numeric($score) || $score < 0 || $score > 100) {
            throw new Exception('Invalid score value');
        }

        $stmt = $db->prepare(
            "INSERT INTO Results (
                assessment_id, 
                student_id, 
                score, 
                feedback, 
                status,
                created_at
            ) VALUES (?, ?, ?, ?, 'completed', CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
                score = VALUES(score),
                feedback = VALUES(feedback),
                status = VALUES(status)"
        );

        if ($stmt->execute([$assessmentId, $studentId, $score, $feedback])) {
            logSystemActivity(
                'Assessment Results',
                "Result entered/updated for assessment ID: $assessmentId, student ID: $studentId",
                'INFO'
            );
            $success = 'Result saved successfully';
        } else {
            throw new Exception('Failed to save result');
        }
    }

    // Get students and their results
    $stmt = $db->prepare(
        "SELECT s.student_id, s.first_name, s.last_name,
                MAX(r.score) as score, 
                MAX(r.feedback) as feedback, 
                MAX(r.created_at) as submission_date,
                COUNT(DISTINCT sa.answer_id) as answered_questions,
                (SELECT COUNT(*) FROM Questions WHERE assessment_id = ?) as total_questions
         FROM Students s
         JOIN Classes c ON s.class_id = c.class_id
         LEFT JOIN Results r ON (s.student_id = r.student_id AND r.assessment_id = ?)
         LEFT JOIN StudentAnswers sa ON (s.student_id = sa.student_id AND sa.assessment_id = ?)
         WHERE c.class_id = ?
         GROUP BY s.student_id, s.first_name, s.last_name
         ORDER BY s.last_name, s.first_name"
    );
    $stmt->execute([
        $assessmentId,
        $assessmentId,
        $assessmentId,
        $assessment['class_id']
    ]);
    $students = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Enter results error: " . $e->getMessage());
}

$pageTitle = 'Enter Results';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><?php echo htmlspecialchars($assessment['title']); ?></h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($assessment['subject_name']); ?> | 
                <?php echo htmlspecialchars($assessment['class_name']); ?>
            </p>
        </div>
        <div class="btn-group">
            <a href="view_results.php?id=<?php echo $assessmentId; ?>" class="btn btn-primary">
                <i class="fas fa-chart-bar me-2"></i>View Results
            </a>
            <a href="results.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Results
            </a>
        </div>
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

    <!-- Results Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Questions Completed</th>
                            <th>Current Score</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                            <td>
                                <?php if ($student['answered_questions'] > 0): ?>
                                    <?php echo $student['answered_questions']; ?>/<?php echo $student['total_questions']; ?>
                                    (<?php echo round(($student['answered_questions'] / $student['total_questions']) * 100); ?>%)
                                <?php else: ?>
                                    <span class="text-muted">No answers submitted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($student['score'] !== null): ?>
                                    <span class="badge bg-<?php 
                                        echo $student['score'] >= 70 ? 'success' : 
                                            ($student['score'] >= 50 ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo number_format($student['score'], 1); ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Graded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($student['submission_date']): ?>
                                    <?php echo date('M d, Y g:i A', strtotime($student['submission_date'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <?php if ($student['answered_questions'] > 0): ?>
                                    <a href="view_student_answers.php?assessment=<?php echo $assessmentId; ?>&student=<?php echo $student['student_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-success"
                                            onclick="enterResult(<?php echo $student['student_id']; ?>, '<?php 
                                                echo htmlspecialchars(
                                                    $student['first_name'] . ' ' . $student['last_name'], 
                                                    ENT_QUOTES
                                                ); 
                                            ?>', <?php 
                                                echo $student['score'] !== null ? 
                                                    $student['score'] : 'null'; 
                                            ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Enter Result Modal -->
<div class="modal fade" id="enterResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="enter_results.php?id=<?php echo $assessmentId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="student_id" id="studentId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Enter Result for <span id="studentName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Score (%)</label>
                        <input type="number" name="score" id="score" class="form-control" 
                               min="0" max="100" step="0.1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Feedback (Optional)</label>
                        <textarea name="feedback" class="form-control" rows="3"></textarea>
                        <div class="form-text">
                            Provide any additional comments or feedback for the student
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Result</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize modal
const resultModal = new bootstrap.Modal(document.getElementById('enterResultModal'));

// Function to handle entering/editing result
function enterResult(studentId, studentName, currentScore) {
    document.getElementById('studentId').value = studentId;
    document.getElementById('studentName').textContent = studentName;
    document.getElementById('score').value = currentScore !== null ? currentScore : '';
    resultModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    // Add form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
    });

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        }, 5000);
    });
});

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>