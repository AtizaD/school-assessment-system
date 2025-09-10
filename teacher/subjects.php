<?php
// teacher/subjects.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
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
    
    $teacherId = $teacherInfo['teacher_id'];

    // Get current/selected semester using shared component (for link consistency)
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);

    // Check cache first with force refresh option
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $cacheKey = "teacher_subjects_{$teacherId}_all_semesters";
    $cacheFile = BASEPATH . '/cache/teacher_subjects_' . md5($cacheKey) . '.json';
    $cacheExpiry = 300; // 5 minutes
    
    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheExpiry) {
        $subjects = json_decode(file_get_contents($cacheFile), true);
    } else {
        // Split the complex query into optimized smaller queries
        
        // 1. Get basic teacher assignments for all semesters (subjects are permanent)
        $stmt = $db->prepare(
            "SELECT DISTINCT
                s.subject_id,
                s.subject_name,
                s.description,
                c.class_id,
                c.class_name,
                p.program_name
             FROM teacherclassassignments tca
             JOIN subjects s ON tca.subject_id = s.subject_id
             JOIN classes c ON tca.class_id = c.class_id
             JOIN programs p ON c.program_id = p.program_id
             WHERE tca.teacher_id = ?
             ORDER BY s.subject_name, c.class_name"
        );
        $stmt->execute([$teacherId]);
        $basicAssignments = $stmt->fetchAll();
        
        $subjects = [];
        foreach ($basicAssignments as $assignment) {
            // 2. Get student count (fast - indexed)
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
            $stmt->execute([$assignment['class_id']]);
            $studentCount = $stmt->fetchColumn();
            
            // 3. Get assessment statistics for all semesters
            $stmt = $db->prepare(
                "SELECT 
                    COUNT(*) as total_assessments,
                    COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_assessments
                 FROM assessmentclasses ac
                 JOIN assessments a ON ac.assessment_id = a.assessment_id
                 WHERE ac.class_id = ? AND ac.subject_id = ?"
            );
            $stmt->execute([$assignment['class_id'], $assignment['subject_id']]);
            $assessmentStats = $stmt->fetch();
            
            // 4. Get average score for all semesters
            $stmt = $db->prepare(
                "SELECT AVG(r.score) as avg_score
                 FROM results r
                 JOIN assessments a ON r.assessment_id = a.assessment_id
                 JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                 WHERE ac.class_id = ? AND ac.subject_id = ? 
                 AND r.status = 'completed'"
            );
            $stmt->execute([$assignment['class_id'], $assignment['subject_id']]);
            $avgScore = $stmt->fetchColumn() ?: 0;
            
            $subjects[] = [
                'subject_id' => $assignment['subject_id'],
                'subject_name' => $assignment['subject_name'],
                'description' => $assignment['description'],
                'class_id' => $assignment['class_id'],
                'class_name' => $assignment['class_name'],
                'program_name' => $assignment['program_name'],
                'student_count' => $studentCount,
                'assessment_count' => $assessmentStats['total_assessments'] ?: 0,
                'completed_assessments' => $assessmentStats['completed_assessments'] ?: 0,
                'average_score' => $avgScore
            ];
        }
        
        // Cache the results with error handling
        if (!empty($subjects)) {
            try {
                // Ensure cache directory exists
                $cacheDir = dirname($cacheFile);
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode($subjects), LOCK_EX);
            } catch (Exception $e) {
                // Log cache write error but continue
                logError("Cache write error for teacher subjects: " . $e->getMessage());
            }
        }
    }

    // Group subjects by subject name
    $groupedSubjects = [];
    foreach ($subjects as $subject) {
        if (!isset($groupedSubjects[$subject['subject_id']])) {
            $groupedSubjects[$subject['subject_id']] = [
                'subject_name' => $subject['subject_name'],
                'description' => $subject['description'],
                'classes' => [],
                'total_students' => 0,
                'total_assessments' => 0,
                'total_completed' => 0,
                'average_score' => 0,
                'class_count' => 0
            ];
        }
        $groupedSubjects[$subject['subject_id']]['classes'][] = $subject;
        $groupedSubjects[$subject['subject_id']]['total_students'] += $subject['student_count'];
        $groupedSubjects[$subject['subject_id']]['total_assessments'] += $subject['assessment_count'];
        $groupedSubjects[$subject['subject_id']]['total_completed'] += $subject['completed_assessments'];
        $groupedSubjects[$subject['subject_id']]['average_score'] += $subject['average_score'];
        $groupedSubjects[$subject['subject_id']]['class_count']++;
    }

    // Calculate averages
    foreach ($groupedSubjects as &$subject) {
        if ($subject['class_count'] > 0) {
            $subject['average_score'] /= $subject['class_count'];
        }
    }
    unset($subject);

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Teacher subjects page error: " . $e->getMessage());
}

$pageTitle = "My Subjects";
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    /* Critical CSS - loads immediately */
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    .subjects-container {
        padding: 1rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #fff9e6 100%);
        min-height: calc(100vh - 60px);
    }

    .page-header {
        background: linear-gradient(90deg, #000000, #ffd700);
        padding: 1.5rem;
        border-radius: 10px;
        color: #ffffff;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .subject-card {
        background: #ffffff;
        border-radius: 10px;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        overflow: hidden;
        transition: transform 0.2s ease;
    }

    .subject-header {
        background: linear-gradient(90deg, #000000, #ffd700);
        padding: 1.25rem;
        color: #ffffff;
    }

    .subject-body {
        padding: 1.25rem;
    }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-item {
        background: var(--light-yellow);
        padding: 1rem;
        border-radius: 8px;
        text-align: center;
    }

    .class-card {
        background: var(--primary-white);
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        transition: transform 0.2s;
    }

    .class-card:hover {
        transform: translateY(-2px);
    }

    .progress {
        height: 8px;
        background-color: rgba(0,0,0,0.1);
        border-radius: 4px;
    }

    .progress-bar {
        background: linear-gradient(90deg, var(--primary-black), var(--primary-yellow));
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

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .subjects-container {
            padding: 0.5rem;
        }

        .page-header {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0;
            margin: -0.5rem -0.5rem 1rem -0.5rem;
        }

        .subject-card {
            margin-bottom: 1rem;
        }

        .subject-header {
            padding: 1rem;
        }

        .subject-body {
            padding: 1rem;
        }

        .stats-container {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }

        .stat-item {
            padding: 0.75rem;
        }

        .class-card {
            padding: 0.75rem;
        }

        .btn-custom {
            width: 100%;
            margin-bottom: 0.5rem;
        }
    }

    /* Extra Small Devices */
    @media (max-width: 576px) {
        .stats-container {
            grid-template-columns: repeat(2, 1fr);
        }

        .stat-item h4 {
            font-size: 1.25rem;
        }

        .stat-item p {
            font-size: 0.875rem;
        }
    }
    
    /* Performance optimizations */
    .subject-card:hover {
        transform: translateY(-5px);
    }
    
    /* Loading states */
    .loading {
        text-align: center;
        padding: 2rem;
        color: #666;
    }
    
    .loading .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #ffd700;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
        margin: 0 auto 1rem;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<div class="subjects-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">My Subjects</h1>
            <div class="d-flex gap-2">
                <a href="?refresh=1" class="btn btn-custom" title="Refresh data">
                    <i class="fas fa-sync-alt me-2"></i>Refresh
                </a>
                <button type="button" class="btn btn-custom" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
        <p class="mb-0 mt-2">Showing all subjects (stats include all semesters)</p>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($groupedSubjects)): ?>
        <div class="text-center py-5">
            <i class="fas fa-books fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No subjects assigned yet</h5>
        </div>
    <?php else: ?>
        <?php foreach ($groupedSubjects as $subjectId => $subject): ?>
            <div class="subject-card">
                <div class="subject-header">
                    <h5 class="mb-1"><?php echo htmlspecialchars($subject['subject_name']); ?></h5>
                    <?php if ($subject['description']): ?>
                        <p class="mb-0 small"><?php echo htmlspecialchars($subject['description']); ?></p>
                    <?php endif; ?>
                </div>

                <div class="subject-body">
                    <!-- Subject Statistics -->
                    <div class="stats-container">
                        <div class="stat-item">
                            <p class="text-muted mb-1">Classes</p>
                            <h4 class="mb-0"><?php echo $subject['class_count']; ?></h4>
                        </div>
                        <div class="stat-item">
                            <p class="text-muted mb-1">Students</p>
                            <h4 class="mb-0"><?php echo $subject['total_students']; ?></h4>
                        </div>
                        <div class="stat-item">
                            <p class="text-muted mb-1">Assessments</p>
                            <h4 class="mb-0"><?php echo $subject['total_assessments']; ?></h4>
                        </div>
                        <div class="stat-item">
                            <p class="text-muted mb-1">Average Score</p>
                            <h4 class="mb-0"><?php echo number_format($subject['average_score'], 1); ?>%</h4>
                        </div>
                    </div>

                    <!-- Class List -->
                    <h6 class="mb-3">Classes</h6>
                    <?php foreach ($subject['classes'] as $class): ?>
                        <div class="class-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($class['class_name']); ?></h6>
                                    <p class="mb-0 small text-muted">
                                        <?php echo htmlspecialchars($class['program_name']); ?>
                                    </p>
                                </div>
                                <span class="badge-custom">
                                    <?php echo $class['student_count']; ?> students
                                </span>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Assessments Progress</small>
                                    <small class="text-muted">
                                        <?php echo $class['completed_assessments']; ?>/<?php echo $class['assessment_count']; ?>
                                    </small>
                                </div>
                                <div class="progress">
                                    <?php 
                                    $progressPercentage = $class['assessment_count'] > 0 ? 
                                        ($class['completed_assessments'] / $class['assessment_count']) * 100 : 0;
                                    ?>
                                    <div class="progress-bar" style="width: <?php echo $progressPercentage; ?>%"></div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small">
                                    <span class="text-success">
                                        <i class="fas fa-chart-line me-1"></i>
                                        <?php echo number_format($class['average_score'], 1); ?>% avg
                                    </span>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="assessments.php?class=<?php echo $class['class_id']; ?>&subject=<?php echo $subjectId; ?>&semester=<?php echo $currentSemester['semester_id']; ?>" 
                                       class="btn btn-sm btn-custom">
                                        <i class="fas fa-tasks me-1"></i>
                                        <span class="d-none d-sm-inline">Assessments</span>
                                    </a>
                                    <a href="class_details.php?id=<?php echo $class['class_id']; ?>&subject_id=<?php echo $subjectId; ?>&semester=<?php echo $currentSemester['semester_id']; ?>" 
                                       class="btn btn-sm btn-custom">
                                        <i class="fas fa-eye me-1"></i>
                                        <span class="d-none d-sm-inline">View</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Optimized lazy-loaded JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Use requestIdleCallback for non-critical operations
    if ('requestIdleCallback' in window) {
        requestIdleCallback(initializeEnhancements);
    } else {
        setTimeout(initializeEnhancements, 100);
    }
    
    // Critical operations first
    handleAlerts();
});

function initializeEnhancements() {
    // Initialize tooltips only if they exist
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (tooltips.length > 0 && typeof bootstrap !== 'undefined') {
        tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));
    }

    // Touch feedback for mobile (only if touch supported)
    if ('ontouchstart' in window) {
        addTouchFeedback();
    }

    // Print optimization
    if ('onbeforeprint' in window) {
        window.onbeforeprint = () => {
            document.querySelectorAll('.subject-card').forEach(card => {
                card.style.breakInside = 'avoid';
            });
        };
    }
}

function addTouchFeedback() {
    const cards = document.querySelectorAll('.subject-card, .class-card');
    cards.forEach(card => {
        card.addEventListener('touchstart', function() {
            this.style.transform = 'translateY(-2px)';
        }, { passive: true });
        card.addEventListener('touchend', function() {
            this.style.transform = '';
        }, { passive: true });
    });
}

function handleAlerts() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    if (alerts.length > 0 && typeof bootstrap !== 'undefined') {
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }, 5000);
        });
    }
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>