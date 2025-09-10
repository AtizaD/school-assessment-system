<?php
// admin/assessment_participation.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure user is admin
requireRole('admin');

// Initialize variables
$classes = [];
$programs = [];
$subjects = [];
$assessments = [];
$lowParticipationData = [];
$noParticipationData = [];
$error = '';
$success = '';

// Get filter values
$filter_program = isset($_GET['program']) ? sanitizeInput($_GET['program']) : '';
$filter_class = isset($_GET['class']) ? sanitizeInput($_GET['class']) : '';
$filter_subject = isset($_GET['subject']) ? sanitizeInput($_GET['subject']) : '';
$filter_assessment = isset($_GET['assessment']) ? sanitizeInput($_GET['assessment']) : '';
$filter_level = isset($_GET['level']) ? sanitizeInput($_GET['level']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$filter_threshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 40;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'low_participation';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Fetch all programs for filter dropdown
    $stmt = $db->query("SELECT program_id, program_name FROM programs ORDER BY program_name");
    $programs = $stmt->fetchAll();
    
    // Fetch all subjects for filter dropdown
    $stmt = $db->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $subjects = $stmt->fetchAll();
    
    // Fetch all classes for filter dropdown
    $classQuery = "SELECT c.class_id, c.class_name, p.program_name, c.level 
                  FROM classes c 
                  JOIN programs p ON c.program_id = p.program_id 
                  ORDER BY p.program_name, c.level, c.class_name";
    $stmt = $db->query($classQuery);
    $classes = $stmt->fetchAll();
    
    // Fetch all assessments for filter dropdown
    $assessmentQuery = "SELECT assessment_id, title, date FROM assessments ORDER BY date DESC, title";
    $stmt = $db->query($assessmentQuery);
    $assessments = $stmt->fetchAll();
    
    // Get unique class levels
    $levelQuery = "SELECT DISTINCT level FROM classes ORDER BY level";
    $stmt = $db->query($levelQuery);
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query for low participation data
    $lowParticipationQuery = "
        SELECT 
            ac.assessment_id,
            a.title AS assessment_title,
            a.date AS assessment_date,
            ac.class_id,
            c.class_name,
            p.program_name,
            c.level,
            s.subject_id,
            s.subject_name,
            COUNT(DISTINCT st.student_id) AS total_students,
            COUNT(DISTINCT att.student_id) AS students_attempted,
            ROUND((COUNT(DISTINCT att.student_id) / COUNT(DISTINCT st.student_id)) * 100, 2) AS participation_percentage
        FROM 
            assessmentclasses ac
        JOIN 
            assessments a ON ac.assessment_id = a.assessment_id
        JOIN 
            classes c ON ac.class_id = c.class_id
        JOIN 
            programs p ON c.program_id = p.program_id
        JOIN 
            subjects s ON ac.subject_id = s.subject_id
        JOIN 
            students st ON st.class_id = c.class_id
        LEFT JOIN 
            assessmentattempts att ON att.assessment_id = ac.assessment_id AND att.student_id = st.student_id
        WHERE 1=1";
    
    $noParticipationQuery = "
        SELECT 
            ac.assessment_id,
            a.title AS assessment_title,
            a.date AS assessment_date,
            ac.class_id,
            c.class_name,
            p.program_name,
            c.level,
            s.subject_id,
            s.subject_name,
            COUNT(DISTINCT st.student_id) AS total_students
        FROM 
            assessmentclasses ac
        JOIN 
            assessments a ON ac.assessment_id = a.assessment_id
        JOIN 
            classes c ON ac.class_id = c.class_id
        JOIN 
            programs p ON c.program_id = p.program_id
        JOIN 
            subjects s ON ac.subject_id = s.subject_id
        JOIN 
            students st ON st.class_id = c.class_id
        WHERE 
            NOT EXISTS (
                SELECT 1
                FROM assessmentattempts att
                JOIN students s ON att.student_id = s.student_id
                WHERE att.assessment_id = ac.assessment_id AND s.class_id = ac.class_id
            )";
    
    // Apply filters to both queries
    $params = [];
    
    if (!empty($filter_program)) {
        $lowParticipationQuery .= " AND p.program_name = ?";
        $noParticipationQuery .= " AND p.program_name = ?";
        $params[] = $filter_program;
    }
    
    if (!empty($filter_class)) {
        $lowParticipationQuery .= " AND c.class_id = ?";
        $noParticipationQuery .= " AND c.class_id = ?";
        $params[] = $filter_class;
    }
    
    if (!empty($filter_subject)) {
        $lowParticipationQuery .= " AND s.subject_id = ?";
        $noParticipationQuery .= " AND s.subject_id = ?";
        $params[] = $filter_subject;
    }
    
    if (!empty($filter_assessment)) {
        $lowParticipationQuery .= " AND ac.assessment_id = ?";
        $noParticipationQuery .= " AND ac.assessment_id = ?";
        $params[] = $filter_assessment;
    }
    
    if (!empty($filter_level)) {
        $lowParticipationQuery .= " AND c.level = ?";
        $noParticipationQuery .= " AND c.level = ?";
        $params[] = $filter_level;
    }
    
    if (!empty($filter_date_from)) {
        $lowParticipationQuery .= " AND a.date >= ?";
        $noParticipationQuery .= " AND a.date >= ?";
        $params[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $lowParticipationQuery .= " AND a.date <= ?";
        $noParticipationQuery .= " AND a.date <= ?";
        $params[] = $filter_date_to;
    }
    
    if (!empty($search)) {
        $searchTerm = "%$search%";
        $lowParticipationQuery .= " AND (a.title LIKE ? OR c.class_name LIKE ? OR p.program_name LIKE ? OR s.subject_name LIKE ?)";
        $noParticipationQuery .= " AND (a.title LIKE ? OR c.class_name LIKE ? OR p.program_name LIKE ? OR s.subject_name LIKE ?)";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    // Finalize low participation query
    $lowParticipationQuery .= " 
        GROUP BY 
            ac.assessment_id, ac.class_id, c.class_name, p.program_name, c.level, s.subject_id, s.subject_name, a.title, a.date
        HAVING 
            (COUNT(DISTINCT att.student_id) / COUNT(DISTINCT st.student_id)) * 100 < ? 
            AND COUNT(DISTINCT att.student_id) > 0
        ORDER BY 
            participation_percentage ASC, 
            a.date DESC,
            a.title, 
            c.class_name";
    
    $params[] = $filter_threshold;
    
    // Finalize no participation query
    $noParticipationQuery .= "
        GROUP BY 
            ac.assessment_id, ac.class_id, c.class_name, p.program_name, c.level, s.subject_id, s.subject_name, a.title, a.date
        ORDER BY 
            a.date DESC,
            a.title, 
            c.class_name";
    
    // Execute queries
    $stmt = $db->prepare($lowParticipationQuery);
    $stmt->execute($params);
    $lowParticipationData = $stmt->fetchAll();
    
    // Remove the threshold parameter for no participation query
    array_pop($params);
    $stmt = $db->prepare($noParticipationQuery);
    $stmt->execute($params);
    $noParticipationData = $stmt->fetchAll();
    
    // Count totals for summary
    $totalLowParticipation = count($lowParticipationData);
    $totalNoParticipation = count($noParticipationData);
    
    // Get most critical classes (lowest participation rates)
    $criticalClasses = [];
    if (!empty($lowParticipationData)) {
        $criticalClasses = array_slice($lowParticipationData, 0, 5);
    }
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    logError("Assessment participation dashboard error: " . $e->getMessage());
}

$pageTitle = 'Assessment Participation Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-warning">Assessment Participation Dashboard</h1>
            <p class="text-muted">Monitor and track class participation in assessments</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-warning" id="exportBtn">
                <i class="fas fa-file-export me-1"></i> Export Data
            </button>
            <button type="button" class="btn btn-warning" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-warning text-white shadow">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">Classes with Low Participation</h6>
                            <h2 class="mb-0"><?php echo $totalLowParticipation; ?></h2>
                        </div>
                        <div class="bg-black bg-opacity-25 rounded-circle p-3">
                            <i class="fas fa-chart-line fa-2x"></i>
                        </div>
                    </div>
                    <p class="small mb-0">Below <?php echo $filter_threshold; ?>% participation rate</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card bg-danger text-white shadow">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase mb-1">Classes with No Participation</h6>
                            <h2 class="mb-0"><?php echo $totalNoParticipation; ?></h2>
                        </div>
                        <div class="bg-black bg-opacity-25 rounded-circle p-3">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                        </div>
                    </div>
                    <p class="small mb-0">0% participation rate</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body">
                    <h5 class="card-title text-warning">Most Critical Classes</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Assessment</th>
                                    <th>Participation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($criticalClasses)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No critical classes found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($criticalClasses as $class): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($class['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($class['assessment_title']); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                        <div class="progress-bar bg-danger" role="progressbar" 
                                                             style="width: <?php echo $class['participation_percentage']; ?>%" 
                                                             aria-valuenow="<?php echo $class['participation_percentage']; ?>" 
                                                             aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <span class="small"><?php echo $class['participation_percentage']; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="tab" id="currentTab" value="<?php echo $tab; ?>">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select" onchange="this.form.submit()">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo htmlspecialchars($program['program_name']); ?>" 
                                        <?php echo $filter_program === $program['program_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-select" onchange="this.form.submit()">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo htmlspecialchars($level); ?>" 
                                        <?php echo $filter_level === $level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Class</label>
                        <select name="class" class="form-select" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                        <?php echo $filter_class == $class['class_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Subject</label>
                        <select name="subject" class="form-select" onchange="this.form.submit()">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $filter_subject == $subject['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Assessment</label>
                        <select name="assessment" class="form-select" onchange="this.form.submit()">
                            <option value="">All Assessments</option>
                            <?php foreach ($assessments as $assessment): ?>
                                <option value="<?php echo $assessment['assessment_id']; ?>" 
                                        <?php echo $filter_assessment == $assessment['assessment_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($assessment['title'] . ' (' . $assessment['date'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Threshold (%)</label>
                        <input type="number" name="threshold" class="form-control" 
                               value="<?php echo $filter_threshold; ?>" min="1" max="99" 
                               onchange="this.form.submit()">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo $filter_date_from; ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo $filter_date_to; ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by title, class, subject..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="assessment_participation.php" class="btn btn-outline-secondary d-block">
                            <i class="fas fa-redo me-1"></i> Reset Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'low_participation' ? 'active bg-dark text-warning' : ''; ?>" 
               href="#" onclick="changeTab('low_participation')">
                <i class="fas fa-chart-line me-1"></i> Low Participation
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'no_participation' ? 'active bg-dark text-warning' : ''; ?>" 
               href="#" onclick="changeTab('no_participation')">
                <i class="fas fa-exclamation-triangle me-1"></i> No Participation
            </a>
        </li>
    </ul>

    <!-- Low Participation Tab -->
    <div class="tab-content" id="low_participation" <?php echo $tab !== 'low_participation' ? 'style="display:none"' : ''; ?>>
        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="lowParticipationTable">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">#</th>
                                <th>Class</th>
                                <th>Program</th>
                                <th>Level</th>
                                <th>Subject</th>
                                <th>Assessment</th>
                                <th>Date</th>
                                <th>Total Students</th>
                                <th>Attempted</th>
                                <th>Participation %</th>
                                <th width="10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lowParticipationData)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-3">
                                        <i class="fas fa-info-circle me-2"></i>No low participation data found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lowParticipationData as $index => $data): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($data['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['program_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['level']); ?></td>
                                        <td><?php echo htmlspecialchars($data['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['assessment_title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($data['assessment_date'])); ?></td>
                                        <td><?php echo $data['total_students']; ?></td>
                                        <td><?php echo $data['students_attempted']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                    <div class="progress-bar 
                                                        <?php echo $data['participation_percentage'] < 20 ? 'bg-danger' : 'bg-warning'; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $data['participation_percentage']; ?>%" 
                                                         aria-valuenow="<?php echo $data['participation_percentage']; ?>" 
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <span class="small"><?php echo $data['participation_percentage']; ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="students.php?class_id=<?php echo $data['class_id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary" title="View Students">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                <a href="performance_dashboard.php?class_id=<?php echo $data['class_id']; ?>&assessment_id=<?php echo $data['assessment_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="View Performance">
                                                    <i class="fas fa-chart-bar"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-warning" 
                                                        onclick="sendReminder(<?php echo $data['assessment_id']; ?>, <?php echo $data['class_id']; ?>)" 
                                                        title="Send Reminder">
                                                    <i class="fas fa-bell"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- No Participation Tab -->
    <div class="tab-content" id="no_participation" <?php echo $tab !== 'no_participation' ? 'style="display:none"' : ''; ?>>
        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="noParticipationTable">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">#</th>
                                <th>Class</th>
                                <th>Program</th>
                                <th>Level</th>
                                <th>Subject</th>
                                <th>Assessment</th>
                                <th>Date</th>
                                <th>Total Students</th>
                                <th width="10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($noParticipationData)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-3">
                                        <i class="fas fa-info-circle me-2"></i>No zero participation data found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($noParticipationData as $index => $data): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($data['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['program_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['level']); ?></td>
                                        <td><?php echo htmlspecialchars($data['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['assessment_title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($data['assessment_date'])); ?></td>
                                        <td><?php echo $data['total_students']; ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="students.php?class_id=<?php echo $data['class_id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary" title="View Students">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="sendReminder(<?php echo $data['assessment_id']; ?>, <?php echo $data['class_id']; ?>)" 
                                                        title="Send Urgent Reminder">
                                                    <i class="fas fa-bell"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Reminder Modal -->
<div class="modal fade" id="reminderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-warning">
                <h5 class="modal-title">Send Participation Reminder</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="reminderForm" action="send_reminder.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="assessment_id" id="reminderAssessmentId">
                <input type="hidden" name="class_id" id="reminderClassId">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reminder Message</label>
                        <textarea class="form-control" name="message" rows="4" required>Dear students, this is a reminder to complete your pending assessment. Please log in to the system and submit your answers as soon as possible.</textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" name="notify_teachers" id="notifyTeachers" checked>
                        <label class="form-check-label" for="notifyTeachers">
                            Also notify class teachers
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-paper-plane me-1"></i> Send Reminder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Options Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-warning">
                <h5 class="modal-title">Export Participation Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="exportForm" action="export_participation.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="export_filters" id="exportFilters">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select class="form-select" name="export_format">
                            <option value="excel">Excel (.xlsx)</option>
                            <option value="csv">CSV (.csv)</option>
                            <option value="pdf">PDF (.pdf)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Data to Export</label>
                        <select class="form-select" name="export_data">
                            <option value="low_participation">Low Participation Data</option>
                            <option value="no_participation">No Participation Data</option>
                            <option value="both">Both Datasets</option>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" name="include_student_details" id="includeStudentDetails">
                        <label class="form-check-label" for="includeStudentDetails">
                            Include individual student details
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize DataTables
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#lowParticipationTable').DataTable({
            "pageLength": 25,
            "order": [[9, "asc"]],  // Sort by participation percentage ascending
            "dom": 'Bfrtip',
            "buttons": [
                'copy', 'excel', 'pdf', 'print'
            ]
        });
        
        $('#noParticipationTable').DataTable({
            "pageLength": 25,
            "dom": 'Bfrtip',
            "buttons": [
                'copy', 'excel', 'pdf', 'print'
            ]
        });
    }
    
    // Handle tab switching
    document.querySelectorAll('.nav-tabs .nav-link').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const tabId = this.getAttribute('href').substring(1);
            changeTab(tabId);
        });
    });
    
    // Handle export button
    document.getElementById('exportBtn').addEventListener('click', function() {
        // Set current filters as JSON in hidden field
        const filters = {
            program: '<?php echo addslashes($filter_program); ?>',
            class: '<?php echo addslashes($filter_class); ?>',
            subject: '<?php echo addslashes($filter_subject); ?>',
            assessment: '<?php echo addslashes($filter_assessment); ?>',
            level: '<?php echo addslashes($filter_level); ?>',
            date_from: '<?php echo addslashes($filter_date_from); ?>',
            date_to: '<?php echo addslashes($filter_date_to); ?>',
            threshold: '<?php echo addslashes($filter_threshold); ?>',
            search: '<?php echo addslashes($search); ?>'
        };
        
        document.getElementById('exportFilters').value = JSON.stringify(filters);
        
        // Show export modal
        new bootstrap.Modal(document.getElementById('exportModal')).show();
    });
});

// Function to change tabs
// Function to change tabs
function changeTab(tabId) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(function(content) {
        content.style.display = 'none';
    });
    
    // Show selected tab content
    document.getElementById(tabId).style.display = 'block';
    
    // Update active tab styling
    document.querySelectorAll('.nav-tabs .nav-link').forEach(function(tab) {
        tab.classList.remove('active', 'bg-dark', 'text-warning');
    });
    
    document.querySelector('.nav-tabs .nav-link[onclick*="' + tabId + '"]').classList.add('active', 'bg-dark', 'text-warning');
    
    // Update hidden input for form submissions
    document.getElementById('currentTab').value = tabId;
    
    // Submit the form to refresh the data with the new tab
    document.getElementById('filterForm').submit();
}

// Function to send reminders
function sendReminder(assessmentId, classId) {
    document.getElementById('reminderAssessmentId').value = assessmentId;
    document.getElementById('reminderClassId').value = classId;
    
    // Show reminder modal
    new bootstrap.Modal(document.getElementById('reminderModal')).show();
}

// Ensure filters are preserved when changing tabs
function preserveFilters(tabId) {
    document.getElementById('currentTab').value = tabId;
    document.getElementById('filterForm').submit();
}
</script>

<style>
/* Custom Styles for Assessment Participation Dashboard */
.table > :not(caption) > * > * {
    padding: 0.75rem;
}

.table-dark th {
    background: linear-gradient(45deg, #000000, #333333);
    color: #ffd700;
    font-weight: 500;
    white-space: nowrap;
}

.progress {
    background-color: #f2f2f2;
    border-radius: 10px;
    height: 8px;
}

.progress-bar {
    border-radius: 10px;
}

.bg-warning, .btn-warning {
    background: linear-gradient(45deg, #000000, #ffd700) !important;
    border: none;
    color: white !important;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ffd700, #000000) !important;
    color: white !important;
}

.card {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.card-title {
    font-weight: 600;
    margin-bottom: 15px;
}

.nav-tabs .nav-link {
    color: #333;
    border: 1px solid #ddd;
    border-bottom: none;
    background-color: #f8f9fa;
    margin-right: 5px;
    border-radius: 5px 5px 0 0;
    font-weight: 500;
}

.nav-tabs .nav-link.active {
    color: #ffd700;
    background-color: #000;
    border-color: #333;
}

.nav-tabs .nav-link:hover:not(.active) {
    background-color: #e9ecef;
    border-color: #ddd;
}

/* For print layout */
@media print {
    .navbar, #sidebar-wrapper, 
    .btn, button, .nav-tabs, 
    .card-header, .form-control, .form-select,
    #filterForm, .actions {
        display: none !important;
    }
    
    .card {
        border: 1px solid #ddd;
        margin-bottom: 1rem;
        box-shadow: none !important;
    }
    
    .container-fluid {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    .tab-content {
        display: block !important;
    }
    
    table {
        width: 100% !important;
        font-size: 11px !important;
    }
    
    @page {
        size: landscape;
        margin: 0.5cm;
    }
}
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>