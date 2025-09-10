<?php
// admin/student_submissions.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once BASEPATH . '/api/grade_assessment.php';

requireRole('admin');

$error = '';
$success = '';
$studentAssessments = [];
$assessmentDetails = null;
$studentDetails = null;

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $action = $_POST['action'] ?? '';
        
        if ($action === 'submit_assessment') {
            $assessmentId = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
            $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $reason = sanitizeInput($_POST['reason'] ?? '');
            
            if (!$assessmentId || !$studentId || empty($reason)) {
                throw new Exception('Missing required information');
            }

            $db->beginTransaction();
            
            try {
                // Verify the attempt exists and is in progress
                $stmt = $db->prepare(
                    "SELECT aa.attempt_id, aa.start_time, a.title, a.duration,
                            s.first_name, s.last_name, u.username
                     FROM assessmentattempts aa
                     JOIN assessments a ON aa.assessment_id = a.assessment_id
                     JOIN students st ON aa.student_id = st.student_id
                     JOIN users u ON st.user_id = u.user_id
                     LEFT JOIN students s ON aa.student_id = s.student_id
                     WHERE aa.assessment_id = ? AND aa.student_id = ? AND aa.status = 'in_progress'"
                );
                $stmt->execute([$assessmentId, $studentId]);
                $attempt = $stmt->fetch();
                
                if (!$attempt) {
                    throw new Exception('No in-progress attempt found for this student');
                }

                // Mark attempt as completed
                $stmt = $db->prepare(
                    "UPDATE assessmentattempts 
                     SET status = 'completed', end_time = CURRENT_TIMESTAMP 
                     WHERE assessment_id = ? AND student_id = ?"
                );
                $stmt->execute([$assessmentId, $studentId]);

                // Grade the assessment
                $score = gradeAssessment($assessmentId, $studentId);

                // Save result
                $stmt = $db->prepare(
                    "INSERT INTO results (assessment_id, student_id, score, status, feedback) 
                     VALUES (?, ?, ?, 'completed', ?)
                     ON DUPLICATE KEY UPDATE 
                     score = VALUES(score), 
                     status = VALUES(status), 
                     feedback = VALUES(feedback),
                     updated_at = CURRENT_TIMESTAMP"
                );
                
                $feedback = "Assessment submitted by administrator. Reason: " . $reason;
                $stmt->execute([$assessmentId, $studentId, $score, $feedback]);

                // Log the admin action
                logSystemActivity(
                    'Admin Assessment Submission',
                    "Assessment ID: $assessmentId submitted by admin for student ID: $studentId. " .
                    "Reason: $reason. Score: $score",
                    'INFO'
                );

                // Log to audit trail
                $stmt = $db->prepare(
                    "INSERT INTO audittrail (
                        table_name, record_id, action_type, 
                        old_values, new_values, user_id, ip_address, user_agent
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $oldValues = json_encode(['status' => 'in_progress']);
                $newValues = json_encode([
                    'status' => 'completed', 
                    'score' => $score,
                    'admin_submitted' => true,
                    'reason' => $reason
                ]);
                
                $stmt->execute([
                    'assessmentattempts',
                    $attempt['attempt_id'],
                    'UPDATE',
                    $oldValues,
                    $newValues,
                    $_SESSION['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);

                $db->commit();
                
                $success = "Assessment successfully submitted for {$attempt['first_name']} {$attempt['last_name']} " .
                          "({$attempt['username']}) - Score: $score";
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }
    }

    // Get filter parameters
    $searchTerm = sanitizeInput($_GET['search'] ?? '');
    $classFilter = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
    $assessmentFilter = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT);
    $levelFilter = sanitizeInput($_GET['level'] ?? '');

    // Build the query to get in-progress assessments
    $whereClause = "WHERE aa.status = 'in_progress'";
    $params = [];

    if (!empty($searchTerm)) {
        $whereClause .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR u.username LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }

    if ($classFilter) {
        $whereClause .= " AND s.class_id = ?";
        $params[] = $classFilter;
    }

    if ($assessmentFilter) {
        $whereClause .= " AND aa.assessment_id = ?";
        $params[] = $assessmentFilter;
    }

    if (!empty($levelFilter)) {
        $whereClause .= " AND c.level = ?";
        $params[] = $levelFilter;
    }

    // Get in-progress assessments
    $stmt = $db->prepare(
        "SELECT 
            aa.assessment_id,
            aa.student_id,
            aa.attempt_id,
            aa.start_time,
            a.title as assessment_title,
            a.duration,
            a.date as assessment_date,
            s.first_name,
            s.last_name,
            u.username,
            CONCAT(p.program_name, ' - ', c.level, ' ', c.class_name) as class_display,
            c.level,
            GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') as subject_name,
            (SELECT COUNT(*) FROM studentanswers sa2 
             WHERE sa2.assessment_id = aa.assessment_id 
             AND sa2.student_id = aa.student_id 
             AND sa2.answer_text IS NOT NULL 
             AND sa2.answer_text != '') as answered_questions,
            CASE 
                WHEN a.use_question_limit = 1 AND a.questions_to_answer IS NOT NULL 
                THEN a.questions_to_answer
                ELSE (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = aa.assessment_id)
            END as total_questions,
            TIMESTAMPDIFF(MINUTE, aa.start_time, NOW()) as minutes_elapsed
         FROM assessmentattempts aa
         JOIN assessments a ON aa.assessment_id = a.assessment_id
         JOIN students s ON aa.student_id = s.student_id
         JOIN users u ON s.user_id = u.user_id
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id AND ac.class_id = s.class_id
         JOIN subjects sub ON ac.subject_id = sub.subject_id
         $whereClause
         GROUP BY aa.assessment_id, aa.student_id, aa.attempt_id, aa.start_time,
                  a.title, a.duration, a.date,
                  s.first_name, s.last_name, u.username,
                  c.class_name, c.level, p.program_name
         ORDER BY aa.start_time DESC"
    );
    
    $stmt->execute($params);
    $studentAssessments = $stmt->fetchAll();

    // Get classes for filter
    $stmt = $db->prepare(
        "SELECT c.class_id, c.class_name, c.level, p.program_name 
         FROM classes c 
         JOIN programs p ON c.program_id = p.program_id 
         ORDER BY p.program_name, c.level, c.class_name"
    );
    $stmt->execute();
    $classes = $stmt->fetchAll();

    // Get distinct levels for filter
    $stmt = $db->prepare(
        "SELECT DISTINCT level 
         FROM classes 
         ORDER BY level"
    );
    $stmt->execute();
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get assessments for filter
    $stmt = $db->prepare(
        "SELECT DISTINCT a.assessment_id, a.title, a.date
         FROM assessments a
         JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id
         WHERE aa.status = 'in_progress'
         ORDER BY a.date DESC, a.title"
    );
    $stmt->execute();
    $assessments = $stmt->fetchAll();

    // If viewing details for a specific student assessment
    $viewAssessmentId = filter_input(INPUT_GET, 'view_assessment', FILTER_VALIDATE_INT);
    $viewStudentId = filter_input(INPUT_GET, 'view_student', FILTER_VALIDATE_INT);
    
    if ($viewAssessmentId && $viewStudentId) {
        // Get assessment details
        $stmt = $db->prepare(
            "SELECT a.*, aa.start_time, aa.attempt_id, s.first_name, s.last_name, u.username,
                    c.class_name, c.level, p.program_name, sub.subject_name
             FROM assessments a
             JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id
             JOIN students s ON aa.student_id = s.student_id
             JOIN users u ON s.user_id = u.user_id
             JOIN classes c ON s.class_id = c.class_id
             JOIN programs p ON c.program_id = p.program_id
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             JOIN subjects sub ON ac.subject_id = sub.subject_id
             WHERE a.assessment_id = ? AND s.student_id = ? AND aa.status = 'in_progress'"
        );
        $stmt->execute([$viewAssessmentId, $viewStudentId]);
        $assessmentDetails = $stmt->fetch();

        if ($assessmentDetails) {
            // Get student's answers
            $stmt = $db->prepare(
                "SELECT q.*, sa.answer_text as student_answer, sa.score as question_score,
                        CASE 
                            WHEN q.question_type = 'MCQ' THEN (
                                SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                                FROM mcqquestions mcq 
                                WHERE mcq.question_id = q.question_id
                            )
                            ELSE NULL
                        END as mcq_data
                 FROM questions q
                 LEFT JOIN studentanswers sa ON q.question_id = sa.question_id 
                    AND sa.student_id = ? AND sa.assessment_id = ?
                 WHERE q.assessment_id = ?
                 ORDER BY q.question_id"
            );
            $stmt->execute([$viewStudentId, $viewAssessmentId, $viewAssessmentId]);
            $assessmentDetails['questions'] = $stmt->fetchAll();

            // Process MCQ options
            foreach ($assessmentDetails['questions'] as &$question) {
                if ($question['question_type'] === 'MCQ' && $question['mcq_data']) {
                    $options = [];
                    $mcqData = explode('|', $question['mcq_data']);
                    foreach ($mcqData as $data) {
                        if (!empty($data)) {
                            list($id, $text, $isCorrect) = explode(':', $data);
                            $options[] = [
                                'id' => $id,
                                'text' => $text,
                                'is_correct' => $isCorrect,
                                'is_selected' => ($question['student_answer'] == $id)
                            ];
                        }
                    }
                    $question['options'] = $options;
                }
            }
            unset($question);
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Admin student submissions error: " . $e->getMessage());
}

$pageTitle = 'Student Assessment Submissions';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-2 text-warning">Student Assessment Submissions</h1>
            <p class="text-muted mb-0">Submit assessments on behalf of students in emergency situations</p>
        </div>
        <?php if ($assessmentDetails): ?>
            <a href="student_submissions.php" class="btn btn-outline-warning">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
        <?php endif; ?>
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

    <?php if ($assessmentDetails): ?>
        <!-- Assessment Details View -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-warning">
                <h5 class="mb-0">
                    <i class="fas fa-user me-2"></i>
                    Assessment Details: <?php echo htmlspecialchars($assessmentDetails['first_name'] . ' ' . $assessmentDetails['last_name']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Student:</strong></td>
                                <td><?php echo htmlspecialchars($assessmentDetails['first_name'] . ' ' . $assessmentDetails['last_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Username:</strong></td>
                                <td><?php echo htmlspecialchars($assessmentDetails['username']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Class:</strong></td>
                                <td><?php echo htmlspecialchars($assessmentDetails['program_name'] . ' - ' . $assessmentDetails['level'] . ' ' . $assessmentDetails['class_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Subject:</strong></td>
                                <td><?php echo htmlspecialchars($assessmentDetails['subject_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Assessment:</strong></td>
                                <td><?php echo htmlspecialchars($assessmentDetails['title']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Started:</strong></td>
                                <td><?php echo date('M d, Y H:i', strtotime($assessmentDetails['start_time'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Duration:</strong></td>
                                <td>
                                    <?php if ($assessmentDetails['duration']): ?>
                                        <?php echo $assessmentDetails['duration']; ?> minutes
                                        <?php
                                        $startTime = strtotime($assessmentDetails['start_time']);
                                        $endTime = $startTime + ($assessmentDetails['duration'] * 60);
                                        $timeLeft = max(0, $endTime - time());
                                        if ($timeLeft > 0): ?>
                                            <span class="badge bg-warning text-dark ms-2">
                                                <?php echo gmdate('H:i:s', $timeLeft); ?> remaining
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger ms-2">Time Expired</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        No time limit
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Progress:</strong></td>
                                <td>
                                    <?php 
                                    $answeredCount = 0;
                                    foreach ($assessmentDetails['questions'] as $q) {
                                        if (!empty($q['student_answer'])) $answeredCount++;
                                    }
                                    ?>
                                    <?php echo $answeredCount; ?>/<?php echo count($assessmentDetails['questions']); ?> questions answered
                                    <div class="progress mt-2" style="height: 10px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo (count($assessmentDetails['questions']) > 0) ? ($answeredCount / count($assessmentDetails['questions'])) * 100 : 0; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Questions Review -->
                <h6 class="text-warning mb-3">Student Answers Review:</h6>
                <div class="questions-review">
                    <?php foreach ($assessmentDetails['questions'] as $index => $question): ?>
                        <div class="question-review-card mb-3">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span><strong>Question <?php echo $index + 1; ?></strong></span>
                                    <div>
                                        <span class="badge bg-secondary"><?php echo $question['question_type']; ?></span>
                                        <span class="badge bg-info"><?php echo $question['max_score']; ?> points</span>
                                        <?php if (!empty($question['student_answer'])): ?>
                                            <span class="badge bg-success">Answered</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Not Answered</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="question-text mb-3">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>
                                    
                                    <?php if ($question['question_type'] === 'MCQ'): ?>
                                        <div class="mcq-options">
                                            <?php foreach ($question['options'] as $option): ?>
                                                <div class="option-item <?php echo $option['is_selected'] ? 'selected' : ''; ?> <?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                                                    <i class="fas <?php echo $option['is_selected'] ? ($option['is_correct'] ? 'fa-check-circle text-success' : 'fa-times-circle text-danger') : 'fa-circle text-muted'; ?>"></i>
                                                    <?php echo htmlspecialchars($option['text']); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="short-answer">
                                            <strong>Student Answer:</strong>
                                            <div class="answer-box">
                                                <?php echo !empty($question['student_answer']) ? nl2br(htmlspecialchars($question['student_answer'])) : '<em class="text-muted">No answer provided</em>'; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Submit Assessment Form -->
                <div class="submit-section mt-4 p-4 bg-light rounded">
                    <h6 class="text-danger mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>Submit Assessment on Behalf of Student
                    </h6>
                    <form method="POST" id="submitAssessmentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="submit_assessment">
                        <input type="hidden" name="assessment_id" value="<?php echo $assessmentDetails['assessment_id']; ?>">
                        <input type="hidden" name="student_id" value="<?php echo $viewStudentId; ?>">
                        
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Reason for Admin Submission</label>
                                <select name="predefined_reason" id="predefinedReason" class="form-select mb-2" onchange="handleReasonSelection()">
                                    <option value="">Select a predefined reason...</option>
                                    <option value="Student left room before time">Student left room before time</option>
                                    <option value="Power failure during assessment">Power failure during assessment</option>
                                    <option value="Student refused to submit the test">Student refused to submit the test</option>
                                    <option value="Computer crashed during assessment">Computer crashed during assessment</option>
                                    <option value="Internet connection lost during assessment">Internet connection lost during assessment</option>
                                    <option value="Browser froze or became unresponsive">Browser froze or became unresponsive</option>
                                    <option value="Assessment time limit exceeded">Assessment time limit exceeded</option>
                                    <option value="Student fell ill during assessment">Student fell ill during assessment</option>
                                    <option value="Student experienced a personal emergency">Student experienced a personal emergency</option>
                                    <option value="Technical difficulties with assessment platform">Technical difficulties with assessment platform</option>
                                    <option value="Network connectivity issues">Network connectivity issues</option>
                                    <option value="Hardware malfunction (keyboard, mouse, etc.)">Hardware malfunction (keyboard, mouse, etc.)</option>
                                    <option value="Student unable to complete within allotted time">Student unable to complete within allotted time</option>
                                    <option value="Medical emergency requiring immediate attention">Medical emergency requiring immediate attention</option>
                                    <option value="System error prevented form submission">System error prevented form submission</option>
                                    <option value="External disruption during assessment">External disruption during assessment</option>
                                    <option value="Student confusion about assessment requirements">Student confusion about assessment requirements</option>
                                    <option value="Assessment instructions were unclear">Assessment instructions were unclear</option>
                                    <option value="Administrative error in assessment setup">Administrative error in assessment setup</option>
                                    <option value="Fire alarm or evacuation during assessment">Fire alarm or evacuation during assessment</option>
                                    <option value="Student had panic attack or anxiety episode">Student had panic attack or anxiety episode</option>
                                    <option value="Special circumstances requiring review">Special circumstances requiring review</option>
                                </select>
                                <textarea name="reason" id="reasonTextarea" class="form-control" rows="3" required 
                                         placeholder="Explain why you are submitting this assessment on behalf of the student..."></textarea>
                                <small class="form-text text-muted">Select from predefined reasons above or type your own explanation below.</small>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action will permanently submit the assessment with the current answers. 
                            The student will not be able to make further changes.
                        </div>
                        
                        <button type="button" class="btn btn-danger" onclick="showSubmitConfirmation()">
                            <i class="fas fa-paper-plane me-2"></i>Submit Assessment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Filters and Search -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Search Student</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Name or username..." 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-select" id="levelSelect">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo htmlspecialchars($level); ?>" 
                                        <?php echo $levelFilter === $level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="filter-info">Filters classes automatically</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-select" id="classSelect">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                        data-level="<?php echo htmlspecialchars($class['level']); ?>"
                                        <?php echo $classFilter == $class['class_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['level'] . ' ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="filter-info" id="classFilterInfo"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Assessment</label>
                        <select name="assessment_id" class="form-select">
                            <option value="">All Assessments</option>
                            <?php foreach ($assessments as $assessment): ?>
                                <option value="<?php echo $assessment['assessment_id']; ?>" 
                                        <?php echo $assessmentFilter == $assessment['assessment_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($assessment['title'] . ' (' . date('M d', strtotime($assessment['date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning me-2">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                        <a href="student_submissions.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- In-Progress Assessments List -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-warning">
                <h5 class="mb-0">
                    <i class="fas fa-clock me-2"></i>
                    In-Progress Assessments (<?php echo count($studentAssessments); ?>)
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($studentAssessments)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No In-Progress Assessments Found</h5>
                        <p class="text-muted">All students have completed their assessments or no assessments match your filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Assessment</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Started</th>
                                    <th>Progress</th>
                                    <th>Time Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentAssessments as $sa): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($sa['first_name'] . ' ' . $sa['last_name']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($sa['username']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo htmlspecialchars($sa['assessment_title']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo date('M d, Y', strtotime($sa['assessment_date'])); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($sa['class_display']); ?></td>
                                        <td><?php echo htmlspecialchars($sa['subject_name']); ?></td>
                                        <td>
                                            <?php echo date('M d, H:i', strtotime($sa['start_time'])); ?>
                                            <br>
                                            <small class="text-muted"><?php echo $sa['minutes_elapsed']; ?> min ago</small>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <?php 
                                                $progress = $sa['total_questions'] > 0 ? ($sa['answered_questions'] / $sa['total_questions']) * 100 : 0;
                                                $progressClass = $progress < 50 ? 'bg-danger' : ($progress < 80 ? 'bg-warning' : 'bg-success');
                                                ?>
                                                <div class="progress-bar <?php echo $progressClass; ?>" style="width: <?php echo $progress; ?>%">
                                                    <?php echo $sa['answered_questions']; ?>/<?php echo $sa['total_questions']; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($sa['duration']): ?>
                                                <?php
                                                $startTime = strtotime($sa['start_time']);
                                                $endTime = $startTime + ($sa['duration'] * 60);
                                                $timeLeft = max(0, $endTime - time());
                                                ?>
                                                <?php if ($timeLeft > 0): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <?php echo gmdate('H:i:s', $timeLeft); ?> left
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Expired</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-info">No limit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?view_assessment=<?php echo $sa['assessment_id']; ?>&view_student=<?php echo $sa['student_id']; ?>" 
                                               class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-eye me-1"></i>Review & Submit
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
    <?php endif; ?>
</div>

<!-- Submit Confirmation Modal -->
<div class="modal fade" id="submitConfirmationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Assessment Submission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
                <p>You are about to submit this assessment on behalf of the student. This will:</p>
                <ul>
                    <li>Permanently end the student's assessment session</li>
                    <li>Grade all answered questions automatically</li>
                    <li>Record this as an admin-submitted assessment</li>
                    <li>Log this action in the audit trail</li>
                </ul>
                <p><strong>Are you sure you want to proceed?</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmSubmit()">
                    <i class="fas fa-paper-plane me-2"></i>Yes, Submit Assessment
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Level and Class filtering visual feedback */
.form-select.filtered {
    border-left: 3px solid #ffd700;
    background-color: #fffef7;
}

.filter-info {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.filter-info.active {
    color: #e5c100;
    font-weight: 500;
}
.text-warning {
    color: #ffd700 !important;
}

.btn-warning {
    background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
    border: none;
    color: white;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ffd700 0%, #000000 100%);
    color: white;
}

.question-review-card {
    border-left: 4px solid #ffd700;
}

.option-item {
    padding: 8px 12px;
    margin: 4px 0;
    border-radius: 4px;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}

.option-item.selected {
    background-color: #e3f2fd;
    border-color: #2196f3;
}

.option-item.correct {
    background-color: #e8f5e8;
    border-color: #4caf50;
}

.option-item.selected.correct {
    background-color: #c8e6c9;
    border-color: #4caf50;
}

.answer-box {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 12px;
    margin-top: 8px;
    min-height: 50px;
}

.submit-section {
    border: 2px dashed #dc3545;
    background-color: #fff5f5 !important;
}

#predefinedReason {
    transition: all 0.3s ease;
}

#predefinedReason:focus {
    border-color: #ffd700;
    box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
}

.form-text.text-muted {
    font-style: italic;
}

.card-header.bg-dark {
    background: linear-gradient(90deg, #000000 0%, #333333 100%) !important;
}

.table-hover tbody tr:hover {
    background-color: rgba(255, 215, 0, 0.1);
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .row.g-3 > div {
        margin-bottom: 1rem;
    }
    
    .col-md-2, .col-md-3 {
        flex: 0 0 100%;
        max-width: 100%;
    }
}
</style>

<script>

function handleReasonSelection() {
    const predefinedReason = document.getElementById('predefinedReason').value;
    const reasonTextarea = document.getElementById('reasonTextarea');
    
    if (predefinedReason) {
        reasonTextarea.value = predefinedReason;
    }
}

function showSubmitConfirmation() {
    const form = document.getElementById('submitAssessmentForm');
    
    // Validate form first
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('submitConfirmationModal'));
    modal.show();
}

function confirmSubmit() {
    const form = document.getElementById('submitAssessmentForm');
    const modal = bootstrap.Modal.getInstance(document.getElementById('submitConfirmationModal'));
    
    // Close modal and submit form
    modal.hide();
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    // Level and Class filtering
    const levelSelect = document.getElementById('levelSelect');
    const classSelect = document.getElementById('classSelect');
    const classFilterInfo = document.getElementById('classFilterInfo');
    
    // Store all class options for filtering
    const allClassOptions = Array.from(classSelect.options).slice(1); // Skip "All Classes" option
    
    function filterClasses() {
        const selectedLevel = levelSelect.value;
        const currentClassValue = classSelect.value; // Store current selection
        
        // Clear existing options except "All Classes"
        classSelect.innerHTML = '<option value="">All Classes</option>';
        
        if (selectedLevel === '') {
            // Show all classes if no level selected
            allClassOptions.forEach(option => {
                classSelect.appendChild(option.cloneNode(true));
            });
            classSelect.classList.remove('filtered');
            classFilterInfo.textContent = '';
            classFilterInfo.classList.remove('active');
        } else {
            // Show only classes matching the selected level
            const filteredOptions = allClassOptions.filter(option => 
                option.dataset.level === selectedLevel
            );
            
            filteredOptions.forEach(option => {
                classSelect.appendChild(option.cloneNode(true));
            });
            
            // Update the "All Classes" text to show filtering is active
            if (filteredOptions.length > 0) {
                classSelect.options[0].text = `All ${selectedLevel} Classes`;
                classSelect.classList.add('filtered');
                classFilterInfo.textContent = `Showing ${filteredOptions.length} classes for ${selectedLevel}`;
                classFilterInfo.classList.add('active');
            } else {
                classFilterInfo.textContent = `No classes found for ${selectedLevel}`;
                classFilterInfo.classList.add('active');
            }
        }
        
        // Restore selection if it's still valid after filtering
        if (currentClassValue) {
            const optionExists = Array.from(classSelect.options).some(opt => opt.value === currentClassValue);
            if (optionExists) {
                classSelect.value = currentClassValue;
            } else {
                classSelect.value = '';
            }
        }
    }
    
    // Add event listener for level change
    levelSelect.addEventListener('change', filterClasses);
    
    // Initialize filtering on page load
    filterClasses();
    
    // Restore the selected class value after filtering (for page loads with existing filters)
    const initialClassValue = '<?php echo $classFilter; ?>';
    if (initialClassValue) {
        classSelect.value = initialClassValue;
    }
    
    
    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Real-time timer updates for time remaining
    const timeElements = document.querySelectorAll('[data-time-left]');
    if (timeElements.length > 0) {
        setInterval(() => {
            timeElements.forEach(element => {
                let timeLeft = parseInt(element.dataset.timeLeft);
                if (timeLeft > 0) {
                    timeLeft--;
                    element.dataset.timeLeft = timeLeft;
                    element.textContent = new Date(timeLeft * 1000).toISOString().substr(11, 8) + ' left';
                } else {
                    element.textContent = 'Expired';
                    element.className = 'badge bg-danger';
                }
            });
        }, 1000);
    }
    
    // Add confirmation for dangerous actions
    document.querySelectorAll('[data-confirm]').forEach(element => {
        element.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
                return false;
            }
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>