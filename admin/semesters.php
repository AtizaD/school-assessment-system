<?php
// admin/semesters.php
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
                $semesterName = sanitizeInput($_POST['semester_name']);
                $isDoubleTrack = isset($_POST['is_double_track']) && $_POST['is_double_track'] === 'on' ? 1 : 0;
                
                try {
                    $db->beginTransaction();
                    
                    // Create main semester record
                    $stmt = $db->prepare(
                        "INSERT INTO Semesters (semester_name, start_date, end_date, is_double_track) 
                         VALUES (?, ?, ?, ?)"
                    );
                    
                    if ($isDoubleTrack) {
                        // For double track, use placeholder dates (will be set per class)
                        $stmt->execute([$semesterName, '2024-01-01', '2024-12-31', true]);
                    } else {
                        // Single track - use provided dates
                        $startDate = sanitizeInput($_POST['start_date']);
                        $endDate = sanitizeInput($_POST['end_date']);
                        
                        if ($startDate >= $endDate) {
                            throw new Exception('End date must be after start date');
                        }
                        
                        $stmt->execute([$semesterName, $startDate, $endDate, false]);
                    }
                    
                    $semesterId = $db->lastInsertId();
                    
                    // Handle form-specific dates
                    if ($isDoubleTrack && isset($_POST['form_dates'])) {
                        $stmt = $db->prepare(
                            "INSERT INTO semester_forms (semester_id, form_level, start_date, end_date) 
                             VALUES (?, ?, ?, ?)"
                        );
                        
                        foreach ($_POST['form_dates'] as $formLevel => $dates) {
                            if (!empty($dates['start_date']) && !empty($dates['end_date'])) {
                                if ($dates['start_date'] >= $dates['end_date']) {
                                    throw new Exception('End date must be after start date for all forms');
                                }
                                $stmt->execute([$semesterId, $formLevel, $dates['start_date'], $dates['end_date']]);
                            }
                        }
                    } else if (!$isDoubleTrack) {
                        // For single track, apply same dates to all forms
                        $formLevels = ['SHS 1', 'SHS 2', 'SHS 3'];
                        
                        $stmt = $db->prepare(
                            "INSERT INTO semester_forms (semester_id, form_level, start_date, end_date) 
                             VALUES (?, ?, ?, ?)"
                        );
                        
                        foreach ($formLevels as $formLevel) {
                            $stmt->execute([$semesterId, $formLevel, $startDate, $endDate]);
                        }
                    }
                    
                    $db->commit();
                    logSystemActivity(
                        'Semester Management',
                        "New semester created: $semesterName (" . ($isDoubleTrack ? 'Double Track' : 'Single Track') . ")",
                        'INFO'
                    );
                    $success = 'Semester created successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                    logError("Semester creation failed: " . $e->getMessage());
                }
                break;

            case 'update':
                $semesterId = sanitizeInput($_POST['semester_id']);
                $semesterName = sanitizeInput($_POST['semester_name']);
                $isDoubleTrack = isset($_POST['is_double_track']) && $_POST['is_double_track'] === 'on' ? 1 : 0;
                
                try {
                    $db->beginTransaction();
                    
                    // Update main semester record
                    $stmt = $db->prepare(
                        "UPDATE Semesters 
                         SET semester_name = ?, is_double_track = ?
                         WHERE semester_id = ?"
                    );
                    $stmt->execute([$semesterName, $isDoubleTrack, $semesterId]);
                    
                    // Delete existing form records for this semester
                    $stmt = $db->prepare("DELETE FROM semester_forms WHERE semester_id = ?");
                    $stmt->execute([$semesterId]);
                    
                    // Handle form-specific dates
                    if ($isDoubleTrack && isset($_POST['edit_form_dates'])) {
                        $stmt = $db->prepare(
                            "INSERT INTO semester_forms (semester_id, form_level, start_date, end_date) 
                             VALUES (?, ?, ?, ?)"
                        );
                        
                        foreach ($_POST['edit_form_dates'] as $formLevel => $dates) {
                            if (!empty($dates['start_date']) && !empty($dates['end_date'])) {
                                if ($dates['start_date'] >= $dates['end_date']) {
                                    throw new Exception('End date must be after start date for all forms');
                                }
                                $stmt->execute([$semesterId, $formLevel, $dates['start_date'], $dates['end_date']]);
                            }
                        }
                        
                        // Update main semester with placeholder dates for double track
                        $stmt = $db->prepare(
                            "UPDATE Semesters 
                             SET start_date = ?, end_date = ?
                             WHERE semester_id = ?"
                        );
                        $stmt->execute(['2024-01-01', '2024-12-31', $semesterId]);
                        
                    } else if (!$isDoubleTrack) {
                        // Single track - use provided dates
                        $startDate = sanitizeInput($_POST['start_date']);
                        $endDate = sanitizeInput($_POST['end_date']);
                        
                        if ($startDate >= $endDate) {
                            throw new Exception('End date must be after start date');
                        }
                        
                        // Update main semester dates
                        $stmt = $db->prepare(
                            "UPDATE Semesters 
                             SET start_date = ?, end_date = ?
                             WHERE semester_id = ?"
                        );
                        $stmt->execute([$startDate, $endDate, $semesterId]);
                        
                        // Apply same dates to all forms
                        $formLevels = ['SHS 1', 'SHS 2', 'SHS 3'];
                        $stmt = $db->prepare(
                            "INSERT INTO semester_forms (semester_id, form_level, start_date, end_date) 
                             VALUES (?, ?, ?, ?)"
                        );
                        
                        foreach ($formLevels as $formLevel) {
                            $stmt->execute([$semesterId, $formLevel, $startDate, $endDate]);
                        }
                    }
                    
                    $db->commit();
                    logSystemActivity(
                        'Semester Management',
                        "Semester updated: $semesterName (". ($isDoubleTrack ? 'Double Track' : 'Single Track') .")",
                        'INFO'
                    );
                    $success = 'Semester updated successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                    logError("Semester update failed: " . $e->getMessage());
                }
                break;

            case 'delete':
                $semesterId = sanitizeInput($_POST['semester_id']);
                try {
                    // Check if semester has associated assessments
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM TeacherClassAssignments WHERE semester_id = ?"
                    );
                    $stmt->execute([$semesterId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete semester with existing assignments');
                    }

                    $stmt = $db->prepare("DELETE FROM Semesters WHERE semester_id = ?");
                    if ($stmt->execute([$semesterId])) {
                        logSystemActivity(
                            'Semester Management',
                            "Semester deleted: ID $semesterId",
                            'INFO'
                        );
                        $success = 'Semester deleted successfully';
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Semester deletion failed: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get all semesters with additional information
$db = DatabaseConfig::getInstance()->getConnection();
$stmt = $db->query(
    "SELECT s.*, 
            COUNT(DISTINCT tca.assignment_id) as assignment_count,
            COUNT(DISTINCT a.assessment_id) as assessment_count,
            COUNT(DISTINCT sf.form_level) as form_count,
            CASE 
                WHEN s.is_double_track = 1 THEN 
                    (SELECT COUNT(*) FROM semester_forms sf2 
                     WHERE sf2.semester_id = s.semester_id 
                     AND CURRENT_DATE BETWEEN sf2.start_date AND sf2.end_date) > 0
                ELSE CURRENT_DATE BETWEEN s.start_date AND s.end_date 
            END as is_current
     FROM Semesters s
     LEFT JOIN TeacherClassAssignments tca ON s.semester_id = tca.semester_id
     LEFT JOIN Assessments a ON s.semester_id = a.semester_id
     LEFT JOIN semester_forms sf ON s.semester_id = sf.semester_id
     GROUP BY s.semester_id
     ORDER BY s.start_date DESC"
);
$semesters = $stmt->fetchAll();

// Get all form levels for double track UI
$formLevels = ['SHS 1', 'SHS 2', 'SHS 3'];

$pageTitle = 'Semester Management';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Semester Management</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSemesterModal">
            <i class="fas fa-plus-circle me-2"></i>Add New Semester
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

    <!-- Semesters Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Semester Name</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Forms</th>
                            <th>Assignments</th>
                            <th>Assessments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($semesters as $semester): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($semester['semester_name']); ?></td>
                            <td>
                                <?php if ($semester['is_double_track']): ?>
                                    <span class="badge bg-primary">Double Track</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Single Track</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($semester['is_double_track']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                            onclick="viewFormDates(<?php echo $semester['semester_id']; ?>)">
                                        <i class="fas fa-calendar-alt me-1"></i>View Form Dates
                                    </button>
                                <?php else: ?>
                                    <?php echo date('M d, Y', strtotime($semester['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($semester['end_date'])); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($semester['is_current']): ?>
                                    <span class="badge bg-success">Current</span>
                                <?php elseif (!$semester['is_double_track'] && strtotime($semester['start_date']) > time()): ?>
                                    <span class="badge bg-info">Upcoming</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Past</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $semester['form_count']; ?></td>
                            <td><?php echo $semester['assignment_count']; ?></td>
                            <td><?php echo $semester['assessment_count']; ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editSemester(<?php echo htmlspecialchars(json_encode($semester)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($semester['assignment_count'] == 0 && $semester['assessment_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteSemester(<?php echo $semester['semester_id']; ?>, '<?php echo htmlspecialchars($semester['semester_name']); ?>')">
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

<!-- Add Semester Modal -->
<div class="modal fade" id="addSemesterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="semesters.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add New Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Semester Name</label>
                        <input type="text" name="semester_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_double_track" id="isDoubleTrack" onchange="toggleDoubleTrack()">
                            <label class="form-check-label" for="isDoubleTrack">
                                <strong>Double Track System</strong>
                                <small class="text-muted d-block">Enable different semester dates for different form levels (SHS 1, SHS 2, SHS 3)</small>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Single Track Dates -->
                    <div id="singleTrackDates">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" id="singleStartDate">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" id="singleEndDate">
                        </div>
                    </div>
                    
                    <!-- Double Track Dates -->
                    <div id="doubleTrackDates" style="display: none;">
                        <h6 class="mb-3">Form-Specific Semester Dates</h6>
                        <?php foreach ($formLevels as $formLevel): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php echo htmlspecialchars($formLevel); ?></h6>
                                <p class="card-text text-muted small">Senior High School - <?php echo htmlspecialchars($formLevel); ?></p>
                                <div class="row">
                                    <div class="col-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="form_dates[<?php echo htmlspecialchars($formLevel); ?>][start_date]" class="form-control">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="form_dates[<?php echo htmlspecialchars($formLevel); ?>][end_date]" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Semester</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Semester Modal -->
<div class="modal fade" id="editSemesterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="semesters.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="semester_id" id="editSemesterId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Semester Name</label>
                        <input type="text" name="semester_name" id="editSemesterName" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_double_track" id="editIsDoubleTrack" onchange="toggleEditDoubleTrack()">
                            <label class="form-check-label" for="editIsDoubleTrack">
                                <strong>Double Track System</strong>
                                <small class="text-muted d-block">Enable different semester dates for different form levels (SHS 1, SHS 2, SHS 3)</small>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Single Track Dates -->
                    <div id="editSingleTrackDates">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="editStartDate" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" id="editEndDate" class="form-control">
                        </div>
                    </div>
                    
                    <!-- Double Track Dates -->
                    <div id="editDoubleTrackDates" style="display: none;">
                        <h6 class="mb-3">Form-Specific Semester Dates</h6>
                        <div id="editFormDatesContainer">
                            <!-- Form dates will be loaded here -->
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Semester</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Semester Modal -->
<div class="modal fade" id="deleteSemesterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="semesters.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="semester_id" id="deleteSemesterId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Delete Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone!
                    </div>
                    <p class="text-center">Are you sure you want to delete semester <strong id="deleteSemesterName"></strong>?</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Semester</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Class Dates Modal -->
<div class="modal fade" id="viewClassDatesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Form-Specific Semester Dates</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body">
                <div id="classDatesContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Declare modal variables at the top level
let editSemesterModal;
let deleteSemesterModal;
let viewClassDatesModal;

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    editSemesterModal = new bootstrap.Modal(document.getElementById('editSemesterModal'));
    deleteSemesterModal = new bootstrap.Modal(document.getElementById('deleteSemesterModal'));
    viewClassDatesModal = new bootstrap.Modal(document.getElementById('viewClassDatesModal'));

    // Date validation handlers
    const startDate = document.getElementById('editStartDate');
    const endDate = document.getElementById('editEndDate');
    
    if (startDate && endDate) {
        startDate.addEventListener('change', function() {
            endDate.min = this.value;
        });
    }
    
    // For add semester form - single track dates
    const addStartDate = document.getElementById('singleStartDate');
    const addEndDate = document.getElementById('singleEndDate');
    
    if (addStartDate && addEndDate) {
        addStartDate.addEventListener('change', function() {
            addEndDate.min = this.value;
        });
    }
    
    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!this.checkValidity() || !validateForm(this)) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    });

    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert:not(.alert-danger)');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// Toggle between single track and double track
function toggleDoubleTrack() {
    const isDoubleTrack = document.getElementById('isDoubleTrack').checked;
    const singleTrackDates = document.getElementById('singleTrackDates');
    const doubleTrackDates = document.getElementById('doubleTrackDates');
    const singleStartDate = document.getElementById('singleStartDate');
    const singleEndDate = document.getElementById('singleEndDate');
    
    if (isDoubleTrack) {
        singleTrackDates.style.display = 'none';
        doubleTrackDates.style.display = 'block';
        singleStartDate.required = false;
        singleEndDate.required = false;
    } else {
        singleTrackDates.style.display = 'block';
        doubleTrackDates.style.display = 'none';
        singleStartDate.required = true;
        singleEndDate.required = true;
    }
}

// Toggle between single track and double track for edit modal
function toggleEditDoubleTrack() {
    const isDoubleTrack = document.getElementById('editIsDoubleTrack').checked;
    const singleTrackDates = document.getElementById('editSingleTrackDates');
    const doubleTrackDates = document.getElementById('editDoubleTrackDates');
    const singleStartDate = document.getElementById('editStartDate');
    const singleEndDate = document.getElementById('editEndDate');
    
    if (isDoubleTrack) {
        singleTrackDates.style.display = 'none';
        doubleTrackDates.style.display = 'block';
        singleStartDate.required = false;
        singleEndDate.required = false;
    } else {
        singleTrackDates.style.display = 'block';
        doubleTrackDates.style.display = 'none';
        singleStartDate.required = true;
        singleEndDate.required = true;
    }
}

// Load form dates for editing
function loadFormDatesForEdit(semesterId) {
    fetch(`../api/get_semester_form_dates.php?semester_id=${semesterId}`)
        .then(response => response.json())
        .then(data => {
            let content = '';
            const formLevels = ['SHS 1', 'SHS 2', 'SHS 3'];
            
            formLevels.forEach(formLevel => {
                const formData = data.find(d => d.form_level === formLevel);
                const startDate = formData ? formData.start_date : '';
                const endDate = formData ? formData.end_date : '';
                
                content += `
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title">${formLevel}</h6>
                            <p class="card-text text-muted small">Senior High School - ${formLevel}</p>
                            <div class="row">
                                <div class="col-6">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="edit_form_dates[${formLevel}][start_date]" class="form-control" value="${startDate}">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">End Date</label>
                                    <input type="date" name="edit_form_dates[${formLevel}][end_date]" class="form-control" value="${endDate}">
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('editFormDatesContainer').innerHTML = content;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('editFormDatesContainer').innerHTML = 
                '<div class="alert alert-danger">Error loading form dates</div>';
        });
}

// Edit semester function
function editSemester(semester) {
    document.getElementById('editSemesterId').value = semester.semester_id;
    document.getElementById('editSemesterName').value = semester.semester_name;
    
    // Set double track checkbox
    const isDoubleTrack = semester.is_double_track == 1;
    document.getElementById('editIsDoubleTrack').checked = isDoubleTrack;
    
    if (isDoubleTrack) {
        // Load form dates for double track
        loadFormDatesForEdit(semester.semester_id);
        toggleEditDoubleTrack();
    } else {
        // Single track - set the main semester dates
        document.getElementById('editStartDate').value = semester.start_date;
        document.getElementById('editEndDate').value = semester.end_date;
        toggleEditDoubleTrack();
    }
    
    editSemesterModal.show();
}

// Delete semester function
function deleteSemester(semesterId, semesterName) {
    document.getElementById('deleteSemesterId').value = semesterId;
    document.getElementById('deleteSemesterName').textContent = semesterName;
    deleteSemesterModal.show();
}

// Form validation function
function validateForm(form) {
    const isDoubleTrack = form.querySelector('[name="is_double_track"]');
    
    if (!isDoubleTrack || !isDoubleTrack.checked) {
        // Single track validation
        const startDate = form.querySelector('[name="start_date"]');
        const endDate = form.querySelector('[name="end_date"]');
        
        if (startDate && endDate && startDate.value && endDate.value) {
            if (new Date(endDate.value) <= new Date(startDate.value)) {
                alert('End date must be after start date');
                return false;
            }
        }
    } else {
        // Double track validation - check both add and edit forms
        const formDates = form.querySelectorAll('[name^="form_dates"], [name^="edit_form_dates"]');
        let hasValidDates = false;
        
        for (let i = 0; i < formDates.length; i += 2) {
            const startDate = formDates[i];
            const endDate = formDates[i + 1];
            
            if (startDate && endDate && startDate.value && endDate.value) {
                hasValidDates = true;
                if (new Date(endDate.value) <= new Date(startDate.value)) {
                    alert('End date must be after start date for all forms');
                    return false;
                }
            }
        }
        
        if (!hasValidDates) {
            alert('Please set dates for at least one form');
            return false;
        }
    }
    return true;
}

// View form dates for double track semesters
function viewFormDates(semesterId) {
    // Show modal
    viewClassDatesModal.show();
    
    // Fetch form dates via AJAX
    fetch(`../api/get_semester_form_dates.php?semester_id=${semesterId}`)
        .then(response => response.json())
        .then(data => {
            let content = '<div class="table-responsive">';
            content += '<table class="table table-striped">';
            content += '<thead><tr><th>Form Level</th><th>Start Date</th><th>End Date</th><th>Status</th></tr></thead>';
            content += '<tbody>';
            
            data.forEach(formData => {
                const startDate = new Date(formData.start_date);
                const endDate = new Date(formData.end_date);
                const today = new Date();
                
                let status = '';
                if (today >= startDate && today <= endDate) {
                    status = '<span class="badge bg-success">Current</span>';
                } else if (today < startDate) {
                    status = '<span class="badge bg-info">Upcoming</span>';
                } else {
                    status = '<span class="badge bg-secondary">Past</span>';
                }
                
                content += `<tr>
                    <td>${formData.form_level}</td>
                    <td>${startDate.toLocaleDateString()}</td>
                    <td>${endDate.toLocaleDateString()}</td>
                    <td>${status}</td>
                </tr>`;
            });
            
            content += '</tbody></table></div>';
            document.getElementById('classDatesContent').innerHTML = content;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('classDatesContent').innerHTML = 
                '<div class="alert alert-danger">Error loading form dates</div>';
        });
}

// Prevent form resubmission on page refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>