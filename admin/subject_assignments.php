<?php
// admin/subject_assignments.php - Assign students to alternative subjects
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

    // Get all classes that have alternative groups configured
    $stmt = $db->query("
        SELECT DISTINCT c.class_id, c.class_name, c.level, p.program_name
        FROM classes c
        JOIN programs p ON c.program_id = p.program_id
        JOIN subject_alternatives sa ON c.class_id = sa.class_id
        ORDER BY c.class_name
    ");
    $classes_with_alternatives = $stmt->fetchAll();

    // Get all alternative groups for display
    $alternative_groups = [];
    if (!empty($classes_with_alternatives)) {
        $stmt = $db->query("
            SELECT 
                sa.alt_id,
                sa.class_id,
                sa.group_name,
                sa.subject_ids,
                c.class_name
            FROM subject_alternatives sa
            JOIN classes c ON sa.class_id = c.class_id
            ORDER BY c.class_name, sa.group_name
        ");
        $alternative_groups = $stmt->fetchAll();
    }

    // Get all subjects for reference
    $stmt = $db->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $all_subjects = $stmt->fetchAll();
    $subjects_lookup = [];
    foreach ($all_subjects as $subject) {
        $subjects_lookup[$subject['subject_id']] = $subject['subject_name'];
    }

} catch (PDOException $e) {
    logError("Subject Assignments page error: " . $e->getMessage());
    $error = "Error loading data. Please refresh the page.";
    $classes_with_alternatives = [];
    $alternative_groups = [];
    $subjects_lookup = [];
}

$pageTitle = 'Subject Assignments';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 text-dark mb-1">Subject Assignments</h1>
                    <p class="text-muted mb-0">Assign students to alternative subjects with automatic mutual exclusion</p>
                </div>
                <div>
                    <a href="subject_alternatives_config.php" class="btn btn-outline-primary">
                        <i class="fas fa-cog me-2"></i>Configure Alternatives
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

            <?php if (empty($classes_with_alternatives)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-4x text-warning mb-4"></i>
                    <h4>No Alternative Groups Configured</h4>
                    <p class="text-muted mb-4">You need to configure subject alternative groups before you can assign students.</p>
                    <a href="subject_alternatives_config.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-cog me-2"></i>Configure Subject Alternatives
                    </a>
                </div>
            <?php else: ?>

            <!-- Class Selection -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <label for="classSelect" class="form-label fw-bold">Select Class</label>
                            <select class="form-select" id="classSelect">
                                <option value="">Choose a class to view alternative subjects...</option>
                                <?php foreach ($classes_with_alternatives as $class): ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name'] . ' - ' . $class['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <button type="button" class="btn btn-outline-success" id="autoBalanceBtn" disabled>
                                <i class="fas fa-balance-scale me-2"></i>Auto Balance All
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignment Interface -->
            <div id="assignmentInterface" style="display: none;">
                <!-- This will be populated via JavaScript -->
            </div>

            <?php endif; ?>
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

.btn-outline-success:hover, .btn-outline-success:focus, .btn-outline-success:active {
    background-color: #28a745 !important;
    border-color: #28a745 !important;
    color: white !important;
}

.btn-outline-warning {
    color: var(--project-black) !important;
    border-color: var(--project-yellow) !important;
    background-color: transparent !important;
}

.btn-outline-warning:hover, .btn-outline-warning:focus, .btn-outline-warning:active {
    background-color: var(--project-yellow) !important;
    border-color: var(--project-yellow) !important;
    color: var(--project-black) !important;
}

.card-header {
    background-color: var(--project-black) !important;
    color: white !important;
    border-bottom: 2px solid var(--project-yellow);
}

.form-control:focus, .form-select:focus {
    border-color: var(--project-yellow) !important;
    box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25) !important;
}

.form-check-input:checked {
    background-color: var(--project-black) !important;
    border-color: var(--project-black) !important;
}

.text-primary {
    color: var(--project-black) !important;
}

.alert-warning {
    background-color: rgba(255, 215, 0, 0.1) !important;
    border-color: var(--project-yellow) !important;
    color: #856404 !important;
}

.fa-exclamation-triangle {
    color: var(--project-yellow) !important;
}

.student-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    background: white;
    padding: 0.5rem;
}

.student-list::-webkit-scrollbar {
    width: 6px;
}

.student-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.student-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.student-list::-webkit-scrollbar-thumb:hover {
    background: #a1a1a1;
}

.student-item {
    padding: 0.5rem;
    margin-bottom: 0.25rem;
    cursor: pointer;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    border-radius: 4px;
}

.student-item:hover {
    background-color: #f8f9fa;
}

.student-item:last-child {
    margin-bottom: 0;
}

.student-name-plain {
    font-weight: normal;
    color: #333;
    line-height: 1.3;
    word-break: break-word;
    font-size: 0.875rem;
    flex-grow: 1;
}

.student-item input[type="checkbox"]:checked + .student-name-plain {
    font-weight: 600;
    color: var(--project-black);
}


.subject-column {
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    padding: 1rem;
    min-height: 400px;
    transition: all 0.3s ease;
}


.subject-header {
    background: var(--project-black);
    color: white;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    text-align: center;
    border: 2px solid var(--project-yellow);
}

.subject-header h5 {
    margin: 0;
    font-weight: 600;
}

.subject-count {
    font-size: 0.875rem;
    opacity: 0.9;
}

.unassigned-section {
    background: rgba(255, 215, 0, 0.1);
    border: 2px dashed var(--project-yellow);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 2rem;
}

.unassigned-header {
    background: var(--project-yellow);
    color: var(--project-black);
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    text-align: center;
    font-weight: 600;
    border: 2px solid var(--project-black);
}

.assignment-stats {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 1rem;
}

.bulk-actions {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
}

.loading-spinner {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.getElementById('classSelect');
    const assignmentInterface = document.getElementById('assignmentInterface');
    const autoBalanceBtn = document.getElementById('autoBalanceBtn');
    
    let currentClassId = null;
    let currentAlternativeGroups = [];
    let allStudents = [];
    let subjectsLookup = <?php echo json_encode($subjects_lookup); ?>;

    // Class selection change
    classSelect.addEventListener('change', function() {
        const selectedClassId = this.value;
        
        if (selectedClassId) {
            currentClassId = selectedClassId;
            loadAssignmentInterface(selectedClassId);
            autoBalanceBtn.disabled = false;
        } else {
            currentClassId = null;
            assignmentInterface.style.display = 'none';
            autoBalanceBtn.disabled = true;
        }
    });

    // Auto balance functionality
    autoBalanceBtn.addEventListener('click', function() {
        if (!currentClassId) return;
        
        if (confirm('This will automatically distribute all unassigned students evenly across all alternative subjects. Continue?')) {
            autoBalanceAllGroups();
        }
    });

    function loadAssignmentInterface(classId) {
        // Show loading
        assignmentInterface.innerHTML = '<div class="text-center py-4"><div class="loading-spinner me-2"></div>Loading assignment interface...</div>';
        assignmentInterface.style.display = 'block';

        // Fetch assignment data
        fetch('../api/subject_assignments.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=load_assignments&class_id=' + classId + '&csrf_token=<?php echo generateCSRFToken(); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentAlternativeGroups = data.groups;
                allStudents = data.students;
                renderAssignmentInterface(data);
            } else {
                assignmentInterface.innerHTML = '<div class="alert alert-danger">Error loading assignment data: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            assignmentInterface.innerHTML = '<div class="alert alert-danger">Failed to load assignment interface. Please try again.</div>';
        });
    }

    function renderAssignmentInterface(data) {
        let html = '';

        // Render each alternative group
        data.groups.forEach(group => {
            html += renderAlternativeGroup(group, data.assignments[group.alt_id] || {}, data.unassigned);
        });

        assignmentInterface.innerHTML = html;
        
        // Initialize bulk selection
        initializeBulkSelection();
    }

    function renderAlternativeGroup(group, assignments, unassigned) {
        const subjectIds = JSON.parse(group.subject_ids);

        let html = `
            <div class="card mb-4" data-group-id="${group.alt_id}">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-random me-2"></i>${group.group_name}
                    </h5>
                </div>
                <div class="card-body">
        `;

        // Unassigned students for this group
        const groupUnassigned = unassigned.filter(student => 
            !Object.values(assignments).some(subjectStudents => 
                subjectStudents.some(assignedStudent => assignedStudent.student_id === student.student_id)
            )
        );

        if (groupUnassigned.length > 0) {
            html += `
                <div class="unassigned-section" data-group-id="${group.alt_id}">
                    <div class="unassigned-header">
                        <i class="fas fa-users me-2"></i>Unassigned Students (${groupUnassigned.length})
                        <button type="button" class="btn btn-sm btn-outline-dark ms-2" onclick="selectAllUnassigned(${group.alt_id})">
                            Select All
                        </button>
                    </div>
                    <div class="student-list" id="unassigned-${group.alt_id}">
            `;
            
            groupUnassigned.forEach(student => {
                html += renderStudentCard(student);
            });
            
            html += `
                    </div>
                    <div class="mt-3">
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <span class="text-muted me-2">Move selected to:</span>
            `;
            
            // Add move buttons for each subject
            subjectIds.forEach(subjectId => {
                const subjectName = subjectsLookup[subjectId] || 'Unknown Subject';
                html += `
                    <button type="button" class="btn btn-outline-primary btn-sm" 
                            onclick="moveSelectedToSubject(${subjectId}, ${group.alt_id})">
                        <i class="fas fa-arrow-right me-1"></i>${subjectName}
                    </button>
                `;
            });
            
            html += `
                        </div>
                    </div>
                </div>
            `;
        }

        // Subject columns with arrow navigation
        html += '<div class="row">';
        subjectIds.forEach((subjectId, index) => {
            const subjectName = subjectsLookup[subjectId] || 'Unknown Subject';
            const subjectStudents = assignments[subjectId] || [];
            const isFirst = index === 0;
            const isLast = index === subjectIds.length - 1;
            const nextSubjectId = isLast ? null : subjectIds[index + 1];
            const prevSubjectId = isFirst ? null : subjectIds[index - 1];
            
            html += `
                <div class="col-md-${Math.floor(12 / subjectIds.length)}">
                    <div class="subject-column" 
                         data-subject-id="${subjectId}" 
                         data-group-id="${group.alt_id}">
                        <div class="subject-header">
                            <h5>${subjectName}</h5>
                            <div class="subject-count" id="count-${subjectId}">${subjectStudents.length} students</div>
                        </div>
                        <div class="subject-students">
                            <div class="student-list" id="subject-${subjectId}">
            `;
            
            subjectStudents.forEach(student => {
                html += renderStudentCard(student);
            });
            
            html += `
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
            `;
            
            // Left arrow (move to previous subject)
            if (!isFirst) {
                html += `
                    <button type="button" class="btn btn-outline-success btn-sm flex-fill me-1" 
                            onclick="moveSelectedBetweenSubjects(${subjectId}, ${prevSubjectId}, ${group.alt_id}, 'left')"
                            title="Move selected to ${subjectsLookup[prevSubjectId]}">
                        <i class="fas fa-arrow-left me-1"></i>${subjectsLookup[prevSubjectId].substring(0, 8)}...
                    </button>
                `;
            } else {
                html += `<div class="flex-fill me-1"></div>`;
            }
            
            // Right arrow (move to next subject)  
            if (!isLast) {
                html += `
                    <button type="button" class="btn btn-outline-success btn-sm flex-fill ms-1" 
                            onclick="moveSelectedBetweenSubjects(${subjectId}, ${nextSubjectId}, ${group.alt_id}, 'right')"
                            title="Move selected to ${subjectsLookup[nextSubjectId]}">
                        ${subjectsLookup[nextSubjectId].substring(0, 8)}...<i class="fas fa-arrow-right ms-1"></i>
                    </button>
                `;
            } else {
                html += `<div class="flex-fill ms-1"></div>`;
            }
            
            html += `
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                                    onclick="selectAllInSubject(${subjectId}, ${group.alt_id})">
                                <i class="fas fa-check-square me-1"></i>Select All in ${subjectName.substring(0, 10)}
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        // Bulk actions
        html += `
            <div class="bulk-actions mt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted" id="selected-count-${group.alt_id}">0 students selected</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="autoBalanceGroup(${group.alt_id})">
                            <i class="fas fa-balance-scale me-1"></i>Auto Balance
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelections(${group.alt_id})">
                            Clear Selection
                        </button>
                    </div>
                </div>
            </div>
        `;

        html += `
                </div>
            </div>
        `;

        return html;
    }

    function renderStudentCard(student) {
        return `
            <div class="student-item" 
                 data-student-id="${student.student_id}"
                 data-student-name="${student.student_name}">
                <input type="checkbox" class="form-check-input me-2" 
                       onchange="updateSelectionCount()">
                <span class="student-name-plain">${student.student_name}</span>
            </div>
        `;
    }

    // Global functions for arrow-based movement
    window.moveSelectedBetweenSubjects = function(fromSubjectId, toSubjectId, groupId, direction) {
        console.log('moveSelectedBetweenSubjects called:', {fromSubjectId, toSubjectId, groupId, direction});
        const fromSubjectElement = document.getElementById(`subject-${fromSubjectId}`);
        if (!fromSubjectElement) {
            alert('Subject element not found: subject-' + fromSubjectId);
            return;
        }
        
        const selectedCheckboxes = fromSubjectElement.querySelectorAll('input[type="checkbox"]:checked');
        const studentIds = [];
        
        selectedCheckboxes.forEach(checkbox => {
            const item = checkbox.closest('.student-item');
            if (item && item.dataset.studentId) {
                studentIds.push(item.dataset.studentId);
            }
        });
        
        if (studentIds.length === 0) {
            alert(`Please select students from ${subjectsLookup[fromSubjectId]} to move.`);
            return;
        }
        
        const directionText = direction === 'left' ? '←' : '→';
        const confirmation = confirm(
            `Move ${studentIds.length} selected student(s) ${directionText} from ${subjectsLookup[fromSubjectId]} to ${subjectsLookup[toSubjectId]}?`
        );
        
        if (confirmation) {
            moveStudentsToSubject(studentIds, toSubjectId, groupId);
        }
    };

    window.selectAllInSubject = function(subjectId, groupId) {
        const subjectElement = document.getElementById(`subject-${subjectId}`);
        const checkboxes = subjectElement.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = true);
        updateSelectionCount();
    };

    function initializeBulkSelection() {
        // Add click handlers for student selection
        document.querySelectorAll('.student-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.type !== 'checkbox') {
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                    updateSelectionCount();
                }
            });
        });
    }

    function updateSelectionCount() {
        currentAlternativeGroups.forEach(group => {
            const groupElement = document.querySelector(`[data-group-id="${group.alt_id}"]`);
            const selectedCheckboxes = groupElement.querySelectorAll('input[type="checkbox"]:checked');
            const countElement = document.getElementById(`selected-count-${group.alt_id}`);
            
            if (countElement) {
                countElement.textContent = `${selectedCheckboxes.length} students selected`;
            }
        });
    }

    // Global functions for button onclick handlers
    window.selectAllUnassigned = function(groupId) {
        const unassignedSection = document.getElementById(`unassigned-${groupId}`);
        const checkboxes = unassignedSection.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = true);
        updateSelectionCount();
    };

    window.clearSelections = function(groupId) {
        const groupElement = document.querySelector(`[data-group-id="${groupId}"]`);
        const checkboxes = groupElement.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = false);
        updateSelectionCount();
    };

    window.moveSelectedToSubject = function(subjectId, groupId) {
        console.log('moveSelectedToSubject called:', {subjectId, groupId});
        const groupElement = document.querySelector(`[data-group-id="${groupId}"]`);
        if (!groupElement) {
            alert('Group element not found: data-group-id=' + groupId);
            return;
        }
        
        const selectedCheckboxes = groupElement.querySelectorAll('input[type="checkbox"]:checked');
        const studentIds = [];
        
        selectedCheckboxes.forEach(checkbox => {
            const item = checkbox.closest('.student-item');
            if (item && item.dataset.studentId) {
                studentIds.push(item.dataset.studentId);
            }
        });
        
        if (studentIds.length === 0) {
            alert('Please select at least one student to move.');
            return;
        }
        
        const subjectName = subjectsLookup[subjectId];
        const confirmation = confirm(
            `Move ${studentIds.length} selected student(s) to ${subjectName}?`
        );
        
        if (confirmation) {
            moveStudentsToSubject(studentIds, subjectId, groupId);
        }
    };

    window.autoBalanceGroup = function(groupId) {
        if (confirm('This will automatically distribute unassigned students evenly across all subjects in this group. Continue?')) {
            fetch('../api/subject_assignments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=auto_balance_group&group_id=${groupId}&csrf_token=<?php echo generateCSRFToken(); ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadAssignmentInterface(currentClassId); // Reload interface
                    alert(data.message);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
    };

    function autoBalanceAllGroups() {
        fetch('../api/subject_assignments.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=auto_balance_all&class_id=${currentClassId}&csrf_token=<?php echo generateCSRFToken(); ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadAssignmentInterface(currentClassId); // Reload interface
                alert(data.message);
            } else {
                alert('Error: ' + data.message);
            }
        });
    }

    function moveStudentToSubject(studentId, subjectId, groupId) {
        moveStudentsToSubject([studentId], subjectId, groupId);
    }

    function moveStudentsToSubject(studentIds, subjectId, groupId) {
        fetch('../api/subject_assignments.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=assign_students&student_ids=${JSON.stringify(studentIds)}&subject_id=${subjectId}&group_id=${groupId}&class_id=${currentClassId}&csrf_token=<?php echo generateCSRFToken(); ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadAssignmentInterface(currentClassId); // Reload interface
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('An error occurred while moving students. Please try again.');
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>