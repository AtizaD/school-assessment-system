<?php
// admin/subject_alternatives_config.php - Configure Subject Alternative Groups
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$error = '';
$success = '';
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get all classes for dropdown
    $stmt = $db->query("
        SELECT c.class_id, c.class_name, c.level, p.program_name
        FROM classes c
        JOIN programs p ON c.program_id = p.program_id
        ORDER BY c.class_name
    ");
    $classes = $stmt->fetchAll();

    // Get all subjects for checkboxes
    $stmt = $db->query("
        SELECT subject_id, subject_name 
        FROM subjects
        ORDER BY subject_name
    ");
    $subjects = $stmt->fetchAll();

    // Get existing alternative groups
    $alternative_groups = [];
    if (!empty($classes)) {
        $stmt = $db->query("
            SELECT 
                sa.alt_id,
                sa.class_id,
                sa.group_name,
                sa.subject_ids,
                sa.max_students_per_subject,
                c.class_name
            FROM subject_alternatives sa
            JOIN classes c ON sa.class_id = c.class_id
            ORDER BY c.class_name, sa.group_name
        ");
        $alternative_groups = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    logError("Subject Alternatives Config page error: " . $e->getMessage());
    $error = "Error loading data. Please refresh the page.";
    $classes = [];
    $subjects = [];
    $alternative_groups = [];
}

$pageTitle = 'Subject Alternatives Configuration';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
.form-check {
    margin-bottom: 0.5rem;
}
.form-check-input:checked + .form-check-label {
    font-weight: 600;
    color: #0d6efd;
}
.badge {
    font-size: 0.875em;
}
</style>

<main class="container-fluid px-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 text-dark mb-1">Subject Alternatives Configuration</h1>
                    <p class="text-muted mb-0">Configure mutually exclusive subjects for each class</p>
                </div>
                <div>
                    <a href="subject_assignments.php" class="btn btn-primary">
                        <i class="fas fa-users-cog me-2"></i>Assign Students
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

            <!-- Current Alternative Groups -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list-ul me-2"></i>Current Alternative Groups
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($alternative_groups)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-random fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Alternative Groups Configured</h5>
                            <p class="text-muted">Create your first alternative group below to get started.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Group Name</th>
                                        <th>Alternative Subjects</th>
                                        <th>Student Distribution</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alternative_groups as $group): ?>
                                        <?php
                                        $subject_ids = json_decode($group['subject_ids'], true);
                                        $subject_names = [];
                                        $subject_counts = [];
                                        foreach ($subjects as $subject) {
                                            if (in_array($subject['subject_id'], $subject_ids)) {
                                                $subject_names[] = $subject['subject_name'];
                                                // Get student count for this subject in this class
                                                $count_stmt = $db->prepare("
                                                    SELECT COUNT(*) FROM special_class 
                                                    WHERE class_id = ? AND subject_id = ? AND status = 'active'
                                                ");
                                                $count_stmt->execute([$group['class_id'], $subject['subject_id']]);
                                                $subject_counts[$subject['subject_name']] = $count_stmt->fetchColumn();
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($group['class_name']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php foreach ($subject_names as $name): ?>
                                                    <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($name); ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <?php foreach ($subject_counts as $name => $count): ?>
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="text-muted small"><?php echo htmlspecialchars($name); ?>:</span>
                                                        <span class="badge bg-info"><?php echo $count; ?> students</span>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php 
                                                $total_assigned = array_sum($subject_counts);
                                                // Get total students in class
                                                $total_stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id = ?");
                                                $total_stmt->execute([$group['class_id']]);
                                                $total_students = $total_stmt->fetchColumn();
                                                $unassigned = $total_students - $total_assigned;
                                                ?>
                                                <?php if ($unassigned > 0): ?>
                                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-1 border-top">
                                                        <span class="text-warning small fw-bold">Unassigned:</span>
                                                        <span class="badge" style="background: #FFD700; color: #1a237e;"><?php echo $unassigned; ?> students</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary edit-group" 
                                                        data-alt-id="<?php echo $group['alt_id']; ?>"
                                                        data-class-id="<?php echo $group['class_id']; ?>"
                                                        data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>"
                                                        data-subject-ids="<?php echo htmlspecialchars($group['subject_ids']); ?>"
                ">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-group"
                                                        data-alt-id="<?php echo $group['alt_id']; ?>"
                                                        data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add New Alternative Group -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus-circle me-2"></i>Add New Alternative Group
                    </h5>
                </div>
                <div class="card-body">
                    <form id="alternativeGroupForm">
                        <input type="hidden" id="alt_id" name="alt_id" value="">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="class_id" class="form-label">Select Class <span class="text-danger">*</span></label>
                                    <select class="form-select" id="class_id" name="class_id" required>
                                        <option value="">Choose a class...</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($class['class_name'] . ' - ' . $class['program_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="group_name" class="form-label">Group Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="group_name" name="group_name" 
                                           placeholder="e.g., Computing/Biology, Science Electives" required>
                                    <div class="form-text">Give this alternative group a descriptive name</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Select Alternative Subjects <span class="text-danger">*</span></label>
                            <div class="form-text mb-2">Choose 2 or more subjects that are mutually exclusive</div>
                            <div class="row" id="subjectCheckboxes">
                                <?php foreach ($subjects as $subject): ?>
                                    <div class="col-md-4 col-lg-3">
                                        <div class="form-check">
                                            <input class="form-check-input subject-checkbox" type="checkbox" 
                                                   name="subject_ids[]" value="<?php echo $subject['subject_id']; ?>" 
                                                   id="subject_<?php echo $subject['subject_id']; ?>">
                                            <label class="form-check-label" for="subject_<?php echo $subject['subject_id']; ?>">
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>


                        <div class="d-flex justify-content-between">
                            <div>
                                <span class="text-muted" id="selectedCount">0 subjects selected</span>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline-secondary" id="resetForm">Reset</button>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i>Create Alternative Group
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Black and Yellow Project Theme */
:root {
    --project-black: #000000;
    --project-yellow: #ffd700;
}

/* Reset Bootstrap Colors to Project Colors */
.btn-primary {
    background-color: var(--project-black) !important;
    border-color: var(--project-black) !important;
    color: white !important;
}

.btn-primary:hover, .btn-primary:focus, .btn-primary:active {
    background-color: var(--project-yellow) !important;
    border-color: var(--project-yellow) !important;
    color: var(--project-black) !important;
}

.btn-outline-primary {
    color: var(--project-black) !important;
    border-color: var(--project-black) !important;
    background-color: transparent !important;
}

.btn-outline-primary:hover, .btn-outline-primary:focus, .btn-outline-primary:active {
    background-color: var(--project-black) !important;
    border-color: var(--project-black) !important;
    color: white !important;
}

.card-header {
    background-color: var(--project-black) !important;
    color: white !important;
    border-bottom: 2px solid var(--project-yellow);
}

.badge-primary {
    background-color: var(--project-black) !important;
    color: white !important;
}

.badge-info {
    background-color: var(--project-yellow) !important;
    color: var(--project-black) !important;
}

.form-control:focus, .form-select:focus {
    border-color: var(--project-yellow) !important;
    box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25) !important;
}

.form-check-input:checked {
    background-color: var(--project-black) !important;
    border-color: var(--project-black) !important;
}

.form-check-input:checked + .form-check-label {
    font-weight: 600;
    color: var(--project-black);
}

.text-primary {
    color: var(--project-black) !important;
}

.alert-info {
    background-color: rgba(255, 215, 0, 0.1) !important;
    border-color: var(--project-yellow) !important;
    color: #856404 !important;
}

.table thead th {
    background-color: var(--project-black) !important;
    color: white !important;
}

.form-check {
    margin-bottom: 0.5rem;
}

.badge {
    font-size: 0.875em;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('alternativeGroupForm');
    const subjectCheckboxes = document.querySelectorAll('.subject-checkbox');
    const selectedCount = document.getElementById('selectedCount');
    const submitBtn = document.getElementById('submitBtn');
    const altIdInput = document.getElementById('alt_id');
    const actionInput = form.querySelector('input[name="action"]');

    // Update selected count
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.subject-checkbox:checked').length;
        selectedCount.textContent = checked + ' subjects selected';
        
        // Enable/disable submit button based on selection
        if (checked >= 2) {
            submitBtn.disabled = false;
            selectedCount.className = 'text-success';
        } else {
            submitBtn.disabled = true;
            selectedCount.className = 'text-warning';
        }
    }

    // Add event listeners to checkboxes
    subjectCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });

    // Initial count update
    updateSelectedCount();

    // Reset form
    document.getElementById('resetForm').addEventListener('click', function() {
        form.reset();
        altIdInput.value = '';
        actionInput.value = 'create';
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Alternative Group';
        updateSelectedCount();
    });

    // Edit group functionality
    document.querySelectorAll('.edit-group').forEach(button => {
        button.addEventListener('click', function() {
            const altId = this.dataset.altId;
            const classId = this.dataset.classId;
            const groupName = this.dataset.groupName;
            const subjectIds = JSON.parse(this.dataset.subjectIds);
            // Populate form
            altIdInput.value = altId;
            actionInput.value = 'update';
            document.getElementById('class_id').value = classId;
            document.getElementById('group_name').value = groupName;
            
            // Check relevant subject checkboxes
            subjectCheckboxes.forEach(checkbox => {
                checkbox.checked = subjectIds.includes(parseInt(checkbox.value));
            });

            submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Alternative Group';
            updateSelectedCount();

            // Scroll to form
            document.querySelector('.card:last-child').scrollIntoView({ behavior: 'smooth' });
        });
    });

    // Delete group functionality
    document.querySelectorAll('.delete-group').forEach(button => {
        button.addEventListener('click', function() {
            const altId = this.dataset.altId;
            const groupName = this.dataset.groupName;
            
            if (confirm(`Are you sure you want to delete the alternative group "${groupName}"? This will also remove all student assignments in this group.`)) {
                const deleteForm = document.createElement('form');
                deleteForm.method = 'POST';
                deleteForm.action = '../api/subject_alternatives.php';
                deleteForm.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="alt_id" value="${altId}">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                `;
                document.body.appendChild(deleteForm);
                deleteForm.submit();
            }
        });
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const checkedSubjects = document.querySelectorAll('.subject-checkbox:checked');
        
        if (checkedSubjects.length < 2) {
            alert('Please select at least 2 subjects for the alternative group.');
            return;
        }

        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

        fetch('../api/subject_alternatives.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '?success=' + encodeURIComponent(data.message);
            } else {
                alert('Error: ' + data.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = altIdInput.value ? 
                    '<i class="fas fa-save me-2"></i>Update Alternative Group' : 
                    '<i class="fas fa-save me-2"></i>Create Alternative Group';
            }
        })
        .catch(error => {
            alert('An error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = altIdInput.value ? 
                '<i class="fas fa-save me-2"></i>Update Alternative Group' : 
                '<i class="fas fa-save me-2"></i>Create Alternative Group';
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>