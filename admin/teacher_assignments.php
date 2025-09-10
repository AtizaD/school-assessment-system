<?php
// admin/teacher_assignments.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$error = '';
$success = '';

// Get success message from session if redirected
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Get error message from session if redirected
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        switch ($_POST['action']) {
            case 'assign_teacher':
                $teacherId = sanitizeInput($_POST['teacher_id']);
                $classId = sanitizeInput($_POST['class_id']);
                $subjectId = sanitizeInput($_POST['subject_id']);
                $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

                try {
                    // Use default semester_id = 1 since column is NOT NULL but we don't use semesters
                    $defaultSemesterId = 1;
                    $stmt = $db->prepare(
                        "INSERT INTO TeacherClassAssignments 
                         (teacher_id, class_id, subject_id, semester_id, is_primary_instructor) 
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    if ($stmt->execute([$teacherId, $classId, $subjectId, $defaultSemesterId, $isPrimary])) {
                        // Get teacher and class info for logging
                        $stmt = $db->prepare(
                            "SELECT t.first_name, t.last_name, c.class_name, s.subject_name
                             FROM Teachers t 
                             JOIN Classes c ON c.class_id = ?
                             JOIN Subjects s ON s.subject_id = ?
                             WHERE t.teacher_id = ?"
                        );
                        $stmt->execute([$classId, $subjectId, $teacherId]);
                        $info = $stmt->fetch();
                        
                        logSystemActivity(
                            'Teacher Assignment',
                            "Teacher {$info['first_name']} {$info['last_name']} assigned to {$info['class_name']} for {$info['subject_name']}",
                            'INFO'
                        );
                        
                        $_SESSION['success'] = 'Teacher assigned successfully';
                        header("Location: teacher_assignments.php");
                        exit;
                    } else {
                        throw new Exception('Failed to assign teacher');
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to assign teacher. Assignment may already exist.';
                    logError("Teacher assignment failed: " . $e->getMessage());
                }
                break;

            case 'update_assignment':
                $assignmentId = sanitizeInput($_POST['assignment_id']);
                $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

                try {
                    $stmt = $db->prepare(
                        "UPDATE TeacherClassAssignments 
                         SET is_primary_instructor = ? 
                         WHERE assignment_id = ?"
                    );
                    if ($stmt->execute([$isPrimary, $assignmentId])) {
                        logSystemActivity(
                            'Teacher Assignment',
                            "Assignment ID $assignmentId updated: Primary status set to " . ($isPrimary ? 'Yes' : 'No'),
                            'INFO'
                        );
                        
                        $_SESSION['success'] = 'Assignment updated successfully';
                        header("Location: teacher_assignments.php");
                        exit;
                    } else {
                        throw new Exception('Failed to update assignment');
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Assignment update failed: " . $e->getMessage());
                }
                break;

            case 'remove_assignment':
                $assignmentId = sanitizeInput($_POST['assignment_id']);
                try {
                    // Get assignment details for logging before deletion
                    $stmt = $db->prepare(
                        "SELECT tca.teacher_id, tca.class_id, tca.subject_id, 
                                t.first_name, t.last_name, c.class_name, s.subject_name
                         FROM TeacherClassAssignments tca
                         JOIN Teachers t ON tca.teacher_id = t.teacher_id
                         JOIN Classes c ON tca.class_id = c.class_id
                         JOIN Subjects s ON tca.subject_id = s.subject_id
                         WHERE tca.assignment_id = ?"
                    );
                    $stmt->execute([$assignmentId]);
                    $assignmentInfo = $stmt->fetch();

                    if (!$assignmentInfo) {
                        throw new Exception('Assignment not found');
                    }

                    // Delete the assignment
                    $stmt = $db->prepare("DELETE FROM TeacherClassAssignments WHERE assignment_id = ?");
                    if ($stmt->execute([$assignmentId])) {
                        logSystemActivity(
                            'Teacher Assignment',
                            "Removed teacher {$assignmentInfo['first_name']} {$assignmentInfo['last_name']} from {$assignmentInfo['class_name']} for {$assignmentInfo['subject_name']}",
                            'INFO'
                        );
                        
                        $_SESSION['success'] = 'Assignment removed successfully';
                        header("Location: teacher_assignments.php");
                        exit;
                    } else {
                        throw new Exception('Failed to remove assignment');
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Assignment removal failed: " . $e->getMessage());
                }
                break;
                
            case 'bulk_assign':
                $teacherId = sanitizeInput($_POST['teacher_id']);
                $subjectId = sanitizeInput($_POST['subject_id']);
                $classIds = $_POST['class_ids'] ?? [];
                $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
                
                if (empty($classIds)) {
                    $error = 'Please select at least one class';
                    break;
                }
                
                try {
                    $db->beginTransaction();
                    $successCount = 0;
                    $errorCount = 0;
                    
                    // Get teacher info for logging
                    $stmt = $db->prepare("SELECT first_name, last_name FROM Teachers WHERE teacher_id = ?");
                    $stmt->execute([$teacherId]);
                    $teacherInfo = $stmt->fetch();
                    
                    // Get subject info for logging
                    $stmt = $db->prepare("SELECT subject_name FROM Subjects WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);
                    $subjectInfo = $stmt->fetch();
                    
                    // Use default semester_id = 1 since column is NOT NULL but we don't use semesters
                    $defaultSemesterId = 1;
                    $insertStmt = $db->prepare(
                        "INSERT INTO TeacherClassAssignments 
                         (teacher_id, class_id, subject_id, semester_id, is_primary_instructor)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    
                    foreach ($classIds as $classId) {
                        try {
                            if ($insertStmt->execute([$teacherId, $classId, $subjectId, $defaultSemesterId, $isPrimary])) {
                                $successCount++;
                            } else {
                                $errorCount++;
                            }
                        } catch (PDOException $e) {
                            // Duplicates will throw an exception, but we continue with other classes
                            $errorCount++;
                            continue;
                        }
                    }
                    
                    $db->commit();
                    
                    if ($successCount > 0) {
                        logSystemActivity(
                            'Teacher Assignment',
                            "Bulk assigned teacher {$teacherInfo['first_name']} {$teacherInfo['last_name']} to $successCount classes for {$subjectInfo['subject_name']}",
                            'INFO'
                        );
                        
                        $_SESSION['success'] = "Successfully assigned teacher to $successCount classes" . 
                            ($errorCount > 0 ? " ($errorCount failed - may already exist)" : "");
                    } else {
                        $_SESSION['error'] = "Failed to assign teacher to any classes. Assignments may already exist.";
                    }
                    
                    header("Location: teacher_assignments.php");
                    exit;
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = $e->getMessage();
                    logError("Bulk teacher assignment failed: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get database connection
$db = DatabaseConfig::getInstance()->getConnection();

// Get all teachers
$stmt = $db->query(
    "SELECT teacher_id, first_name, last_name, email
     FROM Teachers
     ORDER BY last_name, first_name"
);
$teachers = $stmt->fetchAll();

// Get all subjects
$stmt = $db->query(
    "SELECT subject_id, subject_name
     FROM Subjects
     ORDER BY subject_name"
);
$subjects = $stmt->fetchAll();

// Get all classes
$stmt = $db->query(
    "SELECT c.class_id, c.class_name, c.level, p.program_name
     FROM Classes c
     JOIN Programs p ON c.program_id = p.program_id
     ORDER BY p.program_name, c.level, c.class_name"
);
$classes = $stmt->fetchAll();

// Group classes by program and level for dropdown
$classGroups = [];
foreach ($classes as $class) {
    $programName = $class['program_name'];
    $level = $class['level'];
    
    if (!isset($classGroups[$programName])) {
        $classGroups[$programName] = [];
    }
    
    if (!isset($classGroups[$programName][$level])) {
        $classGroups[$programName][$level] = [];
    }
    
    $classGroups[$programName][$level][] = [
        'class_id' => $class['class_id'],
        'class_name' => $class['class_name']
    ];
}


// Get filter parameters
$filterTeacherId = isset($_GET['teacher_id']) ? filter_var($_GET['teacher_id'], FILTER_VALIDATE_INT) : null;
$filterSubjectId = isset($_GET['subject_id']) ? filter_var($_GET['subject_id'], FILTER_VALIDATE_INT) : null;
$filterProgramId = isset($_GET['program_id']) ? filter_var($_GET['program_id'], FILTER_VALIDATE_INT) : null;
$filterLevel = isset($_GET['level']) ? sanitizeInput($_GET['level']) : null;

// Build query with filters
$query = "
    SELECT tca.assignment_id, tca.is_primary_instructor,
           t.teacher_id, t.first_name, t.last_name, t.email,
           c.class_id, c.class_name, c.level,
           p.program_id, p.program_name,
           s.subject_id, s.subject_name
    FROM TeacherClassAssignments tca
    JOIN Teachers t ON tca.teacher_id = t.teacher_id
    JOIN Classes c ON tca.class_id = c.class_id
    JOIN Programs p ON c.program_id = p.program_id
    JOIN Subjects s ON tca.subject_id = s.subject_id
    JOIN Semesters sem ON tca.semester_id = sem.semester_id
    WHERE 1=1";

$params = [];

if ($filterTeacherId) {
    $query .= " AND t.teacher_id = ?";
    $params[] = $filterTeacherId;
}

if ($filterSubjectId) {
    $query .= " AND s.subject_id = ?";
    $params[] = $filterSubjectId;
}

if ($filterProgramId) {
    $query .= " AND p.program_id = ?";
    $params[] = $filterProgramId;
}

if ($filterLevel) {
    $query .= " AND c.level = ?";
    $params[] = $filterLevel;
}

$query .= " ORDER BY t.last_name, t.first_name, p.program_name, c.level, c.class_name, s.subject_name";

// Execute query with parameters
$stmt = $db->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Get all programs for filter
$stmt = $db->query("SELECT program_id, program_name FROM Programs ORDER BY program_name");
$programs = $stmt->fetchAll();

// Get all levels for filter
$stmt = $db->query("SELECT DISTINCT level FROM Classes ORDER BY level");
$levels = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Group assignments by teacher for display
$assignmentsByTeacher = [];
foreach ($assignments as $assignment) {
    $teacherId = $assignment['teacher_id'];
    $teacherName = $assignment['first_name'] . ' ' . $assignment['last_name'];
    
    if (!isset($assignmentsByTeacher[$teacherId])) {
        $assignmentsByTeacher[$teacherId] = [
            'teacher_id' => $teacherId,
            'teacher_name' => $teacherName,
            'teacher_email' => $assignment['email'],
            'assignments' => []
        ];
    }
    
    $assignmentsByTeacher[$teacherId]['assignments'][] = $assignment;
}

$pageTitle = 'Teacher Assignments';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    /* Enhanced UI Styles */
    .card {
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    
    .card-header {
        background: linear-gradient(90deg, #000000, #ffd700);
        color: white;
        border-bottom: none;
        padding: 1rem 1.25rem;
    }
    
    .teacher-card {
        transition: all 0.3s ease;
        margin-bottom: 1.5rem;
    }
    
    .teacher-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .teacher-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
    }
    
    .teacher-info {
        display: flex;
        align-items: center;
    }
    
    .teacher-avatar {
        background: linear-gradient(135deg, #000000, #ffd700);
        color: white;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        font-weight: 600;
        margin-right: 1rem;
    }
    
    .teacher-name {
        font-size: 1.1rem;
        font-weight: 600;
    }
    
    .teacher-email {
        font-size: 0.9rem;
        color: #6c757d;
    }
    
    .assignment-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    .assignment-table td, .assignment-table th {
        padding: 0.75rem 1rem;
        vertical-align: middle;
    }
    
    .primary-badge {
        background-color: #ffd700;
        color: #000;
        font-weight: 500;
        padding: 0.3rem 0.6rem;
        border-radius: 20px;
        font-size: 0.8rem;
    }
    
    .btn-primary, .btn-success {
        background: linear-gradient(45deg, #000000, #ffd700);
        border: none;
    }
    
    .btn-primary:hover, .btn-success:hover {
        background: linear-gradient(45deg, #ffd700, #000000);
    }
    
    .btn-outline-primary {
        color: #000;
        border-color: #ffd700;
    }
    
    .btn-outline-primary:hover {
        background-color: #ffd700;
        color: #000;
        border-color: #ffd700;
    }
    
    .filter-card {
        margin-bottom: 1.5rem;
    }
    
    .filter-form {
        padding: 1rem;
    }
    
    .filter-form label {
        font-weight: 500;
        margin-bottom: 0.25rem;
    }
    
    .filter-buttons {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
    }
    
    .actions-column {
        width: 120px;
    }
    
    .toggle-btn {
        cursor: pointer;
        color: #ffd700;
        background: none;
        border: none;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    
    .toggle-btn:hover {
        background-color: rgba(255, 215, 0, 0.1);
        color: #000;
    }
    
    .nav-pills .nav-link.active {
        background-color: #ffd700;
        color: #000;
    }
    
    .nav-pills .nav-link {
        color: #666;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        background-color: #f8f9fa;
        border-radius: 8px;
    }
    
    .empty-state-icon {
        font-size: 3rem;
        color: #ddd;
        margin-bottom: 1rem;
    }
    
    /* Modal styling */
    .modal-header {
        background: linear-gradient(90deg, #000000, #ffd700);
        color: white;
        border-bottom: none;
    }
    
    .modal-content {
        border: none;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .class-select-container {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 1rem;
    }
    
    .class-group-header {
        font-weight: 600;
        margin-top: 1rem;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid #eee;
        padding-bottom: 0.25rem;
    }
    
    .class-group-header:first-child {
        margin-top: 0;
    }
    
    .level-group {
        margin-left: 1rem;
        margin-bottom: 1rem;
    }
    
    .level-label {
        font-weight: 500;
        margin-bottom: 0.5rem;
    }
    
    .class-checkbox-group {
        margin-left: 1.5rem;
    }
    
    .class-checkbox {
        margin-bottom: 0.25rem;
    }
    
    .class-checkbox input:disabled + label {
        opacity: 0.8;
        background-color: rgba(255, 215, 0, 0.1);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
    }
    
    .class-checkbox .text-muted {
        font-weight: 500;
    }
    
    .already-assigned-note {
        font-size: 0.75rem;
        font-style: italic;
        margin-left: 1.5rem;
        margin-top: -0.25rem;
    }
    
    /* Statistics cards */
    .stat-card {
        background: linear-gradient(135deg, #f8f9fa, #ffffff);
        border-radius: 8px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
    }
    
    .stat-icon {
        background: linear-gradient(45deg, #000000, #ffd700);
        color: white;
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #444;
        line-height: 1;
    }
    
    .stat-label {
        color: #888;
        font-size: 0.9rem;
        margin-top: 0.5rem;
    }
    
    .search-box {
        position: relative;
        max-width: 300px;
    }
    
    .search-box input {
        padding-left: 2.5rem;
        border-radius: 20px;
        border: 1px solid #ddd;
    }
    
    .search-box input:focus {
        border-color: #ffd700;
        box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
    }
    
    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #aaa;
    }
</style>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 text-dark mb-1">Teacher Assignments</h1>
            <p class="text-muted mb-0">Manage teacher assignments across classes and subjects</p>
        </div>
        <div>
            <a href="classes.php" class="btn btn-outline-primary me-2">
                <i class="fas fa-chalkboard me-2"></i>Manage Classes
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                <i class="fas fa-users-cog me-2"></i>Bulk Assign
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignTeacherModal">
                <i class="fas fa-user-plus me-2"></i>Assign Teacher
            </button>
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

    <!-- Dashboard Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-value"><?php echo count($teachers); ?></div>
                <div class="stat-label">Total Teachers</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="stat-value"><?php echo count($classes); ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value"><?php echo count($subjects); ?></div>
                <div class="stat-label">Total Subjects</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo count($assignments); ?></div>
                <div class="stat-label">Active Assignments</div>
            </div>
        </div>
    </div>

    <!-- Filter Panel -->
    <div class="card filter-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Assignments</h5>
        </div>
        <div class="card-body">
            <form action="teacher_assignments.php" method="GET" class="filter-form">
                <div class="row">
                    <div class="col-md-6 col-lg-3 mb-3">
                        <label for="teacher_id" class="form-label">Teacher</label>
                        <select id="teacher_id" name="teacher_id" class="form-select">
                            <option value="">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['teacher_id']; ?>" <?php echo $filterTeacherId == $teacher['teacher_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3 mb-3">
                        <label for="subject_id" class="form-label">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>" <?php echo $filterSubjectId == $subject['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-2 mb-3">
                        <label for="program_id" class="form-label">Program</label>
                        <select id="program_id" name="program_id" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['program_id']; ?>" <?php echo $filterProgramId == $program['program_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-2 mb-3">
                        <label for="level" class="form-label">Level</label>
                        <select id="level" name="level" class="form-select">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo htmlspecialchars($level); ?>" <?php echo $filterLevel == $level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-buttons mt-2">
                    <a href="teacher_assignments.php" class="btn btn-outline-secondary">
                        <i class="fas fa-undo me-1"></i>Reset
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Teacher Assignments Display -->
    <?php if (empty($assignments)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <h4>No Teacher Assignments Found</h4>
            <p class="text-muted">No assignments match your current filter criteria</p>
            <?php if ($filterTeacherId || $filterSubjectId || $filterProgramId || $filterLevel): ?>
                <a href="teacher_assignments.php" class="btn btn-primary mt-3">
                    <i class="fas fa-undo me-2"></i>Clear Filters
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#assignTeacherModal">
                    <i class="fas fa-user-plus me-2"></i>Assign First Teacher
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Grouping by teacher view -->
        <?php foreach ($assignmentsByTeacher as $teacherData): ?>
            <div class="card teacher-card">
                <div class="teacher-header">
                    <div class="teacher-info">
                        <div class="teacher-avatar">
                            <?php 
                            $initials = substr($teacherData['teacher_name'], 0, 1);
                            echo htmlspecialchars($initials); 
                            ?>
                        </div>
                        <div>
                            <div class="teacher-name"><?php echo htmlspecialchars($teacherData['teacher_name']); ?></div>
                            <div class="teacher-email"><?php echo htmlspecialchars($teacherData['teacher_email']); ?></div>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-secondary"><?php echo count($teacherData['assignments']); ?> Assignments</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover assignment-table mb-0">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Level</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Primary Instructor</th>
                                <th class="actions-column">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teacherData['assignments'] as $assignment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assignment['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['level']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                    <td>
                                        <?php if ($assignment['is_primary_instructor']): ?>
                                            <span class="primary-badge">
                                                <i class="fas fa-check-circle me-1"></i>Primary
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                <i class="fas fa-user-friends me-1"></i>Secondary
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="updateAssignment(<?php echo $assignment['assignment_id']; ?>, <?php echo $assignment['is_primary_instructor'] ? 'true' : 'false'; ?>,
                                                    '<?php echo htmlspecialchars(addslashes($assignment['first_name'] . ' ' . $assignment['last_name'])); ?>',
                                                    '<?php echo htmlspecialchars(addslashes($assignment['class_name'])); ?>',
                                                    '<?php echo htmlspecialchars(addslashes($assignment['subject_name'])); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="removeAssignment(<?php echo $assignment['assignment_id']; ?>,
                                                    '<?php echo htmlspecialchars(addslashes($assignment['first_name'] . ' ' . $assignment['last_name'])); ?>',
                                                    '<?php echo htmlspecialchars(addslashes($assignment['class_name'])); ?>',
                                                    '<?php echo htmlspecialchars(addslashes($assignment['subject_name'])); ?>')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<!-- Assign Teacher Modal -->
<div class="modal fade" id="assignTeacherModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teacher_assignments.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="assign_teacher">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign Teacher to Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="teacher_id_assign" class="form-label">Teacher</label>
                        <select id="teacher_id_assign" name="teacher_id" class="form-select" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['teacher_id']; ?>">
                                    <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="class_id" class="form-label">Class</label>
                        <select id="class_id" name="class_id" class="form-select" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classGroups as $programName => $levels): ?>
                                <optgroup label="<?php echo htmlspecialchars($programName); ?>">
                                    <?php foreach ($levels as $level => $levelClasses): ?>
                                        <?php foreach ($levelClasses as $class): ?>
                                            <option value="<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($level . ' - ' . $class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subject_id_assign" class="form-label">Subject</label>
                        <select id="subject_id_assign" name="subject_id" class="form-select" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary" checked>
                        <label class="form-check-label" for="is_primary">
                            Primary Instructor
                        </label>
                        <div class="form-text">
                            Primary instructors are the main teachers for the class-subject combination
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check me-1"></i>Assign Teacher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div class="modal fade" id="bulkAssignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="teacher_assignments.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="bulk_assign">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-users-cog me-2"></i>Bulk Assign Teacher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        This will assign the selected teacher to multiple classes for a specific subject.
                        <br><small class="mt-1 d-block">
                            <i class="fas fa-star text-warning me-1"></i> = Primary Instructor |
                            <i class="fas fa-user-check text-success me-1"></i> = Already Assigned
                        </small>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="teacher_id_bulk" class="form-label">Select Teacher</label>
                            <select id="teacher_id_bulk" name="teacher_id" class="form-select" required>
                                <option value="">-- Select Teacher --</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['teacher_id']; ?>">
                                        <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="subject_id_bulk" class="form-label">Select Subject</label>
                            <select id="subject_id_bulk" name="subject_id" class="form-select" required>
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_primary_bulk" name="is_primary" checked>
                        <label class="form-check-label" for="is_primary_bulk">
                            Primary Instructor
                        </label>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Classes</label>
                        <div class="alert alert-warning d-none" id="noClassesAlert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please select a subject first to see available classes.
                        </div>
                        <div class="class-select-container" id="classSelectContainer">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <p>Select a subject to view available classes</p>
                            </div>
                        </div>
                        <div class="text-muted mt-2">
                            <small><i class="fas fa-info-circle me-1"></i>Only classes linked to the selected subject will be shown</small>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="bulkAssignBtn">
                        <i class="fas fa-check me-1"></i>Assign to Selected Classes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Assignment Modal -->
<div class="modal fade" id="updateAssignmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teacher_assignments.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update_assignment">
                <input type="hidden" name="assignment_id" id="updateAssignmentId">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Update Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Update assignment details for <strong id="updateTeacherName"></strong>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Class:</div>
                                <div class="col-8 fw-bold" id="updateClassName"></div>
                            </div>
                            <div class="row">
                                <div class="col-4 text-muted">Subject:</div>
                                <div class="col-8 fw-bold" id="updateSubjectName"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="updateIsPrimary" name="is_primary">
                        <label class="form-check-label" for="updateIsPrimary">
                            Primary Instructor
                        </label>
                        <div class="form-text">
                            Primary instructors are the main teachers responsible for the class-subject
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Assignment Modal -->
<div class="modal fade" id="removeAssignmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teacher_assignments.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="remove_assignment">
                <input type="hidden" name="assignment_id" id="removeAssignmentId">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-user-minus me-2"></i>Remove Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-exclamation-triangle text-danger fa-4x mb-3"></i>
                        <h4>Confirm Removal</h4>
                    </div>
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This will remove the teacher's assignment for this class and subject.
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Teacher:</div>
                                <div class="col-8 fw-bold" id="removeTeacherName"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Class:</div>
                                <div class="col-8 fw-bold" id="removeClassName"></div>
                            </div>
                            <div class="row">
                                <div class="col-4 text-muted">Subject:</div>
                                <div class="col-8 fw-bold" id="removeSubjectName"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Remove Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    const assignTeacherModal = new bootstrap.Modal(document.getElementById('assignTeacherModal'));
    const bulkAssignModal = new bootstrap.Modal(document.getElementById('bulkAssignModal'));
    const updateAssignmentModal = new bootstrap.Modal(document.getElementById('updateAssignmentModal'));
    const removeAssignmentModal = new bootstrap.Modal(document.getElementById('removeAssignmentModal'));
    
    // Reset bulk assign modal when opened
    document.getElementById('bulkAssignModal').addEventListener('show.bs.modal', function() {
        // Reset form fields
        document.getElementById('teacher_id_bulk').value = '';
        document.getElementById('subject_id_bulk').value = '';
        document.getElementById('is_primary_bulk').checked = true;
        
        // Reset class container
        classContainer.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fas fa-info-circle fa-2x mb-2"></i>
                <p>Select a subject to view available classes</p>
            </div>
        `;
        
        // Update submit button
        updateSubmitButton();
    });
    
    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Initialize select2 if available
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    }
    
    // Validate bulk assign form
    const bulkAssignForm = document.querySelector('#bulkAssignModal form');
    const bulkAssignBtn = document.getElementById('bulkAssignBtn');
    const subjectSelect = document.getElementById('subject_id_bulk');
    const classContainer = document.getElementById('classSelectContainer');
    const noClassesAlert = document.getElementById('noClassesAlert');
    
    // Function to update submit button state
    function updateSubmitButton() {
        const checked = document.querySelectorAll('input[name="class_ids[]"]:checked').length > 0;
        if (bulkAssignBtn) {
            bulkAssignBtn.disabled = !checked;
        }
    }
    
    // Function to load classes based on selected subject
    function loadClassesForSubject(subjectId) {
        if (!subjectId) {
            classContainer.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Select a subject to view available classes</p>
                </div>
            `;
            updateSubmitButton();
            return;
        }
        
        // Show loading state
        classContainer.innerHTML = `
            <div class="text-center text-muted py-4">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Loading classes...
            </div>
        `;
        
        // Get teacher ID for checking existing assignments
        const teacherId = document.getElementById('teacher_id_bulk').value;
        let url = `../api/get_classes.php?subject_id=${subjectId}`;
        
        // If teacher is selected, include teacher_id to get assignment info
        if (teacherId) {
            url += `&teacher_id=${teacherId}`;
        }
        
        // Fetch classes for the selected subject
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.classes && data.classes.length > 0) {
                    renderClassCheckboxes(data.classes);
                    noClassesAlert.classList.add('d-none');
                } else {
                    classContainer.innerHTML = `
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                            <p>No classes found for this subject</p>
                        </div>
                    `;
                }
                updateSubmitButton();
            })
            .catch(error => {
                console.error('Error loading classes:', error);
                classContainer.innerHTML = `
                    <div class="text-center text-danger py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <p>Error loading classes. Please try again.</p>
                    </div>
                `;
                updateSubmitButton();
            });
    }
    
    // Function to render class checkboxes grouped by program and level
    function renderClassCheckboxes(classes) {
        // Group classes by program and level
        const grouped = {};
        classes.forEach(cls => {
            if (!grouped[cls.program_name]) {
                grouped[cls.program_name] = {};
            }
            if (!grouped[cls.program_name][cls.level]) {
                grouped[cls.program_name][cls.level] = [];
            }
            grouped[cls.program_name][cls.level].push(cls);
        });
        
        let html = '';
        Object.keys(grouped).forEach(programName => {
            html += `<div class="class-group-header">${escapeHtml(programName)}</div>`;
            
            Object.keys(grouped[programName]).forEach(level => {
                html += `
                    <div class="level-group">
                        <div class="level-label">${escapeHtml(level)}</div>
                        <div class="class-checkbox-group">
                `;
                
                grouped[programName][level].forEach(cls => {
                    const isAssigned = cls.is_assigned == 1;
                    const isPrimary = cls.is_primary_instructor == 1;
                    
                    let statusIcon = '';
                    let labelClass = '';
                    let checkboxStatus = '';
                    
                    if (isAssigned) {
                        statusIcon = isPrimary ? 
                            '<i class="fas fa-star text-warning ms-1" title="Primary Instructor"></i>' : 
                            '<i class="fas fa-user-check text-success ms-1" title="Already Assigned"></i>';
                        labelClass = 'text-muted';
                        checkboxStatus = 'checked disabled';
                    }
                    
                    html += `
                        <div class="form-check class-checkbox">
                            <input class="form-check-input class-checkbox-input" type="checkbox" 
                                   name="class_ids[]" value="${cls.class_id}" 
                                   id="class_${cls.class_id}" ${checkboxStatus}>
                            <label class="form-check-label ${labelClass}" for="class_${cls.class_id}">
                                ${escapeHtml(cls.class_name)}${statusIcon}
                            </label>
                            ${isAssigned ? '<small class="text-muted d-block ms-4">Already assigned</small>' : ''}
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
        });
        
        classContainer.innerHTML = html;
        
        // Add event listeners to new checkboxes
        const newCheckboxes = document.querySelectorAll('.class-checkbox-input');
        newCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSubmitButton);
        });
        
        // Add select all functionality
        addSelectAllFunctionality();
    }
    
    // Function to add select all functionality
    function addSelectAllFunctionality() {
        // Add program-level select all
        const programHeaders = document.querySelectorAll('.class-group-header');
        programHeaders.forEach(header => {
            const selectAllLabel = document.createElement('label');
            selectAllLabel.className = 'float-end';
            selectAllLabel.style.cursor = 'pointer';
            selectAllLabel.innerHTML = '<input type="checkbox" class="program-select-all me-1"> Select All';
            
            header.appendChild(selectAllLabel);
            
            const selectAllCheckbox = selectAllLabel.querySelector('input');
            
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                let currentElement = header.nextElementSibling;
                
                while (currentElement && currentElement.classList.contains('level-group')) {
                    const checkboxes = currentElement.querySelectorAll('.class-checkbox-input');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                    currentElement = currentElement.nextElementSibling;
                }
                
                updateSubmitButton();
            });
        });
        
        // Add level-level select all
        const levelLabels = document.querySelectorAll('.level-label');
        levelLabels.forEach(label => {
            const selectAllLabel = document.createElement('label');
            selectAllLabel.className = 'float-end';
            selectAllLabel.style.cursor = 'pointer';
            selectAllLabel.innerHTML = '<input type="checkbox" class="level-select-all me-1"> Select All';
            
            label.appendChild(selectAllLabel);
            
            const selectAllCheckbox = selectAllLabel.querySelector('input');
            const checkboxGroup = label.nextElementSibling;
            
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                const checkboxes = checkboxGroup.querySelectorAll('.class-checkbox-input');
                
                checkboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
                
                updateSubmitButton();
            });
        });
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Subject selection change handler
    if (subjectSelect) {
        subjectSelect.addEventListener('change', function() {
            loadClassesForSubject(this.value);
        });
    }
    
    // Teacher selection change handler - reload classes if subject is already selected
    const teacherSelect = document.getElementById('teacher_id_bulk');
    if (teacherSelect) {
        teacherSelect.addEventListener('change', function() {
            const subjectId = subjectSelect.value;
            if (subjectId) {
                loadClassesForSubject(subjectId);
            }
        });
    }
    
    if (bulkAssignForm) {
        bulkAssignForm.addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="class_ids[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one class');
            }
        });
        
        // Initial state
        updateSubmitButton();
    }
    
    // Functions for handling modals
    window.updateAssignment = function(assignmentId, isPrimary, teacherName, className, subjectName) {
        document.getElementById('updateAssignmentId').value = assignmentId;
        document.getElementById('updateIsPrimary').checked = isPrimary;
        document.getElementById('updateTeacherName').textContent = teacherName;
        document.getElementById('updateClassName').textContent = className;
        document.getElementById('updateSubjectName').textContent = subjectName;
        updateAssignmentModal.show();
    };
    
    window.removeAssignment = function(assignmentId, teacherName, className, subjectName) {
        document.getElementById('removeAssignmentId').value = assignmentId;
        document.getElementById('removeTeacherName').textContent = teacherName;
        document.getElementById('removeClassName').textContent = className;
        document.getElementById('removeSubjectName').textContent = subjectName;
        removeAssignmentModal.show();
    };
    
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>