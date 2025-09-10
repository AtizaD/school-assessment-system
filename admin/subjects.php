<?php
// admin/subjects.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        switch ($_POST['action']) {
            case 'create_subject':
                $subjectName = sanitizeInput($_POST['subject_name']);
                $description = sanitizeInput($_POST['description']);

                try {
                    $stmt = $db->prepare(
                        "INSERT INTO Subjects (subject_name, description) 
                         VALUES (?, ?)"
                    );
                    if ($stmt->execute([$subjectName, $description])) {
                        logSystemActivity(
                            'Subject Management',
                            "New subject created: $subjectName",
                            'INFO'
                        );
                        $success = 'Subject created successfully';
                    }
                } catch (PDOException $e) {
                    $error = 'Subject name already exists';
                    logError("Subject creation failed: " . $e->getMessage());
                }
                break;

            case 'link_subject':
                $subjectId = sanitizeInput($_POST['subject_id']);
                $classIds = $_POST['class_ids'] ?? [];
                
                try {
                    $db->beginTransaction();
            
                    // Clear existing assignments
                    $stmt = $db->prepare("DELETE FROM ClassSubjects WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);
            
                    // Add new assignments
                    if (!empty($classIds)) {
                        $stmt = $db->prepare(
                            "INSERT INTO ClassSubjects (subject_id, class_id) 
                            VALUES (?, ?)"
                        );
                        
                        foreach ($classIds as $classId) {
                            $stmt->execute([$subjectId, $classId]);
                        }
                    }
            
                    $db->commit();
                    $success = 'Subject linked to classes successfully';
                    
                    logSystemActivity(
                        'Subject Management',
                        "Subject ID $subjectId linked to classes",
                        'INFO'
                    );
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to link subject to classes';
                    logError("Subject linking failed: " . $e->getMessage());
                }
                break;

            case 'update_subject':
                $subjectId = sanitizeInput($_POST['subject_id']);
                $subjectName = sanitizeInput($_POST['subject_name']);
                $description = sanitizeInput($_POST['description']);
                
                try {
                    $stmt = $db->prepare(
                        "UPDATE Subjects 
                         SET subject_name = ?, description = ? 
                         WHERE subject_id = ?"
                    );
                    if ($stmt->execute([$subjectName, $description, $subjectId])) {
                        logSystemActivity(
                            'Subject Management',
                            "Subject updated: $subjectName",
                            'INFO'
                        );
                        $success = 'Subject updated successfully';
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to update subject';
                    logError("Subject update failed: " . $e->getMessage());
                }
                break;

            case 'delete_subject':
                $subjectId = sanitizeInput($_POST['subject_id']);
                try {
                    $db->beginTransaction();

                    // Delete assignments first
                    $stmt = $db->prepare("DELETE FROM TeacherClassAssignments WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);

                    // Then delete from ClassSubjects
                    $stmt = $db->prepare("DELETE FROM ClassSubjects WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);

                    // Finally delete the subject
                    $stmt = $db->prepare("DELETE FROM Subjects WHERE subject_id = ?");
                    if ($stmt->execute([$subjectId])) {
                        $db->commit();
                        logSystemActivity(
                            'Subject Management',
                            "Subject deleted: ID $subjectId",
                            'INFO'
                        );
                        $success = 'Subject deleted successfully';
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                    logError("Subject deletion failed: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get all subjects with their class assignments
$db = DatabaseConfig::getInstance()->getConnection();
$stmt = $db->query(
    "SELECT s.*, 
            GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') as assigned_classes,
            COUNT(DISTINCT cs.class_id) as class_count
     FROM Subjects s
     LEFT JOIN ClassSubjects cs ON s.subject_id = cs.subject_id
     LEFT JOIN Classes c ON cs.class_id = c.class_id
     GROUP BY s.subject_id
     ORDER BY s.subject_name"
);
$subjects = $stmt->fetchAll();

// Get a complete mapping of subject_id to array of assigned class_ids
$subjectAssignments = [];
foreach ($subjects as $subject) {
    $stmt = $db->prepare(
        "SELECT class_id FROM ClassSubjects WHERE subject_id = ?"
    );
    $stmt->execute([$subject['subject_id']]);
    $subjectAssignments[$subject['subject_id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get all classes for the linking modal
$stmt = $db->query(
    "SELECT c.class_id, c.class_name, c.level, p.program_name
     FROM Classes c
     JOIN Programs p ON c.program_id = p.program_id
     ORDER BY p.program_name, c.level, c.class_name"
);
$classes = $stmt->fetchAll();

// Organize classes by program
$classsByProgram = [];
foreach ($classes as $class) {
    if (!isset($classsByProgram[$class['program_name']])) {
        $classsByProgram[$class['program_name']] = [];
    }
    $classsByProgram[$class['program_name']][] = $class;
}

// Get usage statistics for each subject
$stmt = $db->query(
    "SELECT s.subject_id,
            COUNT(DISTINCT ac.assessment_id) as assessment_count
     FROM Subjects s
     LEFT JOIN AssessmentClasses ac ON s.subject_id = ac.subject_id
     GROUP BY s.subject_id"
);
$subjectStats = [];
while ($row = $stmt->fetch()) {
    $subjectStats[$row['subject_id']] = [
        'assessment_count' => $row['assessment_count']
    ];
}

$pageTitle = 'Subject Management';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
/* Custom Gold and Black Theme */
:root {
    --gold: #ffd700;
    --dark-gold: #d4af37;
    --light-gold: #fff8d6;
    --black: #000000;
    --dark-gray: #333333;
    --light-gray: #f8f9fa;
}

/* Header and Card Styling */
.header-section {
    background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
    padding: 1.5rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    color: white;
}

.card {
    border: none;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.card-header {
    background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
    color: white;
    padding: 1rem;
    border-bottom: none;
}

/* Stats Cards */
.stats-card {
    height: 100%;
    transition: transform 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.stats-icon {
    width: 4rem;
    height: 4rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: rgba(255, 255, 255, 0.2);
    margin-right: 1rem;
}

/* Subject Icon */
.subject-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold) 0%, var(--dark-gold) 100%);
    color: var(--black);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

/* Table Styling */
.table-hover tbody tr:hover {
    background-color: var(--light-gold);
}

/* Button Styling */
.btn-gold {
    color: var(--black);
    background: linear-gradient(to right, var(--gold), var(--dark-gold));
    border: none;
    font-weight: 500;
}

.btn-gold:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.15);
    color: var(--black);
    background: linear-gradient(to right, var(--dark-gold), var(--gold));
}

/* Text colors */
.text-gold {
    color: var(--gold) !important;
}

/* Badge customization */
.badge.bg-success-subtle {
    background-color: rgba(25, 135, 84, 0.15) !important;
    color: #198754;
}

.badge.bg-warning-subtle {
    background-color: rgba(255, 193, 7, 0.15) !important;
    color: #ffc107;
}

/* Modal styling */
.modal-content {
    border: none;
    border-radius: 0.5rem;
    overflow: hidden;
}

.btn-close-white {
    filter: brightness(0) invert(1);
}

/* Custom checkboxes */
.custom-checkbox .form-check-input:checked {
    background-color: var(--gold);
    border-color: var(--gold);
}

/* Program section in linking modal */
.program-section {
    border-radius: 0.5rem;
    overflow: hidden;
}

.program-header {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem 0.5rem 0 0;
}

/* Responsive adjustments */
@media (max-width: 767.98px) {
    .header-action {
        margin-top: 1rem;
        width: 100%;
    }
    
    .search-container {
        margin-top: 1rem;
        width: 100%;
    }
}
</style>

<main class="container-fluid py-4">
    <!-- Dashboard Header -->
    <div class="header-section">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="text-gold">Subject Management</h1>
                <p class="text-white-50">Manage school subjects and their class assignments</p>
            </div>
            <div class="col-md-6 text-md-end">
                <button type="button" class="btn btn-gold header-action" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                    <i class="fas fa-plus-circle me-2"></i>Add New Subject
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <div class="d-flex">
                <div><i class="fas fa-exclamation-circle fa-lg mt-1 me-2"></i></div>
                <div>
                    <strong>Error!</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <div class="d-flex">
                <div><i class="fas fa-check-circle fa-lg mt-1 me-2"></i></div>
                <div>
                    <strong>Success!</strong> <?php echo htmlspecialchars($success); ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-primary text-white stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon">
                            <i class="fas fa-book fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Total Subjects</h6>
                            <h2 class="mb-0"><?php echo count($subjects); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-success text-white stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon">
                            <i class="fas fa-link fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Assigned Subjects</h6>
                            <h2 class="mb-0">
                                <?php 
                                    $assignedCount = count(array_filter($subjects, function($subject) {
                                        return !empty($subject['assigned_classes']);
                                    }));
                                    echo $assignedCount;
                                ?>
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-info text-white stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon">
                            <i class="fas fa-unlock fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Unassigned Subjects</h6>
                            <h2 class="mb-0">
                                <?php 
                                    $unassignedCount = count(array_filter($subjects, function($subject) {
                                        return empty($subject['assigned_classes']);
                                    }));
                                    echo $unassignedCount;
                                ?>
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card bg-warning text-dark stats-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-white">
                            <i class="fas fa-graduation-cap fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Avg Classes/Subject</h6>
                            <h2 class="mb-0">
                                <?php 
                                    $totalClasses = array_sum(array_column($subjects, 'class_count'));
                                    $avgClasses = count($subjects) > 0 ? round($totalClasses / count($subjects), 1) : 0;
                                    echo $avgClasses;
                                ?>
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Subjects Table -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-book me-2"></i>All Subjects
                </h5>
                <div class="input-group search-container" style="max-width: 300px;">
                    <span class="input-group-text bg-white">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" id="subjectSearch" class="form-control" placeholder="Search subjects...">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Subject</th>
                            <th>Description</th>
                            <th>Assigned Classes</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="subjectsTableBody">
                        <?php foreach ($subjects as $subject): 
                            $hasClasses = !empty($subject['assigned_classes']);
                            $classCount = $subject['class_count'];
                            $assessmentCount = $subjectStats[$subject['subject_id']]['assessment_count'] ?? 0;
                        ?>
                        <tr data-subject-name="<?php echo htmlspecialchars(strtolower($subject['subject_name'])); ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="subject-icon me-3">
                                        <i class="fas fa-book-open"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                                        <small class="text-muted">ID: <?php echo $subject['subject_id']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($subject['description'])): ?>
                                    <?php echo htmlspecialchars(mb_strimwidth($subject['description'], 0, 80, '...')); ?>
                                <?php else: ?>
                                    <span class="text-muted">No description</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasClasses): ?>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-success me-2"><?php echo $classCount; ?></span>
                                        <div class="text-truncate" style="max-width: 200px;">
                                            <?php echo htmlspecialchars($subject['assigned_classes']); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark">No classes assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasClasses): ?>
                                    <span class="badge bg-success-subtle">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editSubject(<?php echo htmlspecialchars(json_encode($subject)); ?>)" 
                                            title="Edit Subject">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                            onclick="linkSubject(<?php echo $subject['subject_id']; ?>, '<?php echo htmlspecialchars($subject['subject_name']); ?>')"
                                            title="Link to Classes">
                                        <i class="fas fa-link"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger <?php echo ($assessmentCount > 0) ? 'disabled' : ''; ?>" 
                                            onclick="<?php echo ($assessmentCount > 0) ? 'warningDelete' : 'deleteSubject'; ?>(<?php echo $subject['subject_id']; ?>, '<?php echo htmlspecialchars($subject['subject_name']); ?>')"
                                            title="<?php echo ($assessmentCount > 0) ? 'Cannot delete (has assessments)' : 'Delete Subject'; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">Showing <span id="displayedCount"><?php echo count($subjects); ?></span> of <?php echo count($subjects); ?> subjects</small>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportSubjects()">
                        <i class="fas fa-download me-1"></i>Export List
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="subjects.php" id="addSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create_subject">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Add New Subject
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="fas fa-book text-primary"></i>
                            </span>
                            <input type="text" name="subject_name" class="form-control" required>
                        </div>
                        <div class="form-text">Enter a unique subject name</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="fas fa-align-left text-primary"></i>
                            </span>
                            <textarea name="description" class="form-control" rows="3" placeholder="Provide a brief description of this subject"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create Subject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link Subject to Classes Modal -->
<div class="modal fade" id="linkSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="subjects.php" id="linkSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="link_subject">
                <input type="hidden" name="subject_id" id="linkSubjectId">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-link me-2"></i>Link Subject to Classes
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <div class="d-flex">
                            <div><i class="fas fa-info-circle me-2 mt-1"></i></div>
                            <div>
                                <strong>Subject:</strong> <span id="linkSubjectName" class="fw-bold"></span>
                                <p class="mb-0 small">Select which classes this subject should be taught in</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="class-selection-container">
                        <?php foreach ($classsByProgram as $program => $programClasses): ?>
                        <div class="program-section mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">
                                    <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($program); ?>
                                </h6>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary btn-select-all" data-program="<?php echo htmlspecialchars($program); ?>">
                                        Select All
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-select-none" data-program="<?php echo htmlspecialchars($program); ?>">
                                        Clear
                                    </button>
                                </div>
                            </div>
                            <div class="p-3 border rounded bg-light">
                                <div class="row g-3">
                                    <?php foreach ($programClasses as $class): ?>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   name="class_ids[]" 
                                                   value="<?php echo $class['class_id']; ?>" 
                                                   id="class_<?php echo $class['class_id']; ?>"
                                                   data-program="<?php echo htmlspecialchars($program); ?>">
                                            <label class="form-check-label" for="class_<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($class['level'] . ' ' . $class['class_name']); ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="alert alert-info d-flex align-items-center mb-0 mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <div>
                            <span id="selectedClassCount">0</span> classes selected
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-link me-1"></i>Save Assignments
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="subjects.php" id="editSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update_subject">
                <input type="hidden" name="subject_id" id="editSubjectId">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Subject
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="fas fa-book text-primary"></i>
                            </span>
                            <input type="text" name="subject_name" id="editSubjectName" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="fas fa-align-left text-primary"></i>
                            </span>
                            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Subject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Subject Modal -->
<div class="modal fade" id="deleteSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="subjects.php" id="deleteSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete_subject">
                <input type="hidden" name="subject_id" id="deleteSubjectId">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-trash me-2"></i>Delete Subject
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="mb-3">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                        </div>
                        <h4 class="text-danger">Are you sure?</h4>
                        <p>You are about to delete the subject: <strong id="deleteSubjectName" class="text-danger"></strong></p>
                    </div>
                    
                    <div class="alert alert-danger">
                        <div class="d-flex">
                            <div><i class="fas fa-exclamation-circle me-2 mt-1"></i></div>
                            <div>
                                <strong>Warning:</strong> This action cannot be undone! All class assignments for this subject will also be removed.
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                            <label class="form-check-label" for="confirmDelete">
                                I understand that this action is permanent
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="deleteSubjectBtn" disabled>
                        <i class="fas fa-trash me-1"></i>Delete Subject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Warning Cannot Delete Modal -->
<div class="modal fade" id="warningDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Cannot Delete Subject
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-lock fa-3x text-warning mb-3"></i>
                    <p class="mb-0">The subject <strong id="warningSubjectName"></strong> cannot be deleted because it has active assessments.</p>
                </div>
                
                <div class="alert alert-info">
                    <div class="d-flex">
                        <div><i class="fas fa-info-circle me-2 mt-1"></i></div>
                        <div>
                            <strong>Note:</strong> You must first delete or reassign all assessments associated with this subject before it can be deleted.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="fas fa-download me-2"></i>Export Subjects
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Export Format</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="exportFormat" id="formatCSV" value="csv" checked>
                            <label class="form-check-label" for="formatCSV">
                                <i class="fas fa-file-csv me-1"></i>CSV
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="exportFormat" id="formatXLSX" value="xlsx">
                            <label class="form-check-label" for="formatXLSX">
                                <i class="fas fa-file-excel me-1"></i>Excel
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="exportFormat" id="formatPDF" value="pdf">
                            <label class="form-check-label" for="formatPDF">
                                <i class="fas fa-file-pdf me-1"></i>PDF
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Include Data</label>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeDescription" checked>
                                <label class="form-check-label" for="includeDescription">
                                    Subject Description
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeClasses" checked>
                                <label class="form-check-label" for="includeClasses">
                                    Assigned Classes
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeStats" checked>
                                <label class="form-check-label" for="includeStats">
                                    Assessment Statistics
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeMetadata">
                                <label class="form-check-label" for="includeMetadata">
                                    Created/Updated Dates
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="downloadExport()">
                    <i class="fas fa-download me-1"></i>Export Now
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Store subject assignments data
const subjectAssignments = <?php echo json_encode($subjectAssignments); ?>;

// Initialize modals
let editSubjectModal;
let linkSubjectModal;
let deleteSubjectModal;
let warningDeleteModal;
let exportModal;

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap components
    initializeComponents();
    
    // Initialize event listeners
    initializeEventListeners();
    
    // Setup search functionality
    setupSearch();
    
    // Setup alert auto-dismiss
    setupAlertAutoDismiss();
});

// Initialize Bootstrap components
function initializeComponents() {
    // Initialize modals
    editSubjectModal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    linkSubjectModal = new bootstrap.Modal(document.getElementById('linkSubjectModal'));
    deleteSubjectModal = new bootstrap.Modal(document.getElementById('deleteSubjectModal'));
    warningDeleteModal = new bootstrap.Modal(document.getElementById('warningDeleteModal'));
    exportModal = new bootstrap.Modal(document.getElementById('exportModal'));
    
    // Initialize tooltips
    const tooltipTriggerList = document.querySelectorAll('[title]');
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        tooltipTriggerList.forEach(function(tooltipTriggerEl) {
            new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
}

// Initialize event listeners
function initializeEventListeners() {
    // Setup select all/none buttons for class linking
    setupClassSelectionButtons();
    
    // Setup delete confirmation checkbox
    setupDeleteConfirmation();
    
    // Setup class selection count
    setupClassSelectionCount();
    
    // Setup form validation
    setupFormValidation();
}

// Edit subject function
function editSubject(subject) {
    document.getElementById('editSubjectId').value = subject.subject_id;
    document.getElementById('editSubjectName').value = subject.subject_name;
    document.getElementById('editDescription').value = subject.description;
    editSubjectModal.show();
}

// Link subject function
function linkSubject(subjectId, subjectName) {
    document.getElementById('linkSubjectId').value = subjectId;
    document.getElementById('linkSubjectName').textContent = subjectName;
    
    // Clear all checkboxes first
    document.querySelectorAll('input[name="class_ids[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // If the subject has assigned classes, check the corresponding boxes
    if (subjectAssignments[subjectId] && subjectAssignments[subjectId].length > 0) {
        const assignedClassIds = subjectAssignments[subjectId];
        
        document.querySelectorAll('input[name="class_ids[]"]').forEach(checkbox => {
            if (assignedClassIds.includes(parseInt(checkbox.value))) {
                checkbox.checked = true;
            }
        });
    }
    
    // Update selected count
    updateSelectedClassCount();
    
    linkSubjectModal.show();
}

// Delete subject function
function deleteSubject(subjectId, subjectName) {
    document.getElementById('deleteSubjectId').value = subjectId;
    document.getElementById('deleteSubjectName').textContent = subjectName;
    document.getElementById('confirmDelete').checked = false;
    document.getElementById('deleteSubjectBtn').disabled = true;
    deleteSubjectModal.show();
}

// Warning for subjects that cannot be deleted
function warningDelete(subjectId, subjectName) {
    document.getElementById('warningSubjectName').textContent = subjectName;
    warningDeleteModal.show();
}

// Export subjects function
function exportSubjects() {
    exportModal.show();
}

// Download export
function downloadExport() {
    // This would normally call a server endpoint to generate the export
    // For now, we'll just simulate a download with an alert
    const format = document.querySelector('input[name="exportFormat"]:checked').value;
    alert(`Export initiated in ${format.toUpperCase()} format. In a real implementation, this would download the file.`);
    exportModal.hide();
}

// Setup form validation
function setupFormValidation() {
    // Add Subject Form Validation
    const addForm = document.getElementById('addSubjectForm');
    if (addForm) {
        addForm.addEventListener('submit', function(event) {
            if (!this.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    }
    
    // Edit Subject Form Validation
    const editForm = document.getElementById('editSubjectForm');
    if (editForm) {
        editForm.addEventListener('submit', function(event) {
            if (!this.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    }
    
    // Link Subject Form Validation
    const linkForm = document.getElementById('linkSubjectForm');
    if (linkForm) {
        linkForm.addEventListener('submit', function(event) {
            // Optional: Add custom validation here if needed
        });
    }
    
    // Delete Subject Form Validation
    const deleteForm = document.getElementById('deleteSubjectForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function(event) {
            if (!document.getElementById('confirmDelete').checked) {
                event.preventDefault();
                event.stopPropagation();
                alert('Please confirm that you understand this action is permanent');
            }
        });
    }
}

// Setup delete confirmation
function setupDeleteConfirmation() {
    const confirmCheckbox = document.getElementById('confirmDelete');
    const deleteButton = document.getElementById('deleteSubjectBtn');
    
    if (confirmCheckbox && deleteButton) {
        confirmCheckbox.addEventListener('change', function() {
            deleteButton.disabled = !this.checked;
        });
    }
}

// Setup class selection buttons
function setupClassSelectionButtons() {
    // Select All buttons
    document.querySelectorAll('.btn-select-all').forEach(button => {
        button.addEventListener('click', function() {
            const program = this.getAttribute('data-program');
            document.querySelectorAll(`input[data-program="${program}"]`).forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedClassCount();
        });
    });
    
    // Select None buttons
    document.querySelectorAll('.btn-select-none').forEach(button => {
        button.addEventListener('click', function() {
            const program = this.getAttribute('data-program');
            document.querySelectorAll(`input[data-program="${program}"]`).forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedClassCount();
        });
    });
}

// Setup class selection count
function setupClassSelectionCount() {
    const modal = document.getElementById('linkSubjectModal');
    if (modal) {
        // Update count when modal is shown
        modal.addEventListener('shown.bs.modal', function() {
            updateSelectedClassCount();
        });
        
        // Update count when checkboxes change
        document.querySelectorAll('input[name="class_ids[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedClassCount();
            });
        });
    }
}

// Update selected class count
function updateSelectedClassCount() {
    const selectedCount = document.querySelectorAll('input[name="class_ids[]"]:checked').length;
    const countElement = document.getElementById('selectedClassCount');
    if (countElement) {
        countElement.textContent = selectedCount;
    }
}

// Setup search functionality
function setupSearch() {
    const searchInput = document.getElementById('subjectSearch');
    const tableBody = document.getElementById('subjectsTableBody');
    const displayedCountElement = document.getElementById('displayedCount');
    
    if (searchInput && tableBody) {
        const rows = tableBody.querySelectorAll('tr');
        
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            let visibleCount = 0;
            
            rows.forEach(row => {
                const subjectName = row.getAttribute('data-subject-name');
                const description = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const classes = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                
                if (subjectName.includes(searchTerm) || 
                    description.includes(searchTerm) || 
                    classes.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update displayed count
            if (displayedCountElement) {
                displayedCountElement.textContent = visibleCount;
            }
        });
    }
}

// Setup alert auto-dismiss
function setupAlertAutoDismiss() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
}

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>