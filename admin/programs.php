<?php
// admin/programs.php
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
            case 'create':
                $programName = sanitizeInput($_POST['program_name']);
                try {
                    $stmt = $db->prepare("INSERT INTO Programs (program_name) VALUES (?)");
                    if ($stmt->execute([$programName])) {
                        logSystemActivity(
                            'Program Management',
                            "New program created: $programName",
                            'INFO'
                        );
                        $success = 'Program created successfully';
                    } else {
                        throw new Exception('Failed to create program');
                    }
                } catch (PDOException $e) {
                    $error = 'Program name already exists';
                    logError("Program creation failed: " . $e->getMessage());
                }
                break;

            case 'update':
                $programId = sanitizeInput($_POST['program_id']);
                $programName = sanitizeInput($_POST['program_name']);
                try {
                    $stmt = $db->prepare("UPDATE Programs SET program_name = ? WHERE program_id = ?");
                    if ($stmt->execute([$programName, $programId])) {
                        logSystemActivity(
                            'Program Management',
                            "Program updated: $programName",
                            'INFO'
                        );
                        $success = 'Program updated successfully';
                    } else {
                        throw new Exception('Failed to update program');
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to update program. Name may already exist.';
                    logError("Program update failed: " . $e->getMessage());
                }
                break;

            case 'delete':
                $programId = sanitizeInput($_POST['program_id']);
                try {
                    // Check if program has associated classes
                    $stmt = $db->prepare("SELECT COUNT(*) FROM Classes WHERE program_id = ?");
                    $stmt->execute([$programId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete program with existing classes');
                    }

                    $stmt = $db->prepare("DELETE FROM Programs WHERE program_id = ?");
                    if ($stmt->execute([$programId])) {
                        logSystemActivity(
                            'Program Management',
                            "Program deleted: ID $programId",
                            'INFO'
                        );
                        $success = 'Program deleted successfully';
                    } else {
                        throw new Exception('Failed to delete program');
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Program deletion failed: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get all programs with class counts
$db = DatabaseConfig::getInstance()->getConnection();
// Get all programs with class counts
$stmt = $db->query(
    "SELECT p.*, 
            COUNT(DISTINCT c.class_id) as class_count,
            COUNT(DISTINCT cs.subject_id) as subject_count
     FROM Programs p
     LEFT JOIN Classes c ON p.program_id = c.program_id
     LEFT JOIN ClassSubjects cs ON c.class_id = cs.class_id
     GROUP BY p.program_id
     ORDER BY p.program_name"
);
$programs = $stmt->fetchAll();

$pageTitle = 'Program Management';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">Program Management</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
            <i class="fas fa-plus-circle me-2"></i>Add New Program
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

    <!-- Programs Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Program Name</th>
                            <th>Classes</th>
                            <th>Subjects</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $program): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                            <td><?php echo $program['class_count']; ?></td>
                            <td><?php echo $program['subject_count']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($program['created_at'])); ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editProgram(<?php echo htmlspecialchars(json_encode($program)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($program['class_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteProgram(<?php echo $program['program_id']; ?>, '<?php echo htmlspecialchars($program['program_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
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

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="programs.php" id="addProgramForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add New Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Program Name</label>
                        <input type="text" name="program_name" class="form-control" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="programs.php" id="editProgramForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="program_id" id="editProgramId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Program Name</label>
                        <input type="text" name="program_name" id="editProgramName" class="form-control" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Program Modal -->
<div class="modal fade" id="deleteProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="programs.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="program_id" id="deleteProgramId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Delete Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone!
                    </div>
                    <p class="text-center">Are you sure you want to delete program <strong id="deleteProgramName"></strong>?</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    const editProgramModal = new bootstrap.Modal(document.getElementById('editProgramModal'));
    const deleteProgramModal = new bootstrap.Modal(document.getElementById('deleteProgramModal'));

    // Edit program function
    window.editProgram = function(program) {
        document.getElementById('editProgramId').value = program.program_id;
        document.getElementById('editProgramName').value = program.program_name;
        editProgramModal.show();
    }

    // Delete program function
    window.deleteProgram = function(programId, programName) {
        document.getElementById('deleteProgramId').value = programId;
        document.getElementById('deleteProgramName').textContent = programName;
        deleteProgramModal.show();
    }

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-danger)');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});


</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>