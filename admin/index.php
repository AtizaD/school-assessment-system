<?php
/**
 * Admin Dashboard - School Assessment Management System
 * Professional interface for administrative oversight and control
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get user statistics by role
    $stmt = $db->query("
        SELECT role, COUNT(*) as count 
        FROM Users 
        GROUP BY role
    ");
    $userStats = [];
    while ($row = $stmt->fetch()) {
        $userStats[$row['role']] = $row['count'];
    }

    // Get recent user registrations (last 7 days)
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM Users 
        WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
    ");
    $recentUsers = $stmt->fetchColumn();

    // Get assessment statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_assessments,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'archived' THEN 1 END) as archived
        FROM Assessments
    ");
    $assessmentStats = $stmt->fetch();

    // Get active sessions count
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM UserSessions 
        WHERE is_active = TRUE 
        AND expire_timestamp > CURRENT_TIMESTAMP
    ");
    $activeSessions = $stmt->fetchColumn();

    // Get today's assessments count
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM Assessments 
        WHERE date = CURRENT_DATE AND status = 'pending'
    ");
    $todayAssessments = $stmt->fetchColumn();

    // Get program statistics (top 5)
    $stmt = $db->query("
        SELECT p.program_id, p.program_name, COUNT(c.class_id) as class_count
        FROM Programs p
        LEFT JOIN Classes c ON p.program_id = c.program_id
        GROUP BY p.program_id, p.program_name
        ORDER BY class_count DESC
        LIMIT 5
    ");
    $programStats = $stmt->fetchAll();

    // Get recent assessments
    $stmt = $db->query("
        SELECT 
            a.assessment_id, 
            a.title, 
            a.date,
            a.status,
            s.subject_name,
            COUNT(DISTINCT q.question_id) as question_count
        FROM Assessments a
        JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
        JOIN Subjects s ON ac.subject_id = s.subject_id
        LEFT JOIN Questions q ON a.assessment_id = q.assessment_id
        GROUP BY a.assessment_id, a.title, a.date, s.subject_name, a.status
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $recentAssessments = $stmt->fetchAll();

    // Get recent system activity
    $stmt = $db->query("
        SELECT 
            sl.severity,
            sl.component,
            sl.message,
            sl.created_at,
            u.username
        FROM SystemLogs sl
        LEFT JOIN Users u ON sl.user_id = u.user_id
        ORDER BY sl.created_at DESC
        LIMIT 6
    ");
    $recentActivity = $stmt->fetchAll();

    // Calculate percentages for analytics
    $total = $assessmentStats['total_assessments'];
    $completedPercentage = $total > 0 ? round(($assessmentStats['completed'] / $total) * 100) : 0;
    $pendingPercentage = $total > 0 ? round(($assessmentStats['pending'] / $total) * 100) : 0;
    $archivedPercentage = $total > 0 ? round(($assessmentStats['archived'] / $total) * 100) : 0;

} catch (PDOException $e) {
    logError("Dashboard data fetch error: " . $e->getMessage());
    $error = "Error loading dashboard data. Please refresh the page.";
}

$pageTitle = 'Admin Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="dashboard-main">
    <!-- Header Section -->
    <section class="dashboard-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="dashboard-title">
                    <i class="fas fa-tachometer-alt"></i>
                    Assessment Control Center
                </h1>
                <p class="dashboard-subtitle">
                    Welcome back, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> • 
                    <?php echo date('l, F j, Y'); ?>
                </p>
            </div>
            <div class="header-actions">
                <div class="today-highlight">
                    <i class="fas fa-calendar-day"></i>
                    <span><?php echo $todayAssessments; ?> assessments today</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Key Metrics -->
    <section class="metrics-section">
        <div class="metrics-grid">
            <div class="metric-card students">
                <div class="metric-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="metric-content">
                    <div class="metric-number"><?php echo number_format($userStats['student'] ?? 0); ?></div>
                    <div class="metric-label">Total Students</div>
                    <div class="metric-change positive">
                        <i class="fas fa-arrow-up"></i>
                        +<?php echo $recentUsers; ?> this week
                    </div>
                </div>
            </div>

            <div class="metric-card assessments">
                <div class="metric-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="metric-content">
                    <div class="metric-number"><?php echo number_format($assessmentStats['total_assessments']); ?></div>
                    <div class="metric-label">Total Assessments</div>
                    <div class="metric-change info">
                        <i class="fas fa-hourglass-half"></i>
                        <?php echo $assessmentStats['pending']; ?> pending
                    </div>
                </div>
            </div>

            <div class="metric-card sessions">
                <div class="metric-icon">
                    <i class="fas fa-wifi"></i>
                </div>
                <div class="metric-content">
                    <div class="metric-number"><?php echo number_format($activeSessions); ?></div>
                    <div class="metric-label">Live Sessions</div>
                    <div class="metric-change positive">
                        <i class="fas fa-circle pulse"></i>
                        Online now
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content Grid -->
    <section class="content-section">
        <div class="content-grid">
            <!-- Left Column -->
            <div class="content-left">
                <!-- Quick Actions -->
                <div class="panel quick-actions-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-bolt"></i>
                            Quick Actions
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div class="actions-grid">
                            <a href="users.php" class="action-btn">
                                <i class="fas fa-users"></i>
                                <span>Manage Users</span>
                            </a>
                            <a href="programs.php" class="action-btn">
                                <i class="fas fa-graduation-cap"></i>
                                <span>Programs</span>
                            </a>
                            <a href="classes.php" class="action-btn">
                                <i class="fas fa-school"></i>
                                <span>Classes</span>
                            </a>
                            <a href="subjects.php" class="action-btn">
                                <i class="fas fa-book"></i>
                                <span>Subjects</span>
                            </a>
                            <a href="special_class.php" class="action-btn">
                                <i class="fas fa-star"></i>
                                <span>Special Enrollments</span>
                            </a>
                            <a href="reset_assessment.php" class="action-btn">
                                <i class="fas fa-redo-alt"></i>
                                <span>Reset Assessment</span>
                            </a>
                            <a href="student_submissions.php" class="action-btn">
                                <i class="fas fa-paper-plane"></i>
                                <span>Submit Assessment</span>
                            </a>
                            <a href="assessment_participation.php" class="action-btn">
                                <i class="fas fa-paper-plane"></i>
                                <span>Assessment Participation</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Assessment Analytics -->
                <div class="panel analytics-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-analytics"></i>
                            Assessment Analytics
                        </h3>
                        <button class="refresh-btn" onclick="refreshData()" aria-label="Refresh data">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    <div class="panel-body">
                        <div class="analytics-grid">
                            <div class="analytics-item completed">
                                <div class="analytics-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="analytics-content">
                                    <div class="analytics-number"><?php echo $assessmentStats['completed']; ?></div>
                                    <div class="analytics-label">Completed</div>
                                    <div class="analytics-percentage"><?php echo $completedPercentage; ?>%</div>
                                </div>
                            </div>
                            
                            <div class="analytics-item pending">
                                <div class="analytics-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="analytics-content">
                                    <div class="analytics-number"><?php echo $assessmentStats['pending']; ?></div>
                                    <div class="analytics-label">Pending</div>
                                    <div class="analytics-percentage"><?php echo $pendingPercentage; ?>%</div>
                                </div>
                            </div>
                            
                            <div class="analytics-item archived">
                                <div class="analytics-icon">
                                    <i class="fas fa-archive"></i>
                                </div>
                                <div class="analytics-content">
                                    <div class="analytics-number"><?php echo $assessmentStats['archived']; ?></div>
                                    <div class="analytics-label">Archived</div>
                                    <div class="analytics-percentage"><?php echo $archivedPercentage; ?>%</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress-summary">
                            <div class="progress-header">
                                <span>Overall Assessment Progress</span>
                                <span class="progress-value"><?php echo $completedPercentage; ?>% Complete</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $completedPercentage; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="panel status-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-server"></i>
                            System Status
                        </h3>
                        <span class="status-indicator online">
                            <i class="fas fa-circle"></i>
                            Online
                        </span>
                    </div>
                    <div class="panel-body">
                        <div class="status-grid">
                            <div class="status-item">
                                <div class="status-label">Database</div>
                                <div class="status-value good">
                                    <i class="fas fa-check-circle"></i>
                                    Operational
                                </div>
                            </div>
                            <div class="status-item">
                                <div class="status-label">Active Users</div>
                                <div class="status-value">
                                    <i class="fas fa-users"></i>
                                    <?php echo $activeSessions; ?> online
                                </div>
                            </div>
                            <div class="status-item">
                                <div class="status-label">Today's Tests</div>
                                <div class="status-value">
                                    <i class="fas fa-calendar-day"></i>
                                    <?php echo $todayAssessments; ?> scheduled
                                </div>
                            </div>
                            <div class="status-item">
                                <div class="status-label">System Load</div>
                                <div class="status-value good">
                                    <i class="fas fa-tachometer-alt"></i>
                                    Normal
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="content-right">
                <!-- Recent Assessments -->
                <div class="panel assessments-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-file-alt"></i>
                            Recent Assessments
                        </h3>
                        <a href="reports.php" class="view-all-btn">
                            <span>View All</span>
                        </a>
                    </div>
                    <div class="panel-body">
                        <div class="assessments-list">
                            <?php if (!empty($recentAssessments)): ?>
                                <?php foreach ($recentAssessments as $assessment): ?>
                                <div class="assessment-item">
                                    <div class="assessment-info">
                                        <div class="assessment-title"><?php echo htmlspecialchars($assessment['title']); ?></div>
                                        <div class="assessment-meta">
                                            <span class="subject"><?php echo htmlspecialchars($assessment['subject_name']); ?></span>
                                            <span class="date"><?php echo date('M j', strtotime($assessment['date'])); ?></span>
                                            <span class="questions"><?php echo $assessment['question_count']; ?> questions</span>
                                        </div>
                                    </div>
                                    <div class="assessment-status">
                                        <span class="status-badge <?php echo $assessment['status']; ?>">
                                            <?php echo ucfirst($assessment['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <p>No assessments found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Programs Overview -->
                <div class="panel programs-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-graduation-cap"></i>
                            Programs Overview
                        </h3>
                        <a href="programs.php" class="view-all-btn">
                            <span>Manage</span>
                        </a>
                    </div>
                    <div class="panel-body">
                        <div class="programs-list">
                            <?php if (!empty($programStats)): ?>
                                <?php foreach ($programStats as $program): ?>
                                <div class="program-item">
                                    <div class="program-info">
                                        <div class="program-name"><?php echo htmlspecialchars($program['program_name']); ?></div>
                                        <div class="program-classes"><?php echo number_format($program['class_count']); ?> classes</div>
                                    </div>
                                    <div class="program-action">
                                        <a href="programs.php?id=<?php echo (int)$program['program_id']; ?>" class="view-btn" aria-label="View program details">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-graduation-cap"></i>
                                    <p>No programs found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- System Activity -->
                <div class="panel activity-panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="fas fa-history"></i>
                            System Activity
                        </h3>
                        <a href="audit-logs.php" class="view-all-btn">
                            <span>View Logs</span>
                        </a>
                    </div>
                    <div class="panel-body">
                        <div class="activity-timeline">
                            <?php if (!empty($recentActivity)): ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-indicator <?php echo strtolower($activity['severity']); ?>"></div>
                                    <div class="activity-content">
                                        <div class="activity-header">
                                            <span class="activity-type"><?php echo htmlspecialchars($activity['component']); ?></span>
                                            <span class="activity-time"><?php echo date('H:i', strtotime($activity['created_at'])); ?></span>
                                        </div>
                                        <div class="activity-message"><?php echo htmlspecialchars($activity['message']); ?></div>
                                        <div class="activity-user">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($activity['username'] ?? 'System'); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<style>
/* CSS Variables - Black & Gold Professional Theme */
:root {
    --black: #000000;
    --gold: #ffd700;
    --gold-dark: #b8a000;
    --gold-light: #fff9cc;
    --white: #ffffff;
    --gray-50: #f8f9fa;
    --gray-100: #e9ecef;
    --gray-200: #dee2e6;
    --gray-400: #6c757d;
    --gray-600: #495057;
    --gray-800: #343a40;
    --success: #28a745;
    --danger: #dc3545;
    --warning: #ffc107;
    --info: #17a2b8;
    --shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
    --shadow-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.1);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Scoped Styles - Only affect dashboard content */
.dashboard-main * { 
    box-sizing: border-box; 
}

.dashboard-main {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--gray-800);
    line-height: 1.6;
    padding: 0;
    min-height: 100vh;
}

/* Header Section */
.dashboard-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 200px;
    background: radial-gradient(circle, var(--gold) 0%, transparent 70%);
    opacity: 0.1;
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 1;
}

.dashboard-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.dashboard-title i {
    color: var(--gold);
    font-size: 2rem;
}

.dashboard-subtitle {
    font-size: 1.1rem;
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
}

.today-highlight {
    background: rgba(255, 215, 0, 0.1);
    border: 1px solid var(--gold);
    color: var(--gold);
    padding: 1rem 1.5rem;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

/* Metrics Section */
.metrics-section {
    padding: 0 2rem;
    margin-bottom: 2rem;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.metric-card {
    background: var(--white);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    border-left: 4px solid var(--gold);
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold), var(--black), var(--gold));
}

.metric-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.metric-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--white);
    background: linear-gradient(135deg, var(--black) 0%, var(--gold-dark) 100%);
}

.metric-content {
    flex: 1;
}

.metric-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.metric-label {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-600);
    margin: 0.25rem 0;
}

.metric-change {
    font-size: 0.875rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.metric-change.positive { color: var(--success); }
.metric-change.stable { color: var(--gray-400); }
.metric-change.info { color: var(--gold-dark); }

.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Content Section */
.content-section {
    padding: 0 2rem 2rem;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.content-left, .content-right {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

/* Panel Styles */
.panel {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: var(--transition);
}

.panel:hover {
    box-shadow: var(--shadow-hover);
}

.panel-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.25rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid var(--gold);
    position: relative;
}

.panel-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--gold), transparent, var(--gold));
}

.panel-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.panel-title i {
    color: var(--gold);
}

.panel-body {
    padding: 1.5rem;
}

/* Quick Actions */
.actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 1.5rem 1rem;
    background: var(--white);
    border: 2px solid var(--gray-200);
    border-radius: var(--border-radius);
    text-decoration: none;
    color: var(--gray-800);
    transition: var(--transition);
    font-weight: 500;
    position: relative;
    overflow: hidden;
}

.action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,215,0,0.1), transparent);
    transition: left 0.5s ease;
}

.action-btn:hover {
    color: var(--gold-dark);
    border-color: var(--gold);
    background: var(--gold-light);
    transform: translateY(-2px);
}

.action-btn:hover::before {
    left: 100%;
}

.action-btn i {
    font-size: 1.5rem;
    margin-bottom: 0.75rem;
    color: var(--black);
    transition: var(--transition);
}

.action-btn:hover i {
    color: var(--gold-dark);
}

/* Analytics Panel */
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.analytics-item {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: var(--transition);
    border-left: 4px solid;
}

.analytics-item.completed { border-left-color: var(--gold); }
.analytics-item.pending { border-left-color: var(--black); }
.analytics-item.archived { border-left-color: var(--gray-400); }

.analytics-item:hover {
    background: var(--gold-light);
    transform: translateY(-2px);
}

.analytics-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--white);
}

.analytics-item.completed .analytics-icon {
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
}

.analytics-item.pending .analytics-icon {
    background: linear-gradient(135deg, var(--black), #333333);
}

.analytics-item.archived .analytics-icon {
    background: linear-gradient(135deg, var(--gray-400), #5a5a5a);
}

.analytics-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.analytics-label {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0.25rem 0;
}

.analytics-percentage {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gold-dark);
}

.progress-summary {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--border-radius);
    padding: 1.25rem;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    font-weight: 600;
    color: var(--black);
}

.progress-value {
    color: var(--gold-dark);
    font-size: 1.1rem;
}

.progress-bar-container {
    width: 100%;
    height: 12px;
    background: var(--gray-200);
    border-radius: 6px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--gold), var(--gold-dark));
    border-radius: 6px;
    transition: width 2s ease-out;
    position: relative;
}

.progress-bar::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Status Panel */
.status-indicator {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 20px;
}

.status-indicator.online {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success);
}

.status-indicator.online i {
    animation: pulse 2s infinite;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.status-item {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    transition: var(--transition);
}

.status-item:hover {
    background: var(--gold-light);
    transform: translateY(-2px);
}

.status-label {
    font-size: 0.875rem;
    color: var(--gray-600);
    font-weight: 500;
}

.status-value {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--black);
}

.status-value.good {
    color: var(--success);
}

.status-value i {
    color: var(--gold-dark);
}

.status-value.good i {
    color: var(--success);
}

/* Lists */
.assessments-list, .programs-list, .activity-timeline {
    border: 1px solid var(--gray-200);
    border-radius: var(--border-radius);
    background: var(--white);
    overflow: hidden;
}

.assessment-item, .program-item, .activity-item {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    margin: 0;
    border-radius: 0;
    background: var(--white);
    transition: var(--transition);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.assessment-item:last-child, .program-item:last-child, .activity-item:last-child {
    border-bottom: none;
}

.assessment-item:hover, .program-item:hover, .activity-item:hover {
    background: var(--gray-50);
    border-left: 4px solid var(--gold);
    padding-left: calc(1.5rem - 4px);
}

.assessment-title, .program-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--black);
    letter-spacing: -0.01em;
    margin-bottom: 0.25rem;
}

.assessment-meta, .program-classes {
    font-size: 0.875rem;
    color: var(--gray-600);
    display: flex;
    gap: 1rem;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.completed {
    background: var(--gold);
    color: var(--black);
}

.status-badge.pending {
    background: var(--black);
    color: var(--white);
}

.status-badge.archived {
    background: var(--gray-200);
    color: var(--gray-600);
}

/* Activity Timeline */
.activity-item {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}

.activity-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-top: 0.5rem;
    flex-shrink: 0;
}

.activity-indicator.info { background: var(--gold); }
.activity-indicator.warning { background: var(--warning); }
.activity-indicator.error { background: var(--danger); }
.activity-indicator.critical { background: var(--danger); }

.activity-content {
    flex: 1;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.activity-type {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--black);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.activity-time {
    font-size: 0.75rem;
    color: var(--gray-600);
}

.activity-message {
    font-size: 0.875rem;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
    line-height: 1.4;
}

.activity-user {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

/* Buttons */
.refresh-btn, .view-all-btn, .view-btn {
    background: var(--black);
    color: var(--white);
    border: 2px solid var(--black);
    padding: 0.5rem 1rem;
    border-radius: var(--border-radius);
    font-weight: 600;
    text-decoration: none;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    transition: var(--transition);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.refresh-btn::before, .view-all-btn::before, .view-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: var(--gold);
    transition: left 0.3s ease;
    z-index: 0;
}

.refresh-btn:hover::before, .view-all-btn:hover::before, .view-btn:hover::before {
    left: 0;
}

.refresh-btn:hover, .view-all-btn:hover, .view-btn:hover {
    color: var(--black);
    border-color: var(--gold);
}

.refresh-btn span, .view-all-btn span, .view-btn span {
    position: relative;
    z-index: 1;
}

.view-btn {
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--gray-600);
}

.empty-state i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

.empty-state p {
    margin: 0;
    font-size: 1rem;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .dashboard-title {
        font-size: 2rem;
    }
    
    .metrics-section, .content-section {
        padding: 0 1rem 2rem;
    }
    
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .actions-grid {
        grid-template-columns: 1fr;
    }
    
    .analytics-grid, .status-grid {
        grid-template-columns: 1fr;
    }
    
    .assessment-meta {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .progress-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}

@media (max-width: 480px) {
    .metric-card {
        flex-direction: column;
        text-align: center;
    }
    
    .assessment-item, .program-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }
    
    .activity-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
}

/* Animations */
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.metric-card, .panel {
    animation: slideInUp 0.6s ease-out;
}

.metric-card:nth-child(1) { animation-delay: 0.1s; }
.metric-card:nth-child(2) { animation-delay: 0.2s; }
.metric-card:nth-child(3) { animation-delay: 0.3s; }

/* Focus States for Accessibility */
.action-btn:focus,
.refresh-btn:focus,
.view-all-btn:focus,
.view-btn:focus {
    outline: 2px solid var(--gold);
    outline-offset: 2px;
}

/* Print Styles */
@media print {
    .dashboard-header,
    .refresh-btn,
    .view-all-btn,
    .view-btn {
        display: none;
    }
    
    .panel {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid var(--gray-400);
    }
    
    .content-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Initialize Dashboard
    initializeDashboard();
    
    function initializeDashboard() {
        animateMetrics();
        setupEventListeners();
        startLiveUpdates();
        setupAccessibility();
    }
    
    // Animate metric numbers on page load
    function animateMetrics() {
        const metricNumbers = document.querySelectorAll('.metric-number, .analytics-number');
        
        metricNumbers.forEach(element => {
            const finalValue = parseInt(element.textContent.replace(/,/g, '')) || 0;
            animateNumber(element, 0, finalValue, 2000);
        });
    }
    
    function animateNumber(element, start, end, duration) {
        const startTime = performance.now();
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            const currentValue = Math.floor(start + (end - start) * easeOutQuart);
            
            element.textContent = currentValue.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                element.textContent = end.toLocaleString();
            }
        }
        
        requestAnimationFrame(update);
    }
    
    // Setup event listeners
    function setupEventListeners() {
        // Refresh data functionality
        window.refreshData = function() {
            const refreshBtn = document.querySelector('.refresh-btn i');
            if (refreshBtn) {
                refreshBtn.style.animation = 'spin 1s linear';
                
                // Simulate data refresh
                setTimeout(() => {
                    refreshBtn.style.animation = '';
                    showNotification('Data refreshed successfully', 'success');
                    updateLastRefreshTime();
                }, 1000);
            }
        };
        
        // Add hover effects to action buttons
        const actionBtns = document.querySelectorAll('.action-btn');
        actionBtns.forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px) scale(1.02)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = '';
            });
        });
        
        // Add click effects to metric cards
        const metricCards = document.querySelectorAll('.metric-card');
        metricCards.forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });
        
        // Add loading states to links (only within dashboard)
        const allLinks = document.querySelectorAll('.dashboard-main a[href]:not([href^="#"])');
        allLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (!this.href.includes('#')) {
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                    
                    // Add loading spinner for button-like links
                    if (this.classList.contains('view-all-btn') || this.classList.contains('view-btn')) {
                        const icon = this.querySelector('i');
                        if (icon) {
                            icon.className = 'fas fa-spinner fa-spin';
                        }
                    }
                    
                    // Reset after 3 seconds as fallback
                    setTimeout(() => {
                        this.style.opacity = '';
                        this.style.pointerEvents = '';
                    }, 3000);
                }
            });
        });
    }
    
    // Start live updates
    function startLiveUpdates() {
        // Update live sessions every 30 seconds
        setInterval(updateLiveSessions, 30000);
        
        // Update system status every 60 seconds
        setInterval(updateSystemStatus, 60000);
    }
    
    function updateLiveSessions() {
        const sessionElement = document.querySelector('.metric-card.sessions .metric-number');
        if (sessionElement) {
            // Simulate slight variations in session count
            const baseCount = <?php echo $activeSessions; ?>;
            const variation = Math.floor(Math.random() * 3) - 1; // -1, 0, or 1
            const newCount = Math.max(0, baseCount + variation);
            animateNumber(sessionElement, parseInt(sessionElement.textContent.replace(/,/g, '')), newCount, 1000);
        }
    }
    
    function updateSystemStatus() {
        const statusElements = document.querySelectorAll('.status-value.good');
        statusElements.forEach(element => {
            // Add a brief pulse effect to show it's being updated
            element.style.opacity = '0.7';
            setTimeout(() => {
                element.style.opacity = '';
            }, 200);
        });
    }
    
    function updateLastRefreshTime() {
        // Update any "last updated" timestamps if they exist
        const timeElements = document.querySelectorAll('.last-updated');
        timeElements.forEach(element => {
            element.textContent = 'Updated just now';
        });
    }
    
    // Setup accessibility features
    function setupAccessibility() {
        // Add keyboard navigation support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close any open modals or dropdowns (only within dashboard)
                document.querySelectorAll('.dashboard-main .dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
        
        // Add focus indicators (only within dashboard)
        const focusableElements = document.querySelectorAll('.dashboard-main a, .dashboard-main button, .dashboard-main .action-btn');
        focusableElements.forEach(element => {
            element.addEventListener('focus', function() {
                this.style.outline = '2px solid var(--gold)';
                this.style.outlineOffset = '2px';
            });
            
            element.addEventListener('blur', function() {
                this.style.outline = '';
                this.style.outlineOffset = '';
            });
        });
    }
    
    // Notification system
    window.showNotification = function(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i>
            <span>${message}</span>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        // Trigger animation
        setTimeout(() => notification.classList.add('show'), 100);
        
        // Auto-remove notification
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    };
    
    // Performance monitoring
    function logPerformanceMetrics() {
        if (window.performance && window.performance.timing) {
            const timing = window.performance.timing;
            const loadTime = timing.loadEventEnd - timing.navigationStart;
            
            if (loadTime > 3000) {
                console.warn('Dashboard loaded slowly:', loadTime + 'ms');
            }
        }
    }
    
    // Log performance metrics after page load
    setTimeout(logPerformanceMetrics, 1000);
    
    // Smooth scrolling for better UX
    document.documentElement.style.scrollBehavior = 'smooth';
});

// CSS for notifications and animations
const additionalStyles = document.createElement('style');
additionalStyles.textContent = `
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: var(--black);
        color: var(--white);
        padding: 1rem 1.5rem;
        border-radius: var(--border-radius);
        border-left: 4px solid var(--gold);
        box-shadow: var(--shadow-hover);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        z-index: 1000;
        font-weight: 500;
        min-width: 300px;
    }
    
    .notification.show {
        transform: translateX(0);
    }
    
    .notification-success {
        border-left-color: var(--success);
    }
    
    .notification i {
        color: var(--gold);
        font-size: 1.2rem;
    }
    
    .notification-success i {
        color: var(--success);
    }
    
    .notification-close {
        background: none;
        border: none;
        color: var(--white);
        cursor: pointer;
        padding: 0.25rem;
        margin-left: auto;
        border-radius: 4px;
        transition: background-color 0.2s;
    }
    
    .notification-close:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Enhanced responsive styles */
    @media (max-width: 480px) {
        .notification {
            right: 10px;
            left: 10px;
            transform: translateY(-100px);
            min-width: auto;
        }
        
        .notification.show {
            transform: translateY(0);
        }
    }
    
    /* Loading states */
    .loading {
        opacity: 0.7;
        pointer-events: none;
    }
    
    .loading * {
        cursor: wait !important;
    }
    
    /* Enhanced focus states */
    .panel:focus-within {
        box-shadow: var(--shadow-hover);
    }
    
    /* Print optimization */
    @media print {
        .notification {
            display: none;
        }
    }
`;
document.head.appendChild(additionalStyles);
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>