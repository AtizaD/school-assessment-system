<?php
// student/index.php 
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

// Ensure user is logged in and has student role
requireRole('student');

$error = '';
$stats = [
    'total_assessments' => 0,
    'completed_assessments' => 0,
    'average_score' => 0,
    'highest_score' => 0
];
$upcomingAssessments = [];
$recentResults = [];
$todayAssessments = ['count' => 0];
$studentInfo = [
    'first_name' => '',
    'last_name' => '',
    'program_name' => '',
    'class_name' => ''
];
$currentSemester = ['semester_id' => null, 'semester_name' => '', 'is_double_track' => 0];
$subjectPerformance = [];
$inProgressAssessment = false;

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get student info with class details
    $stmt = $db->prepare(
        "SELECT s.*, c.class_name, p.program_name 
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

    // Get current semester (using same logic as teacher semester selector)
    $stmt = $db->prepare("
        SELECT DISTINCT s.semester_id, s.semester_name, s.is_double_track
        FROM semesters s
        LEFT JOIN semester_forms sf ON s.semester_id = sf.semester_id
        WHERE (
            (s.is_double_track = 0 AND s.start_date <= CURDATE() AND s.end_date >= CURDATE())
            OR 
            (s.is_double_track = 1 AND sf.start_date <= CURDATE() AND sf.end_date >= CURDATE())
        )
        ORDER BY s.semester_id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    // If no current semester found, get the most recent one
    if (!$currentSemester) {
        $stmt = $db->prepare(
            "SELECT semester_id, semester_name, is_double_track
             FROM semesters 
             ORDER BY semester_id DESC 
             LIMIT 1"
        );
        $stmt->execute();
        $currentSemester = $stmt->fetch();
    }
    
    // If still no semester found, set default values
    if (!$currentSemester) {
        $currentSemester = [
            'semester_id' => null,
            'semester_name' => 'No Active Semester',
            'is_double_track' => 0
        ];
    }

    // Get summary statistics (only if we have a valid semester)
    if ($currentSemester['semester_id']) {
        $stmt = $db->prepare(
            "SELECT 
                COUNT(DISTINCT a.assessment_id) as total_assessments,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.assessment_id END) as completed_assessments,
                ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score,
                MAX(CASE WHEN r.status = 'completed' THEN r.score END) as highest_score
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.class_id = ? AND a.semester_id = ?"
        );
        $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
        $fetchedStats = $stmt->fetch();
        if ($fetchedStats) {
            $stats = $fetchedStats;
        }
    }

    // Get today's date
    $today = date('Y-m-d');

    // Get upcoming assessments (next 7 days) - including allow_late_submission
    if ($currentSemester['semester_id']) {
        $stmt = $db->prepare(
            "SELECT 
                a.assessment_id, a.title, a.date, a.start_time, a.end_time, 
                a.duration, a.allow_late_submission, s.subject_name,
                DATEDIFF(a.date, ?) as days_until
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             JOIN subjects s ON ac.subject_id = s.subject_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.class_id = ? 
             AND a.semester_id = ?
             AND a.date >= ?
             AND a.date <= DATE_ADD(?, INTERVAL 7 DAY)
             AND (r.status IS NULL OR r.status != 'completed')
             AND a.status = 'pending'
             ORDER BY a.date, a.start_time
             LIMIT 5"
        );
        $stmt->execute([$today, $studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id'], $today, $today]);
        $upcomingAssessments = $stmt->fetchAll();
    }

    // Get recent results (last 5)
    $stmt = $db->prepare(
        "SELECT 
            r.result_id, r.score, r.created_at,
            a.assessment_id, a.title, a.date,
            s.subject_name,
            (SELECT SUM(q.max_score) FROM questions q WHERE q.assessment_id = a.assessment_id) as total_possible
         FROM results r
         JOIN assessments a ON r.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         WHERE r.student_id = ?
         AND ac.class_id = ?
         AND r.status = 'completed'
         ORDER BY r.created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
    $recentResults = $stmt->fetchAll();

    // Get subject performance
    if ($currentSemester['semester_id']) {
        $stmt = $db->prepare(
            "SELECT 
                s.subject_name,
                COUNT(DISTINCT a.assessment_id) as total_assessments,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.assessment_id END) as completed_assessments,
                ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 1) as average_score
             FROM subjects s
             JOIN assessmentclasses ac ON s.subject_id = ac.subject_id
             JOIN assessments a ON ac.assessment_id = a.assessment_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.class_id = ? AND a.semester_id = ?
             GROUP BY s.subject_id, s.subject_name
             ORDER BY average_score DESC"
        );
        $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $currentSemester['semester_id']]);
        $subjectPerformance = $stmt->fetchAll();
    }

    // Get notification count
    $stmt = $db->prepare(
        "SELECT COUNT(*) as count
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         LEFT JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id AND aa.student_id = ?
         WHERE ac.class_id = ? 
         AND a.date = ?
         AND a.status = 'pending'
         AND aa.attempt_id IS NULL"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id'], $today]);
    $todayAssessments = $stmt->fetch();

    // Check if there's an assessment in progress
    $stmt = $db->prepare(
        "SELECT aa.assessment_id, aa.attempt_id, a.title, s.subject_name, aa.start_time
         FROM assessmentattempts aa
         JOIN assessments a ON aa.assessment_id = a.assessment_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         WHERE aa.student_id = ? 
         AND aa.status = 'in_progress'
         AND ac.class_id = ?
         ORDER BY aa.start_time DESC
         LIMIT 1"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
    $inProgressAssessment = $stmt->fetch();

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Dashboard error: " . $e->getMessage());
}

$pageTitle = 'Student Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="welcome-card">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?>!</h1>
                        <p class="welcome-info">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($studentInfo['program_name']); ?> | 
                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($studentInfo['class_name']); ?> | 
                            <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($currentSemester['semester_name'] ?? 'No Active Semester'); ?>
                        </p>
                        <?php if ($todayAssessments['count'] > 0): ?>
                            <div class="notification-alert">
                                <i class="fas fa-bell"></i> You have <?php echo $todayAssessments['count']; ?> assessment<?php echo $todayAssessments['count'] > 1 ? 's' : ''; ?> scheduled for today!
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="assessments.php" class="btn btn-primary">
                            <i class="fas fa-tasks me-2"></i>View All Assessments
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Stats Cards Row -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card assessments-card">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo $stats['total_assessments'] ?? 0; ?></h3>
                    <p class="stat-label">Total Assessments</p>
                    <div class="stat-progress">
                        <?php 
                        $completionPercentage = ($stats['total_assessments'] > 0) 
                            ? round(($stats['completed_assessments'] / $stats['total_assessments']) * 100) 
                            : 0;
                        ?>
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $completionPercentage; ?>%"></div>
                        </div>
                        <p class="stat-detail"><?php echo $stats['completed_assessments'] ?? 0; ?> completed (<?php echo $completionPercentage; ?>%)</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card score-card">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo $stats['average_score'] ?? 'N/A'; ?>%</h3>
                    <p class="stat-label">Average Score</p>
                    <p class="stat-detail">
                        <i class="fas fa-trophy text-warning"></i> Highest: <?php echo $stats['highest_score'] ?? 'N/A'; ?>%
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card upcoming-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo count($upcomingAssessments); ?></h3>
                    <p class="stat-label">Upcoming Assessments</p>
                    <p class="stat-detail">
                        <?php if (count($upcomingAssessments) > 0): ?>
                            <i class="fas fa-clock text-info"></i> Next: <?php echo date('M d', strtotime($upcomingAssessments[0]['date'])); ?>
                        <?php else: ?>
                            <i class="fas fa-check-circle text-success"></i> No upcoming assessments
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card date-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo date('F d, Y'); ?></h3>
                    <p class="stat-label">Today's Date</p>
                    <p class="stat-detail">
                        <i class="fas fa-clock"></i> <?php echo date('l'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Rows -->
    <div class="row">
        <!-- Upcoming Assessments Column -->
        <div class="col-xl-6 mb-4">
            <div class="content-card">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Upcoming Assessments</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingAssessments)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <p>No upcoming assessments in the next 7 days.</p>
                            <a href="schedule.php" class="btn btn-sm btn-outline-primary">View Full Schedule</a>
                        </div>
                    <?php else: ?>
                        <div class="upcoming-list">
                            <?php foreach ($upcomingAssessments as $assessment): 
                                // Calculate availability with proper time checking
                                $now = date('H:i:s');
                                $currentDate = date('Y-m-d');
                                $startTime = $assessment['start_time'] ?? '00:00:00';
                                $endTime = $assessment['end_time'] ?? '23:59:59';
                                
                                // Check if assessment is available right now
                                $isToday = ($assessment['date'] === $currentDate);
                                $isTimeAvailable = ($now >= $startTime && $now <= $endTime);
                                $allowLateSubmission = $assessment['allow_late_submission'] ?? false;
                                
                                // Assessment is available if:
                                // 1. It's today AND current time is within the time window, OR
                                // 2. It's past the date but late submission is allowed
                                $isAvailable = ($isToday && $isTimeAvailable) || 
                                              ($assessment['date'] < $currentDate && $allowLateSubmission);
                                
                                // Check if student can take assessment (no assessment in progress or this is the one in progress)
                                $canTakeAssessment = !$inProgressAssessment || 
                                                   ($inProgressAssessment && $assessment['assessment_id'] == $inProgressAssessment['assessment_id']);
                            ?>
                                <div class="upcoming-item">
                                    <div class="upcoming-date <?php echo ($assessment['days_until'] == 0) ? 'today' : ''; ?>">
                                        <span class="date-number"><?php echo date('d', strtotime($assessment['date'])); ?></span>
                                        <span class="date-month"><?php echo date('M', strtotime($assessment['date'])); ?></span>
                                    </div>
                                    <div class="upcoming-details">
                                        <h6><?php echo htmlspecialchars($assessment['title']); ?></h6>
                                        <p class="assessment-subject"><?php echo htmlspecialchars($assessment['subject_name']); ?></p>
                                        <div class="assessment-time">
                                            <i class="far fa-clock me-1"></i>
                                            <?php 
                                            if ($startTime != '00:00:00' || $endTime != '23:59:59') {
                                                echo date('g:i A', strtotime($startTime)) . ' - ' . 
                                                     date('g:i A', strtotime($endTime));
                                            } else {
                                                echo 'All Day';
                                            }
                                            ?>
                                            <?php if ($assessment['duration']): ?>
                                                <span class="duration-badge">
                                                    <?php echo $assessment['duration']; ?> min
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Show time status -->
                                        <?php if ($isToday): ?>
                                            <div class="time-status">
                                                <?php if ($isTimeAvailable): ?>
                                                    <span class="badge bg-success"><i class="fas fa-play"></i> Available Now</span>
                                                <?php elseif ($now < $startTime): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-clock"></i> Opens at <?php echo date('g:i A', strtotime($startTime)); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?php if ($allowLateSubmission): ?>
                                                        <span class="badge bg-info">
                                                            <i class="fas fa-clock"></i> Late Submission Allowed
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">
                                                            <i class="fas fa-times"></i> Time Expired
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="upcoming-action">
                                        <?php if ($isAvailable && $canTakeAssessment): ?>
                                            <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" class="btn btn-sm btn-primary">Take Now</a>
                                        <?php elseif (!$canTakeAssessment): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Complete your current assessment first">
                                                <i class="fas fa-lock me-1"></i>Locked
                                            </button>
                                        <?php elseif ($isToday && $now < $startTime): ?>
                                            <span class="time-until">Opens in 
                                                <?php 
                                                $timeUntil = strtotime($startTime) - strtotime($now);
                                                $hours = floor($timeUntil / 3600);
                                                $minutes = floor(($timeUntil % 3600) / 60);
                                                if ($hours > 0) {
                                                    echo $hours . 'h ' . $minutes . 'm';
                                                } else {
                                                    echo $minutes . 'm';
                                                }
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="days-until"><?php echo $assessment['days_until']; ?> day<?php echo $assessment['days_until'] > 1 ? 's' : ''; ?> left</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card-footer text-center">
                            <a href="assessments.php" class="btn btn-link">View All Scheduled Assessments</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Results Column -->
        <div class="col-xl-6 mb-4">
            <div class="content-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>Recent Results</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentResults)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <p>No assessment results yet.</p>
                            <a href="assessments.php" class="btn btn-sm btn-outline-primary">View Assessments</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Assessment</th>
                                        <th>Subject</th>
                                        <th>Date</th>
                                        <th>Score</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentResults as $result): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($result['title']); ?></td>
                                            <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($result['date'])); ?></td>
                                            <td>
                                                <?php 
                                                $percentage = ($result['total_possible'] > 0) 
                                                    ? ($result['score'] / $result['total_possible']) * 100 
                                                    : 0;
                                                
                                                $scoreClass = '';
                                                if ($percentage >= 80) $scoreClass = 'excellent';
                                                elseif ($percentage >= 60) $scoreClass = 'good';
                                                elseif ($percentage >= 50) $scoreClass = 'average';
                                                else $scoreClass = 'poor';
                                                ?>
                                                <span class="score-badge <?php echo $scoreClass; ?>">
                                                    <?php echo number_format($percentage, 1); ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <a href="view_result.php?id=<?php echo $result['assessment_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-center">
                            <a href="results.php" class="btn btn-link">View All Results</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Third Row - Subject Performance -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="content-card">
                <div class="card-header">
                    <h5><i class="fas fa-book me-2"></i>Subject Performance</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($subjectPerformance)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <p>No subject performance data available yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="subject-performance">
                            <div class="row">
                                <?php foreach ($subjectPerformance as $subject): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="subject-card">
                                            <h6 class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></h6>
                                            <div class="subject-stats">
                                                <div class="subject-score">
                                                    <?php 
                                                    $scoreClass = '';
                                                    if ($subject['average_score'] >= 80) $scoreClass = 'excellent';
                                                    elseif ($subject['average_score'] >= 60) $scoreClass = 'good';
                                                    elseif ($subject['average_score'] >= 50) $scoreClass = 'average';
                                                    else $scoreClass = 'poor';
                                                    ?>
                                                    <span class="score-badge <?php echo $scoreClass; ?>">
                                                        <?php echo $subject['average_score'] ?? 'N/A'; ?>%
                                                    </span>
                                                </div>
                                                <div class="subject-progress">
                                                    <div class="progress">
                                                        <?php 
                                                        $completionPercentage = ($subject['total_assessments'] > 0) 
                                                            ? ($subject['completed_assessments'] / $subject['total_assessments']) * 100 
                                                            : 0;
                                                        ?>
                                                        <div class="progress-bar" role="progressbar" style="width: <?php echo $completionPercentage; ?>%"></div>
                                                    </div>
                                                    <div class="progress-label">
                                                        <?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?> Assessments
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="quick-links">
                <a href="progress.php" class="quick-link-card">
                    <div class="quick-link-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="quick-link-text">Progress Report</div>
                </a>
                <a href="subjects.php" class="quick-link-card">
                    <div class="quick-link-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="quick-link-text">My Subjects</div>
                </a>
                <a href="schedule.php" class="quick-link-card">
                    <div class="quick-link-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="quick-link-text">Assessment Schedule</div>
                </a>
                <a href="results.php" class="quick-link-card">
                    <div class="quick-link-icon">
                        <i class="fas fa-award"></i>
                    </div>
                    <div class="quick-link-text">All Results</div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --primary: #000000;
    --gold: #ffd700;
    --light-gold: #fff7cc;
    --dark-gold: #cc9900;
    --white: #ffffff;
    --gray: #f8f9fa;
    --light-gray: #f3f3f3;
    --dark-gray: #343a40;
    --success: #28a745;
    --info: #17a2b8;
    --warning: #ffc107;
    --danger: #dc3545;
    --shadow: rgba(0, 0, 0, 0.1);
}

/* Welcome Card */
.welcome-card {
    background: linear-gradient(135deg, var(--primary) 0%, #222 60%, var(--gold) 100%);
    color: var(--white);
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 15px var(--shadow);
}

.welcome-title {
    font-size: 1.8rem;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--gold);
}

.welcome-info {
    font-size: 1rem;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.welcome-info i {
    color: var(--gold);
    margin-right: 0.3rem;
}

.notification-alert {
    background: rgba(255, 215, 0, 0.2);
    color: var(--gold);
    display: inline-block;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-weight: 500;
    margin-top: 0.5rem;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.btn-primary {
    background: linear-gradient(45deg, var(--primary) 0%, var(--gold) 100%);
    border: none;
    color: var(--white);
    font-weight: 500;
    padding: 0.5rem 1.25rem;
    border-radius: 5px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 10px rgba(0, 0, 0, 0.15);
    background: linear-gradient(45deg, var(--gold) 0%, var(--primary) 100%);
}

/* Stat Cards */
.stat-card {
    background: var(--white);
    border-radius: 10px;
    padding: 1.5rem;
    height: 100%;
    box-shadow: 0 4px 15px var(--shadow);
    display: flex;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background: linear-gradient(90deg, var(--primary), var(--gold));
}

.assessments-card::before {
    background: linear-gradient(90deg, #3498db, #2980b9);
}

.score-card::before {
    background: linear-gradient(90deg, #f39c12, #e67e22);
}

.upcoming-card::before {
    background: linear-gradient(90deg, #2ecc71, #27ae60);
}

.date-card::before {
    background: linear-gradient(90deg, #9b59b6, #8e44ad);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1.5rem;
    font-size: 1.5rem;
    background: var(--gray);
    color: var(--primary);
}

.assessments-card .stat-icon {
    color: #3498db;
}

.score-card .stat-icon {
    color: #f39c12;
}

.upcoming-card .stat-icon {
    color: #2ecc71;
}

.date-card .stat-icon {
    color: #9b59b6;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.stat-label {
    color: #6c757d;
    margin: 0;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.stat-detail {
    font-size: 0.875rem;
    color: #6c757d;
    margin: 0;
}

.stat-progress {
    margin-top: 0.5rem;
}

.progress {
    height: 5px;
    margin-bottom: 0.5rem;
    border-radius: 5px;
    background-color: var(--light-gray);
}

.progress-bar {
    background: linear-gradient(90deg, var(--primary), var(--gold));
    border-radius: 5px;
}

/* Content Cards */
.content-card {
    background: var(--white);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px var(--shadow);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.card-header {
    background: linear-gradient(90deg, var(--primary) 0%, #222 100%);
    color: var(--gold);
    padding: 1rem 1.5rem;
    font-weight: 500;
    border-bottom: none;
}

.card-header h5 {
    margin: 0;
    font-size: 1.1rem;
}

.card-body {
    flex: 1;
    padding: 1.5rem;
}

.card-footer {
    background-color: var(--gray);
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 0.75rem 1.5rem;
}

.btn-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}

.btn-link:hover {
    color: var(--gold);
    text-decoration: none;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-icon {
    font-size: 2.5rem;
    color: #adb5bd;
    margin-bottom: 1rem;
}

.btn-outline-primary {
    color: var(--primary);
    border-color: var(--primary);
    background: transparent;
    transition: all 0.2s;
}

.btn-outline-primary:hover {
    background: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}

/* Upcoming Assessments */
.upcoming-list {
    padding: 0.5rem 0;
}

.upcoming-item {
    display: flex;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    align-items: center;
    transition: all 0.2s;
}

.upcoming-item:last-child {
    border-bottom: none;
}

.upcoming-item:hover {
    background-color: var(--gray);
}

.upcoming-date {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    background: var(--gray);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    font-weight: 600;
    line-height: 1.1;
    color: var(--dark-gray);
    border: 1px solid rgba(0,0,0,0.05);
}

.upcoming-date.today {
    background: rgba(255, 215, 0, 0.2);
    border-color: rgba(255, 215, 0, 0.5);
}

.date-number {
    font-size: 1.5rem;
    font-weight: 700;
}

.date-month {
    font-size: 0.8rem;
    text-transform: uppercase;
}

.upcoming-details {
    flex: 1;
}

.upcoming-details h6 {
    margin: 0 0 0.3rem 0;
    font-weight: 600;
    line-height: 1.3;
}

.assessment-subject {
    color: #6c757d;
    font-size: 0.875rem;
    margin-bottom: 0.3rem;
}

.assessment-time {
    font-size: 0.875rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.3rem;
}

.duration-badge {
    background: rgba(0,0,0,0.05);
    padding: 0.1rem 0.5rem;
    border-radius: 50px;
    font-size: 0.75rem;
    display: inline-block;
}

.time-status {
    margin-top: 0.3rem;
}

.time-status .badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.upcoming-action {
    min-width: 100px;
    text-align: right;
}

.days-until, .time-until {
    font-size: 0.875rem;
    color: #6c757d;
    display: block;
    text-align: center;
}

.time-until {
    font-weight: 500;
    color: #28a745;
}

/* Recent Results */
.table {
    margin-bottom: 0;
}

.table th {
    background: var(--gray);
    border-top: none;
    font-weight: 600;
    padding: 0.75rem 1rem;
}

.table td {
    padding: 1rem;
    vertical-align: middle;
}

.score-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.875rem;
}

.score-badge.excellent {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.score-badge.good {
    background: rgba(23, 162, 184, 0.1);
    color: #17a2b8;
}

.score-badge.average {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.score-badge.poor {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

/* Subject Performance */
.subject-performance {
    padding: 0.5rem;
}

.subject-card {
    background: var(--gray);
    border-radius: 10px;
    padding: 1rem;
    height: 100%;
    transition: all 0.3s ease;
    border: 1px solid rgba(0,0,0,0.05);
}

.subject-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.subject-name {
    margin: 0 0 0.75rem 0;
    font-weight: 600;
    border-bottom: 2px solid var(--gold);
    padding-bottom: 0.5rem;
    display: inline-block;
}

.subject-stats {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.subject-progress {
    flex: 1;
    margin-left: 1rem;
}

.progress-label {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

/* Quick Links */
.quick-links {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
}

.quick-link-card {
    background: var(--white);
    border-radius: 10px;
    padding: 1.5rem 1rem;
    text-align: center;
    flex: 1;
    min-width: 150px;
    max-width: 220px;
    box-shadow: 0 4px 15px var(--shadow);
    transition: all 0.3s ease;
    text-decoration: none;
    color: var(--dark-gray);
    border: 1px solid rgba(0,0,0,0.05);
}

.quick-link-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    color: var(--primary);
    border-color: var(--gold);
}

.quick-link-icon {
    font-size: 2rem;
    margin-bottom: 1rem;
    color: var(--gold);
}

.quick-link-text {
    font-weight: 500;
}

/* Badge Styling */
.badge {
    font-weight: 500;
    padding: 0.4em 0.6em;
    border-radius: 0.25rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.badge i {
    font-size: 0.875em;
}

/* Button Disabled States */
.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.btn:disabled[title] {
    cursor: help;
}

/* Responsive Adjustments */
@media (max-width: 991.98px) {
    .welcome-card {
        padding: 1.5rem;
    }
    
    .welcome-title {
        font-size: 1.5rem;
    }
    
    .stat-card {
        margin-bottom: 1rem;
        padding: 1rem;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
        margin-right: 1rem;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
    
    .content-card {
        margin-bottom: 1.5rem;
    }
}

@media (max-width: 767.98px) {
    .welcome-title {
        font-size: 1.25rem;
    }
    
    .upcoming-item {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start;
    }
    
    .upcoming-action {
        width: 100%;
        text-align: left;
        margin-top: 0.5rem;
    }
    
    .subject-stats {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .subject-progress {
        width: 100%;
        margin-left: 0;
    }
    
    .quick-link-card {
        min-width: 120px;
        padding: 1rem 0.75rem;
    }
    
    .quick-link-icon {
        font-size: 1.5rem;
        margin-bottom: 0.75rem;
    }
    
    .quick-link-text {
        font-size: 0.875rem;
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

    // Add smooth animations for card hover effects
    const cards = document.querySelectorAll('.card, .stat-card, .subject-card, .quick-link-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            if (!this.classList.contains('stat-card')) {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.1)';
                this.style.transition = 'all 0.3s ease';
            }
        });
        
        card.addEventListener('mouseleave', function() {
            if (!this.classList.contains('stat-card')) {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            }
        });
    });
    
    // Update time display every minute for time-sensitive assessments
    setInterval(function() {
        const timeElements = document.querySelectorAll('.time-until');
        timeElements.forEach(element => {
            // You could implement real-time countdown here if needed
            // For now, we'll refresh the page every hour to update time status
        });
    }, 60000); // 1 minute
    
    // Refresh page every hour to update time-sensitive content
    setTimeout(function() {
        if (document.querySelector('.time-status')) {
            location.reload();
        }
    }, 3600000); // 1 hour
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>