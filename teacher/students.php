<?php
// teacher/students.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
requireRole('teacher');

// Start output buffering
ob_start();

// Check for existing flash messages
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

// Initialize variables to default values
$totalStudents = 0;
$studentsWithAssessments = 0;
$overallAverageScore = 0;
$assessmentCompletionRate = 0;
$students = [];
$teacherAssignments = [];
$semesters = [];
$selectedClass = 0;
$selectedSubject = 0;
$selectedSemester = 0;
$searchQuery = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get current/selected semester using shared component
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $selectedSemester = $currentSemester['semester_id'];
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherInfo['teacher_id'];

    // Get filter parameters
    $selectedClass = isset($_GET['class']) ? intval($_GET['class']) : 0;
    $selectedSubject = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
    $searchQuery = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

    // Get teacher's classes and subjects for filters in the selected semester
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, s.subject_id, s.subject_name
         FROM teacherclassassignments tca
         JOIN classes c ON tca.class_id = c.class_id
         JOIN subjects s ON tca.subject_id = s.subject_id
         WHERE tca.teacher_id = ? AND tca.semester_id = ?
         ORDER BY c.class_name, s.subject_name"
    );
    $stmt->execute([$teacherId, $selectedSemester]);
    $teacherAssignments = $stmt->fetchAll();

    // Build the query to get students based on filters - includes both regular students and special enrollments
    $baseQuery = "
        SELECT DISTINCT 
            s.student_id,
            s.first_name,
            s.last_name,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                ELSE s.class_id
            END as enrolled_class_id,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN sc_c.class_name
                ELSE c.class_name
            END as enrolled_class_name,
            c.class_name as original_class_name,
            p.program_name,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN 'special'
                ELSE 'regular'
            END as enrollment_type,
            sc.notes as special_notes,
            (
                SELECT COUNT(DISTINCT r.result_id) 
                FROM results r
                JOIN assessments a ON r.assessment_id = a.assessment_id
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE r.student_id = s.student_id 
                AND ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE s.class_id
                END
                AND a.semester_id = ?
                AND r.status = 'completed'
            ) as completed_assessments,
            (
                SELECT ROUND(AVG(r.score), 1)
                FROM results r
                JOIN assessments a ON r.assessment_id = a.assessment_id
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE r.student_id = s.student_id 
                AND ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE s.class_id
                END
                AND a.semester_id = ?
                AND r.status = 'completed'
            ) as average_score
        FROM students s
        JOIN classes c ON s.class_id = c.class_id
        JOIN programs p ON c.program_id = p.program_id
        LEFT JOIN special_class sc ON s.student_id = sc.student_id AND sc.status = 'active'
        LEFT JOIN classes sc_c ON sc.class_id = sc_c.class_id
        JOIN teacherclassassignments tca ON (
            (sc.sp_id IS NULL AND tca.class_id = s.class_id) OR
            (sc.sp_id IS NOT NULL AND tca.class_id = sc.class_id AND tca.subject_id = sc.subject_id)
        )
        WHERE tca.teacher_id = ?
        AND tca.semester_id = ?";

    $params = [$selectedSemester, $selectedSemester, $teacherId, $selectedSemester];

    // Add class filter if selected (check both original class and enrolled class for special students)
    if ($selectedClass) {
        $baseQuery .= " AND (c.class_id = ? OR sc.class_id = ?)";
        $params[] = $selectedClass;
        $params[] = $selectedClass;
    }

    // Add subject filter if selected
    if ($selectedSubject) {
        $baseQuery .= " AND tca.subject_id = ?";
        $params[] = $selectedSubject;
    }

    // Add search filter if provided
    if ($searchQuery) {
        $baseQuery .= " AND (s.first_name LIKE ? OR s.last_name LIKE ?)";
        $params[] = "%{$searchQuery}%";
        $params[] = "%{$searchQuery}%";
    }

    $baseQuery .= " ORDER BY s.last_name, s.first_name";

    $stmt = $db->prepare($baseQuery);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // Get summary statistics
    $totalStudents = count($students);
    $studentsWithAssessments = 0;
    $totalAverageScore = 0;
    $scoreCount = 0;

    foreach ($students as $student) {
        if ($student['completed_assessments'] > 0) {
            $studentsWithAssessments++;
        }
        if ($student['average_score'] !== null) {
            $totalAverageScore += $student['average_score'];
            $scoreCount++;
        }
    }

    $overallAverageScore = $scoreCount > 0 ? round($totalAverageScore / $scoreCount, 1) : 0;
    $assessmentCompletionRate = $totalStudents > 0 ? round(($studentsWithAssessments / $totalStudents) * 100, 1) : 0;

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Students page error: " . $e->getMessage());
}

$pageTitle = "Manage Students";
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    .page-gradient {
        min-height: 100vh;
        padding: 1rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #fff9e6 100%);
    }

    .header-gradient {
        background: linear-gradient(90deg, #000000 0%, #ffd700 50%, #ffeb80 100%);
        padding: 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 2rem;
        color: white;
    }

    .filter-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .stats-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        height: 100%;
        border-left: 4px solid #ffd700;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
    }

    .student-card {
        background: white;
        border-radius: 10px;
        border: 1px solid rgba(0,0,0,0.1);
        transition: transform 0.2s;
        margin-bottom: 1rem;
    }

    .student-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .student-card-header {
        background: #f8f9fa;
        padding: 1rem 1.5rem;
        border-radius: 10px 10px 0 0;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .student-card-body {
        padding: 1.5rem;
    }

    .progress {
        height: 8px;
        border-radius: 4px;
        background-color: #f0f0f0;
    }

    .progress-bar {
        background: linear-gradient(90deg, #000000, #ffd700);
    }

    .btn-yellow {
        background: linear-gradient(45deg, #000000, #ffd700);
        border: none;
        color: white;
        transition: all 0.3s ease;
    }

    .btn-yellow:hover {
        background: linear-gradient(45deg, #ffd700, #000000);
        transform: translateY(-1px);
        color: white;
    }

    .score-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
    }

    .score-high {
        background-color: #d4edda;
        color: #155724;
    }

    .score-medium {
        background-color: #fff3cd;
        color: #856404;
    }

    .score-low {
        background-color: #f8d7da;
        color: #721c24;
    }

    .no-data {
        color: #6c757d;
        font-style: italic;
    }

    @media (max-width: 768px) {
        .header-gradient {
            padding: 1rem;
            text-align: center;
        }

        .stats-card {
            margin-bottom: 1rem;
        }

        .filter-section {
            padding: 1rem;
        }

        .student-card-header,
        .student-card-body {
            padding: 1rem;
        }

        .btn-yellow {
            width: 100%;
            margin-bottom: 0.5rem;
        }
    }
</style>

<div class="page-gradient">
    <div class="container-fluid px-4">
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

        <div class="header-gradient">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                <div class="mb-3 mb-md-0">
                    <h1 class="h3 mb-0">My Students</h1>
                    <p class="mb-0 mt-2">
                        <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo addSemesterToUrl('results.php', $selectedSemester); ?>" class="btn btn-light">
                        <i class="fas fa-chart-bar me-2"></i>View Assessment Results
                    </a>
                </div>
            </div>
        </div>


        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm" class="row g-3 align-items-end">
                <!-- Hidden semester field to maintain semester in form submissions -->
                <input type="hidden" name="semester" value="<?php echo $selectedSemester; ?>">

                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Class</label>
                    <select name="class" class="form-select" id="classSelect">
                        <option value="">All Classes</option>
                        <?php 
                        $seenClasses = [];
                        foreach ($teacherAssignments as $assignment):
                            if (!in_array($assignment['class_id'], $seenClasses)):
                                $seenClasses[] = $assignment['class_id'];
                        ?>
                            <option value="<?php echo $assignment['class_id']; ?>"
                                <?php echo $assignment['class_id'] == $selectedClass ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assignment['class_name']); ?>
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>

                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Subject</label>
                    <select name="subject" class="form-select" id="subjectSelect">
                        <option value="">All Subjects</option>
                        <?php 
                        $seenSubjects = [];
                        foreach ($teacherAssignments as $assignment):
                            if (!in_array($assignment['subject_id'], $seenSubjects)):
                                $seenSubjects[] = $assignment['subject_id'];
                        ?>
                            <option value="<?php echo $assignment['subject_id']; ?>"
                                <?php echo $assignment['subject_id'] == $selectedSubject ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assignment['subject_name']); ?>
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>

                <div class="col-md-4 col-sm-6">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search students..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <button type="submit" class="btn btn-yellow">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Total Students</h6>
                    <h3 class="mb-0"><?php echo $totalStudents; ?></h3>
                    <?php if ($selectedClass): ?>
                        <?php foreach ($teacherAssignments as $assignment): ?>
                            <?php if ($assignment['class_id'] == $selectedClass): ?>
                                <small class="text-muted">
                                    Class: <?php echo htmlspecialchars($assignment['class_name']); ?>
                                </small>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Assessment Completion</h6>
                    <h3 class="mb-0"><?php echo $assessmentCompletionRate; ?>%</h3>
                    <small class="text-muted">
                        <?php echo $studentsWithAssessments; ?> students with assessments
                    </small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Average Score</h6>
                    <h3 class="mb-0"><?php echo $overallAverageScore; ?>%</h3>
                    <?php if ($selectedSubject): ?>
                        <?php foreach ($teacherAssignments as $assignment): ?>
                            <?php if ($assignment['subject_id'] == $selectedSubject): ?>
                                <small class="text-muted">
                                    Subject: <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                </small>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Student List -->
        <div class="students-section">
            <?php if (empty($students)): ?>
                <div class="text-center py-5 bg-white rounded">
                    <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No students found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($students as $student): ?>
                        <div class="col-lg-6 col-xl-4">
                            <div class="student-card">
                                <div class="student-card-header">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-0">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                <?php if ($student['enrollment_type'] === 'special'): ?>
                                                    <span class="badge bg-warning text-dark ms-1" title="Special Enrollment">
                                                        <i class="fas fa-star"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </h5>
                                            <?php if ($student['enrollment_type'] === 'special' && $student['special_notes']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-info-circle"></i> 
                                                    <?php echo htmlspecialchars($student['special_notes']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($student['average_score'] !== null): ?>
                                            <?php 
                                            $scoreClass = '';
                                            if ($student['average_score'] >= 70) {
                                                $scoreClass = 'score-high';
                                            } elseif ($student['average_score'] >= 50) {
                                                $scoreClass = 'score-medium';
                                            } else {
                                                $scoreClass = 'score-low';
                                            }
                                            ?>
                                            <span class="score-badge <?php echo $scoreClass; ?>">
                                                <?php echo $student['average_score']; ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="student-card-body">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">Program</span>
                                            <span><?php echo htmlspecialchars($student['program_name']); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-muted">
                                                <?php echo $student['enrollment_type'] === 'special' ? 'Enrolled For' : 'Class'; ?>
                                            </span>
                                            <div class="text-end">
                                                <div><?php echo htmlspecialchars($student['enrolled_class_name']); ?></div>
                                                <?php if ($student['enrollment_type'] === 'special'): ?>
                                                    <small class="text-muted">
                                                        (Originally: <?php echo htmlspecialchars($student['original_class_name']); ?>)
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Completed Assessments</span>
                                            <span>
                                                <?php echo $student['completed_assessments'] > 0 ? 
                                                    $student['completed_assessments'] : 
                                                    '<span class="no-data">None</span>'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end mt-3">
                                        <a href="student_result.php?student=<?php echo $student['student_id']; ?>&semester=<?php echo $selectedSemester; ?><?php echo $selectedSubject ? '&subject=' . $selectedSubject : ''; ?>"
                                           class="btn btn-sm btn-yellow">
                                            <i class="fas fa-chart-bar me-1"></i>View Results
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-submit form when filters change
        const filterForm = document.getElementById('filterForm');
        const filterSelects = filterForm.querySelectorAll('select');
        
        filterSelects.forEach(select => {
            select.addEventListener('change', () => {
                filterForm.submit();
            });
        });

        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            }, 5000);
        });
    });
</script>

<?php
ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>