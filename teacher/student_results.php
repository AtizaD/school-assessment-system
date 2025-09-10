<?php
// teacher/student_results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';

requireRole('teacher');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher info
    $stmt = $db->prepare(
        "SELECT teacher_id FROM Teachers WHERE user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherInfo['teacher_id'];

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name 
         FROM Semesters 
         WHERE start_date <= CURDATE() 
         AND end_date >= CURDATE() 
         LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();

    if (!$currentSemester) {
        throw new Exception('No active semester found');
    }

    // Get assigned classes and subjects
    $stmt = $db->prepare(
        "SELECT DISTINCT 
            c.class_id,
            c.class_name,
            p.program_name
         FROM TeacherClassAssignments tca
         JOIN Classes c ON tca.class_id = c.class_id
         JOIN Programs p ON c.program_id = p.program_id
         WHERE tca.teacher_id = ? AND tca.semester_id = ?
         ORDER BY p.program_name, c.class_name"
    );
    $stmt->execute([$teacherId, $currentSemester['semester_id']]);
    $assignments = $stmt->fetchAll();

    // Get class and subject from query parameters
    $selectedClass = isset($_GET['class']) ? (int)$_GET['class'] : null;
    $selectedSubject = isset($_GET['subject']) ? (int)$_GET['subject'] : null;

    $students = [];
    $classResults = [];

    if ($selectedClass && $selectedSubject) {
        // Verify teacher's assignment
        $stmt = $db->prepare(
            "SELECT 1 FROM TeacherClassAssignments 
             WHERE teacher_id = ? 
             AND class_id = ? 
             AND subject_id = ?
             AND semester_id = ?"
        );
        $stmt->execute([$teacherId, $selectedClass, $selectedSubject, $currentSemester['semester_id']]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Not authorized for this class/subject combination');
        }

        // Get students and their results
        $stmt = $db->prepare(
            "SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                COUNT(DISTINCT a.assessment_id) as total_assessments,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments,
                ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score,
                MAX(CASE WHEN r.status = 'completed' THEN r.score END) as highest_score,
                MIN(CASE WHEN r.status = 'completed' THEN r.score END) as lowest_score
             FROM Students s
             LEFT JOIN Results r ON s.student_id = r.student_id
             LEFT JOIN Assessments a ON r.assessment_id = a.assessment_id
             LEFT JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
             WHERE s.class_id = ?
             AND ac.class_id = s.class_id
             AND ac.subject_id = ?
             AND a.semester_id = ?
             GROUP BY s.student_id
             ORDER BY s.last_name, s.first_name"
        );
        $stmt->execute([
            $selectedClass,
            $selectedSubject,
            $currentSemester['semester_id']
        ]);
        $students = $stmt->fetchAll();

        // Get class statistics
        if (!empty($students)) {
            $totalStudents = count($students);
            $totalAssessments = $students[0]['total_assessments'] ?? 0;
            $avgScores = array_filter(array_column($students, 'average_score')); // Filter out null/0 scores
            
            $classResults = [
                'total_students' => $totalStudents,
                'total_assessments' => $totalAssessments,
                'average_score' => !empty($avgScores) ? round(array_sum($avgScores) / count($avgScores), 1) : 0,
                'completion_rate' => ($totalStudents > 0 && $totalAssessments > 0) ? 
                    round((array_sum(array_column($students, 'completed_assessments')) / 
                    ($totalStudents * $totalAssessments)) * 100, 1) : 0
            ];
        }
    }

} catch (Exception $e) {
    logError("Student results error: " . $e->getMessage());
    $error = $e->getMessage();
}

$pageTitle = 'Student Results';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <!-- Page Header -->
    <div class="header-gradient d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 text-white mb-0">Student Results</h1>
            <p class="text-white-50 mb-0">
                <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Class & Subject Selection -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="student_results.php" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Class</label>
                    <select name="class" class="form-select" required onchange="this.form.submit()">
                        <option value="">Select Class</option>
                        <?php
                        $currentProgram = '';
                        foreach ($assignments as $assignment):
                            if ($currentProgram != $assignment['program_name']):
                                if ($currentProgram != '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($assignment['program_name']) . '">';
                                $currentProgram = $assignment['program_name'];
                            endif;
                        ?>
                            <option value="<?php echo $assignment['class_id']; ?>"
                                    <?php echo $selectedClass == $assignment['class_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assignment['class_name']); ?>
                            </option>
                        <?php
                        endforeach;
                        if ($currentProgram != '') echo '</optgroup>';
                        ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Subject</label>
                    <select name="subject" class="form-select" required onchange="this.form.submit()">
                        <option value="">Select Subject</option>
                        <?php 
                        if ($selectedClass) {
                            $subjectStmt = $db->prepare(
                                "SELECT DISTINCT 
                                    s.subject_id,
                                    s.subject_name
                                 FROM TeacherClassAssignments tca
                                 JOIN Subjects s ON tca.subject_id = s.subject_id
                                 WHERE tca.teacher_id = ? 
                                 AND tca.class_id = ?
                                 AND tca.semester_id = ?
                                 ORDER BY s.subject_name"
                            );
                            $subjectStmt->execute([$teacherId, $selectedClass, $currentSemester['semester_id']]);
                            $subjects = $subjectStmt->fetchAll();
                            
                            foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>"
                                        <?php echo $selectedSubject == $subject['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach;
                        } ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fas fa-search me-2"></i>View Results
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedClass && $selectedSubject && !empty($students)): ?>
        <!-- Class Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-users fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Total Students</h6>
                                <h4 class="mb-0"><?php echo $classResults['total_students']; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-tasks fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Assessments</h6>
                                <h4 class="mb-0"><?php echo $classResults['total_assessments']; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-chart-line fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Class Average</h6>
                                <h4 class="mb-0"><?php echo $classResults['average_score']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-check-circle fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Completion Rate</h6>
                                <h4 class="mb-0"><?php echo $classResults['completion_rate']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students Results Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3" style="background: linear-gradient(145deg, #000000 0%, #ffd700 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-white mb-0">Student Results</h5>
                    <button onclick="exportResults()" class="btn btn-light btn-sm">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">Student</th>
                                <th class="py-3 text-center">Assessments</th>
                                <th class="py-3 text-end">Average Score</th>
                                <th class="py-3 text-end">Highest Score</th>
                                <th class="py-3 text-end">Lowest Score</th>
                                <th class="py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="py-3">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    </td>
                                    <td class="py-3 text-center">
                                        <span class="badge bg-<?php 
                                            echo $student['completed_assessments'] == $student['total_assessments'] 
                                                ? 'success' 
                                                : ($student['completed_assessments'] > 0 ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo $student['completed_assessments']; ?>/<?php echo $student['total_assessments']; ?>
                                        </span>
                                    </td>
                                    <td class="py-3 text-end">
                                        <?php if ($student['average_score']): ?>
                                            <span class="badge bg-<?php 
                                                echo $student['average_score'] >= 70 
                                                    ? 'success' 
                                                    : ($student['average_score'] >= 50 ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo $student['average_score']; ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-end">
                                        <?php echo $student['highest_score'] ? $student['highest_score'] . '%' : '-'; ?>
                                    </td>
                                    <td class="py-3 text-end">
                                        <?php echo $student['lowest_score'] ? $student['lowest_score'] . '%' : '-'; ?>
                                    </td>
                                    <td class="py-3 text-end">
                                        <a href="student_result.php?student=<?php echo $student['student_id']; ?>&subject=<?php echo $selectedSubject; ?>" 
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($selectedClass && $selectedSubject): ?>
        <div class="text-center py-5">
            <i class="fas fa-users text-muted fa-3x mb-3"></i>
            <h5 class="text-muted">No students found for this class</h5>
        </div>
    <?php endif; ?>
</main>

<style>
/* Custom styles for student results page */
.card {
    transition: transform 0.2s;
    margin-bottom: 1rem;
}

.card:hover {
    transform: translateY(-2px);
}

.header-gradient {
    background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.btn-warning {
    background: linear-gradient(45deg, #ffd700 0%, #ffb300 100%);
    border: none;
    color: #000;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ffb300 0%, #ffd700 100%);
    color: #000;
    transform: translateY(-1px);
}

.table > :not(caption) > * > * {
    padding: 1rem;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
}

.rounded-circle {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    .header-gradient {
        padding: 1rem;
        margin: -1rem -1rem 1rem -1rem;
        border-radius: 0;
    }

    .container-fluid {
        padding: 0;
    }

    .card-body {
        padding: 1rem;
    }

    .table-responsive {
        margin: 0;
    }

    .btn-warning {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .rounded-circle {
        width: 36px;
        height: 36px;
    }

    .rounded-circle i {
        font-size: 1rem !important;
    }

    .header-gradient h1 {
        font-size: 1.5rem;
    }

    .card-title {
        font-size: 1.1rem;
    }

    /* Stack form elements on mobile */
    .form-group {
        margin-bottom: 1rem;
    }

    .col-md-5, .col-md-2 {
        width: 100%;
        margin-bottom: 1rem;
    }

    /* Adjust statistics cards for mobile */
    .col-md-3 {
        margin-bottom: 1rem;
    }

    .table {
        font-size: 0.875rem;
    }

    /* Hide less important columns on mobile */
    .table td:nth-child(4),
    .table td:nth-child(5),
    .table th:nth-child(4),
    .table th:nth-child(5) {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        new bootstrap.Tooltip(tooltip);
    });

    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        }, 5000);
    });
});

// Export results function
function exportResults() {
    const selectedClass = document.querySelector('select[name="class"] option:checked').text;
    const selectedSubject = document.querySelector('select[name="subject"] option:checked').text;
    
    let csv = 'Student Results Report\n';
    csv += `Class: ${selectedClass}\n`;
    csv += `Subject: ${selectedSubject}\n\n`;
    
    csv += 'Student,Completed Assessments,Total Assessments,Average Score,Highest Score,Lowest Score\n';
    
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const columns = row.querySelectorAll('td');
        const student = columns[0].textContent.trim();
        const assessments = columns[1].textContent.trim().split('/');
        const avgScore = columns[2].textContent.trim().replace('%', '');
        const highScore = columns[3].textContent.trim().replace('%', '');
        const lowScore = columns[4].textContent.trim().replace('%', '');
        
        csv += `${student},${assessments[0]},${assessments[1]},${avgScore},${highScore},${lowScore}\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', `${selectedClass}_${selectedSubject}_Results.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>