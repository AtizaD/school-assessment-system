<?php
// admin/classes.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($success)) {
    // Redirect to prevent form resubmission
    header('Location: classes.php?success=' . urlencode($success));
    exit;
}

$error = '';
$success = '';
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Helper function to generate class names in sequence
function generateClassNames($prefix, $start, $end)
{
    $classes = [];
    $startNum = intval(preg_replace('/[^0-9]/', '', $start));
    $endNum = intval(preg_replace('/[^0-9]/', '', $end));
    $pattern = preg_replace('/[0-9]+/', '', $start); // Extract the pattern without numbers

    for ($i = $startNum; $i <= $endNum; $i++) {
        $classes[] = $prefix . $pattern . $i;
    }
    return $classes;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $db = DatabaseConfig::getInstance()->getConnection();

        switch ($_POST['action']) {
            case 'create':
                $programId = sanitizeInput($_POST['program_id']);
                $level = sanitizeInput($_POST['level']);
                $createType = sanitizeInput($_POST['create_type']);
                $successCount = 0;
                $errorCount = 0;

                try {
                    if ($createType === 'range') {
                        $prefix = sanitizeInput($_POST['class_prefix']);
                        $startRange = sanitizeInput($_POST['start_range']);
                        $endRange = sanitizeInput($_POST['end_range']);

                        $classNames = generateClassNames($prefix, $startRange, $endRange);

                        $stmt = $db->prepare(
                            "INSERT INTO Classes (program_id, level, class_name) 
                             VALUES (?, ?, ?)"
                        );

                        foreach ($classNames as $className) {
                            try {
                                if ($stmt->execute([$programId, $level, $className])) {
                                    $successCount++;
                                }
                            } catch (PDOException $e) {
                                $errorCount++;
                                continue;
                            }
                        }

                        if ($successCount > 0) {
                            logSystemActivity(
                                'Class Management',
                                "Created $successCount classes in range $startRange to $endRange",
                                'INFO'
                            );
                            $success = "Successfully created $successCount classes" .
                                ($errorCount > 0 ? " ($errorCount failed)" : "");
                        } else {
                            $error = 'Failed to create any classes';
                        }
                    } else {
                        // Single class creation
                        $className = sanitizeInput($_POST['class_name']);
                        $stmt = $db->prepare(
                            "INSERT INTO Classes (program_id, level, class_name) 
                             VALUES (?, ?, ?)"
                        );
                        if ($stmt->execute([$programId, $level, $className])) {
                            logSystemActivity(
                                'Class Management',
                                "New class created: $className in level $level",
                                'INFO'
                            );
                            $success = 'Class created successfully';
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to create class(es). Some names may already exist.';
                    logError("Class creation failed: " . $e->getMessage());
                }
                break;

            case 'update':
                $classId = sanitizeInput($_POST['class_id']);
                $programId = sanitizeInput($_POST['program_id']);
                $level = sanitizeInput($_POST['level']);
                $className = sanitizeInput($_POST['class_name']);

                try {
                    $stmt = $db->prepare(
                        "UPDATE Classes 
                         SET program_id = ?, level = ?, class_name = ? 
                         WHERE class_id = ?"
                    );
                    if ($stmt->execute([$programId, $level, $className, $classId])) {
                        logSystemActivity(
                            'Class Management',
                            "Class updated: $className",
                            'INFO'
                        );
                        $success = 'Class updated successfully';
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to update class. Name may already exist.';
                    logError("Class update failed: " . $e->getMessage());
                }
                break;

            case 'delete':
                $classId = sanitizeInput($_POST['class_id']);
                try {
                    // Check if class has students
                    $stmt = $db->prepare("SELECT COUNT(*) FROM Students WHERE class_id = ?");
                    $stmt->execute([$classId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete class with enrolled students');
                    }

                    // Check if class has assessments
                    $stmt = $db->prepare("SELECT COUNT(*) FROM AssessmentClasses WHERE class_id = ?");
                    $stmt->execute([$classId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete class with existing assessments');
                    }

                    $stmt = $db->prepare("DELETE FROM Classes WHERE class_id = ?");
                    if ($stmt->execute([$classId])) {
                        logSystemActivity(
                            'Class Management',
                            "Class deleted: ID $classId",
                            'INFO'
                        );
                        $success = 'Class deleted successfully';
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Class deletion failed: " . $e->getMessage());
                }
                break;

            case 'assign_teacher':
                $classId = sanitizeInput($_POST['class_id']);
                $teacherId = sanitizeInput($_POST['teacher_id']);
                $subjectId = sanitizeInput($_POST['subject_id']);
                $semesterId = sanitizeInput($_POST['semester_id']);

                try {
                    $stmt = $db->prepare(
                        "INSERT INTO TeacherClassAssignments 
                         (teacher_id, class_id, subject_id, semester_id, is_primary_instructor) 
                         VALUES (?, ?, ?, ?, TRUE)"
                    );
                    if ($stmt->execute([$teacherId, $classId, $subjectId, $semesterId])) {
                        logSystemActivity(
                            'Class Management',
                            "Teacher assigned to class: Class ID $classId, Teacher ID $teacherId",
                            'INFO'
                        );
                        $success = 'Teacher assigned successfully';
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to assign teacher. Assignment may already exist.';
                    logError("Teacher assignment failed: " . $e->getMessage());
                }
                break;

            case 'remove_teacher':
                $assignmentId = sanitizeInput($_POST['assignment_id']);
                try {
                    // Get assignment details for logging before deletion
                    $stmt = $db->prepare(
                        "SELECT tca.class_id, tca.teacher_id, c.class_name, 
                                    CONCAT(t.first_name, ' ', t.last_name) as teacher_name
                             FROM TeacherClassAssignments tca
                             JOIN Classes c ON tca.class_id = c.class_id
                             JOIN Teachers t ON tca.teacher_id = t.teacher_id
                             WHERE tca.assignment_id = ?"
                    );
                    $stmt->execute([$assignmentId]);
                    $assignmentInfo = $stmt->fetch();

                    if (!$assignmentInfo) {
                        throw new Exception('Teacher assignment not found');
                    }

                    // Delete the assignment
                    $stmt = $db->prepare("DELETE FROM TeacherClassAssignments WHERE assignment_id = ?");
                    if ($stmt->execute([$assignmentId])) {
                        logSystemActivity(
                            'Class Management',
                            "Teacher unassigned from class: {$assignmentInfo['teacher_name']} from {$assignmentInfo['class_name']}",
                            'INFO'
                        );
                        $success = 'Teacher unassigned successfully';
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    logError("Teacher unassignment failed: " . $e->getMessage());
                }
                break;
        }
    }
}

// Get all classes with related information
$db = DatabaseConfig::getInstance()->getConnection();
$stmt = $db->query(
    "SELECT c.*, p.program_name,
            COUNT(DISTINCT s.student_id) as student_count,
            GROUP_CONCAT(DISTINCT CONCAT(tca.assignment_id, ':', t.first_name, ' ', t.last_name, ':', subj.subject_name) SEPARATOR '|') as teacher_data
     FROM Classes c
     JOIN Programs p ON c.program_id = p.program_id
     LEFT JOIN Students s ON c.class_id = s.class_id
     LEFT JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
     LEFT JOIN Teachers t ON tca.teacher_id = t.teacher_id
     LEFT JOIN Subjects subj ON tca.subject_id = subj.subject_id
     GROUP BY c.class_id
     ORDER BY CAST(SUBSTRING_INDEX(c.level, ' ', -1) AS UNSIGNED),
         SUBSTRING_INDEX(c.level, ' ', 1),
         p.program_name ASC,
         c.class_name ASC"
);
$classes = $stmt->fetchAll();

// Get programs for dropdown
$stmt = $db->query("SELECT program_id, program_name FROM Programs ORDER BY program_name");
$programs = $stmt->fetchAll();

// Get available teachers
$stmt = $db->query(
    "SELECT teacher_id, first_name, last_name 
     FROM Teachers 
     ORDER BY last_name, first_name"
);
$teachers = $stmt->fetchAll();

// Get subjects
$stmt = $db->query("SELECT subject_id, subject_name FROM Subjects ORDER BY subject_name");
$subjects = $stmt->fetchAll();

// Get current semester
$stmt = $db->query(
    "SELECT semester_id, semester_name 
     FROM Semesters 
     WHERE start_date <= CURRENT_DATE AND end_date >= CURRENT_DATE"
);
$currentSemester = $stmt->fetch();

// Group classes by program and level for better organization
$classGroups = [];
foreach ($classes as $class) {
    $programId = $class['program_id'];
    $level = $class['level'];
    
    if (!isset($classGroups[$programId])) {
        $classGroups[$programId] = [
            'program_name' => $class['program_name'],
            'levels' => []
        ];
    }
    
    if (!isset($classGroups[$programId]['levels'][$level])) {
        $classGroups[$programId]['levels'][$level] = [];
    }
    
    $classGroups[$programId]['levels'][$level][] = $class;
}

$pageTitle = 'Class Management';
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
    
    .program-card {
        transition: all 0.3s ease;
    }
    
    .program-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .level-header {
        background: #f8f9fa;
        border-left: 4px solid #ffd700;
        padding: 10px 15px;
        margin-bottom: 1rem;
        font-weight: 600;
    }
    
    .class-card {
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        transition: all 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    
    .class-card:hover {
        border-color: #ffd700;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    }
    
    .class-card .badge-corner {
        position: absolute;
        top: 0;
        right: 0;
        padding: 8px;
        border-bottom-left-radius: 8px;
        font-size: 0.75rem;
    }
    
    .class-info {
        margin-bottom: 0.75rem;
    }
    
    .class-name {
        font-size: 1.1rem;
        font-weight: 600;
        color: #333;
    }
    
    .class-meta {
        display: flex;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }
    
    .class-meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #666;
        font-size: 0.9rem;
    }
    
    .teacher-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #eee;
    }
    
    .teacher-item:last-child {
        border-bottom: none;
    }
    
    .teacher-name {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .action-btn {
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
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
    
    .filter-dropdown {
        margin-left: 0.5rem;
    }

    /* Modal enhancements */
    .modal-header {
        background: linear-gradient(90deg, #000000, #ffd700);
        color: white;
        border-bottom: none;
    }
    
    .modal-footer {
        border-top: 1px solid #eee;
    }
    
    .modal-content {
        border: none;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    /* Tab UI for class creation */
    .creation-tabs {
        display: flex;
        border-bottom: 1px solid #ddd;
        margin-bottom: 1rem;
    }
    
    .creation-tab {
        padding: 0.75rem 1.5rem;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease;
    }
    
    .creation-tab.active {
        border-bottom-color: #ffd700;
        font-weight: 600;
    }
    
    .creation-tab:hover:not(.active) {
        background-color: #f8f9fa;
    }
    
    /* Empty state styling */
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

    /* Teacher list styling */
    .teachers-list {
        max-height: 200px;
        overflow-y: auto;
        padding: 0.5rem;
        background-color: #f8f9fa;
        border-radius: 4px;
    }

    .nav-pills .nav-link.active {
        background-color: #ffd700;
        color: #000;
    }
    
    .nav-pills .nav-link {
        color: #666;
    }
    
    .class-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
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
</style>

<script>
    // Function to handle creation type toggle
    function toggleCreationType(type) {
        const singleFields = document.getElementById('singleClassFields');
        const rangeFields = document.getElementById('rangeFields');

        if (type === 'single') {
            singleFields.style.display = 'block';
            rangeFields.style.display = 'none';
            document.querySelector('input[name="class_name"]').required = true;
            document.querySelector('input[name="class_prefix"]').required = false;
            document.querySelector('input[name="start_range"]').required = false;
            document.querySelector('input[name="end_range"]').required = false;
            
            // Update active tab visualization
            document.getElementById('single-tab').classList.add('active');
            document.getElementById('range-tab').classList.remove('active');
        } else {
            singleFields.style.display = 'none';
            rangeFields.style.display = 'block';
            document.querySelector('input[name="class_name"]').required = false;
            document.querySelector('input[name="class_prefix"]').required = true;
            document.querySelector('input[name="start_range"]').required = true;
            document.querySelector('input[name="end_range"]').required = true;
            
            // Update active tab visualization
            document.getElementById('single-tab').classList.remove('active');
            document.getElementById('range-tab').classList.add('active');
        }
    }

    // Declare modal variables globally
    let editClassModal;
    let assignTeacherModal;
    let deleteClassModal;
    let removeTeacherModal;
    
    // Edit class function
    function editClass(classData) {
        document.getElementById('editClassId').value = classData.class_id;
        document.getElementById('editProgramId').value = classData.program_id;
        document.getElementById('editLevel').value = classData.level;
        document.getElementById('editClassName').value = classData.class_name;
        editClassModal.show();
    }

    // Assign teacher function
    function assignTeacher(classId, className) {
        document.getElementById('assignClassId').value = classId;
        document.getElementById('assignClassName').textContent = className;
        assignTeacherModal.show();
    }

    // Delete class function
    function deleteClass(classId, className) {
        document.getElementById('deleteClassId').value = classId;
        document.getElementById('deleteClassName').textContent = className;
        deleteClassModal.show();
    }

    // Remove teacher function
    function removeTeacher(assignmentId, teacherName, className) {
        document.getElementById('removeAssignmentId').value = assignmentId;
        document.getElementById('removeTeacherName').textContent = teacherName;
        document.getElementById('removeClassName').textContent = className;
        removeTeacherModal.show();
    }
    
    // Function to filter classes
    function filterClasses() {
        const searchInput = document.getElementById('classSearch').value.toLowerCase();
        const programFilter = document.getElementById('programFilter').value;
        
        // Get all classes
        const classCards = document.querySelectorAll('.class-card');
        const levelHeaders = document.querySelectorAll('.level-header');
        const programCards = document.querySelectorAll('.program-card');
        
        // Reset visibility
        levelHeaders.forEach(header => header.style.display = 'block');
        programCards.forEach(card => card.style.display = 'block');
        
        // Track which programs and levels have visible classes
        const visibleLevels = new Set();
        const visiblePrograms = new Set();
        
        // Filter class cards
        classCards.forEach(card => {
            const className = card.getAttribute('data-class-name').toLowerCase();
            const classProgram = card.getAttribute('data-program-id');
            const classLevel = card.getAttribute('data-level');
            
            const matchesSearch = className.includes(searchInput);
            const matchesProgram = programFilter === '' || classProgram === programFilter;
            
            if (matchesSearch && matchesProgram) {
                card.style.display = 'block';
                visibleLevels.add(classLevel + '-' + classProgram);
                visiblePrograms.add(classProgram);
            } else {
                card.style.display = 'none';
            }
        });
        
        // Hide empty level headers
        levelHeaders.forEach(header => {
            const levelId = header.getAttribute('data-level-id');
            const programId = header.getAttribute('data-program-id');
            if (!visibleLevels.has(levelId + '-' + programId)) {
                header.style.display = 'none';
            }
        });
        
        // Hide empty program cards
        programCards.forEach(card => {
            const programId = card.getAttribute('data-program-id');
            if (!visiblePrograms.has(programId)) {
                card.style.display = 'none';
            }
        });
    }

    // Toggle collapse functions for program cards
    function toggleProgram(programId) {
        const programContent = document.getElementById('program-content-' + programId);
        const toggleIcon = document.getElementById('toggle-icon-' + programId);
        
        if (programContent.style.display === 'none') {
            programContent.style.display = 'block';
            toggleIcon.classList.remove('fa-chevron-down');
            toggleIcon.classList.add('fa-chevron-up');
        } else {
            programContent.style.display = 'none';
            toggleIcon.classList.remove('fa-chevron-up');
            toggleIcon.classList.add('fa-chevron-down');
        }
    }

    // Extended JavaScript for better functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize modals
        initializeModals();
        initializeTooltips();
        
        // Explicitly initialize dropdowns after everything else
        setTimeout(function() {
            initializeDropdowns();
        }, 100);
        
        // Add input event listeners for class range preview
        setupClassRangePreview();
        
        // Auto-dismiss alerts
        setupAutoDismissAlerts();
        
        // Set up search and filter listeners
        document.getElementById('classSearch').addEventListener('input', filterClasses);
        document.getElementById('programFilter').addEventListener('change', filterClasses);
        
        // Update the create type when clicking tabs
        document.getElementById('single-tab').addEventListener('click', function() {
            document.getElementById('createType').value = 'single';
        });
        
        document.getElementById('range-tab').addEventListener('click', function() {
            document.getElementById('createType').value = 'range';
        });
    });

    // Initialize dropdowns explicitly
    function initializeDropdowns() {
        if (typeof bootstrap !== 'undefined') {
            // Initialize dropdowns, but skip the profile dropdown to avoid conflicts
            const dropdownElementList = document.querySelectorAll('[data-bs-toggle="dropdown"]:not(#navbarDropdown)');
            
            dropdownElementList.forEach(function (dropdownToggleEl) {
                try {
                    new bootstrap.Dropdown(dropdownToggleEl);
                } catch (e) {
                    // Silently skip failed initializations
                }
            });
            
            // Only use manual implementation for profile dropdown to avoid conflicts
            setupManualProfileDropdown();
        }
    }
    
    // Manual profile dropdown setup as reliable fallback
    function setupManualProfileDropdown() {
        const profileDropdown = document.getElementById('navbarDropdown');
        if (!profileDropdown) {
            return;
        }
        
        // Remove any existing manual handlers
        const newDropdown = profileDropdown.cloneNode(true);
        profileDropdown.parentNode.replaceChild(newDropdown, profileDropdown);
        
        // Ensure dropdown starts in closed state
        const dropdownMenu = newDropdown.nextElementSibling;
        if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
            dropdownMenu.classList.remove('show');
            newDropdown.setAttribute('aria-expanded', 'false');
        }
        
        // Add click handler
        newDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const dropdownMenu = this.nextElementSibling;
            if (!dropdownMenu || !dropdownMenu.classList.contains('dropdown-menu')) {
                return;
            }
            
            // Force close all dropdowns first, including this one
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
                if (menu.previousElementSibling) {
                    menu.previousElementSibling.setAttribute('aria-expanded', 'false');
                }
            });
            
            // Small delay to ensure state is clean, then open this dropdown
            setTimeout(() => {
                dropdownMenu.classList.add('show');
                newDropdown.setAttribute('aria-expanded', 'true');
            }, 50);
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!newDropdown.contains(e.target) && !e.target.closest('.dropdown-menu')) {
                const dropdownMenu = newDropdown.nextElementSibling;
                if (dropdownMenu && dropdownMenu.classList.contains('show')) {
                    dropdownMenu.classList.remove('show');
                    newDropdown.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }
    
    // Initialize all modals
    function initializeModals() {
        editClassModal = new bootstrap.Modal(document.getElementById('editClassModal'));
        assignTeacherModal = new bootstrap.Modal(document.getElementById('assignTeacherModal'));
        deleteClassModal = new bootstrap.Modal(document.getElementById('deleteClassModal'));
        removeTeacherModal = new bootstrap.Modal(document.getElementById('removeTeacherModal'));
    }
    
    // Initialize tooltips
    function initializeTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            new bootstrap.Tooltip(tooltipTriggerEl, {
                boundary: document.body
            });
        });
    }
    
    // Auto-dismiss alerts
    function setupAutoDismissAlerts() {
        const alerts = document.querySelectorAll('.alert:not(.alert-danger):not(.alert-info)');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    }
    
    // Set up class range preview
    function setupClassRangePreview() {
        // Get range inputs
        const prefixInput = document.querySelector('input[name="class_prefix"]');
        const startInput = document.querySelector('input[name="start_range"]');
        const endInput = document.querySelector('input[name="end_range"]');
        const previewContainer = document.getElementById('classPreview');
        
        // Add input listeners
        [prefixInput, startInput, endInput].forEach(input => {
            if (input) {
                input.addEventListener('input', updateClassPreview);
            }
        });
        
        // Function to update preview
        function updateClassPreview() {
            if (!prefixInput || !startInput || !endInput || !previewContainer) return;
            
            const prefix = prefixInput.value;
            const start = startInput.value;
            const end = endInput.value;
            
            if (!start || !end) {
                previewContainer.innerHTML = '<span class="text-muted">Enter start and end values to see a preview</span>';
                return;
            }
            
            try {
                // Extract number components
                const startNum = parseInt(start.replace(/[^\d]/g, ''), 10);
                const endNum = parseInt(end.replace(/[^\d]/g, ''), 10);
                
                if (isNaN(startNum) || isNaN(endNum) || startNum > endNum) {
                    previewContainer.innerHTML = '<span class="text-danger">Invalid range. Start number must be less than or equal to end number.</span>';
                    return;
                }
                
                // Get the non-numeric part
                const pattern = start.replace(/\d+/g, '');
                
                // Generate preview
                const classNames = [];
                const MAX_PREVIEW = 10; // Maximum number of classes to show in preview
                
                for (let i = startNum; i <= endNum && i < startNum + MAX_PREVIEW; i++) {
                    classNames.push(`${prefix}${pattern}${i}`);
                }
                
                if (endNum - startNum + 1 > MAX_PREVIEW) {
                    classNames.push('...');
                    classNames.push(`${prefix}${pattern}${endNum}`);
                }
                
                previewContainer.innerHTML = `
                    <div class="preview-classes">
                        ${classNames.map(name => `<span class="badge bg-light text-dark me-2 mb-2">${name}</span>`).join('')}
                    </div>
                    <div class="mt-2 small text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        ${endNum - startNum + 1} classes will be created
                    </div>
                `;
            } catch (e) {
                previewContainer.innerHTML = '<span class="text-danger">Error generating preview</span>';
            }
        }
    }
</script>

<!-- Main Content -->
<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 text-dark mb-1">Class Management</h1>
            <p class="text-muted mb-0">Create, manage and organize your classes and teacher assignments</p>
        </div>
        <div>
            <a href="teacher_assignments.php" class="btn btn-outline-primary me-2">
                <i class="fas fa-chalkboard-teacher me-2"></i>Teacher Assignments
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                <i class="fas fa-plus-circle me-2"></i>Add New Class
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
                    <i class="fas fa-school"></i>
                </div>
                <div class="stat-value"><?php echo count($classes); ?></div>
                <div class="stat-label">Total Classes</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $totalStudents = 0;
                    foreach ($classes as $class) {
                        $totalStudents += $class['student_count'];
                    }
                    echo $totalStudents;
                    ?>
                </div>A
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-value"><?php echo count($teachers); ?></div>
                <div class="stat-label">Available Teachers</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-value"><?php echo count($subjects); ?></div>
                <div class="stat-label">Available Subjects</div>
            </div>
        </div>
    </div>

    <!-- Search and Filter Tools -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="classSearch" class="form-control" placeholder="Search classes...">
                    </div>
                </div>
                <div class="col-md-6 d-flex justify-content-end">
                    <div class="d-flex align-items-center">
                        <label class="me-2">Filter by Program:</label>
                        <select id="programFilter" class="form-select form-select-sm" style="width: auto;">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['program_id']; ?>">
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Classes Display - Card View -->
    <?php if (empty($classGroups)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-school"></i>
            </div>
            <h4>No Classes Found</h4>
            <p class="text-muted">Get started by adding your first class</p>
            <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addClassModal">
                <i class="fas fa-plus-circle me-2"></i>Add New Class
            </button>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($classGroups as $programId => $programData): ?>
                <div class="col-12 mb-4 program-card" data-program-id="<?php echo $programId; ?>">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($programData['program_name']); ?>
                            </h5>
                            <button class="toggle-btn" onclick="toggleProgram('<?php echo $programId; ?>')" 
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Toggle Program View">
                                <i id="toggle-icon-<?php echo $programId; ?>" class="fas fa-chevron-up"></i>
                            </button>
                        </div>
                        <div class="card-body" id="program-content-<?php echo $programId; ?>">
                            <?php foreach ($programData['levels'] as $level => $levelClasses): ?>
                                <div class="level-header mb-3" data-level-id="<?php echo htmlspecialchars($level); ?>" data-program-id="<?php echo $programId; ?>">
                                    <i class="fas fa-layer-group me-2"></i><?php echo htmlspecialchars($level); ?>
                                </div>
                                <div class="row mb-4">
                                    <?php foreach ($levelClasses as $class): ?>
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <div class="class-card" 
                                                data-class-name="<?php echo htmlspecialchars($class['class_name']); ?>"
                                                data-program-id="<?php echo $class['program_id']; ?>"
                                                data-level="<?php echo htmlspecialchars($class['level']); ?>">
                                                
                                                <div class="badge-corner bg-primary text-white">
                                                    <i class="fas fa-user-graduate me-1"></i><?php echo $class['student_count']; ?>
                                                </div>
                                                
                                                <div class="class-info">
                                                    <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                                    <div class="class-meta">
                                                        <div class="class-meta-item">
                                                            <i class="fas fa-graduation-cap text-muted"></i>
                                                            <span><?php echo htmlspecialchars($programData['program_name']); ?></span>
                                                        </div>
                                                        <div class="class-meta-item">
                                                            <i class="fas fa-layer-group text-muted"></i>
                                                            <span><?php echo htmlspecialchars($class['level']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="class-section">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h6 class="mb-0">Teachers</h6>
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                            onclick="assignTeacher(<?php echo $class['class_id']; ?>, '<?php echo htmlspecialchars(addslashes($class['class_name'])); ?>')"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Assign Teacher">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <div class="teachers-list">
                                                        <?php if (!empty($class['teacher_data'])): ?>
                                                            <?php 
                                                            $teachersList = explode('|', $class['teacher_data']);
                                                            foreach ($teachersList as $teacherInfo): 
                                                                list($assignmentId, $teacherName, $subjectName) = explode(':', $teacherInfo);
                                                            ?>
                                                                <div class="teacher-item">
                                                                    <div class="teacher-name">
                                                                        <i class="fas fa-user text-muted"></i>
                                                                        <span><?php echo htmlspecialchars($teacherName); ?>
                                                                            <small class="text-muted">(<?php echo htmlspecialchars($subjectName); ?>)</small>
                                                                        </span>
                                                                    </div>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                        onclick="removeTeacher(<?php echo $assignmentId; ?>, '<?php echo htmlspecialchars(addslashes($teacherName)); ?>', '<?php echo htmlspecialchars(addslashes($class['class_name'])); ?>')"
                                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Remove Teacher">
                                                                        <i class="fas fa-times"></i>
                                                                    </button>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="text-center text-muted py-3">
                                                                <i class="fas fa-user-slash me-2"></i>No teachers assigned
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="class-actions mt-3">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="editClass(<?php echo htmlspecialchars(json_encode($class, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Class">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </button>
                                                    
                                                    <?php if ($class['student_count'] == 0): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                            onclick="deleteClass(<?php echo $class['class_id']; ?>, '<?php echo htmlspecialchars(addslashes($class['class_name'])); ?>')"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Class">
                                                            <i class="fas fa-trash me-1"></i> Delete
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Cannot delete class with students">
                                                            <i class="fas fa-trash me-1"></i> Delete
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Add Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="classes.php" id="addClassForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- Creation Type Tabs -->
                    <div class="creation-tabs mb-4">
                        <div id="single-tab" class="creation-tab active" onclick="toggleCreationType('single')">
                            <i class="fas fa-chalkboard me-2"></i>Single Class
                        </div>
                        <div id="range-tab" class="creation-tab" onclick="toggleCreationType('range')">
                            <i class="fas fa-th me-2"></i>Multiple Classes (Range)
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Program</label>
                                <select name="program_id" class="form-select" required>
                                    <option value="">-- Select Program --</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo $program['program_id']; ?>">
                                            <?php echo htmlspecialchars($program['program_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Program the class belongs to
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Level</label>
                                <input type="text" name="level" class="form-control" required
                                    placeholder="e.g., SHS 1, Grade 10, Primary 6">
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>Educational level of the class
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="create_type" value="single" id="createType">

                    <!-- Single Class Fields -->
                    <div id="singleClassFields">
                        <div class="mb-3">
                            <label class="form-label">Class Name</label>
                            <input type="text" name="class_name" class="form-control"
                                placeholder="e.g., A1, Science, Block 3">
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>Unique name identifier for this class
                            </div>
                        </div>
                    </div>

                    <!-- Range Fields -->
                    <div id="rangeFields" style="display: none;">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="alert alert-info mb-4">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Create Multiple Classes at Once</strong><br>
                                    This will create a series of classes with sequential numbering. For example:<br>
                                    Prefix "1" with range "A1" to "A5" creates: 1A1, 1A2, 1A3, 1A4, 1A5
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Class Prefix (Optional)</label>
                            <input type="text" name="class_prefix" class="form-control"
                                placeholder="e.g., 1, SHS, Room">
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>Text to prepend to each class name
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Start Range</label>
                                    <input type="text" name="start_range" class="form-control"
                                        placeholder="e.g., A1, 1, 101">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>First class name in sequence
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">End Range</label>
                                    <input type="text" name="end_range" class="form-control"
                                        placeholder="e.g., A5, 10, 110">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>Last class name in sequence
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Preview</h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-0">Enter values above to see a preview of classes that will be created</p>
                                    <div id="classPreview" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create Class
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Class Modal -->
<div class="modal fade" id="editClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php" id="editClassForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="class_id" id="editClassId">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <select name="program_id" id="editProgramId" class="form-select" required>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['program_id']; ?>">
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>Note: Program cannot be changed if students are assigned
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Level</label>
                        <input type="text" name="level" id="editLevel" class="form-control" required>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>Educational level for this class
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Class Name</label>
                        <input type="text" name="class_name" id="editClassName" class="form-control" required>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>Unique identifier for this class
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Class
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Teacher Modal -->
<div class="modal fade" id="assignTeacherModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="assign_teacher">
                <input type="hidden" name="class_id" id="assignClassId">
                <input type="hidden" name="semester_id" value="<?php echo $currentSemester ? $currentSemester['semester_id'] : ''; ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Assigning teacher to class: <strong id="assignClassName"></strong> for 
                        <strong><?php echo htmlspecialchars($currentSemester['semester_name'] ?? 'Current Semester'); ?></strong>
                    </div>
                
                    <div class="mb-3">
                        <label class="form-label">Teacher</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['teacher_id']; ?>">
                                    <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>Select the teacher to assign
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>Select the subject the teacher will teach
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Assign Teacher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Class Modal -->
<div class="modal fade" id="deleteClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="class_id" id="deleteClassId">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-exclamation-triangle text-danger fa-4x mb-3"></i>
                        <h4>Confirm Deletion</h4>
                    </div>
                    
                    <div class="alert alert-danger mb-4">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. All data associated with this class will be permanently removed.
                    </div>
                    
                    <p class="text-center mb-0">
                        Are you sure you want to delete class <strong id="deleteClassName" class="text-danger"></strong>?
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete Permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Teacher Modal -->
<div class="modal fade" id="removeTeacherModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="remove_teacher">
                <input type="hidden" name="assignment_id" id="removeAssignmentId">

                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-user-minus me-2"></i>Remove Teacher Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                        <h5>Confirm Teacher Removal</h5>
                    </div>
                    
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> You are about to remove this teacher assignment. This may affect any ongoing courses or assessments.
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-4 text-muted">Teacher:</div>
                                <div class="col-8 fw-bold" id="removeTeacherName"></div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-4 text-muted">Class:</div>
                                <div class="col-8 fw-bold" id="removeClassName"></div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="text-center mb-0">
                        Are you sure you want to remove this teacher assignment?
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-user-minus me-1"></i>Remove Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>