<?php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();
$error = null;
$success = null;
$assessments = [];
$attempts = [];
$searchTerm = '';
$assessmentId = 0;
$status = '';
$classId = 0;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_assessment') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token");
        }
        
        $attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
        $assessmentId = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $resetType = $_POST['reset_type'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $enableEditMode = isset($_POST['enable_edit_mode']);
        
        $timeCalculationMethod = $_POST['time_calculation_method'] ?? 'remaining_time';
        $remainingTimeMinutes = filter_input(INPUT_POST, 'remaining_time_minutes', FILTER_VALIDATE_INT);
        $issueTime = $_POST['issue_time'] ?? '';
        
        if (!$attemptId || !$assessmentId || !$studentId || !in_array($resetType, ['partial', 'full']) || empty($reason)) {
            throw new Exception("All fields are required");
        }
        
        if ($resetType === 'partial' && !$remainingTimeMinutes && $timeCalculationMethod === 'remaining_time') {
            throw new Exception("Please provide remaining time");
        }
        
        $stmt = $db->prepare("SELECT * FROM Assessments WHERE assessment_id = ?");
        $stmt->execute([$assessmentId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assessment) {
            throw new Exception("Assessment not found");
        }
        
        $stmt = $db->prepare("SELECT * FROM AssessmentAttempts WHERE attempt_id = ? AND assessment_id = ? AND student_id = ?");
        $stmt->execute([$attemptId, $assessmentId, $studentId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attempt) {
            throw new Exception("Attempt not found");
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM AssessmentResets WHERE assessment_id = ? AND student_id = ?");
        $stmt->execute([$assessmentId, $studentId]);
        $resetCount = $stmt->fetchColumn();
        
        if ($resetCount >= 5) {
            throw new Exception("Maximum reset limit reached (5 per assessment)");
        }
        
        $db->beginTransaction();
        
        try {
            $actualTimeUsedMinutes = 0;
            $originalDurationMinutes = $assessment['duration'] ?? 60;
            
            if ($resetType === 'partial') {
                if ($timeCalculationMethod === 'remaining_time') {
                    $actualTimeUsedMinutes = $originalDurationMinutes - $remainingTimeMinutes;
                } else if ($timeCalculationMethod === 'issue_time' && !empty($issueTime)) {
                    $assessmentDate = date('Y-m-d', strtotime($attempt['start_time']));
                    $issueDateTime = $assessmentDate . ' ' . $issueTime;
                    $issueTimestamp = strtotime($issueDateTime);
                    $startTime = strtotime($attempt['start_time']);
                    
                    if ($issueTimestamp <= $startTime) {
                        throw new Exception("Issue time must be after start time");
                    }
                    
                    $actualTimeUsedMinutes = round(($issueTimestamp - $startTime) / 60);
                    $remainingTimeMinutes = $originalDurationMinutes - $actualTimeUsedMinutes;
                    
                    if ($remainingTimeMinutes <= 0) {
                        throw new Exception("Issue time indicates student already exceeded assessment duration");
                    }
                }
                
                if ($actualTimeUsedMinutes < 0 || $actualTimeUsedMinutes >= $originalDurationMinutes) {
                    throw new Exception("Invalid time calculation");
                }
            }
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM StudentAnswers WHERE assessment_id = ? AND student_id = ?");
            $stmt->execute([$assessmentId, $studentId]);
            $answeredQuestions = $stmt->fetchColumn();
            
            $stmt = $db->prepare("INSERT INTO AssessmentResets (assessment_id, student_id, admin_id, reset_type, reason, previous_status, previous_answers_count) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$assessmentId, $studentId, $_SESSION['user_id'], $resetType, $reason, $attempt['status'], $answeredQuestions]);
            
            $metadata = [
                'original_duration_minutes' => $originalDurationMinutes,
                'reset_type' => $resetType,
                'reset_timestamp' => time(),
                'admin_reset' => true,
                'reset_by' => $_SESSION['user_id'],
                'reset_reason' => $reason,
                'timer_started' => false // Flag to track if timer has started
            ];
            
            if ($resetType === 'partial') {
                $metadata['time_tracking'] = [
                    'calculation_method' => $timeCalculationMethod,
                    'actual_time_used_minutes' => $actualTimeUsedMinutes,
                    'remaining_time_minutes' => $remainingTimeMinutes,
                    'issue_time' => $issueTime,
                    'original_start_time' => $attempt['start_time']
                ];
                $metadata['custom_duration_minutes'] = $remainingTimeMinutes;
            }
            
            $existingMetadata = [];
            if (!empty($attempt['answer_metadata'])) {
                $existingMetadata = json_decode($attempt['answer_metadata'], true);
            }
            
            if (isset($existingMetadata['reset_history'])) {
                $metadata['reset_history'] = $existingMetadata['reset_history'];
            } else {
                $metadata['reset_history'] = [];
            }
            $metadata['reset_history'][] = $metadata;
            
            if ($resetType === 'full') {
                $stmt = $db->prepare("DELETE FROM StudentAnswers WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
                
                $stmt = $db->prepare("DELETE FROM Results WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
                
                $stmt = $db->prepare("DELETE FROM AssessmentAttempts WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
            } else {
                // FIXED: Don't update start_time here - let student's first access set it
                $stmt = $db->prepare("UPDATE AssessmentAttempts SET status = 'in_progress', end_time = NULL, answer_metadata = ? WHERE attempt_id = ?");
                $stmt->execute([json_encode($metadata), $attemptId]);
                
                $stmt = $db->prepare("DELETE FROM Results WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
            }
            
            if ($enableEditMode) {
                $stmt = $db->prepare("UPDATE Assessments SET reset_edit_mode = 1 WHERE assessment_id = ?");
                $stmt->execute([$assessmentId]);
            }
            
            logSystemActivity('Assessment Reset', "Assessment ID: $assessmentId reset for Student ID: $studentId. Type: $resetType", 'INFO', $_SESSION['user_id']);
            
            $db->commit();
            
            $successMessage = "Assessment reset successfully";
            if ($resetType === 'partial') {
                $successMessage .= ". Timer will start when student accesses the assessment";
            }
            $success = $successMessage;
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_reset') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token");
        }
        
        $selectedAttempts = $_POST['selected_attempts'] ?? [];
        $resetType = $_POST['bulk_reset_type'] ?? '';
        $reason = trim($_POST['bulk_reason'] ?? '');
        $enableEditMode = isset($_POST['bulk_enable_edit_mode']);
        
        $bulkTimeMethod = $_POST['bulk_time_method'] ?? 'remaining_time';
        $bulkRemainingTime = filter_input(INPUT_POST, 'bulk_remaining_time', FILTER_VALIDATE_INT);
        $bulkIssueTime = $_POST['bulk_issue_time'] ?? '';
        
        if (empty($selectedAttempts) || empty($reason)) {
            throw new Exception("Please select attempts and provide reason");
        }
        
        if ($resetType === 'partial' && !$bulkRemainingTime && $bulkTimeMethod === 'remaining_time') {
            throw new Exception("Please provide remaining time for partial reset");
        }
        
        $db->beginTransaction();
        
        try {
            $resetCount = 0;
            $assessmentIds = [];
            
            foreach ($selectedAttempts as $attemptData) {
                list($attemptId, $assessmentId, $studentId) = explode('-', $attemptData);
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM AssessmentResets WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
                if ($stmt->fetchColumn() >= 5) continue;
                
                $stmt = $db->prepare("SELECT * FROM AssessmentAttempts WHERE attempt_id = ?");
                $stmt->execute([$attemptId]);
                $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$attempt) continue;
                
                $stmt = $db->prepare("SELECT duration FROM Assessments WHERE assessment_id = ?");
                $stmt->execute([$assessmentId]);
                $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$assessment) continue;
                
                $actualTimeUsedMinutes = 0;
                $originalDurationMinutes = $assessment['duration'] ?? 60;
                
                if ($resetType === 'partial') {
                    if ($bulkTimeMethod === 'remaining_time') {
                        $actualTimeUsedMinutes = $originalDurationMinutes - $bulkRemainingTime;
                    } else if ($bulkTimeMethod === 'issue_time' && !empty($bulkIssueTime)) {
                        $assessmentDate = date('Y-m-d', strtotime($attempt['start_time']));
                        $issueDateTime = $assessmentDate . ' ' . $bulkIssueTime;
                        $issueTimestamp = strtotime($issueDateTime);
                        $startTime = strtotime($attempt['start_time']);
                        
                        if ($issueTimestamp > $startTime) {
                            $actualTimeUsedMinutes = round(($issueTimestamp - $startTime) / 60);
                            $bulkRemainingTime = $originalDurationMinutes - $actualTimeUsedMinutes;
                        }
                    }
                }
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM StudentAnswers WHERE assessment_id = ? AND student_id = ?");
                $stmt->execute([$assessmentId, $studentId]);
                $answeredQuestions = $stmt->fetchColumn();
                
                $stmt = $db->prepare("INSERT INTO AssessmentResets (assessment_id, student_id, admin_id, reset_type, reason, previous_status, previous_answers_count) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$assessmentId, $studentId, $_SESSION['user_id'], $resetType, $reason, $attempt['status'], $answeredQuestions]);
                
                $metadata = [
                    'original_duration_minutes' => $originalDurationMinutes,
                    'reset_type' => $resetType,
                    'reset_timestamp' => time(),
                    'bulk_reset' => true,
                    'admin_reset' => true,
                    'reset_by' => $_SESSION['user_id'],
                    'reset_reason' => $reason,
                    'timer_started' => false // Flag to track if timer has started
                ];
                
                if ($resetType === 'partial') {
                    $metadata['time_tracking'] = [
                        'calculation_method' => $bulkTimeMethod,
                        'actual_time_used_minutes' => $actualTimeUsedMinutes,
                        'remaining_time_minutes' => $bulkRemainingTime,
                        'issue_time' => $bulkIssueTime,
                        'original_start_time' => $attempt['start_time']
                    ];
                    $metadata['custom_duration_minutes'] = $bulkRemainingTime;
                }
                
                if ($resetType === 'full') {
                    $stmt = $db->prepare("DELETE FROM StudentAnswers WHERE assessment_id = ? AND student_id = ?");
                    $stmt->execute([$assessmentId, $studentId]);
                    
                    $stmt = $db->prepare("DELETE FROM Results WHERE assessment_id = ? AND student_id = ?");
                    $stmt->execute([$assessmentId, $studentId]);
                    
                    $stmt = $db->prepare("DELETE FROM AssessmentAttempts WHERE assessment_id = ? AND student_id = ?");
                    $stmt->execute([$assessmentId, $studentId]);
                } else {
                    // FIXED: Don't update start_time here - let student's first access set it
                    $stmt = $db->prepare("UPDATE AssessmentAttempts SET status = 'in_progress', end_time = NULL, answer_metadata = ? WHERE attempt_id = ?");
                    $stmt->execute([json_encode($metadata), $attemptId]);
                    
                    $stmt = $db->prepare("DELETE FROM Results WHERE assessment_id = ? AND student_id = ?");
                    $stmt->execute([$assessmentId, $studentId]);
                }
                
                $assessmentIds[] = $assessmentId;
                $resetCount++;
            }
            
            if ($enableEditMode && !empty($assessmentIds)) {
                $uniqueAssessmentIds = array_unique($assessmentIds);
                foreach ($uniqueAssessmentIds as $aid) {
                    $stmt = $db->prepare("UPDATE Assessments SET reset_edit_mode = 1 WHERE assessment_id = ?");
                    $stmt->execute([$aid]);
                }
            }
            
            logSystemActivity('Bulk Assessment Reset', "Bulk reset performed on $resetCount attempts", 'INFO', $_SESSION['user_id']);
            
            $db->commit();
            $success = "Successfully reset $resetCount assessment attempts. Timers will start when students access their assessments.";
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    $searchTerm = $_GET['search'] ?? '';
    $assessmentId = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT) ?: 0;
    $status = $_GET['status'] ?? '';
    $classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
    
    $stmt = $db->query("SELECT assessment_id, title, duration, reset_edit_mode FROM Assessments ORDER BY date DESC, title");
    $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT c.class_id, c.class_name, c.level, p.program_name FROM classes c JOIN programs p ON c.program_id = p.program_id ORDER BY p.program_name, c.level, c.class_name");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $query = "
        SELECT 
            aa.attempt_id, aa.assessment_id, aa.student_id, aa.start_time, aa.status, aa.answer_metadata,
            a.title AS assessment_title, a.date AS assessment_date, a.reset_edit_mode, a.duration as assessment_duration,
            a.use_question_limit, a.questions_to_answer,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            u.username, c.class_name, c.class_id, c.level, p.program_name,
            (SELECT COUNT(*) FROM StudentAnswers sa WHERE sa.assessment_id = aa.assessment_id AND sa.student_id = aa.student_id) AS answered_questions,
            (SELECT COUNT(*) FROM Questions q WHERE q.assessment_id = aa.assessment_id) AS total_questions_pool,
            (SELECT COUNT(*) FROM AssessmentResets ar WHERE ar.assessment_id = aa.assessment_id AND ar.student_id = aa.student_id) AS reset_count
        FROM AssessmentAttempts aa
        JOIN Assessments a ON aa.assessment_id = a.assessment_id
        JOIN Students s ON aa.student_id = s.student_id
        JOIN Users u ON s.user_id = u.user_id
        JOIN Classes c ON s.class_id = c.class_id
        JOIN Programs p ON c.program_id = p.program_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($searchTerm)) {
        $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR u.username LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($assessmentId > 0) {
        $query .= " AND aa.assessment_id = ?";
        $params[] = $assessmentId;
    }
    
    if (!empty($status)) {
        $query .= " AND aa.status = ?";
        $params[] = $status;
    }
    
    if ($classId > 0) {
        $query .= " AND c.class_id = ?";
        $params[] = $classId;
    }
    
    $query .= " ORDER BY aa.start_time DESC LIMIT 100";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($attempts as &$attempt) {
        if ($attempt['use_question_limit'] && $attempt['questions_to_answer']) {
            $attempt['total_questions'] = $attempt['questions_to_answer'];
            $attempt['is_question_pool'] = true;
        } else {
            $attempt['total_questions'] = $attempt['total_questions_pool'];
            $attempt['is_question_pool'] = false;
        }
        
        $attempt['time_info'] = '';
        if (!empty($attempt['answer_metadata'])) {
            $metadata = json_decode($attempt['answer_metadata'], true);
            if (isset($metadata['reset_type']) && $metadata['reset_type'] === 'partial') {
                if (isset($metadata['timer_started']) && !$metadata['timer_started']) {
                    $attempt['time_info'] = "Timer not started - waiting for student access";
                } elseif (isset($metadata['time_tracking'])) {
                    $timeTracking = $metadata['time_tracking'];
                    $attempt['time_info'] = "Used: {$timeTracking['actual_time_used_minutes']}min, Remaining: {$timeTracking['remaining_time_minutes']}min";
                }
            }
        }
    }
    unset($attempt);
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Reset assessment error: " . $e->getMessage());
}

$pageTitle = 'Reset Student Assessments';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid px-3 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4">Reset Student Assessments</h1>
        <a href="settings.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title m-0">Search Attempts</h5>
        </div>
        <div class="card-body">
            <form method="get" action="reset_assessment.php" class="row g-3">
                <div class="col-lg-3 col-md-6">
                    <select name="assessment_id" class="form-select">
                        <option value="0">All Assessments</option>
                        <?php foreach ($assessments as $assessment): ?>
                            <option value="<?php echo $assessment['assessment_id']; ?>" 
                                <?php echo ($assessmentId == $assessment['assessment_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assessment['title']); ?>
                                <?php if ($assessment['reset_edit_mode']): ?>
                                    (Edit Mode)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <select name="class_id" class="form-select">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>" 
                                <?php echo ($classId == $class['class_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-lg-2 col-md-6">
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="in_progress" <?php echo ($status === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo ($status === 'completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="expired" <?php echo ($status === 'expired') ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search by name or username" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                        <a href="reset_assessment.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title m-0">Assessment Attempts</h5>
                    <div class="mt-2" id="bulkControls" style="display: none;">
                        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#bulkResetModal">
                            <i class="fas fa-redo-alt me-1"></i>Bulk Reset Selected
                        </button>
                        <span class="badge bg-info ms-2" id="selectedCount">0 selected</span>
                    </div>
                </div>
                <div>
                    <span class="badge bg-secondary"><?php echo count($attempts); ?> results</span>
                    <div class="form-check form-check-inline ms-3">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label" for="selectAll">Select All</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="30">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                </div>
                            </th>
                            <th>Student</th>
                            <th>Assessment</th>
                            <th>Class</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Duration</th>
                            <th>Time Info</th>
                            <th>Resets</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attempts)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-3">No assessment attempts found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attempts as $attempt): ?>
                                <tr>
                                    <td>
                                        <?php if ($attempt['reset_count'] < 5): ?>
                                            <div class="form-check">
                                                <input class="form-check-input attempt-checkbox" type="checkbox" 
                                                       value="<?php echo $attempt['attempt_id'] . '-' . $attempt['assessment_id'] . '-' . $attempt['student_id']; ?>">
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($attempt['student_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($attempt['username']); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($attempt['assessment_title']); ?></div>
                                        <?php if ($attempt['reset_edit_mode']): ?>
                                            <span class="badge bg-warning text-dark">Edit Mode</span>
                                        <?php endif; ?>
                                        <?php if ($attempt['is_question_pool']): ?>
                                            <span class="badge bg-info text-white ms-1">Pool</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($attempt['class_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($attempt['program_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $progress = ($attempt['total_questions'] > 0) 
                                            ? round(($attempt['answered_questions'] / $attempt['total_questions']) * 100) 
                                            : 0;
                                        ?>
                                        <div class="progress" style="height: 6px; width: 100px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                        <small><?php echo $attempt['answered_questions']; ?>/<?php echo $attempt['total_questions']; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        switch ($attempt['status']) {
                                            case 'completed':
                                                $statusClass = 'bg-success';
                                                break;
                                            case 'in_progress':
                                                $statusClass = 'bg-warning text-dark';
                                                break;
                                            default:
                                                $statusClass = 'bg-secondary';
                                        }
                                        ?>
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($attempt['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($attempt['start_time']): ?>
                                            <small><?php echo date('M d, g:i A', strtotime($attempt['start_time'])); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not started</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo $attempt['assessment_duration'] ? $attempt['assessment_duration'] . 'm' : 'No limit'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($attempt['time_info'])): ?>
                                            <small class="text-info"><?php echo htmlspecialchars($attempt['time_info']); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Normal</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $attempt['reset_count']; ?>/5</span>
                                    </td>
                                    <td>
                                        <?php if ($attempt['reset_count'] < 5): ?>
                                            <button type="button" class="btn btn-sm btn-primary reset-btn"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#resetModal"
                                                    data-attempt-id="<?php echo $attempt['attempt_id']; ?>"
                                                    data-assessment-id="<?php echo $attempt['assessment_id']; ?>"
                                                    data-student-id="<?php echo $attempt['student_id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($attempt['student_name']); ?>"
                                                    data-assessment-title="<?php echo htmlspecialchars($attempt['assessment_title']); ?>"
                                                    data-progress="<?php echo $attempt['answered_questions']; ?>/<?php echo $attempt['total_questions']; ?>"
                                                    data-reset-edit-mode="<?php echo $attempt['reset_edit_mode']; ?>"
                                                    data-current-duration="<?php echo $attempt['assessment_duration']; ?>">
                                                <i class="fas fa-redo-alt"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary" disabled>
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Modals remain the same -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="reset_assessment">
                <input type="hidden" name="attempt_id" id="modal_attempt_id">
                <input type="hidden" name="assessment_id" id="modal_assessment_id">
                <input type="hidden" name="student_id" id="modal_student_id">
                
                <div class="modal-header py-2" style="background: linear-gradient(135deg, #000000 0%, #ffd700 100%);">
                    <h6 class="modal-title mb-0 text-white">Reset Assessment</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-3">
                    <div class="student-info mb-3 p-2 bg-light rounded">
                        <div class="row g-2 small">
                            <div class="col-8">
                                <strong id="modal_student_name"></strong>
                                <div class="text-muted" id="modal_assessment_title"></div>
                            </div>
                            <div class="col-4 text-end">
                                <span id="modal_progress" class="badge bg-secondary"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Timer will start when student accesses the assessment after reset</small>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small mb-1">Reset Type</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="reset_type" id="reset_partial" value="partial" checked>
                                <label class="btn btn-outline-primary btn-sm" for="reset_partial">Partial</label>
                                
                                <input type="radio" class="btn-check" name="reset_type" id="reset_full" value="full">
                                <label class="btn btn-outline-danger btn-sm" for="reset_full">Full</label>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <label class="form-label fw-bold small mb-1">Edit Mode</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="enable_edit_mode" id="enable_edit_mode" value="1">
                                <label class="form-check-label small" for="enable_edit_mode">Enable</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="timeTrackingSection" class="mb-3">
                        <label class="form-label fw-bold small mb-2">Time Calculation</label>
                        
                        <div class="btn-group w-100 mb-2" role="group">
                            <input type="radio" class="btn-check" name="time_calculation_method" id="method_remaining" value="remaining_time" checked>
                            <label class="btn btn-outline-secondary btn-sm" for="method_remaining">Give Time</label>
                            
                            <input type="radio" class="btn-check" name="time_calculation_method" id="method_issue_time" value="issue_time">
                            <label class="btn btn-outline-secondary btn-sm" for="method_issue_time">Issue Time</label>
                        </div>
                        
                        <div id="remainingTimeGroup">
                            <div class="input-group input-group-sm">
                                <input type="number" name="remaining_time_minutes" id="remaining_time_minutes" 
                                       class="form-control" min="1" max="480" placeholder="15" required>
                                <span class="input-group-text">min remaining</span>
                            </div>
                        </div>
                        
                        <div id="issueTimeGroup" style="display: none;">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Issue at</span>
                                <input type="time" name="issue_time" id="issue_time" class="form-control">
                                <span class="input-group-text">today</span>
                            </div>
                        </div>
                        
                        <small id="timeCalculation" class="text-info d-block mt-1"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small mb-1">Quick Reasons</label>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm reason-btn" data-reason="Power outage during assessment">Power</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm reason-btn" data-reason="Internet connection issues">Network</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm reason-btn" data-reason="System technical malfunction">System</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm reason-btn" data-reason="Timer malfunction reported">Timer</button>
                        </div>
                    </div>
                    
                    <div class="mb-0">
                        <label for="reason" class="form-label fw-bold small mb-1">Detailed Reason</label>
                        <textarea class="form-control form-control-sm" id="reason" name="reason" rows="2" required 
                                  placeholder="Provide detailed reason for reset"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkResetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="bulkResetForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="bulk_reset">
                <div id="selectedAttemptsContainer"></div>
                
                <div class="modal-header py-2" style="background: linear-gradient(135deg, #ffd700 0%, #f0ad4e 100%);">
                    <h6 class="modal-title mb-0 text-dark">Bulk Reset</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold small mb-1">Selected Attempts</label>
                        <div id="bulkPreviewList" class="border rounded p-2 bg-light small" style="max-height: 120px; overflow-y: auto;">
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Timers will start when students access their assessments after reset</small>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-7">
                            <label class="form-label fw-bold small mb-1">Reset Type</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="bulk_reset_type" id="bulk_partial" value="partial" checked>
                                <label class="btn btn-outline-primary btn-sm" for="bulk_partial">Partial</label>
                                
                                <input type="radio" class="btn-check" name="bulk_reset_type" id="bulk_full" value="full">
                                <label class="btn btn-outline-danger btn-sm" for="bulk_full">Full</label>
                            </div>
                        </div>
                        
                        <div class="col-5">
                            <label class="form-label fw-bold small mb-1">Edit Mode</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="bulk_enable_edit_mode" id="bulk_enable_edit_mode" value="1">
                                <label class="form-check-label small" for="bulk_enable_edit_mode">Enable</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="bulkTimeTrackingSection" class="mb-3">
                        <label class="form-label fw-bold small mb-2">Time Calculation</label>
                        
                        <div class="btn-group w-100 mb-2" role="group">
                            <input type="radio" class="btn-check" name="bulk_time_method" id="bulk_method_remaining" value="remaining_time" checked>
                            <label class="btn btn-outline-secondary btn-sm" for="bulk_method_remaining">Give Time</label>
                            
                            <input type="radio" class="btn-check" name="bulk_time_method" id="bulk_method_issue_time" value="issue_time">
                            <label class="btn btn-outline-secondary btn-sm" for="bulk_method_issue_time">Issue Time</label>
                        </div>
                        
                        <div id="bulkRemainingTimeGroup">
                            <div class="input-group input-group-sm">
                                <input type="number" name="bulk_remaining_time" id="bulk_remaining_time" 
                                       class="form-control" min="1" max="480" placeholder="15">
                                <span class="input-group-text">min</span>
                            </div>
                        </div>
                        
                        <div id="bulkIssueTimeGroup" style="display: none;">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">At</span>
                                <input type="time" name="bulk_issue_time" id="bulk_issue_time" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-0">
                        <label for="bulk_reason" class="form-label fw-bold small mb-1">Bulk Reset Reason</label>
                        <textarea class="form-control form-control-sm" id="bulk_reason" name="bulk_reason" rows="2" required 
                                  placeholder="Detailed reason for bulk reset..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">Reset Selected</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.table-responsive {
    border-radius: 0.375rem;
}

.btn-primary {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
    border: none;
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(45deg, #ffd700 0%, #000000 100%);
    color: white;
}

.btn-warning {
    background: linear-gradient(45deg, #ffd700 0%, #ff6f00 100%);
    border: none;
    color: #000;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ff6f00 0%, #ffd700 100%);
    color: #000;
}

.progress-bar {
    background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
}

.badge.bg-info {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%) !important;
    color: white;
}

.card-header {
    background: linear-gradient(90deg, #000000 0%, #222 100%);
    color: #ffd700;
}

.text-info {
    color: #ffd700 !important;
}

.reason-btn.selected {
    background: linear-gradient(45deg, #ffd700 0%, #ff6f00 100%);
    border-color: #ffd700;
    color: #000;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .badge {
        font-size: 0.7rem;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .container-fluid {
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
}

@media (max-width: 576px) {
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 1rem;
    }
    
    .form-check-inline {
        margin-left: 0 !important;
    }
    
    .btn-group {
        flex-wrap: wrap;
    }
    
    .input-group-sm .form-control,
    .input-group-sm .input-group-text {
        font-size: 0.75rem;
    }
}

.reason-btn {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

#bulkControls {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const attemptCheckboxes = document.querySelectorAll('.attempt-checkbox');
    const selectAllCheckbox = document.getElementById('selectAll');
    const selectAllHeaderCheckbox = document.getElementById('selectAllHeader');
    const bulkControls = document.getElementById('bulkControls');
    const selectedCountBadge = document.getElementById('selectedCount');
    
    let selectedAttempts = [];
    
    function updateBulkControls() {
        selectedAttempts = Array.from(attemptCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
        selectedCountBadge.textContent = `${selectedAttempts.length} selected`;
        
        bulkControls.style.display = selectedAttempts.length > 0 ? 'block' : 'none';
        
        const allChecked = selectedAttempts.length === attemptCheckboxes.length && attemptCheckboxes.length > 0;
        const someChecked = selectedAttempts.length > 0;
        
        selectAllCheckbox.checked = allChecked;
        selectAllCheckbox.indeterminate = someChecked && !allChecked;
        selectAllHeaderCheckbox.checked = allChecked;
        selectAllHeaderCheckbox.indeterminate = someChecked && !allChecked;
    }
    
    attemptCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkControls);
    });
    
    [selectAllCheckbox, selectAllHeaderCheckbox].forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            attemptCheckboxes.forEach(cb => cb.checked = this.checked);
            updateBulkControls();
        });
    });
    
    document.querySelectorAll('.reset-btn').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('modal_attempt_id').value = this.dataset.attemptId;
            document.getElementById('modal_assessment_id').value = this.dataset.assessmentId;
            document.getElementById('modal_student_id').value = this.dataset.studentId;
            document.getElementById('modal_student_name').textContent = this.dataset.studentName;
            document.getElementById('modal_assessment_title').textContent = this.dataset.assessmentTitle;
            document.getElementById('modal_progress').textContent = this.dataset.progress;
            
            document.getElementById('reset_partial').checked = true;
            toggleTimeTrackingSection();
            
            const editModeCheckbox = document.getElementById('enable_edit_mode');
            if (this.dataset.resetEditMode == '1') {
                editModeCheckbox.checked = true;
                editModeCheckbox.disabled = true;
            } else {
                editModeCheckbox.checked = false;
                editModeCheckbox.disabled = false;
            }
            
            document.getElementById('reason').value = '';
            document.querySelectorAll('.reason-btn').forEach(btn => btn.classList.remove('selected'));
        });
    });
    
    function toggleTimeTrackingSection() {
        const isPartial = document.getElementById('reset_partial').checked;
        const timeSection = document.getElementById('timeTrackingSection');
        const remainingTimeInput = document.getElementById('remaining_time_minutes');
        
        if (isPartial) {
            timeSection.style.display = 'block';
            remainingTimeInput.required = true;
        } else {
            timeSection.style.display = 'none';
            remainingTimeInput.required = false;
            document.getElementById('timeCalculation').textContent = '';
        }
    }
    
    document.getElementById('reset_partial').addEventListener('change', toggleTimeTrackingSection);
    document.getElementById('reset_full').addEventListener('change', toggleTimeTrackingSection);
    
    document.getElementById('method_remaining').addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('issueTimeGroup').style.display = 'none';
            document.getElementById('remainingTimeGroup').style.display = 'block';
            document.getElementById('issue_time').required = false;
            document.getElementById('remaining_time_minutes').required = true;
            updateTimeCalculation();
        }
    });
    
    document.getElementById('method_issue_time').addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('issueTimeGroup').style.display = 'block';
            document.getElementById('remainingTimeGroup').style.display = 'none';
            document.getElementById('issue_time').required = true;
            document.getElementById('remaining_time_minutes').required = false;
            updateTimeCalculation();
        }
    });
    
    function updateTimeCalculation() {
        const method = document.querySelector('input[name="time_calculation_method"]:checked')?.value;
        const remainingTime = parseInt(document.getElementById('remaining_time_minutes').value) || 0;
        const issueTime = document.getElementById('issue_time').value;
        const currentDuration = 60; // Default assumption
        
        if (method === 'remaining_time' && remainingTime > 0) {
            const usedTime = currentDuration - remainingTime;
            document.getElementById('timeCalculation').textContent = 
                `Student will be credited with ${usedTime} minutes used, ${remainingTime} minutes remaining (timer starts on access)`;
        } else if (method === 'issue_time' && issueTime) {
            document.getElementById('timeCalculation').textContent = 
                `Remaining time will be calculated automatically based on issue time (timer starts on access)`;
        } else {
            document.getElementById('timeCalculation').textContent = '';
        }
    }
    
    document.getElementById('remaining_time_minutes').addEventListener('input', updateTimeCalculation);
    document.getElementById('issue_time').addEventListener('change', updateTimeCalculation);
    
    document.querySelectorAll('.reason-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('reason').value = this.dataset.reason;
            document.querySelectorAll('.reason-btn').forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
        });
    });
    
    document.querySelector('[data-bs-target="#bulkResetModal"]').addEventListener('click', function() {
        if (selectedAttempts.length === 0) {
            alert('Please select at least one assessment attempt to reset.');
            return;
        }
        populateBulkModal();
    });
    
    function populateBulkModal() {
        const container = document.getElementById('selectedAttemptsContainer');
        const previewList = document.getElementById('bulkPreviewList');
        
        container.innerHTML = '';
        previewList.innerHTML = '';
        
        selectedAttempts.forEach(attemptData => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_attempts[]';
            input.value = attemptData;
            container.appendChild(input);
            
            const [attemptId, assessmentId, studentId] = attemptData.split('-');
            const row = document.querySelector(`input[value="${attemptData}"]`).closest('tr');
            const studentName = row.querySelector('td:nth-child(2) .fw-bold').textContent;
            const assessmentTitle = row.querySelector('td:nth-child(3) .fw-bold').textContent;
            
            const previewItem = document.createElement('div');
            previewItem.className = 'd-flex justify-content-between align-items-center p-1 mb-1 bg-white rounded border';
            previewItem.innerHTML = `
                <small><strong>${studentName}</strong> - ${assessmentTitle}</small>
                <span class="badge bg-primary">Selected</span>
            `;
            previewList.appendChild(previewItem);
        });
    }
    
    document.getElementById('bulk_method_remaining').addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('bulkRemainingTimeGroup').style.display = 'block';
            document.getElementById('bulkIssueTimeGroup').style.display = 'none';
            document.getElementById('bulk_issue_time').required = false;
            document.getElementById('bulk_remaining_time').required = true;
        }
    });
    
    document.getElementById('bulk_method_issue_time').addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('bulkRemainingTimeGroup').style.display = 'none';
            document.getElementById('bulkIssueTimeGroup').style.display = 'block';
            document.getElementById('bulk_issue_time').required = true;
            document.getElementById('bulk_remaining_time').required = false;
        }
    });
    
    document.getElementById('resetForm').addEventListener('submit', function(e) {
        const reason = document.getElementById('reason').value.trim();
        if (reason.length < 5) {
            e.preventDefault();
            alert('Please provide a more detailed reason for this reset');
            document.getElementById('reason').focus();
            return false;
        }
        return confirm('Are you sure you want to reset this assessment? The timer will start when the student accesses it.');
    });
    
    document.getElementById('bulkResetForm').addEventListener('submit', function(e) {
        const reason = document.getElementById('bulk_reason').value.trim();
        if (reason.length < 10) {
            e.preventDefault();
            alert('Please provide a detailed reason for this bulk reset');
            document.getElementById('bulk_reason').focus();
            return false;
        }
        return confirm(`Are you sure you want to reset ${selectedAttempts.length} assessment attempts? Timers will start when students access their assessments.`);
    });
    
    toggleTimeTrackingSection();
    updateBulkControls();
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>