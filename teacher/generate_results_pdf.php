<?php
// teacher/generate_class_results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';

// Require teacher role
requireRole('teacher');

$error = '';
$success = '';
$classes = [];
$subjects = [];
$assessments = [];
$results = [];
$stats = [];

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher info
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    $teacherId = $teacherInfo['teacher_id'];

    // Get classes assigned to this teacher
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, p.program_name 
         FROM classes c
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE tca.teacher_id = ?
         ORDER BY p.program_name, c.class_name"
    );
    $stmt->execute([$teacherId]);
    $classes = $stmt->fetchAll();

    // If class is selected, get subjects
    $selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : 0;
    if ($selectedClassId) {
        $stmt = $db->prepare(
            "SELECT DISTINCT s.subject_id, s.subject_name
             FROM subjects s
             JOIN teacherclassassignments tca ON s.subject_id = tca.subject_id
             WHERE tca.teacher_id = ?
             AND tca.class_id = ?
             ORDER BY s.subject_name"
        );
        $stmt->execute([$teacherId, $selectedClassId]);
        $subjects = $stmt->fetchAll();
    }

    // If subject is selected, get assessments
    $selectedSubjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
    if ($selectedClassId && $selectedSubjectId) {
        $stmt = $db->prepare(
            "SELECT a.assessment_id, a.title, a.date, a.status
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             WHERE ac.class_id = ?
             AND ac.subject_id = ?
             ORDER BY a.date DESC"
        );
        $stmt->execute([$selectedClassId, $selectedSubjectId]);
        $assessments = $stmt->fetchAll();
    }

    // If assessment is selected, get results
    $selectedAssessmentId = isset($_GET['assessment']) ? (int)$_GET['assessment'] : 0;
    if ($selectedClassId && $selectedSubjectId && $selectedAssessmentId) {
        // Get class and subject details
        $stmt = $db->prepare(
            "SELECT c.class_name, s.subject_name, a.title as assessment_title, 
                    a.date as assessment_date, a.description
             FROM classes c
             JOIN subjects s ON 1=1
             JOIN assessments a ON 1=1
             WHERE c.class_id = ? AND s.subject_id = ? AND a.assessment_id = ?"
        );
        $stmt->execute([$selectedClassId, $selectedSubjectId, $selectedAssessmentId]);
        $classInfo = $stmt->fetch();

        // Get all students in the class
        $stmt = $db->prepare(
            "SELECT s.student_id, s.first_name, s.last_name
             FROM students s
             WHERE s.class_id = ?
             ORDER BY s.last_name, s.first_name"
        );
        $stmt->execute([$selectedClassId]);
        $allStudents = $stmt->fetchAll();

        // Get results for the assessment
        $stmt = $db->prepare(
            "SELECT r.student_id, s.first_name, s.last_name, r.score, r.created_at
             FROM results r
             JOIN students s ON r.student_id = s.student_id
             WHERE r.assessment_id = ?
             AND r.status = 'completed'
             ORDER BY r.score DESC"
        );
        $stmt->execute([$selectedAssessmentId]);
        $results = $stmt->fetchAll();

        // Add position (rank) to each result
        $rank = 1;
        $prevScore = null;
        $sameRankCount = 0;
        
        foreach ($results as $key => $result) {
            if ($prevScore !== null && $prevScore != $result['score']) {
                $rank += $sameRankCount;
                $sameRankCount = 1;
            } else {
                $sameRankCount++;
            }
            
            $results[$key]['position'] = $rank;
            $prevScore = $result['score'];
        }

        // Calculate statistics
        $stats = [
            'total_students' => count($allStudents),
            'students_attempted' => count($results),
            'highest_score' => !empty($results) ? $results[0]['score'] : 0,
            'lowest_score' => !empty($results) ? $results[count($results) - 1]['score'] : 0,
            'average_score' => 0
        ];

        if (!empty($results)) {
            $totalScore = array_reduce($results, function($carry, $item) {
                return $carry + $item['score'];
            }, 0);
            $stats['average_score'] = round($totalScore / count($results), 2);
        }
    }

} catch (Exception $e) {
    logError("Generate class results error: " . $e->getMessage());
    $error = $e->getMessage();
}

$pageTitle = 'Generate Class Results';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="header-gradient mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h3 text-white mb-1">Generate Class Results</h1>
                <p class="text-white-50 mb-0">Generate and export class assessment results</p>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-gradient py-3">
            <h5 class="card-title mb-0">Select Parameters</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <label for="class" class="form-label">Class</label>
                    <select name="class" id="class" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>" 
                                <?php echo $selectedClassId == $class['class_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="subject" class="form-label">Subject</label>
                    <select name="subject" id="subject" class="form-select" 
                            onchange="this.form.submit()" 
                            <?php echo $selectedClassId ? '' : 'disabled'; ?>>
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['subject_id']; ?>" 
                                <?php echo $selectedSubjectId == $subject['subject_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="assessment" class="form-label">Assessment</label>
                    <select name="assessment" id="assessment" class="form-select" 
                            onchange="this.form.submit()" 
                            <?php echo ($selectedClassId && $selectedSubjectId) ? '' : 'disabled'; ?>>
                        <option value="">-- Select Assessment --</option>
                        <?php foreach ($assessments as $assessment): ?>
                            <option value="<?php echo $assessment['assessment_id']; ?>" 
                                <?php echo $selectedAssessmentId == $assessment['assessment_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assessment['title'] . ' (' . date('M d, Y', strtotime($assessment['date'])) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedAssessmentId && !empty($results)): ?>
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                <i class="fas fa-users fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Participation Rate</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['students_attempted']; ?>/<?php echo $stats['total_students']; ?>
                                    (<?php echo round(($stats['students_attempted'] / $stats['total_students']) * 100, 1); ?>%)
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                <i class="fas fa-percentage fa-2x text-success"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Average Score</h6>
                                <h4 class="mb-0"><?php echo $stats['average_score']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3">
                                <i class="fas fa-trophy fa-2x text-info"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Highest Score</h6>
                                <h4 class="mb-0"><?php echo $stats['highest_score']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-chart-line fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Lowest Score</h6>
                                <h4 class="mb-0"><?php echo $stats['lowest_score']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-gradient py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <?php echo htmlspecialchars($classInfo['class_name'] . ' - ' . $classInfo['subject_name'] . ' - ' . $classInfo['assessment_title']); ?>
                    </h5>
                    <div>
                        <button onclick="exportToPDF()" class="btn btn-light btn-sm ms-2">
                            <i class="fas fa-file-pdf me-2"></i>Export to PDF
                        </button>
                        
                        <!-- New Export All Classes Button -->
                        <button onclick="exportAllClassesToPDF()" class="btn btn-warning btn-sm ms-2">
                            <i class="fas fa-file-pdf me-2"></i>Export All Classes
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="resultsTable">
                        <thead class="table-light">
                            <tr>
                                <th>S/N</th>
                                <th>Student Name</th>
                                <th>Score (%)</th>
                                <th>Position</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($results as $result): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($result['last_name'] . ', ' . $result['first_name']); ?>
                                    </td>
                                    <td><?php echo $result['score']; ?>%</td>
                                    <td><?php echo getOrdinal($result['position']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Form for exporting single class results -->
        <form id="exportForm" action="export_results_pdf.php" method="post" target="_blank">
            <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
            <input type="hidden" name="subject_id" value="<?php echo $selectedSubjectId; ?>">
            <input type="hidden" name="assessment_id" value="<?php echo $selectedAssessmentId; ?>">
        </form>

        <!-- New form for exporting all classes -->
        <form id="exportAllForm" action="export_all_results_pdf.php" method="post" target="_blank">
            <input type="hidden" name="subject_id" value="<?php echo $selectedSubjectId; ?>">
            <input type="hidden" name="assessment_id" value="<?php echo $selectedAssessmentId; ?>">
        </form>
    <?php elseif ($selectedAssessmentId): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="empty-state">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5>No Results Found</h5>
                    <p class="text-muted">There are no completed results for this assessment.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<style>
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    /* Header Section */
    .header-gradient {
        background: var(--primary-black);
        padding: 1.5rem;
        border-radius: 4px;
        margin-bottom: 1.5rem;
    }

    .header-gradient h1 {
        color: var(--primary-yellow);
        font-weight: 600;
    }

    /* Cards and Tables */
    .card {
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        box-shadow: none;
        transition: box-shadow 0.2s;
    }

    .card-header.bg-gradient {
        background: var(--primary-black);
        border-radius: 4px 4px 0 0;
        padding: 1rem;
        color: white;
    }

    .rounded-circle {
        background-color: var(--light-yellow) !important;
    }

    .rounded-circle i {
        color: var(--dark-yellow) !important;
    }

    .table thead th {
        background-color: #f8f9fa;
        border-bottom: 2px solid #e0e0e0;
        font-weight: 600;
    }

    /* Empty State */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }

    /* Buttons */
    .btn-light {
        background: var(--primary-white);
        border: 1px solid var(--dark-yellow);
        color: var(--primary-black);
    }

    .btn-light:hover {
        background: var(--light-yellow);
        border-color: var(--dark-yellow);
    }
    
    .btn-warning {
        background: var(--primary-yellow);
        border: 1px solid var(--dark-yellow);
        color: var(--primary-black);
    }

    .btn-warning:hover {
        background: var(--dark-yellow);
        color: var(--primary-black);
    }
</style>

<script>
    function exportToPDF() {
        document.getElementById('exportForm').submit();
    }
    
    function exportAllClassesToPDF() {
        document.getElementById('exportAllForm').submit();
    }

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
</script>

<?php
// Helper function to convert number to ordinal
function getOrdinal($number) {
    if (!in_array(($number % 100), [11, 12, 13])) {
        switch ($number % 10) {
            case 1:  return $number . 'st';
            case 2:  return $number . 'nd';
            case 3:  return $number . 'rd';
        }
    }
    return $number . 'th';
}
?>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>