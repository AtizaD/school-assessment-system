<?php
// create_assessment.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Assessment.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

requireRole('teacher');

// Start output buffering
ob_start();

// Check for existing flash messages
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

$classByProgram = [];
$allSubjects = [];

try {
    // Get teacher ID
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherInfo['teacher_id'];
    
    // Get current semester and all semesters using shared functions
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $selectedSemesterId = $currentSemester['semester_id'];

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid request');
            }

            // Begin transaction
            $db->beginTransaction();
            
            // Use the selected semester from form submission
            $semesterId = isset($_POST['semester_id']) ? (int)$_POST['semester_id'] : $selectedSemesterId;
            
            // Validate semester exists
            $stmt = $db->prepare("SELECT semester_id FROM semesters WHERE semester_id = ?");
            $stmt->execute([$semesterId]);
            $semester = $stmt->fetch();
            
            if (!$semester) {
                throw new Exception('Invalid semester selected');
            }
            
            // Check if class_subject selections were made
            if (empty($_POST['class_subjects']) || !is_array($_POST['class_subjects'])) {
                throw new Exception('You must select at least one class and subject');
            }
            
            // Validate class permissions
            foreach ($_POST['class_subjects'] as $classSubject) {
                list($classId, $subjectId) = explode(',', sanitizeInput($classSubject));
                
                // Verify teacher assignment
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM TeacherClassAssignments 
                     WHERE teacher_id = ? AND class_id = ? AND subject_id = ?"
                );
                $stmt->execute([$teacherId, $classId, $subjectId]);
                
                if ($stmt->fetchColumn() == 0) {
                    throw new Exception('Not authorized for one or more selected class/subject combinations');
                }
            }

            // Validate start time, end time and duration
            $startTime = sanitizeInput($_POST['start_time']);
            $endTime = sanitizeInput($_POST['end_time']);
            $duration = $_POST['duration'] ? intval($_POST['duration']) : null;

            $maxDuration = calculateTimeDifferenceInMinutes($startTime, $endTime);

            if ($duration && $duration > $maxDuration) {
                throw new Exception('Duration cannot exceed the time between start and end times');
            }

            // Handle question limit settings
            $useQuestionLimit = isset($_POST['use_question_limit']) ? 1 : 0;
            $questionsToAnswer = $useQuestionLimit && $_POST['questions_to_answer'] ? 
                intval($_POST['questions_to_answer']) : null;

            // Validate question limit
            if ($useQuestionLimit && (!$questionsToAnswer || $questionsToAnswer < 1)) {
                throw new Exception('Please specify how many questions students should answer');
            }

            // Validate assessment type
            $assessmentTypeId = intval($_POST['assessment_type_id'] ?? 0);
            if (!$assessmentTypeId) {
                throw new Exception('Please select an assessment type');
            }

            // Create assessment
            $stmt = $db->prepare(
                "INSERT INTO Assessments (
                    semester_id,
                    assessment_type_id,
                    title,
                    description,
                    date,
                    start_time,
                    end_time,
                    duration,
                    allow_late_submission,
                    late_submission_days,
                    shuffle_questions,
                    shuffle_options,
                    use_question_limit,
                    questions_to_answer,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );

            $stmt->execute([
                $semesterId,
                $assessmentTypeId,
                sanitizeInput($_POST['title']),
                sanitizeInput($_POST['description'] ?? ''),
                sanitizeInput($_POST['date']),
                $startTime,
                $endTime,
                $duration,
                isset($_POST['allow_late_submission']) ? 1 : 0,
                isset($_POST['allow_late_submission']) && isset($_POST['late_submission_days']) ? 
                    filter_var($_POST['late_submission_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]) ?: 7 : 7,
                isset($_POST['shuffle_questions']) ? 1 : 0,
                isset($_POST['shuffle_options']) ? 1 : 0,
                $useQuestionLimit,
                $questionsToAnswer
            ]);

            $assessmentId = $db->lastInsertId();
            
            // Link assessment to classes and subjects
            $stmtAssignClass = $db->prepare("INSERT INTO AssessmentClasses (assessment_id, class_id, subject_id) VALUES (?, ?, ?)");
            
            foreach ($_POST['class_subjects'] as $classSubject) {
                list($classId, $subjectId) = explode(',', sanitizeInput($classSubject));
                $stmtAssignClass->execute([$assessmentId, $classId, $subjectId]);
            }
            
            // Log successful creation
            logSystemActivity(
                'Assessment Creation',
                "Successfully created assessment ID: $assessmentId",
                'INFO'
            );
    
            $db->commit();
            
            $_SESSION['success'] = 'Assessment created successfully';
            
            // Redirect with semester parameter
            $redirectUrl = addSemesterToUrl('assessments.php', $semesterId);
            header("Location: $redirectUrl");
            ob_end_clean();
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            
            $error = $e->getMessage();
            logError("Assessment creation error: " . $e->getMessage());
        }
    }

    // Fetch teacher's classes and subjects
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, p.program_name, s.subject_id, s.subject_name
         FROM Classes c
         JOIN Programs p ON c.program_id = p.program_id
         JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
         JOIN Subjects s ON tca.subject_id = s.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY p.program_name, c.class_name, s.subject_name"
    );
    $stmt->execute([$teacherId]);
    $assignments = $stmt->fetchAll();

    // Organize classes by program and collect all subjects
    foreach ($assignments as $assignment) {
        if (!isset($classByProgram[$assignment['program_name']])) {
            $classByProgram[$assignment['program_name']] = [];
        }
        $classByProgram[$assignment['program_name']][] = $assignment;
        
        // Collect unique subjects
        if (!in_array($assignment['subject_name'], $allSubjects)) {
            $allSubjects[] = $assignment['subject_name'];
        }
    }
    
    // Sort subjects alphabetically
    sort($allSubjects);
    
    // Get active assessment types
    $stmt = $db->query("
        SELECT type_id, type_name, description 
        FROM assessment_types 
        WHERE is_active = 1 
        ORDER BY sort_order
    ");
    $assessmentTypes = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Create assessment page load error: " . $e->getMessage());
}

$pageTitle = 'Create Assessment';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="page-gradient">
    <div class="page-content container-fluid px-4">
        <div class="header-gradient d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Create New Assessment</h1>
            <a href="<?php echo addSemesterToUrl('assessments.php', $currentSemester['semester_id']); ?>" class="btn btn-custom">
                <i class="fas fa-arrow-left me-2"></i>Back to Assessments
            </a>
        </div>
        

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Assessment Details</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo addSemesterToUrl('create_assessment.php', $currentSemester['semester_id']); ?>" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="semester_id" value="<?php echo $currentSemester['semester_id']; ?>">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Classes & Subjects</label>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            You can select multiple classes to assign this assessment to. Use the filters below to narrow down your selection.
                        </div>
                        
                        <!-- Filter Controls -->
                        <div class="filter-controls mb-3 p-3 bg-light rounded">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Filter by Program:</label>
                                    <select id="programFilter" class="form-select form-select-sm">
                                        <option value="">All Programs</option>
                                        <?php foreach (array_keys($classByProgram) as $program): ?>
                                            <option value="<?php echo htmlspecialchars($program); ?>">
                                                <?php echo htmlspecialchars($program); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Filter by Subject:</label>
                                    <select id="subjectFilter" class="form-select form-select-sm">
                                        <option value="">All Subjects</option>
                                        <?php foreach ($allSubjects as $subject): ?>
                                            <option value="<?php echo htmlspecialchars($subject); ?>">
                                                <?php echo htmlspecialchars($subject); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end gap-2">
                                    <button type="button" id="selectAllVisible" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-check-square me-1"></i>Select All Visible
                                    </button>
                                    <button type="button" id="deselectAll" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-square me-1"></i>Deselect All
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Selection Summary -->
                        <div id="selectionSummary" class="alert alert-secondary mb-3" style="display: none;">
                            <i class="fas fa-info-circle me-2"></i>
                            <span id="selectionCount">0</span> class(es) selected
                        </div>
                        
                        <!-- Class Selection Area -->
                        <div class="class-selection-area p-3 border rounded">
                            <?php foreach ($classByProgram as $program => $classes): ?>
                                <div class="program-section" data-program="<?php echo htmlspecialchars($program); ?>">
                                    <div class="program-header d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="program-title mb-0 text-primary fw-bold">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            <?php echo htmlspecialchars($program); ?>
                                        </h6>
                                        <div class="program-controls">
                                            <button type="button" class="btn btn-sm btn-outline-primary select-program-all" 
                                                    data-program="<?php echo htmlspecialchars($program); ?>">
                                                <i class="fas fa-check-square me-1"></i>Select All
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Group by Subject within Program -->
                                    <?php 
                                    $classesBySubject = [];
                                    foreach ($classes as $class) {
                                        if (!isset($classesBySubject[$class['subject_name']])) {
                                            $classesBySubject[$class['subject_name']] = [];
                                        }
                                        $classesBySubject[$class['subject_name']][] = $class;
                                    }
                                    ?>
                                    
                                    <?php foreach ($classesBySubject as $subject => $subjectClasses): ?>
                                        <div class="subject-group mb-3" data-subject="<?php echo htmlspecialchars($subject); ?>">
                                            <div class="subject-header d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="subject-title mb-0 text-secondary">
                                                    <i class="fas fa-book me-2"></i>
                                                    <?php echo htmlspecialchars($subject); ?>
                                                </h6>
                                                <button type="button" class="btn btn-sm btn-outline-secondary select-subject-all" 
                                                        data-subject="<?php echo htmlspecialchars($subject); ?>"
                                                        data-program="<?php echo htmlspecialchars($program); ?>">
                                                    <i class="fas fa-check-square me-1"></i>Select All
                                                </button>
                                            </div>
                                            <div class="row mb-2">
                                                <?php foreach ($subjectClasses as $class): ?>
                                                    <div class="col-md-6 col-lg-4 mb-2">
                                                        <div class="form-check class-checkbox" 
                                                             data-program="<?php echo htmlspecialchars($program); ?>"
                                                             data-subject="<?php echo htmlspecialchars($subject); ?>">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="class_subjects[]" 
                                                                   value="<?php echo $class['class_id'] . ',' . $class['subject_id']; ?>" 
                                                                   id="class_<?php echo $class['class_id'] . '_' . $class['subject_id']; ?>">
                                                            <label class="form-check-label" for="class_<?php echo $class['class_id'] . '_' . $class['subject_id']; ?>">
                                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <hr class="my-4">
                            <?php endforeach; ?>
                        </div>
                        <div class="invalid-feedback">Please select at least one class and subject</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-4">
                            <label class="form-label fw-bold">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Assessment Type</label>
                            <select name="assessment_type_id" class="form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach ($assessmentTypes as $type): ?>
                                    <option value="<?php echo $type['type_id']; ?>">
                                        <?php echo htmlspecialchars($type['type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="date" class="form-control" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Duration (minutes)</label>
                            <input type="number" name="duration" class="form-control" min="1">
                            <div class="form-text">Leave empty for no time limit</div>
                        </div>
                        
                        <div class="col-md-6 mb-4">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="allow_late_submission" class="form-check-input" id="allowLate">
                                <label class="form-check-label fw-bold" for="allowLate">Allow Late Submissions</label>
                            </div>
                            
                            <div class="mt-3" id="lateSubmissionDays" style="display: none;">
                                <label class="form-label fw-bold">Late Submission Period (days)</label>
                                <input type="number" name="late_submission_days" class="form-control" min="1" max="30" value="7">
                                <div class="form-text">Number of days after assessment date to allow late submissions</div>
                            </div>
                            
                            <div class="form-check mt-3">
                                <input type="checkbox" name="shuffle_questions" class="form-check-input" id="shuffleQuestions">
                                <label class="form-check-label fw-bold" for="shuffleQuestions">
                                    <i class="fas fa-random me-1"></i> Randomize Question Order
                                </label>
                                <div class="form-text">Each student will see questions in a different random order</div>
                            </div>
                            
                            <div class="form-check mt-3">
                                <input type="checkbox" name="shuffle_options" class="form-check-input" id="shuffleOptions">
                                <label class="form-check-label fw-bold" for="shuffleOptions">
                                    <i class="fas fa-random me-1"></i> Randomize MCQ Options
                                </label>
                                <div class="form-text">Answer choices for multiple choice questions will be displayed in random order</div>
                            </div>

                            <div class="form-check mt-3">
                                <input type="checkbox" name="use_question_limit" class="form-check-input" id="useQuestionLimit">
                                <label class="form-check-label fw-bold" for="useQuestionLimit">
                                    <i class="fas fa-layer-group me-1"></i> Use Question Pool with Limit
                                </label>
                                <div class="form-text">Students answer a subset of total questions</div>
                            </div>

                            <div id="questionLimitSettings" class="mt-3 p-3 bg-light rounded" style="display: none;">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Questions to Answer</label>
                                    <input type="number" name="questions_to_answer" class="form-control" min="1">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Students will answer this many questions randomly selected from the total pool
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3">
                        <a href="<?php echo addSemesterToUrl('assessments.php', $currentSemester['semester_id']); ?>" class="btn delete-btn">Cancel</a>
                        <button type="submit" class="btn btn-custom">Create Assessment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom CSS for assessment page */
.page-gradient {
    min-height: 100vh;
    padding: 1rem;
    position: relative;
    background: linear-gradient(135deg, #f8f9fa 0%, #fff9e6 100%);
}

.header-gradient {
    background: linear-gradient(90deg, #000000 0%, #ffd700 50%, #ffeb80 100%);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.header-gradient h1 {
    color: white;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
    margin: 0;
}

.card {
    background: white;
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}

.card-header {
    background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
    border: none;
    color: white;
    font-weight: 500;
    border-radius: 12px 12px 0 0;
    padding: 1rem 1.5rem;
}

.btn-custom {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
    font-weight: 500;
    padding: 0.5rem 1.5rem;
}

.btn-custom:hover {
    background: linear-gradient(45deg, #ffd700 0%, #000000 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    color: white;
}

.delete-btn {
    background: linear-gradient(45deg, rgba(0,0,0,0.05) 0%, #ffe6e6 100%);
    border: 1px solid #ff4d4d;
    color: #ff4d4d;
    padding: 0.5rem 1.5rem;
}

.delete-btn:hover {
    background: linear-gradient(45deg, #ff4d4d 0%, #000000 100%);
    color: white;
}

.form-control, .form-select {
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 6px;
    padding: 0.625rem;
}

.form-control:focus, .form-select:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.2rem rgba(255,215,0,0.25);
}

.status-select {
    background: linear-gradient(90deg, #ffffff 0%, #fff9e6 100%);
}

.class-selection-area {
    max-height: 400px;
    overflow-y: auto;
    background-color: #f9f9f9;
}

.filter-controls {
    border: 2px dashed #dee2e6;
    transition: all 0.3s ease;
}

.filter-controls:hover {
    border-color: #ffd700;
    background-color: #fff9e6 !important;
}

.program-section {
    margin-bottom: 1.5rem;
}

.program-header {
    background: linear-gradient(90deg, rgba(0,0,0,0.05) 0%, rgba(255,215,0,0.1) 100%);
    padding: 0.75rem;
    border-radius: 6px;
    border-left: 4px solid #ffd700;
}

.subject-group {
    margin-left: 1rem;
    padding-left: 1rem;
    border-left: 2px solid #e9ecef;
}

.subject-header {
    background: rgba(108,117,125,0.1);
    padding: 0.5rem;
    border-radius: 4px;
}

.class-checkbox {
    transition: all 0.2s ease;
    padding: 0.25rem;
    border-radius: 4px;
}

.class-checkbox:hover {
    background-color: rgba(255,215,0,0.1);
}

.form-check-input:checked {
    background-color: #ffd700;
    border-color: #ffd700;
}

.program-title {
    font-size: 1rem;
}

.subject-title {
    font-size: 0.9rem;
}

#questionLimitSettings {
    border: 2px dashed #ffd700;
    background: rgba(255, 215, 0, 0.05);
}

.semester-selector {
    background: linear-gradient(90deg, rgba(255,215,0,0.1) 0%, rgba(255,215,0,0.05) 100%);
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid rgba(255,215,0,0.3);
}

.semester-selector .form-select {
    background: white;
    border: 1px solid rgba(0,0,0,0.15);
}

.semester-selector .form-select:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.2rem rgba(255,215,0,0.25);
}

@media (max-width: 768px) {
    .page-gradient {
        padding: 0.5rem;
    }
    
    .header-gradient {
        padding: 1rem;
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .btn-custom, .delete-btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .d-flex.justify-content-end {
        flex-direction: column;
    }
    
    .filter-controls .row {
        flex-direction: column;
    }
    
    .filter-controls .col-md-4 {
        margin-bottom: 1rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filter functionality
    const programFilter = document.getElementById('programFilter');
    const subjectFilter = document.getElementById('subjectFilter');
    const selectAllVisibleBtn = document.getElementById('selectAllVisible');
    const deselectAllBtn = document.getElementById('deselectAll');
    const selectionSummary = document.getElementById('selectionSummary');
    const selectionCount = document.getElementById('selectionCount');
    const useQuestionLimit = document.getElementById('useQuestionLimit');
    const questionLimitSettings = document.getElementById('questionLimitSettings');
    
    // Late submission functionality
    const allowLateCheckbox = document.getElementById('allowLate');
    const lateSubmissionDays = document.getElementById('lateSubmissionDays');
    
    if (allowLateCheckbox && lateSubmissionDays) {
        allowLateCheckbox.addEventListener('change', function() {
            if (this.checked) {
                lateSubmissionDays.style.display = 'block';
            } else {
                lateSubmissionDays.style.display = 'none';
            }
        });
    }
    
    // Question limit toggle
    useQuestionLimit.addEventListener('change', function() {
        questionLimitSettings.style.display = this.checked ? 'block' : 'none';
    });
    
    // Filter event listeners
    programFilter.addEventListener('change', applyFilters);
    subjectFilter.addEventListener('change', applyFilters);
    
    // Select/Deselect buttons
    selectAllVisibleBtn.addEventListener('click', function() {
        const visibleCheckboxes = document.querySelectorAll('.class-checkbox:not([style*="display: none"]) input[type="checkbox"]');
        visibleCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSelectionSummary();
    });
    
    deselectAllBtn.addEventListener('click', function() {
        const allCheckboxes = document.querySelectorAll('input[name="class_subjects[]"]');
        allCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSelectionSummary();
    });
    
    // Program-level select all buttons
    document.querySelectorAll('.select-program-all').forEach(button => {
        button.addEventListener('click', function() {
            const program = this.dataset.program;
            const programSection = document.querySelector(`[data-program="${program}"]`);
            const checkboxes = programSection.querySelectorAll('input[type="checkbox"]:not([style*="display: none"])');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectionSummary();
        });
    });
    
    // Subject-level select all buttons
    document.querySelectorAll('.select-subject-all').forEach(button => {
        button.addEventListener('click', function() {
            const subject = this.dataset.subject;
            const program = this.dataset.program;
            const subjectGroup = document.querySelector(`[data-program="${program}"] [data-subject="${subject}"]`);
            const checkboxes = subjectGroup.querySelectorAll('input[type="checkbox"]:not([style*="display: none"])');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectionSummary();
        });
    });
    
    // Update selection summary when checkboxes change
    document.addEventListener('change', function(e) {
        if (e.target.name === 'class_subjects[]') {
            updateSelectionSummary();
        }
    });
    
    function applyFilters() {
        const selectedProgram = programFilter.value;
        const selectedSubject = subjectFilter.value;
        
        // Show/hide program sections
        document.querySelectorAll('.program-section').forEach(section => {
            const programName = section.dataset.program;
            const shouldShowProgram = !selectedProgram || programName === selectedProgram;
            section.style.display = shouldShowProgram ? 'block' : 'none';
        });
        
        // Show/hide subject groups and individual checkboxes
        document.querySelectorAll('.class-checkbox').forEach(checkbox => {
            const programName = checkbox.dataset.program;
            const subjectName = checkbox.dataset.subject;
            
            const shouldShow = (!selectedProgram || programName === selectedProgram) && 
                             (!selectedSubject || subjectName === selectedSubject);
            
            checkbox.style.display = shouldShow ? 'block' : 'none';
        });
        
        // Hide subject groups that have no visible checkboxes
        document.querySelectorAll('.subject-group').forEach(group => {
            const visibleCheckboxes = group.querySelectorAll('.class-checkbox:not([style*="display: none"])');
            group.style.display = visibleCheckboxes.length > 0 ? 'block' : 'none';
        });
        
        updateSelectionSummary();
    }
    
    function updateSelectionSummary() {
        const checkedBoxes = document.querySelectorAll('input[name="class_subjects[]"]:checked');
        const count = checkedBoxes.length;
        
        selectionCount.textContent = count;
        selectionSummary.style.display = count > 0 ? 'block' : 'none';
        
        if (count > 0) {
            selectionSummary.className = 'alert alert-success mb-3';
        } else {
            selectionSummary.className = 'alert alert-secondary mb-3';
        }
    }

    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        if (!this.checkValidity() || !validateDuration() || !validateClassSelection() || !validateQuestionLimit()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
    });

    // Validate at least one class is selected
    function validateClassSelection() {
        const checkboxes = document.querySelectorAll('input[name="class_subjects[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one class and subject');
            return false;
        }
        return true;
    }

    // Validate question limit settings
    function validateQuestionLimit() {
        const useLimit = document.getElementById('useQuestionLimit').checked;
        const questionsToAnswer = document.querySelector('input[name="questions_to_answer"]').value;
        
        if (useLimit && (!questionsToAnswer || questionsToAnswer < 1)) {
            alert('Please specify how many questions students should answer');
            return false;
        }
        return true;
    }

    // Date validation
    const dateInput = document.querySelector('input[name="date"]');
    dateInput.addEventListener('change', function() {
        const selectedDate = new Date(this.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (selectedDate < today) {
            this.setCustomValidity('Please select a future date');
        } else {
            this.setCustomValidity('');
        }
    });

    function validateDuration() {
        const startTime = document.querySelector('input[name="start_time"]').value;
        const endTime = document.querySelector('input[name="end_time"]').value;
        const duration = document.querySelector('input[name="duration"]').value;

        if (startTime && endTime && duration) {
            const start = new Date(`2000-01-01T${startTime}`);
            const end = new Date(`2000-01-01T${endTime}`);
            const maxDuration = (end - start) / 60000; // Convert to minutes

            if (parseInt(duration) > maxDuration) {
                alert(`Duration cannot exceed ${Math.floor(maxDuration)} minutes based on the selected start and end times.`);
                return false;
            }
        }
        return true;
    }

    // Add event listeners to update max duration
    document.querySelector('input[name="start_time"]').addEventListener('change', updateMaxDuration);
    document.querySelector('input[name="end_time"]').addEventListener('change', updateMaxDuration);

    function updateMaxDuration() {
        const startTime = document.querySelector('input[name="start_time"]').value;
        const endTime = document.querySelector('input[name="end_time"]').value;
        const durationInput = document.querySelector('input[name="duration"]');

        if (startTime && endTime) {
            const start = new Date(`2000-01-01T${startTime}`);
            const end = new Date(`2000-01-01T${endTime}`);
            const maxDuration = Math.floor((end - start) / 60000);

            durationInput.max = maxDuration;
            durationInput.setAttribute('max', maxDuration);
        }
    }

    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        }, 5000);
    });
    
    // Initialize selection summary
    updateSelectionSummary();
});
</script>

<?php 
function calculateTimeDifferenceInMinutes($startTime, $endTime) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $interval = $start->diff($end);
    return ($interval->h * 60) + $interval->i;
}

ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php'; 
?>