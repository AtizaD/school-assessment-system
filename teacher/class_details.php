<?php
// teacher/class_details.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

requireRole('teacher');

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    // Get class ID and validate access
    $classId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $className = sanitizeInput($_GET['class'] ?? '');

    if (!$classId || !$className) {
        throw new Exception('Invalid class parameters');
    }

    // Verify teacher has access to this class using the TeacherClassAssignments table
    $stmt = $db->prepare(
        "SELECT c.*, p.program_name, s.subject_name 
         FROM classes c
         JOIN programs p ON c.program_id = p.program_id
         JOIN teacher_class_assignments tca ON c.class_id = tca.class_id
         JOIN subjects s ON tca.subject_id = s.subject_id
         WHERE c.class_id = ? AND tca.teacher_id = ?
         LIMIT 1"
    );
    $stmt->execute([$classId, $teacherInfo['teacher_id']]);
    $classInfo = $stmt->fetch();

    if (!$classInfo) {
        throw new Exception('Class not found or access denied');
    }

    // Get current semester and all semesters for selector
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);

    // Get class statistics - update to use assessment_classes table to connect assessments to classes
    $stmt = $db->prepare(
        "SELECT 
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN a.assessment_id END) as completed_assessments,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score,
            MAX(CASE WHEN r.status = 'completed' THEN r.score END) as highest_score,
            MIN(CASE WHEN r.status = 'completed' THEN r.score END) as lowest_score
         FROM classes c
         LEFT JOIN students s ON c.class_id = s.class_id
         LEFT JOIN assessment_classes ac ON c.class_id = ac.class_id
         LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id 
            AND a.semester_id = ?
         LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
            AND aa.student_id = s.student_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id 
            AND r.student_id = s.student_id
         WHERE c.class_id = ?"
    );
    $stmt->execute([$currentSemester['semester_id'], $classId]);
    $classStats = $stmt->fetch();

    // Get students with their performance data
    $stmt = $db->prepare(
        "SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            u.username,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN aa.status = 'completed' THEN a.assessment_id END) as completed_assessments,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score,
            COUNT(DISTINCT CASE WHEN r.score >= 50 THEN r.result_id END) as passed_assessments
         FROM students s
         JOIN users u ON s.user_id = u.user_id
         LEFT JOIN assessment_classes ac ON s.class_id = ac.class_id
         LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id 
            AND a.semester_id = ?
         LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
            AND aa.student_id = s.student_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id 
            AND r.student_id = s.student_id
         WHERE s.class_id = ?
         GROUP BY s.student_id
         ORDER BY s.last_name, s.first_name"
    );
    $stmt->execute([$currentSemester['semester_id'], $classId]);
    $students = $stmt->fetchAll();

    // Get recent assessments
    $stmt = $db->prepare(
        "SELECT a.*, s.subject_name,
            COUNT(DISTINCT aa.student_id) as attempt_count,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.student_id END) as completion_count,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score
         FROM assessments a
         JOIN assessment_classes ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id
         WHERE ac.class_id = ? AND a.semester_id = ?
         GROUP BY a.assessment_id, s.subject_name
         ORDER BY a.date DESC, a.created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$classId, $currentSemester['semester_id']]);
    $recentAssessments = $stmt->fetchAll();

    // Calculate performance trends
    $stmt = $db->prepare(
        "SELECT 
            DATE_FORMAT(a.date, '%Y-%m') as month,
            COUNT(DISTINCT a.assessment_id) as assessment_count,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score
         FROM assessments a
         JOIN assessment_classes ac ON a.assessment_id = ac.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id
         WHERE ac.class_id = ? AND a.semester_id = ?
         GROUP BY DATE_FORMAT(a.date, '%Y-%m')
         ORDER BY month DESC
         LIMIT 6"
    );
    $stmt->execute([$classId, $currentSemester['semester_id']]);
    $performanceTrends = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Class details page error: " . $e->getMessage());
}

$pageTitle = "Class Details - " . htmlspecialchars($className);
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    .class-details-container {
        padding: 1rem;
        background: linear-gradient(135deg, #f8f9fa 0%, var(--light-yellow) 100%);
        min-height: calc(100vh - 60px);
    }

    .header-gradient {
        background: linear-gradient(90deg, var(--primary-black), var(--primary-yellow));
        padding: 1.5rem;
        border-radius: 10px;
        color: var(--primary-white);
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .stats-card {
        background: var(--primary-white);
        border-radius: 10px;
        padding: 1.25rem;
        height: 100%;
        border-left: 4px solid var(--primary-yellow);
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
    }

    .semester-selector {
        background: var(--primary-white);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        border-left: 4px solid var(--primary-yellow);
    }

    .semester-selector .form-select {
        border: 2px solid var(--primary-yellow);
        color: var(--primary-black);
        background-color: var(--primary-white);
        max-width: 300px;
    }

    .semester-selector .form-select:focus {
        border-color: var(--dark-yellow);
        box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
    }

    .content-card {
        background: var(--primary-white);
        border-radius: 10px;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .card-header-custom {
        background: linear-gradient(90deg, var(--primary-black), var(--primary-yellow));
        color: var(--primary-white);
        padding: 1rem 1.25rem;
        border-radius: 10px 10px 0 0;
    }

    .progress {
        height: 8px;
        background-color: rgba(0,0,0,0.1);
        border-radius: 4px;
    }

    .progress-bar {
        background: linear-gradient(90deg, var(--primary-black), var(--primary-yellow));
    }

    .student-row {
        transition: background-color 0.2s;
        border-left: 3px solid transparent;
    }

    .student-row:hover {
        background-color: var(--light-yellow);
        border-left-color: var(--primary-yellow);
    }

    .badge-custom {
        background: linear-gradient(45deg, var(--primary-black), var(--primary-yellow));
        color: var(--primary-white);
        padding: 0.4em 0.8em;
        border-radius: 20px;
    }

    .btn-custom {
        background: linear-gradient(45deg, var(--primary-black), var(--primary-yellow));
        border: none;
        color: var(--primary-white);
        padding: 0.5rem 1.5rem;
        border-radius: 5px;
        transition: all 0.2s;
    }

    .btn-custom:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        color: var(--primary-white);
    }

    .assessment-card {
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        transition: transform 0.2s;
    }

    .assessment-card:hover {
        transform: translateY(-2px);
    }

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .class-details-container {
            padding: 0.5rem;
        }

        .header-gradient {
            padding: 1rem;
            margin: -0.5rem -0.5rem 1rem -0.5rem;
            border-radius: 0;
        }

        .stats-card {
            margin-bottom: 1rem;
            padding: 1rem;
        }

        .content-card {
            margin-bottom: 1rem;
        }

        .card-header-custom {
            padding: 0.75rem 1rem;
        }

        .assessment-card {
            padding: 0.75rem;
        }

        .table-responsive {
            margin: 0 -1rem;
            width: calc(100% + 2rem);
        }

        .student-row td {
            white-space: nowrap;
        }

        .btn-custom {
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .btn-group {
            flex-direction: column;
            width: 100%;
        }

        .btn-group .btn {
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .semester-selector {
            margin: -0.5rem -0.5rem 1rem -0.5rem;
            border-radius: 0;
        }

        .semester-selector .form-select {
            max-width: 100%;
        }
    }

    /* Extra Small Devices */
    @media (max-width: 576px) {
        .stats-card h4 {
            font-size: 1.25rem;
        }

        .header-gradient h1 {
            font-size: 1.5rem;
        }

        .badge-custom {
            font-size: 0.75rem;
        }
    }

    /* Print Styles */
    @media print {
        .class-details-container {
            padding: 0;
            background: none;
        }

        .content-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .no-print {
            display: none;
        }
    }
</style>

<div class="class-details-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <!-- Class Header -->
        <div class="header-gradient">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h1 class="h3 mb-0">Class <?php echo htmlspecialchars($classInfo['class_name']); ?></h1>
                <div class="btn-group no-print">
                    <button type="button" class="btn btn-light" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                    <a href="<?php echo addSemesterToUrl('subjects.php', $currentSemester['semester_id']); ?>" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Subjects
                    </a>
                </div>
            </div>
            <p class="mb-0">
                <?php echo htmlspecialchars($classInfo['program_name']); ?> | 
                <?php echo htmlspecialchars($classInfo['subject_name']); ?> |
                <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
            </p>
        </div>


        <!-- Class Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-6">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Students</h6>
                    <h4 class="mb-0"><?php echo $classStats['total_students']; ?></h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Assessments</h6>
                    <h4 class="mb-0">
                        <?php echo $classStats['completed_assessments']; ?>/<?php echo $classStats['total_assessments']; ?>
                    </h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stats-card">
                    <h6 class="text-muted mb-2">Average Score</h6>
                    <h4 class="mb-0"><?php echo $classStats['average_score'] !== null ? 
                        number_format($classStats['average_score'], 1) . '%' : 'N/A'; ?></h4>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stats-card">
                <h6 class="text-muted mb-2">Highest Score</h6>
                    <h4 class="mb-0"><?php echo $classStats['highest_score'] !== null ? 
                        number_format($classStats['highest_score'], 1) . '%' : 'N/A'; ?></h4>
                </div>
            </div>
        </div>

        <!-- Student List -->
        <div class="content-card mb-4">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Students</h5>
                <div class="btn-group no-print">
                    <a href="<?php echo addSemesterToUrl('create_assessment.php?class=' . $classId, $currentSemester['semester_id']); ?>" class="btn btn-sm btn-light">
                        <i class="fas fa-plus me-2"></i>New Assessment
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th class="text-center">Assessments</th>
                                <th class="text-center">Performance</th>
                                <th class="text-center">Average</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): 
                                $completionRate = $student['total_assessments'] > 0 ? 
                                    ($student['completed_assessments'] / $student['total_assessments']) * 100 : 0;
                                $passRate = $student['completed_assessments'] > 0 ? 
                                    ($student['passed_assessments'] / $student['completed_assessments']) * 100 : 0;
                            ?>
                                <tr class="student-row">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-user-graduate text-warning me-2"></i>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $student['completed_assessments']; ?>/<?php echo $student['total_assessments']; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2">
                                                <div class="progress-bar" 
                                                     style="width: <?php echo $completionRate; ?>%">
                                                </div>
                                            </div>
                                            <span class="small"><?php echo number_format($completionRate, 1); ?>%</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-custom">
                                            <?php echo $student['average_score'] !== null ? 
                                                number_format($student['average_score'], 1) . '%' : 'N/A'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="<?php echo addSemesterToUrl('student_profile.php?student=' . urlencode($student['username']), $currentSemester['semester_id']); ?>" 
                                               class="btn btn-sm btn-custom">
                                                <i class="fas fa-user me-1"></i>
                                                <span class="d-none d-md-inline">Profile</span>
                                            </a>
                                            <a href="<?php echo addSemesterToUrl('student_results.php?id=' . $student['student_id'], $currentSemester['semester_id']); ?>" 
                                               class="btn btn-sm btn-custom">
                                                <i class="fas fa-chart-bar me-1"></i>
                                                <span class="d-none d-md-inline">Results</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Assessments -->
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h5 class="mb-0">Recent Assessments</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recentAssessments)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No assessments found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentAssessments as $assessment): 
                        $completionRate = $assessment['attempt_count'] > 0 ? 
                            ($assessment['completion_count'] / $assessment['attempt_count']) * 100 : 0;
                    ?>
                        <div class="assessment-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($assessment['title']); ?></h6>
                                    <p class="mb-0 small text-muted">
                                        <?php echo htmlspecialchars($assessment['subject_name']); ?> | 
                                        <?php echo date('M d, Y', strtotime($assessment['date'])); ?>
                                    </p>
                                </div>
                                <span class="badge-custom">
                                    <?php echo ucfirst($assessment['status']); ?>
                                </span>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Completion Rate</small>
                                    <small class="text-muted">
                                        <?php echo $assessment['completion_count']; ?>/<?php echo $assessment['attempt_count']; ?>
                                    </small>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $completionRate; ?>%"></div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-success small">
                                    <i class="fas fa-chart-line me-1"></i>
                                    <?php echo $assessment['average_score'] !== null ? 
                                        number_format($assessment['average_score'], 1) . '%' : 'N/A'; ?>
                                </span>
                                <div class="btn-group">
                                    <a href="<?php echo addSemesterToUrl('view_results.php?id=' . $assessment['assessment_id'], $currentSemester['semester_id']); ?>" 
                                       class="btn btn-sm btn-custom">
                                        <i class="fas fa-chart-bar me-1"></i>
                                        <span class="d-none d-md-inline">Results</span>
                                    </a>
                                    <?php if ($assessment['status'] === 'pending'): ?>
                                        <a href="<?php echo addSemesterToUrl('edit_assessment.php?id=' . $assessment['assessment_id'], $currentSemester['semester_id']); ?>" 
                                           class="btn btn-sm btn-custom">
                                            <i class="fas fa-edit me-1"></i>
                                            <span class="d-none d-md-inline">Edit</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Performance Trends -->
        <div class="content-card">
            <div class="card-header-custom">
                <h5 class="mb-0">Performance Trends</h5>
            </div>
            <div class="card-body">
                <?php if (empty($performanceTrends)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No performance data available</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-center">Assessments</th>
                                    <th class="text-center">Average Score</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $previousScore = null;
                                foreach ($performanceTrends as $trend): 
                                    $trend['month'] = date('F Y', strtotime($trend['month'] . '-01'));
                                    $scoreChange = ($previousScore !== null && $trend['average_score'] !== null) ? 
                                        $trend['average_score'] - $previousScore : 0;
                                    $previousScore = $trend['average_score'];
                                ?>
                                    <tr>
                                        <td><?php echo $trend['month']; ?></td>
                                        <td class="text-center"><?php echo $trend['assessment_count']; ?></td>
                                        <td class="text-center">
                                            <?php echo $trend['average_score'] !== null ? 
                                                number_format($trend['average_score'], 1) . '%' : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <?php if ($scoreChange !== 0 && $trend['average_score'] !== null): ?>
                                                <span class="<?php echo $scoreChange > 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <i class="fas fa-<?php echo $scoreChange > 0 ? 'arrow-up' : 'arrow-down'; ?> me-1"></i>
                                                    <?php echo abs(number_format($scoreChange, 1)); ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">
                                                    <i class="fas fa-minus"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        }, 5000);
    });

    // Add touch feedback for mobile devices
    if ('ontouchstart' in window) {
        const cards = document.querySelectorAll('.stats-card, .assessment-card');
        cards.forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'translateY(-2px)';
            });
            card.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
    }

    // Print handler
    window.onbeforeprint = function() {
        document.querySelectorAll('.content-card').forEach(card => {
            card.style.breakInside = 'avoid';
        });
    };
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>