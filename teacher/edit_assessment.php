<?php
// edit_assessment.php
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

// Initialize variables
$assessment = null;
$classByProgram = [];
$selectedClassSubjects = [];
$teacherId = null;
$teacherAssessments = [];
$assessmentSubjects = [];

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherInfo['teacher_id'];
    
    // Get semester information
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $semesterId = $currentSemester['semester_id'];

    // If no assessment ID is provided, show the assessment selection screen
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        // Get assessments this teacher has access to for the selected semester
        $stmt = $db->prepare(
            "SELECT DISTINCT a.assessment_id, a.title, a.date, a.status, a.reset_edit_mode, s.semester_name, 
                    GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') as classes
             FROM Assessments a
             JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
             JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
             JOIN Classes c ON ac.class_id = c.class_id
             JOIN Semesters s ON a.semester_id = s.semester_id
             WHERE tca.teacher_id = ? AND a.semester_id = ? AND tca.semester_id = ?
             GROUP BY a.assessment_id
             ORDER BY a.date DESC, a.title"
        );
        $stmt->execute([$teacherId, $semesterId, $semesterId]);
        $teacherAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If form submitted with selected assessment, redirect
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_assessment']) && is_numeric($_POST['selected_assessment'])) {
            // Check if selected assessment is completed
            $stmt = $db->prepare("SELECT status FROM Assessments WHERE assessment_id = ?");
            $stmt->execute([intval($_POST['selected_assessment'])]);
            $selectedAssessment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($selectedAssessment && $selectedAssessment['status'] === 'completed') {
                $_SESSION['error'] = 'Completed assessments cannot be edited';
                header('Location: assessments.php' . (!empty($_GET['semester']) ? '?semester=' . (int)$_GET['semester'] : ''));
                ob_end_clean();
                exit;
            }
            
            $redirectUrl = 'edit_assessment.php?id=' . intval($_POST['selected_assessment']);
            if (!empty($_GET['semester'])) {
                $redirectUrl .= '&semester=' . (int)$_GET['semester'];
            }
            header('Location: ' . $redirectUrl);
            ob_end_clean();
            exit;
        }
    } else {
        // Process with specific assessment ID
        $assessmentId = (int) $_GET['id'];

        // Check if teacher has access to this assessment and it belongs to the selected semester
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM AssessmentClasses ac
             JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id 
             JOIN Assessments a ON ac.assessment_id = a.assessment_id
             WHERE ac.assessment_id = ? AND tca.teacher_id = ? AND a.semester_id = ? AND tca.semester_id = ?"
        );
        $stmt->execute([$assessmentId, $teacherId, $semesterId, $semesterId]);
        
        if ($stmt->fetchColumn() == 0) {
            $_SESSION['error'] = 'You do not have permission to edit this assessment or it does not belong to the selected semester';
            $redirectUrl = 'assessments.php';
            if (!empty($_GET['semester'])) {
                $redirectUrl .= '?semester=' . (int)$_GET['semester'];
            }
            header('Location: ' . $redirectUrl);
            ob_end_clean();
            exit;
        }

        // Get assessment details with question limit fields
        $stmt = $db->prepare(
            "SELECT a.*, s.semester_name 
             FROM Assessments a
             JOIN Semesters s ON a.semester_id = s.semester_id
             WHERE a.assessment_id = ?"
        );
        $stmt->execute([$assessmentId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assessment) {
            throw new Exception('Assessment not found');
        }
        
        // Check if assessment is completed
        if ($assessment['status'] === 'completed') {
            $_SESSION['error'] = 'Completed assessments cannot be edited';
            $redirectUrl = 'assessments.php';
            if (!empty($_GET['semester'])) {
                $redirectUrl .= '?semester=' . (int)$_GET['semester'];
            }
            header('Location: ' . $redirectUrl);
            ob_end_clean();
            exit;
        }
        
        // Get subjects associated with this assessment
        $stmt = $db->prepare(
            "SELECT DISTINCT ac.subject_id
             FROM AssessmentClasses ac
             WHERE ac.assessment_id = ?"
        );
        $stmt->execute([$assessmentId]);
        $assessmentSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get current class-subject assignments for this assessment
        $stmt = $db->prepare(
            "SELECT ac.class_id, ac.subject_id 
             FROM AssessmentClasses ac
             WHERE ac.assessment_id = ?"
        );
        $stmt->execute([$assessmentId]);
        $currentAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($currentAssignments as $assignment) {
            $selectedClassSubjects[] = $assignment['class_id'] . ',' . $assignment['subject_id'];
        }

        // Handle form submission for assessment edit
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
            try {
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    throw new Exception('Invalid request');
                }

                // Begin transaction
                $db->beginTransaction();
                
                // Get semester for the selected date
                $stmt = $db->prepare(
                    "SELECT semester_id FROM Semesters 
                     WHERE start_date <= ? AND end_date >= ?
                     ORDER BY start_date DESC LIMIT 1"
                );
                $stmt->execute([$_POST['date'], $_POST['date']]);
                $semester = $stmt->fetch();
                
                if (!$semester) {
                    throw new Exception('Selected date is not within any active semester');
                }
                
                // Check if class_subject selections were made
                if (empty($_POST['class_subjects']) || !is_array($_POST['class_subjects'])) {
                    throw new Exception('You must select at least one class and subject');
                }
                
                // Validate class permissions for current semester
                foreach ($_POST['class_subjects'] as $classSubject) {
                    list($classId, $subjectId) = explode(',', sanitizeInput($classSubject));
                    
                    // Verify teacher assignment for current semester
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM TeacherClassAssignments 
                         WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND semester_id = ?"
                    );
                    $stmt->execute([$teacherId, $classId, $subjectId, $semesterId]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        throw new Exception('Not authorized for one or more selected class/subject combinations in the current semester');
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

                // Update assessment with question limit fields              
                $stmt = $db->prepare(
                    "UPDATE Assessments SET
                        semester_id = ?,
                        title = ?,
                        description = ?,
                        date = ?,
                        start_time = ?,
                        end_time = ?,
                        duration = ?,
                        allow_late_submission = ?,
                        late_submission_days = ?,
                        shuffle_questions = ?,
                        shuffle_options = ?,
                        use_question_limit = ?,
                        questions_to_answer = ?
                    WHERE assessment_id = ? AND (status != 'completed' OR reset_edit_mode = 1)"
                );

                $result = $stmt->execute([
                    $semester['semester_id'],
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
                    $questionsToAnswer,
                    $assessmentId
                ]);

                // First check if query executed successfully
                if (!$result) {
                    throw new Exception('Database error occurred while updating the assessment.');
                }

                // Check if the assessment is editable, regardless of rowCount
                $checkStmt = $db->prepare(
                    "SELECT assessment_id, status, reset_edit_mode 
                    FROM Assessments 
                    WHERE assessment_id = ?"
                );
                $checkStmt->execute([$assessmentId]);
                $assessmentInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$assessmentInfo) {
                    throw new Exception('Assessment not found.');
                } else if ($assessmentInfo['status'] === 'completed' && !$assessmentInfo['reset_edit_mode']) {
                    throw new Exception('This assessment has been completed and edit mode is not enabled. Please contact an administrator.');
                }
                
                // Variable to track if any changes were made
                $changesDetected = false;
                
                // Check if class selections have changed
                $currentClassesStmt = $db->prepare("SELECT class_id, subject_id FROM AssessmentClasses WHERE assessment_id = ?");
                $currentClassesStmt->execute([$assessmentId]);
                $currentClasses = $currentClassesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Convert current classes to comparable format
                $currentClassesSet = [];
                foreach ($currentClasses as $class) {
                    $currentClassesSet[] = $class['class_id'] . ',' . $class['subject_id'];
                }
                
                // Convert submitted classes to comparable format
                $submittedClassesSet = [];
                foreach ($_POST['class_subjects'] as $classSubject) {
                    $submittedClassesSet[] = sanitizeInput($classSubject);
                }
                
                // Sort both arrays to ensure consistent comparison
                sort($currentClassesSet);
                sort($submittedClassesSet);
                
                // Compare the two sets
                if ($currentClassesSet !== $submittedClassesSet) {
                    $changesDetected = true;
                } elseif ($stmt->rowCount() > 0) {
                    // Changes detected in the main assessment table
                    $changesDetected = true;
                }
                
                // Check if we're in edit mode or if no students have started
                $inEditMode = (bool)$assessmentInfo['reset_edit_mode'];
                
                // Only check for attempts if not in edit mode
                if (!$inEditMode) {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM AssessmentAttempts 
                        WHERE assessment_id = ?"
                    );
                    $stmt->execute([$assessmentId]);
                    $attemptCount = $stmt->fetchColumn();
                    
                    if ($attemptCount > 0 && $changesDetected && $currentClassesSet !== $submittedClassesSet) {
                        throw new Exception('Cannot modify class assignments as students have already started this assessment. Please use the admin reset function to enable edit mode if needed.');
                    }
                }
                
                // Update class-subject assignments
                // First, delete all current assignments
                $stmt = $db->prepare("DELETE FROM AssessmentClasses WHERE assessment_id = ?");
                $stmt->execute([$assessmentId]);
                
                // Then, insert new assignments
                $stmtAssignClass = $db->prepare("INSERT INTO AssessmentClasses (assessment_id, class_id, subject_id) VALUES (?, ?, ?)");
                
                foreach ($_POST['class_subjects'] as $classSubject) {
                    list($classId, $subjectId) = explode(',', sanitizeInput($classSubject));
                    $stmtAssignClass->execute([$assessmentId, $classId, $subjectId]);
                }
                
                // If we get here and there are no changes detected, it means only the class assignments were changed
                // or nothing was changed at all
                if (!$changesDetected) {
                    if ($currentClassesSet !== $submittedClassesSet) {
                        $changesDetected = true;
                    }
                }
                
                // Check if any changes were made
                if (!$changesDetected) {
                    $_SESSION['success'] = 'No changes were detected. Assessment remains unchanged.';
                    $redirectUrl = 'assessments.php';
                    if (!empty($_GET['semester'])) {
                        $redirectUrl .= '?semester=' . (int)$_GET['semester'];
                    }
                    header("Location: " . $redirectUrl);
                    ob_end_clean();
                    exit;
                }
                
                // Log successful update
                logSystemActivity(
                    'Assessment Update',
                    "Successfully updated assessment ID: $assessmentId",
                    'INFO'
                );
        
                $db->commit();
                
                $_SESSION['success'] = 'Assessment updated successfully';
                
                $redirectUrl = 'assessments.php';
                if (!empty($_GET['semester'])) {
                    $redirectUrl .= '?semester=' . (int)$_GET['semester'];
                }
                header("Location: " . $redirectUrl);
                ob_end_clean();
                exit;

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                
                $error = $e->getMessage();
                logError("Assessment update error: " . $e->getMessage());
            }
        }

        // Fetch teacher's classes and subjects that match the assessment's subjects for the current semester
        // We need to ensure we only display relevant classes
        $stmt = $db->prepare(
            "SELECT DISTINCT c.class_id, c.class_name, p.program_name, s.subject_id, s.subject_name
             FROM Classes c
             JOIN Programs p ON c.program_id = p.program_id
             JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
             JOIN Subjects s ON tca.subject_id = s.subject_id
             WHERE tca.teacher_id = ? AND tca.semester_id = ?
             AND (s.subject_id IN (SELECT DISTINCT subject_id FROM AssessmentClasses WHERE assessment_id = ?) 
                 OR ? = 1)
             ORDER BY p.program_name, c.class_name, s.subject_name"
        );
        $stmt->execute([$teacherId, $semesterId, $assessmentId, empty($assessmentSubjects) ? 1 : 0]);
        $assignments = $stmt->fetchAll();

        // Organize classes by program
        foreach ($assignments as $assignment) {
            if (!isset($classByProgram[$assignment['program_name']])) {
                $classByProgram[$assignment['program_name']] = [];
            }
            $classByProgram[$assignment['program_name']][] = $assignment;
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Edit assessment page load error: " . $e->getMessage());
}

$pageTitle = isset($assessment) ? 'Edit Assessment' : 'Select Assessment to Edit';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="page-gradient">
    <div class="page-content container-fluid px-4">
        <div class="header-gradient d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><?php echo $pageTitle; ?></h1>
            <?php
            $backUrl = 'assessments.php';
            if (!empty($_GET['semester'])) {
                $backUrl .= '?semester=' . (int)$_GET['semester'];
            }
            ?>
            <a href="<?php echo $backUrl; ?>" class="btn btn-custom">
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

        <?php if (!isset($assessment) && !empty($teacherAssessments)): ?>
            <!-- Assessment Selection Screen -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Select Assessment to Edit</h5>
                </div>
                <div class="card-body">
                    <?php
                    $formAction = 'edit_assessment.php';
                    if (!empty($_GET['semester'])) {
                        $formAction .= '?semester=' . (int)$_GET['semester'];
                    }
                    ?>
                    <form method="POST" action="<?php echo $formAction; ?>">
                        <div class="mb-4">
                            <label for="selected_assessment" class="form-label fw-bold">Choose an Assessment</label>
                            <select name="selected_assessment" id="selected_assessment" class="form-select" required>
                                <option value="">-- Select Assessment --</option>
                                <?php foreach ($teacherAssessments as $assess): ?>
                                    <?php if ($assess['status'] === 'completed'): ?>
                                        <option value="<?php echo $assess['assessment_id']; ?>" disabled>
                                            <?php echo htmlspecialchars($assess['title'] . ' (' . $assess['date'] . ' - ' . $assess['semester_name'] . ')' . ' - ' . $assess['classes'] . ' [COMPLETED]'); ?>
                                        </option>
                                    <?php else: ?>
                                        <option value="<?php echo $assess['assessment_id']; ?>">
                                            <?php echo htmlspecialchars($assess['title'] . ' (' . $assess['date'] . ' - ' . $assess['semester_name'] . ')' . ' - ' . $assess['classes']); ?>
                                            <?php if (!empty($assess['reset_edit_mode'])): ?> [EDIT MODE]<?php endif; ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Completed assessments cannot be edited and are shown as disabled.
                                Assessments with [EDIT MODE] have been specially enabled for editing despite having active attempts.
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-custom">
                                <i class="fas fa-edit me-2"></i>Edit Selected Assessment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif (!isset($assessment) && empty($teacherAssessments)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                You don't have any assessments available to edit. Please create a new assessment first.
            </div>
            <div class="text-center mt-4">
                <?php
                $createUrl = 'create_assessment.php';
                if (!empty($_GET['semester'])) {
                    $createUrl .= '?semester=' . (int)$_GET['semester'];
                }
                ?>
                <a href="<?php echo $createUrl; ?>" class="btn btn-custom">
                    <i class="fas fa-plus me-2"></i>Create New Assessment
                </a>
            </div>
        <?php elseif ($assessment): ?>
            <!-- Assessment Edit Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Assessment Details</h5>
                    <?php if ($assessment['reset_edit_mode']): ?>
                        <div class="badge bg-warning text-dark mt-2">
                            <i class="fas fa-edit me-1"></i> Edit Mode Enabled
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php
                    $formAction = 'edit_assessment.php?id=' . $assessment['assessment_id'];
                    if (!empty($_GET['semester'])) {
                        $formAction .= '&semester=' . (int)$_GET['semester'];
                    }
                    ?>
                    <form method="POST" action="<?php echo $formAction; ?>" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Classes & Subjects</label>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                You can select multiple classes to assign this assessment to. Only classes where you teach 
                                the relevant subjects are shown.
                            </div>
                            <?php if (empty($classByProgram)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No eligible classes found. You may need to update your class assignments.
                                </div>
                            <?php else: ?>
                                <div class="class-selection-area p-3 border rounded">
                                    <?php foreach ($classByProgram as $program => $classes): ?>
                                        <h6 class="mt-2 mb-3"><?php echo htmlspecialchars($program); ?></h6>
                                        <div class="row mb-3">
                                            <?php foreach ($classes as $class): ?>
                                                <div class="col-md-6 col-lg-4 mb-2">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" 
                                                            name="class_subjects[]" 
                                                            value="<?php echo $class['class_id'] . ',' . $class['subject_id']; ?>" 
                                                            id="class_<?php echo $class['class_id'] . '_' . $class['subject_id']; ?>"
                                                            <?php echo in_array($class['class_id'] . ',' . $class['subject_id'], $selectedClassSubjects) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="class_<?php echo $class['class_id'] . '_' . $class['subject_id']; ?>">
                                                            <?php echo htmlspecialchars($class['class_name'] . ' - ' . $class['subject_name']); ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="invalid-feedback">Please select at least one class and subject</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-4">
                                <label class="form-label fw-bold">Title</label>
                                <input type="text" name="title" class="form-control" required 
                                    value="<?php echo htmlspecialchars($assessment['title']); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($assessment['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-4">
                                <label class="form-label fw-bold">Date</label>
                                <input type="date" name="date" class="form-control" required 
                                    value="<?php echo htmlspecialchars($assessment['date']); ?>">
                            </div>

                            <div class="col-md-4 mb-4">
                                <label class="form-label fw-bold">Start Time</label>
                                <input type="time" name="start_time" class="form-control" required
                                    value="<?php echo htmlspecialchars($assessment['start_time']); ?>">
                            </div>
                            
                            <div class="col-md-4 mb-4">
                                <label class="form-label fw-bold">End Time</label>
                                <input type="time" name="end_time" class="form-control" required
                                    value="<?php echo htmlspecialchars($assessment['end_time']); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold">Duration (minutes)</label>
                                <input type="number" name="duration" class="form-control" min="1"
                                    value="<?php echo htmlspecialchars($assessment['duration'] ?? ''); ?>">
                                <div class="form-text">Leave empty for no time limit</div>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <div class="form-check mt-4">
                                    <input type="checkbox" name="allow_late_submission" class="form-check-input" id="allowLate"
                                        <?php echo $assessment['allow_late_submission'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="allowLate">Allow Late Submissions</label>
                                </div>
                                
                                <div class="mt-3" id="lateSubmissionDays" style="display: <?php echo $assessment['allow_late_submission'] ? 'block' : 'none'; ?>;">
                                    <label class="form-label fw-bold">Late Submission Period (days)</label>
                                    <input type="number" name="late_submission_days" class="form-control" min="1" max="30" 
                                           value="<?php echo htmlspecialchars($assessment['late_submission_days'] ?? 7); ?>">
                                    <div class="form-text">Number of days after assessment date to allow late submissions</div>
                                </div>
                                
                                <div class="form-check mt-3">
                                    <input type="checkbox" name="shuffle_questions" class="form-check-input" id="shuffleQuestions"
                                        <?php echo $assessment['shuffle_questions'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="shuffleQuestions">
                                        <i class="fas fa-random me-1"></i> Randomize Question Order
                                    </label>
                                    <div class="form-text">Each student will see questions in a different random order</div>
                                </div>
                                
                                <div class="form-check mt-3">
                                    <input type="checkbox" name="shuffle_options" class="form-check-input" id="shuffleOptions"
                                        <?php echo $assessment['shuffle_options'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="shuffleOptions">
                                        <i class="fas fa-random me-1"></i> Randomize MCQ Options
                                    </label>
                                    <div class="form-text">Answer choices for multiple choice questions will be displayed in random order</div>
                                </div>

                                <div class="form-check mt-3">
                                    <input type="checkbox" name="use_question_limit" class="form-check-input" id="useQuestionLimit"
                                        <?php echo $assessment['use_question_limit'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="useQuestionLimit">
                                        <i class="fas fa-layer-group me-1"></i> Use Question Pool with Limit
                                    </label>
                                    <div class="form-text">Students answer a subset of total questions</div>
                                </div>

                                <div id="questionLimitSettings" class="mt-3 p-3 bg-light rounded" 
                                     style="display: <?php echo $assessment['use_question_limit'] ? 'block' : 'none'; ?>;">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Questions to Answer</label>
                                        <input type="number" name="questions_to_answer" class="form-control" min="1"
                                               value="<?php echo htmlspecialchars($assessment['questions_to_answer'] ?? ''); ?>">
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Students will answer this many questions randomly selected from the total pool
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-3">
                            <?php
                            $cancelUrl = 'assessments.php';
                            if (!empty($_GET['semester'])) {
                                $cancelUrl .= '?semester=' . (int)$_GET['semester'];
                            }
                            ?>
                            <a href="<?php echo $cancelUrl; ?>" class="btn delete-btn">Cancel</a>
                            <button type="submit" class="btn btn-custom">Update Assessment</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($selectedClassSubjects) && !$assessment['reset_edit_mode']): ?>
                <!-- Check for any existing attempts -->
                <?php
                    $hasAttempts = false;
                    $db = DatabaseConfig::getInstance()->getConnection();
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM AssessmentAttempts 
                         WHERE assessment_id = ?"
                    );
                    $stmt->execute([$assessment['assessment_id']]);
                    $hasAttempts = ($stmt->fetchColumn() > 0);
                ?>
                
                <?php if ($hasAttempts): ?>
                    <div class="alert alert-warning mt-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Some students have already started this assessment. 
                        Class-subject assignments cannot be modified, but you can update other details.
                        <br>
                        If you need to make significant changes to this assessment, please contact an administrator to enable Edit Mode.
                    </div>
                <?php endif; ?>
            <?php elseif ($assessment['reset_edit_mode']): ?>
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Edit Mode:</strong> This assessment can be fully edited even though students have attempts.
                    <br>
                    Any changes you make will be applied to the assessment structure while preserving student attempts.
                    <br>
                    Edit mode has been enabled by an administrator through the reset process.
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
    max-height: 300px;
    overflow-y: auto;
    background-color: #f9f9f9;
}

#questionLimitSettings {
    border: 2px dashed #ffd700;
    background: rgba(255, 215, 0, 0.05);
}

.semester-selector {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border-left: 4px solid #ffd700;
}

.semester-selector .form-select {
    border: 1px solid rgba(255, 215, 0, 0.3);
    transition: all 0.3s ease;
}

.semester-selector .form-select:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
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
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
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
    if (useQuestionLimit && questionLimitSettings) {
        useQuestionLimit.addEventListener('change', function() {
            questionLimitSettings.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Form validation for assessment edit
    const editForm = document.querySelector('form.needs-validation');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!this.checkValidity() || !validateDuration() || !validateClassSelection() || !validateQuestionLimit()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    }

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
        const useLimit = document.getElementById('useQuestionLimit');
        const questionsToAnswer = document.querySelector('input[name="questions_to_answer"]');
        
        if (useLimit && questionsToAnswer && useLimit.checked && (!questionsToAnswer.value || questionsToAnswer.value < 1)) {
            alert('Please specify how many questions students should answer');
            return false;
        }
        return true;
    }

    // Date validation - allow current or future dates for edits
    const dateInput = document.querySelector('input[name="date"]');
    if (dateInput) {
        const originalDate = dateInput.value; // Store original date

        dateInput.addEventListener('change', function() {
            const selectedDate = new Date(this.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            // Allow editing to keep the current date even if it's in the past
            if (this.value === originalDate) {
                this.setCustomValidity('');
            } else if (selectedDate < today) {
                this.setCustomValidity('Please select today or a future date');
            } else {
                this.setCustomValidity('');
            }
        });
    }

    function validateDuration() {
        const startTime = document.querySelector('input[name="start_time"]');
        const endTime = document.querySelector('input[name="end_time"]');
        const duration = document.querySelector('input[name="duration"]');
        
        if (!startTime || !endTime || !duration || !duration.value) return true;
        
        if (startTime.value && endTime.value && duration.value) {
            const start = new Date(`2000-01-01T${startTime.value}`);
            const end = new Date(`2000-01-01T${endTime.value}`);
            
            // Handle cases where end time is on the next day
            let maxDuration = (end - start) / 60000; // Convert to minutes
            if (maxDuration < 0) {
                maxDuration += 24 * 60; // Add 24 hours in minutes
            }

            if (parseInt(duration.value) > maxDuration) {
                alert(`Duration cannot exceed ${Math.floor(maxDuration)} minutes based on the selected start and end times.`);
                return false;
            }
        }
        return true;
    }

    // Add event listeners to update max duration
    const startTimeInput = document.querySelector('input[name="start_time"]');
    const endTimeInput = document.querySelector('input[name="end_time"]');
    
    if (startTimeInput && endTimeInput) {
        startTimeInput.addEventListener('change', updateMaxDuration);
        endTimeInput.addEventListener('change', updateMaxDuration);
    }

    function updateMaxDuration() {
        const startTime = document.querySelector('input[name="start_time"]').value;
        const endTime = document.querySelector('input[name="end_time"]').value;
        const durationInput = document.querySelector('input[name="duration"]');

        if (startTime && endTime && durationInput) {
            const start = new Date(`2000-01-01T${startTime}`);
            const end = new Date(`2000-01-01T${endTime}`);
            
            // Handle cases where end time is on the next day
            let maxDuration = (end - start) / 60000; // Convert to minutes
            if (maxDuration < 0) {
                maxDuration += 24 * 60; // Add 24 hours in minutes
            }

            durationInput.max = Math.floor(maxDuration);
            durationInput.setAttribute('max', Math.floor(maxDuration));
        }
    }

    // Enhance assessment selection dropdown with search capability
    const selectElement = document.getElementById('selected_assessment');
    if (selectElement && typeof $ !== 'undefined' && $.fn.select2) {
        $(selectElement).select2({
            placeholder: 'Search and select assessment...',
            allowClear: true,
            width: '100%'
        });
    }

    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);
        }
    });
});
</script>

<?php 
function calculateTimeDifferenceInMinutes($startTime, $endTime) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    
    // Handle cases where end time is on the next day
    $interval = $start->diff($end);
    $minutes = ($interval->h * 60) + $interval->i;
    
    if ($start > $end) {
        $minutes = (24 * 60) - $minutes;
    }
    
    return $minutes;
}

ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php'; 
?>