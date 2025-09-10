<?php
// student/progress.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$overallStats = [];
$subjectProgress = [];
$recentAssessments = [];
$progressChart = [];
$performanceData = [];

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id, s.first_name, s.last_name,
                c.class_name, p.program_name 
         FROM students s 
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name, start_date, end_date
         FROM semesters 
         WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    if (!$currentSemester) {
        // Get most recent semester if no current one
        $stmt = $db->prepare(
            "SELECT semester_id, semester_name, start_date, end_date 
             FROM semesters 
             ORDER BY end_date DESC LIMIT 1"
        );
        $stmt->execute();
        $currentSemester = $stmt->fetch();
    }

    if (!$currentSemester) {
        throw new Exception('No semester data found');
    }

    // Get overall statistics
    $stmt = $db->prepare(
        "SELECT 
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.assessment_id END) as completed_assessments,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END), 1) as average_percentage,
            MAX(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END) as highest_percentage,
            MIN(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END) as lowest_percentage,
            COUNT(DISTINCT ac.subject_id) as total_subjects
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE ac.class_id = ? AND a.semester_id = ?"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
    $overallStats = $stmt->fetch();

    // Calculate completion rate
    $overallStats['completion_rate'] = $overallStats['total_assessments'] > 0 
        ? round(($overallStats['completed_assessments'] / $overallStats['total_assessments']) * 100, 1)
        : 0;

    // Get subject-wise progress
    $stmt = $db->prepare(
        "SELECT 
            s.subject_id,
            s.subject_name,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.assessment_id END) as completed_assessments,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END), 1) as average_percentage,
            MAX(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END) as highest_percentage,
            MIN(CASE WHEN r.status = 'completed' THEN 
                (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 
            END) as lowest_percentage
         FROM subjects s
         JOIN assessmentclasses ac ON s.subject_id = ac.subject_id
         JOIN assessments a ON ac.assessment_id = a.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE ac.class_id = ? AND a.semester_id = ?
         GROUP BY s.subject_id, s.subject_name
         ORDER BY average_percentage DESC"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
    $subjectProgress = $stmt->fetchAll();

    // Calculate completion rates for subjects
    foreach ($subjectProgress as &$subject) {
        $subject['completion_rate'] = $subject['total_assessments'] > 0 
            ? round(($subject['completed_assessments'] / $subject['total_assessments']) * 100, 1)
            : 0;
        
        // Determine performance level
        if ($subject['average_percentage'] >= 85) {
            $subject['performance_level'] = 'excellent';
        } elseif ($subject['average_percentage'] >= 75) {
            $subject['performance_level'] = 'good';
        } elseif ($subject['average_percentage'] >= 60) {
            $subject['performance_level'] = 'satisfactory';
        } else {
            $subject['performance_level'] = 'needs_improvement';
        }
    }

    // Get recent assessments for timeline
    $stmt = $db->prepare(
        "SELECT 
            a.assessment_id,
            a.title,
            a.date,
            s.subject_name,
            r.score,
            r.status,
            r.created_at,
            (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id) as total_possible,
            CASE WHEN r.status = 'completed' THEN 
                ROUND((r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100, 1)
            END as percentage
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE ac.class_id = ? AND a.semester_id = ?
         AND r.status = 'completed'
         ORDER BY r.created_at DESC
         LIMIT 10"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
    $recentAssessments = $stmt->fetchAll();

    // Get progress chart data (last 30 days)
    $stmt = $db->prepare(
        "SELECT 
            DATE(r.created_at) as assessment_date,
            ROUND(AVG((r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100), 1) as avg_percentage,
            COUNT(*) as assessment_count
         FROM results r
         JOIN assessments a ON r.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         WHERE r.student_id = ? AND ac.class_id = ? 
         AND r.status = 'completed'
         AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY DATE(r.created_at)
         ORDER BY assessment_date ASC"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
    $progressChart = $stmt->fetchAll();

    // Get performance distribution
    $stmt = $db->prepare(
        "SELECT 
            CASE 
                WHEN (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 >= 90 THEN 'A (90-100%)'
                WHEN (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 >= 80 THEN 'B (80-89%)'
                WHEN (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 >= 70 THEN 'C (70-79%)'
                WHEN (r.score / (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id)) * 100 >= 60 THEN 'D (60-69%)'
                ELSE 'F (Below 60%)'
            END as grade_range,
            COUNT(*) as count
         FROM results r
         JOIN assessments a ON r.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         WHERE r.student_id = ? AND ac.class_id = ? 
         AND r.status = 'completed' AND a.semester_id = ?
         GROUP BY grade_range
         ORDER BY grade_range"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
    $performanceData = $stmt->fetchAll();

} catch (Exception $e) {
    logError("Progress page error: " . $e->getMessage());
    $error = "Error loading progress data: " . $e->getMessage();
}

$pageTitle = 'My Progress';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 text-warning">My Progress</h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?> | 
                <?php echo htmlspecialchars($studentInfo['program_name']); ?> | 
                <?php echo htmlspecialchars($studentInfo['class_name']); ?>
            </p>
        </div>
        <div class="text-end">
            <small class="text-muted">
                <?php echo htmlspecialchars($currentSemester['semester_name']); ?><br>
                <?php echo date('M d, Y', strtotime($currentSemester['start_date'])); ?> - 
                <?php echo date('M d, Y', strtotime($currentSemester['end_date'])); ?>
            </small>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <!-- Overall Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-header" style="background: linear-gradient(145deg, #2c3e50 0%, #3498db 100%);">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $overallStats['completed_assessments']; ?>/<?php echo $overallStats['total_assessments']; ?></h3>
                            <p>Assessments Completed</p>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <div class="progress mb-2">
                            <div class="progress-bar bg-info" style="width: <?php echo $overallStats['completion_rate']; ?>%"></div>
                        </div>
                        <small class="text-muted"><?php echo $overallStats['completion_rate']; ?>% completion rate</small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-header" style="background: linear-gradient(145deg, #000 0%, #617186 100%);">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $overallStats['average_percentage'] ?? 'N/A'; ?>%</h3>
                            <p>Average Score</p>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <?php if ($overallStats['average_percentage']): ?>
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    <i class="fas fa-arrow-up text-success"></i>
                                    High: <?php echo $overallStats['highest_percentage']; ?>%
                                </small>
                                <small class="text-muted">
                                    <i class="fas fa-arrow-down text-danger"></i>
                                    Low: <?php echo $overallStats['lowest_percentage']; ?>%
                                </small>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">No completed assessments yet</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <div class="stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $overallStats['total_subjects']; ?></h3>
                            <p>Active Subjects</p>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <?php 
                        $excellentSubjects = array_filter($subjectProgress, function($s) { 
                            return ($s['average_percentage'] ?? 0) >= 85; 
                        });
                        ?>
                        <small class="text-muted">
                            <?php echo count($excellentSubjects); ?> performing excellently
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="stat-header" style="background: linear-gradient(145deg, #2c3e50 0%, #d35400 100%);">
                        <div class="stat-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-info">
                            <h3>
                                <?php 
                                if ($overallStats['average_percentage'] >= 90) echo 'A';
                                elseif ($overallStats['average_percentage'] >= 80) echo 'B';
                                elseif ($overallStats['average_percentage'] >= 70) echo 'C';
                                elseif ($overallStats['average_percentage'] >= 60) echo 'D';
                                else echo $overallStats['average_percentage'] ? 'F' : 'N/A';
                                ?>
                            </h3>
                            <p>Overall Grade</p>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <?php 
                        $performance = '';
                        if ($overallStats['average_percentage'] >= 85) $performance = 'Excellent';
                        elseif ($overallStats['average_percentage'] >= 75) $performance = 'Good';
                        elseif ($overallStats['average_percentage'] >= 60) $performance = 'Satisfactory';
                        else $performance = $overallStats['average_percentage'] ? 'Needs Improvement' : 'No Data';
                        ?>
                        <small class="text-muted"><?php echo $performance; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">
                            <i class="fas fa-chart-line me-2"></i>Performance Trend (Last 30 Days)
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="progressChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">
                            <i class="fas fa-chart-pie me-2"></i>Grade Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="gradeChart" height="150"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject Progress -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">
                            <i class="fas fa-book me-2"></i>Subject Performance
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($subjectProgress)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-book text-warning fa-2x mb-2"></i>
                                <p class="text-muted mb-0">No subject data available yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($subjectProgress as $subject): ?>
                                    <div class="col-lg-6">
                                        <div class="subject-progress-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                                                <span class="badge bg-<?php 
                                                    echo match($subject['performance_level']) {
                                                        'excellent' => 'success',
                                                        'good' => 'info',
                                                        'satisfactory' => 'warning',
                                                        'needs_improvement' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?> <?php echo $subject['performance_level'] === 'warning' ? 'text-dark' : ''; ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $subject['performance_level'])); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="row g-3 mb-3">
                                                <div class="col-4 text-center">
                                                    <div class="metric-value"><?php echo $subject['average_percentage'] ?? 'N/A'; ?>%</div>
                                                    <div class="metric-label">Average</div>
                                                </div>
                                                <div class="col-4 text-center">
                                                    <div class="metric-value"><?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?></div>
                                                    <div class="metric-label">Completed</div>
                                                </div>
                                                <div class="col-4 text-center">
                                                    <div class="metric-value"><?php echo $subject['highest_percentage'] ?? 'N/A'; ?>%</div>
                                                    <div class="metric-label">Best</div>
                                                </div>
                                            </div>
                                            
                                            <div class="progress mb-2" style="height: 8px;">
                                                <div class="progress-bar bg-<?php 
                                                    echo match($subject['performance_level']) {
                                                        'excellent' => 'success',
                                                        'good' => 'info',
                                                        'satisfactory' => 'warning',
                                                        'needs_improvement' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>" style="width: <?php echo $subject['completion_rate']; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $subject['completion_rate']; ?>% assessments completed</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Assessments -->
        <div class="row g-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">
                            <i class="fas fa-history me-2"></i>Recent Assessment Results
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentAssessments)): ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-clipboard-list text-warning fa-2x mb-2"></i>
                                <p class="text-muted mb-0">No recent assessment results to display.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="py-3">Assessment</th>
                                            <th class="py-3">Subject</th>
                                            <th class="py-3">Date Taken</th>
                                            <th class="py-3">Score</th>
                                            <th class="py-3">Grade</th>
                                            <th class="py-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAssessments as $assessment): ?>
                                            <tr>
                                                <td class="py-3"><?php echo htmlspecialchars($assessment['title']); ?></td>
                                                <td class="py-3"><?php echo htmlspecialchars($assessment['subject_name']); ?></td>
                                                <td class="py-3"><?php echo date('M d, Y g:i A', strtotime($assessment['created_at'])); ?></td>
                                                <td class="py-3">
                                                    <span class="score-display">
                                                        <?php echo $assessment['percentage']; ?>%
                                                        <small class="text-muted d-block">
                                                            <?php echo $assessment['score']; ?>/<?php echo $assessment['total_possible']; ?> points
                                                        </small>
                                                    </span>
                                                </td>
                                                <td class="py-3">
                                                    <?php 
                                                    $percentage = $assessment['percentage'];
                                                    if ($percentage >= 90) {
                                                        echo '<span class="badge bg-success">A</span>';
                                                    } elseif ($percentage >= 80) {
                                                        echo '<span class="badge bg-info">B</span>';
                                                    } elseif ($percentage >= 70) {
                                                        echo '<span class="badge bg-warning text-dark">C</span>';
                                                    } elseif ($percentage >= 60) {
                                                        echo '<span class="badge bg-warning text-dark">D</span>';
                                                    } else {
                                                        echo '<span class="badge bg-danger">F</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="py-3">
                                                    <a href="view_result.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                                       class="btn btn-sm btn-warning">
                                                        <i class="fas fa-eye me-1"></i>View Details
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<style>
:root {
    --primary: #000000;
    --gold: #ffd700;
    --white: #ffffff;
    --gray: #f8f9fa;
    --dark-gray: #343a40;
    --shadow: rgba(0, 0, 0, 0.1);
}

/* Stat Cards */
.stat-card {
    background: var(--white);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 15px var(--shadow);
    transition: transform 0.3s ease;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-header {
    padding: 1.5rem;
    color: var(--white);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-info h3 {
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.stat-info p {
    margin: 0;
    opacity: 0.9;
    font-size: 0.9rem;
}

.stat-footer {
    padding: 1rem 1.5rem;
    background: var(--gray);
}

/* Subject Progress Cards */
.subject-progress-card {
    background: var(--white);
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 1rem;
    transition: all 0.3s ease;
}

.subject-progress-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px var(--shadow);
}

.metric-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--dark-gray);
}

.metric-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Score Display */
.score-display {
    font-weight: 600;
}

/* Progress Bars */
.progress {
    height: 8px;
    border-radius: 4px;
    background-color: rgba(0,0,0,0.1);
}

.progress-bar {
    border-radius: 4px;
}

/* Cards */
.card {
    border: none;
    transition: transform 0.2s;
    box-shadow: 0 4px 15px var(--shadow);
}

.card:hover {
    transform: translateY(-2px);
}

.card-header {
    border-bottom: none;
}

.btn-warning {
    color: #000;
    background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);
    border: none;
}

.btn-warning:hover {
    background: linear-gradient(145deg, #ff6f00 0%, #ffc107 100%);
    color: #000;
}

.text-warning {
    color: #ffc107 !important;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
}

.table > :not(caption) > * > * {
    padding: 1rem;
}

/* Responsive Design */
@media (max-width: 991.98px) {
    .stat-header {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .stat-info h3 {
        font-size: 1.5rem;
    }
}

@media (max-width: 767.98px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .badge {
        font-size: 0.7rem;
        padding: 0.3em 0.5em;
    }
}
</style>

<script src="<?php echo BASE_URL; ?>/assets/js/external/chart-4.4.1.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Progress Trend Chart
    const progressCtx = document.getElementById('progressChart').getContext('2d');
    const progressData = <?php echo json_encode($progressChart); ?>;
    
    const progressChart = new Chart(progressCtx, {
        type: 'line',
        data: {
            labels: progressData.map(item => new Date(item.assessment_date).toLocaleDateString()),
            datasets: [{
                label: 'Average Score %',
                data: progressData.map(item => item.avg_percentage),
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#ffc107',
                pointBorderColor: '#000',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });

    // Grade Distribution Chart
    const gradeCtx = document.getElementById('gradeChart').getContext('2d');
    const gradeData = <?php echo json_encode($performanceData); ?>;
    
    const gradeChart = new Chart(gradeCtx, {
        type: 'doughnut',
        data: {
            labels: gradeData.map(item => item.grade_range),
            datasets: [{
                data: gradeData.map(item => item.count),
                backgroundColor: [
                    '#28a745', // A - Green
                    '#17a2b8', // B - Blue
                    '#ffc107', // C - Yellow
                    '#fd7e14', // D - Orange
                    '#dc3545'  // F - Red
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            }
        }
    });

    // Add smooth animations for card hover effects
    const cards = document.querySelectorAll('.card, .stat-card, .subject-progress-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            if (!this.classList.contains('stat-card')) {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.15)';
                this.style.transition = 'all 0.3s ease';
            }
        });
        
        card.addEventListener('mouseleave', function() {
            if (!this.classList.contains('stat-card')) {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.1)';
            }
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>