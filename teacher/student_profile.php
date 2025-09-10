<?php
// teacher/student_profile.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
requireRole('teacher');

// Start output buffering
ob_start();

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher information - FIXED: lowercase table name
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    // Get student info from session or URL encoded data
    $studentId = isset($_GET['id']) ? intval($_GET['id']) : null;
    $studentUsername = isset($_GET['student']) ? sanitizeInput($_GET['student']) : null;

    if (!$studentId && !$studentUsername) {
        throw new Exception('Student not specified');
    }

    // Modify the query to find by either ID or username
    if ($studentId) {
        $stmt = $db->prepare(
            "SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            c.class_name,
            c.class_id,
            p.program_name,
            u.username
         FROM students s
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         JOIN users u ON s.user_id = u.user_id
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id
         WHERE tca.teacher_id = ? AND s.student_id = ?"
        );
        $stmt->execute([$teacherInfo['teacher_id'], $studentId]);
    } else {
        $stmt = $db->prepare(
            "SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            c.class_name,
            c.class_id,
            p.program_name,
            u.username
         FROM students s
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         JOIN users u ON s.user_id = u.user_id
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id
         WHERE tca.teacher_id = ? AND u.username = ?"
        );
        $stmt->execute([$teacherInfo['teacher_id'], $studentUsername]);
    }
    $studentInfo = $stmt->fetch();
    if (!$studentInfo) {
        throw new Exception('Student not found or access denied');
    }

    // Get current/selected semester using shared component
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);

    // Get student's assessment performance - FIXED: completely restructured query to match schema
    $stmt = $db->prepare(
        "SELECT 
            s.subject_name,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT aa.assessment_id) as attempted_assessments,
            COALESCE(AVG(r.score), 0) as average_score,
            MAX(r.score) as highest_score,
            MIN(CASE WHEN r.score > 0 THEN r.score END) as lowest_score,
            COUNT(DISTINCT CASE WHEN r.score >= 50 THEN r.result_id END) as passed_assessments
         FROM teacherclassassignments tca
         JOIN subjects s ON tca.subject_id = s.subject_id
         LEFT JOIN assessmentclasses ac ON tca.class_id = ac.class_id AND tca.subject_id = ac.subject_id
         LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id AND a.semester_id = ?
         LEFT JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id AND aa.student_id = ?
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ? AND r.status = 'completed'
         WHERE tca.teacher_id = ? AND tca.class_id = ?
         GROUP BY s.subject_name"
    );
    $stmt->execute([
        $currentSemester['semester_id'],
        $studentInfo['student_id'],
        $studentInfo['student_id'],
        $teacherInfo['teacher_id'],
        $studentInfo['class_id']
    ]);
    $subjectPerformance = $stmt->fetchAll();

    // Get recent assessment attempts - FIXED: join with assessmentclasses table and adjust join conditions
    $stmt = $db->prepare(
        "SELECT 
            a.title,
            a.date,
            s.subject_name,
            aa.status as attempt_status,
            r.score,
            aa.start_time,
            aa.end_time
         FROM assessmentattempts aa
         JOIN assessments a ON aa.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN results r ON aa.assessment_id = r.assessment_id AND r.student_id = aa.student_id
         WHERE aa.student_id = ?
         ORDER BY aa.start_time DESC
         LIMIT 5"
    );
    $stmt->execute([$studentInfo['student_id']]);
    $recentAttempts = $stmt->fetchAll();

    // Calculate overall statistics
    $overallStats = [
        'total_assessments' => 0,
        'completed_assessments' => 0,
        'average_score' => 0,
        'pass_rate' => 0
    ];

    foreach ($subjectPerformance as $subject) {
        $overallStats['total_assessments'] += $subject['total_assessments'];
        $overallStats['completed_assessments'] += $subject['attempted_assessments'];
        if ($subject['average_score'] > 0) {
            $overallStats['average_score'] += $subject['average_score'];
        }
        if ($subject['total_assessments'] > 0) {
            $overallStats['pass_rate'] += ($subject['passed_assessments'] / $subject['total_assessments']) * 100;
        }
    }

    if (count($subjectPerformance) > 0) {
        $overallStats['average_score'] /= count($subjectPerformance);
        $overallStats['pass_rate'] /= count($subjectPerformance);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Student profile page error: " . $e->getMessage());
}

$pageTitle = 'Student Profile';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    /* Custom styles for Student Profile page */
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ffcc00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    .profile-container {
        padding: 1rem;
        max-width: 100%;
        margin: 0 auto;
    }

    .profile-header {
        background: linear-gradient(90deg, var(--primary-black) 0%, var(--primary-yellow) 100%);
        padding: 2rem;
        border-radius: 10px;
        color: var(--primary-white);
        margin-bottom: 2rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .stat-card {
        background: var(--primary-white);
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s ease;
        border-left: 4px solid var(--primary-yellow);
    }

    .stat-card:hover {
        transform: translateY(-2px);
    }

    .performance-card {
        background: var(--primary-white);
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .progress {
        height: 8px;
        background-color: var(--light-yellow);
        border-radius: 4px;
        overflow: hidden;
    }

    .progress-bar {
        background: linear-gradient(90deg, var(--primary-black), var(--primary-yellow));
    }

    .attempt-card {
        background: var(--primary-white);
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(0, 0, 0, 0.05);
        transition: transform 0.2s ease;
    }

    .attempt-card:hover {
        transform: translateY(-2px);
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .status-badge.completed {
        background-color: #d4edda;
        color: #155724;
    }

    .status-badge.in_progress {
        background-color: #fff3cd;
        color: #856404;
    }

    .status-badge.expired {
        background-color: #f8d7da;
        color: #721c24;
    }

    .btn-custom {
        background: linear-gradient(45deg, var(--primary-black), var(--primary-yellow));
        border: none;
        color: var(--primary-white);
        padding: 0.5rem 1.5rem;
        border-radius: 5px;
        transition: all 0.2s ease;
    }

    .btn-custom:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        color: var(--primary-white);
    }

    @media (max-width: 768px) {
        .profile-container {
            padding: 0.5rem;
        }

        .profile-header {
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-card,
        .performance-card {
            padding: 1rem;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
        }
    }
</style>

<div class="profile-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <div class="profile-header">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0">Student Profile</h1>
                <a href="students.php?semester=<?php echo $currentSemester['semester_id']; ?>" class="btn btn-custom btn-sm">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <h4><?php echo htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?></h4>
                    <p class="mb-0">
                        <i class="fas fa-id-card me-2"></i>
                        <?php echo htmlspecialchars($studentInfo['username']); ?>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-1">
                        <i class="fas fa-graduation-cap me-2"></i>
                        <?php echo htmlspecialchars($studentInfo['program_name']); ?>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-chalkboard me-2"></i>
                        <?php echo htmlspecialchars($studentInfo['class_name']); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-calendar me-2"></i>
                        Semester: <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Overall Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <h6 class="text-muted mb-2">Total Assessments</h6>
                    <h3 class="mb-0"><?php echo $overallStats['total_assessments']; ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <h6 class="text-muted mb-2">Completed</h6>
                    <h3 class="mb-0"><?php echo $overallStats['completed_assessments']; ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <h6 class="text-muted mb-2">Average Score</h6>
                    <h3 class="mb-0"><?php echo number_format($overallStats['average_score'], 1); ?>%</h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <h6 class="text-muted mb-2">Pass Rate</h6>
                    <h3 class="mb-0"><?php echo number_format($overallStats['pass_rate'], 1); ?>%</h3>
                </div>
            </div>
        </div>

        <!-- Subject Performance -->
        <div class="row">
            <div class="col-lg-8">
                <div class="performance-card">
                    <h5 class="mb-4">Subject Performance</h5>
                    <?php if (empty($subjectPerformance)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-book fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No subject performance data available</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($subjectPerformance as $subject): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                                    <span class="text-muted">
                                        <?php echo $subject['attempted_assessments']; ?>/<?php echo $subject['total_assessments']; ?> completed
                                    </span>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar" style="width: <?php echo is_null($subject['average_score']) ? 0 : $subject['average_score']; ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">Average: <?php echo is_null($subject['average_score']) ? 'N/A' : number_format($subject['average_score'], 1) . '%'; ?></small>
                                    <small class="text-muted">
                                        Highest: <?php echo is_null($subject['highest_score']) ? 'N/A' : number_format($subject['highest_score'], 1) . '%'; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Assessment Attempts -->
            <div class="col-lg-4">
                <div class="performance-card">
                    <h5 class="mb-4">Recent Attempts</h5>
                    <?php if (empty($recentAttempts)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No recent assessment attempts</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentAttempts as $attempt): ?>
                            <div class="attempt-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($attempt['title']); ?></h6>
                                        <small class="text-muted d-block">
                                            <?php echo htmlspecialchars($attempt['subject_name']); ?>
                                        </small>
                                    </div>
                                    <span class="status-badge <?php echo $attempt['attempt_status']; ?>">
                                        <?php echo ucfirst($attempt['attempt_status']); ?>
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted small">
                                        <i class="far fa-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($attempt['date'])); ?>
                                    </div>
                                    <?php if ($attempt['score'] !== null): ?>
                                        <div class="fw-bold">
                                            <?php echo number_format($attempt['score'], 1); ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($attempt['start_time'] && $attempt['end_time']): ?>
                                    <div class="text-muted small mt-2">
                                        <i class="far fa-clock me-1"></i>
                                        Duration:
                                        <?php
                                        $start = new DateTime($attempt['start_time']);
                                        $end = new DateTime($attempt['end_time']);
                                        $duration = $start->diff($end);
                                        echo $duration->format('%H:%I:%S');
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="text-center mt-3">
                            <a href="student_result.php?student=<?php echo $studentInfo['student_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                class="btn btn-custom btn-sm">
                                View All Results
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });

        // Add touch feedback for mobile devices
        const cards = document.querySelectorAll('.stat-card, .attempt-card');
        cards.forEach(card => {
            card.addEventListener('touchstart', () => {
                card.style.transform = 'translateY(-2px)';
            });
            card.addEventListener('touchend', () => {
                card.style.transform = 'translateY(0)';
            });
        });

        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);
        });

        // Add smooth scrolling for mobile devices
        if (window.innerWidth <= 768) {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
        }
    });
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>