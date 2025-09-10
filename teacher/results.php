<?php
// teacher/results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Set appropriate time limits and memory
ini_set('max_execution_time', 120); // 2 minutes
ini_set('memory_limit', '256M');    // Increase memory if needed

// Ensure user is logged in and has teacher role
requireRole('teacher');

// Start output buffering
ob_start();

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

try {
    // Initialize statistics variables
    $totalAssessments = 0;
    $completedAssessments = 0;
    $totalStudents = 0;
    $totalSubmissions = 0;
    $overallAverage = 0;
    $submissionRate = 0;

    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher ID - using lowercase table name
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    $teacherId = $teacherInfo['teacher_id'];

    // Get selected class and subject filters
    $selectedClass = isset($_GET['class']) ? intval($_GET['class']) : 0;
    $selectedSubject = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
    $selectedSemester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

    // Get teacher's classes and subjects for filters - using correct table names
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, s.subject_id, s.subject_name
         FROM TeacherClassAssignments tca
         JOIN Classes c ON tca.class_id = c.class_id
         JOIN Subjects s ON tca.subject_id = s.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY c.class_name, s.subject_name"
    );
    $stmt->execute([$teacherId]);
    $teacherAssignments = $stmt->fetchAll();

    // Get available semesters - using correct table names
    $stmt = $db->prepare(
        "SELECT DISTINCT sem.semester_id, sem.semester_name, sem.start_date 
         FROM Semesters sem
         JOIN Assessments a ON sem.semester_id = a.semester_id
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY sem.start_date DESC"
    );
    $stmt->execute([$teacherId]);
    $semesters = $stmt->fetchAll();

    // If no semester selected, use current or most recent
    if (!$selectedSemester) {
        $stmt = $db->prepare(
            "SELECT semester_id FROM Semesters 
             WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
             LIMIT 1"
        );
        $stmt->execute();
        $currentSemester = $stmt->fetch();
        $selectedSemester = $currentSemester ? $currentSemester['semester_id'] : ($semesters[0]['semester_id'] ?? 0);
    }

    // Get overall statistics for header cards - also filtered by class
    $statsQuery = "
        SELECT 
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_assessments,
            (
                SELECT COUNT(DISTINCT r.result_id) 
                FROM Results r 
                JOIN Students st ON r.student_id = st.student_id
                JOIN Assessments a2 ON r.assessment_id = a2.assessment_id
                JOIN AssessmentClasses ac2 ON a2.assessment_id = ac2.assessment_id
                WHERE ac2.class_id IN (
                    SELECT ac3.class_id FROM AssessmentClasses ac3
                    JOIN TeacherClassAssignments tca3 ON ac3.class_id = tca3.class_id AND ac3.subject_id = tca3.subject_id
                    WHERE tca3.teacher_id = ?
                )
                AND a2.semester_id = ?
                " . ($selectedClass ? "AND st.class_id = $selectedClass" : "") . "
                AND r.status = 'completed'
            ) as total_submissions,
            (
                SELECT COUNT(DISTINCT st.student_id)
                FROM Students st
                JOIN Classes c ON st.class_id = c.class_id
                WHERE c.class_id IN (
                    SELECT ac3.class_id FROM AssessmentClasses ac3
                    JOIN TeacherClassAssignments tca3 ON ac3.class_id = tca3.class_id AND ac3.subject_id = tca3.subject_id
                    WHERE tca3.teacher_id = ?
                    " . ($selectedClass ? "AND ac3.class_id = $selectedClass" : "") . "
                )
            ) as total_students,
            ROUND(AVG(CASE 
                WHEN EXISTS (
                    SELECT 1 FROM Results r 
                    JOIN Students st ON r.student_id = st.student_id
                    WHERE r.assessment_id = a.assessment_id 
                    AND r.status = 'completed'
                    " . ($selectedClass ? "AND st.class_id = $selectedClass" : "") . "
                ) 
                THEN (
                    SELECT AVG(r.score)
                    FROM Results r
                    JOIN Students st ON r.student_id = st.student_id
                    WHERE r.assessment_id = a.assessment_id 
                    AND r.status = 'completed'
                    " . ($selectedClass ? "AND st.class_id = $selectedClass" : "") . "
                )
                ELSE NULL
            END), 1) as overall_average
        FROM Assessments a
        JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
        JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
        WHERE tca.teacher_id = ? AND a.semester_id = ?";

    $statsParams = [$teacherId, $selectedSemester, $teacherId, $teacherId, $selectedSemester];

    if ($selectedClass) {
        $statsQuery .= " AND ac.class_id = ?";
        $statsParams[] = $selectedClass;
    }

    if ($selectedSubject) {
        $statsQuery .= " AND ac.subject_id = ?";
        $statsParams[] = $selectedSubject;
    }

    $stmt = $db->prepare($statsQuery);
    $stmt->execute($statsParams);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalAssessments = $stats['total_assessments'] ?? 0;
    $completedAssessments = $stats['completed_assessments'] ?? 0;
    $totalSubmissions = $stats['total_submissions'] ?? 0;
    $overallAverage = $stats['overall_average'] ?? 0;

    // Get total students count with an optimized query - corrected table names
    $studentCountQuery = "
        SELECT COUNT(DISTINCT s.student_id) as total_students
        FROM Students s
        JOIN Classes c ON s.class_id = c.class_id
        WHERE c.class_id IN (
            SELECT ac.class_id 
            FROM AssessmentClasses ac
            JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
            WHERE tca.teacher_id = ? AND tca.semester_id = ?
        )";

    $studentParams = [$teacherId, $selectedSemester];

    if ($selectedClass) {
        $studentCountQuery .= " AND c.class_id = ?";
        $studentParams[] = $selectedClass;
    }

    $stmt = $db->prepare($studentCountQuery);
    $stmt->execute($studentParams);
    $studentCount = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStudents = $studentCount['total_students'] ?? 0;

    // Calculate submission rate
    $submissionRate = $totalStudents ? round(($totalSubmissions / $totalStudents) * 100, 1) : 0;

    // If we selected a specific class, modify the header text
    $classHeaderText = "";
    if ($selectedClass) {
        $stmt = $db->prepare("SELECT class_name FROM Classes WHERE class_id = ?");
        $stmt->execute([$selectedClass]);
        $classInfo = $stmt->fetch();
        if ($classInfo) {
            $classHeaderText = " for " . $classInfo['class_name'];
        }
    }

    // Build query for assessments and results - corrected to show class-specific data
    $baseQuery = "
    SELECT 
        a.assessment_id,
        a.title,
        a.date,
        a.status as assessment_status,
        c.class_name,
        c.class_id,
        s.subject_name,
        s.subject_id,
        (
            SELECT COUNT(DISTINCT sa.student_id) 
            FROM StudentAnswers sa
            JOIN Students st ON sa.student_id = st.student_id
            WHERE sa.assessment_id = a.assessment_id
            AND st.class_id = c.class_id
        ) as total_attempts,
        (
            SELECT COUNT(DISTINCT r.result_id) 
            FROM Results r
            JOIN Students st ON r.student_id = st.student_id
            WHERE r.assessment_id = a.assessment_id 
            AND r.status = 'completed'
            AND st.class_id = c.class_id
        ) as completed_submissions,
        (
            SELECT ROUND(AVG(r.score), 1) 
            FROM Results r
            JOIN Students st ON r.student_id = st.student_id
            WHERE r.assessment_id = a.assessment_id 
            AND r.status = 'completed'
            AND st.class_id = c.class_id
        ) as average_score,
        (
            SELECT MIN(r.score) 
            FROM Results r
            JOIN Students st ON r.student_id = st.student_id
            WHERE r.assessment_id = a.assessment_id 
            AND r.status = 'completed'
            AND st.class_id = c.class_id
        ) as min_score,
        (
            SELECT MAX(r.score) 
            FROM Results r
            JOIN Students st ON r.student_id = st.student_id
            WHERE r.assessment_id = a.assessment_id 
            AND r.status = 'completed'
            AND st.class_id = c.class_id
        ) as max_score,
        (
            SELECT COUNT(*) 
            FROM Students st 
            WHERE st.class_id = c.class_id
        ) as total_students
    FROM Assessments a
    JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
    JOIN Classes c ON ac.class_id = c.class_id
    JOIN Subjects s ON ac.subject_id = s.subject_id
    JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id 
        AND ac.subject_id = tca.subject_id
    WHERE tca.teacher_id = ? 
    AND a.semester_id = ?";

    $params = [$teacherId, $selectedSemester];

    if ($selectedClass) {
        $baseQuery .= " AND c.class_id = ?";
        $params[] = $selectedClass;
    }

    if ($selectedSubject) {
        $baseQuery .= " AND s.subject_id = ?";
        $params[] = $selectedSubject;
    }

    $baseQuery .= " GROUP BY a.assessment_id, a.title, a.date, a.status, c.class_name, c.class_id, s.subject_name, s.subject_id
                ORDER BY a.date DESC, a.assessment_id DESC";

    $stmt = $db->prepare($baseQuery);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Results page error: " . $e->getMessage());
}

$pageTitle = "Assessment Results";
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
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        color: white;
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

    .filter-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .result-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(0, 0, 0, 0.1);
        transition: transform 0.2s;
    }

    .result-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .progress {
        height: 8px;
        border-radius: 4px;
        background-color: #f0f0f0;
    }

    .progress-bar {
        background: linear-gradient(90deg, #000000, #ffd700);
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
    }

    .status-completed {
        background-color: #d4edda;
        color: #155724;
    }

    .status-pending {
        background-color: #fff3cd;
        color: #856404;
    }

    .status-archived {
        background-color: #e2e3e5;
        color: #383d41;
    }

    .btn-filter {
        background: linear-gradient(45deg, #000000, #ffd700);
        border: none;
        color: white;
        transition: all 0.3s ease;
    }

    .btn-filter:hover {
        background: linear-gradient(45deg, #ffd700, #000000);
        transform: translateY(-1px);
    }

    .score-badge {
        background: #f8f9fa;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
        color: #495057;
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

        .result-card {
            padding: 1rem;
        }

        .btn-filter {
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .mobile-stack {
            margin-bottom: 0.5rem;
        }

        .mobile-center {
            text-align: center;
        }
    }
</style>

<div class="page-gradient">
    <div class="container-fluid px-4">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="header-gradient d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div class="mb-3 mb-md-0">
                <h1 class="h3 mb-0">Assessment Results<?php echo htmlspecialchars($classHeaderText); ?></h1>
                <?php if (!empty($semesters)): ?>
                    <?php foreach ($semesters as $semester): ?>
                        <?php if ($semester['semester_id'] == $selectedSemester): ?>
                            <p class="mb-0 mt-2">
                                <?php echo htmlspecialchars($semester['semester_name']); ?>
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <a href="assessments.php" class="btn btn-light">
                    <i class="fas fa-tasks me-2"></i>Manage Assessments
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Total Assessments</h6>
                    <h3 class="mb-0"><?php echo $totalAssessments; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Completed</h6>
                    <h3 class="mb-0"><?php echo $completedAssessments; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Submission Rate</h6>
                    <h3 class="mb-0"><?php echo $submissionRate; ?>%</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Average Score</h6>
                    <h3 class="mb-0"><?php echo $overallAverage; ?>%</h3>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" id="filterForm" class="row g-3 align-items-end">
                <?php if (!empty($semesters)): ?>
                    <div class="col-md-3">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>"
                                    <?php echo $semester['semester_id'] == $selectedSemester ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="form-label">Class</label>
                    <select name="class" class="form-select">
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

                <div class="col-md-3">
                    <label class="form-label">Subject</label>
                    <select name="subject" class="form-select">
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

                <div class="col-md-3">
                    <button type="submit" class="btn btn-filter w-100">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Results List -->
        <div class="results-section">
            <?php if (empty($results)): ?>
                <div class="text-center py-5 bg-white rounded">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No assessment results found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($results as $result): ?>
                    <div class="result-card">
                        <div class="row align-items-center">
                            <div class="col-md-4 mobile-stack">
                                <h5 class="mb-1"><?php echo htmlspecialchars($result['title']); ?></h5>
                                <div class="d-flex flex-wrap gap-3">
                                    <span class="text-muted">
                                        <i class="fas fa-graduation-cap me-1"></i>
                                        <?php echo htmlspecialchars($result['class_name']); ?>
                                    </span>
                                    <span class="text-muted">
                                        <i class="fas fa-book me-1"></i>
                                        <?php echo htmlspecialchars($result['subject_name']); ?>
                                    </span>
                                    <span class="text-muted">
                                        <i class="far fa-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($result['date'])); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-5 mobile-stack">
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <div>
                                        <small class="text-muted d-block">Submissions</small>
                                        <div class="progress mt-1" style="width: 150px;">
                                            <?php
                                            // Use the total_students in this specific class
                                            $submissionRate = $result['total_students'] ?
                                                ($result['completed_submissions'] / $result['total_students']) * 100 : 0;
                                            ?>
                                            <div class="progress-bar" style="width: <?php echo $submissionRate; ?>%"></div>
                                        </div>
                                        <small class="d-block mt-1">
                                            <?php echo $result['completed_submissions']; ?>/<?php echo $result['total_students']; ?>
                                        </small>
                                    </div>

                                    <?php if ($result['completed_submissions'] > 0): ?>
                                        <div class="d-flex gap-2">
                                            <div class="score-badge">
                                                <small class="d-block text-muted">Avg</small>
                                                <span class="fw-bold">
                                                    <?php echo $result['average_score']; ?>%
                                                </span>
                                            </div>
                                            <div class="score-badge">
                                                <small class="d-block text-muted">Min</small>
                                                <span class="fw-bold">
                                                    <?php echo $result['min_score']; ?>%
                                                </span>
                                            </div>
                                            <div class="score-badge">
                                                <small class="d-block text-muted">Max</small>
                                                <span class="fw-bold">
                                                    <?php echo $result['max_score']; ?>%
                                                </span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-3 text-md-end mobile-stack">
                                <div class="d-flex flex-column align-items-md-end">
                                    <span class="status-badge status-<?php echo strtolower($result['assessment_status']); ?> mb-2">
                                        <?php echo ucfirst($result['assessment_status']); ?>
                                    </span>
                                    <a href="view_results.php?id=<?php echo $result['assessment_id']; ?>&class_id=<?php echo $result['class_id']; ?>"
                                        class="btn btn-warning btn-sm">
                                        <i class="fas fa-chart-bar me-1"></i>View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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

        // Handle filter form submission with debounce
        let formInitialized = false;
        const filterForm = document.getElementById('filterForm');
        const filterSelects = filterForm.querySelectorAll('select');

        // Set a flag to prevent auto-submission during page load
        setTimeout(() => {
            formInitialized = true;
        }, 500);

        filterSelects.forEach(select => {
            select.addEventListener('change', () => {
                if (formInitialized) {
                    filterForm.submit();
                }
            });
        });
    });
</script>

<?php
ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>