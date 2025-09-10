<?php
// student/assessments.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id 
         FROM Students s 
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id 
         FROM Semesters 
         WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    if (!$currentSemester) {
        throw new Exception('No active semester found');
    }

    // Check for any in-progress assessment (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT aa.assessment_id, aa.attempt_id, a.title, s.subject_name, aa.start_time
         FROM AssessmentAttempts aa
         JOIN Assessments a ON aa.assessment_id = a.assessment_id
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                   AND sc.class_id = ac.class_id 
                                   AND sc.subject_id = ac.subject_id 
                                   AND sc.status = 'active'
         WHERE aa.student_id = ? 
         AND aa.status = 'in_progress'
         AND (ac.class_id = ? OR sc.sp_id IS NOT NULL)
         ORDER BY aa.start_time DESC
         LIMIT 1"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['student_id'], $studentInfo['class_id']]);
    $inProgressAssessment = $stmt->fetch();

    // Get assessments data with question pool information (regular class + special enrollments)
    $stmt = $db->prepare(
        "SELECT DISTINCT
            a.assessment_id, a.title, a.description, a.date, a.start_time, a.end_time, 
            a.duration, a.allow_late_submission, a.status as assessment_status,
            a.use_question_limit, a.questions_to_answer,
            s.subject_id, s.subject_name,
            r.status as result_status,
            r.score,
            t.first_name as teacher_first_name,
            t.last_name as teacher_last_name,
            COALESCE((SELECT COUNT(*) FROM Questions WHERE assessment_id = a.assessment_id), 0) as total_questions,
            -- Check if this assessment is in progress
            CASE WHEN aa.assessment_id IS NOT NULL THEN 1 ELSE 0 END as is_in_progress,
            -- Indicate if this is from special enrollment
            CASE WHEN sc.sp_id IS NOT NULL THEN 'special' ELSE 'regular' END as enrollment_type,
            sc.notes as special_notes
         FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         JOIN Classes c ON ac.class_id = c.class_id
         LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                   AND sc.class_id = ac.class_id 
                                   AND sc.subject_id = ac.subject_id 
                                   AND sc.status = 'active'
         LEFT JOIN Results r ON (a.assessment_id = r.assessment_id AND r.student_id = ?)
         LEFT JOIN TeacherClassAssignments tca ON (ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id AND tca.semester_id = a.semester_id)
         LEFT JOIN Teachers t ON tca.teacher_id = t.teacher_id
         LEFT JOIN AssessmentAttempts aa ON (a.assessment_id = aa.assessment_id AND aa.student_id = ? AND aa.status = 'in_progress')
         WHERE (c.class_id = ? OR sc.sp_id IS NOT NULL)
         AND a.semester_id = ?
         ORDER BY a.date ASC, a.start_time ASC"
    );

    // Execute with positional parameters in correct order
    $stmt->execute([
        $studentInfo['student_id'],     // For Special_Class join
        $studentInfo['student_id'],     // For Results join
        $studentInfo['student_id'],     // For AssessmentAttempts join
        $studentInfo['class_id'],       // For WHERE class_id
        $currentSemester['semester_id'] // For WHERE semester_id
    ]);
    $assessments = $stmt->fetchAll();

    // Filter assessments into categories
    $todayAssessments = [];
    $upcomingAssessments = [];
    $pastAssessments = [];
    $today = date('Y-m-d');

    foreach ($assessments as $assessment) {
        // Add question pool display information
        if ($assessment['use_question_limit'] && $assessment['questions_to_answer']) {
            $assessment['question_display'] = $assessment['questions_to_answer'] . ' of ' . $assessment['total_questions'] . ' questions';
            $assessment['is_pool'] = true;
        } else {
            $assessment['question_display'] = $assessment['total_questions'] . ' questions';
            $assessment['is_pool'] = false;
        }

        // If assessment is completed, it goes to past assessments
        if ($assessment['result_status'] === 'completed') {
            $pastAssessments[] = $assessment;
        }
        // If assessment is today, it goes to today's assessments
        elseif ($assessment['date'] === $today) {
            $todayAssessments[] = $assessment;
        }
        // If assessment is in future, it goes to upcoming
        elseif ($assessment['date'] > $today) {
            $upcomingAssessments[] = $assessment;
        }
        // Otherwise it goes to past assessments (expired assessments)
        else {
            $pastAssessments[] = $assessment;
        }
    }

    // Sort assessments
    usort($todayAssessments, function ($a, $b) {
        return strtotime($a['start_time'] ?? '00:00:00') - strtotime($b['start_time'] ?? '00:00:00');
    });

    usort($upcomingAssessments, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    usort($pastAssessments, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
} catch (Exception $e) {
    logError("Student assessments error: " . $e->getMessage());
    $error = "Error loading assessments data: " . $e->getMessage();
}

$pageTitle = 'My Assessments';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning">My Assessments</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($inProgressAssessment): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-4" id="inProgressAlert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">Assessment in Progress!</h5>
                    <p class="mb-2">
                        You have an ongoing assessment: <strong>"<?php echo htmlspecialchars($inProgressAssessment['title']); ?>"</strong> 
                        in <?php echo htmlspecialchars($inProgressAssessment['subject_name']); ?>
                    </p>
                    <p class="mb-0 small text-muted">
                        Started: <?php echo date('M d, Y g:i A', strtotime($inProgressAssessment['start_time'])); ?>
                    </p>
                </div>
                <div class="ms-3">
                    <a href="take_assessment.php?id=<?php echo $inProgressAssessment['assessment_id']; ?>" 
                       class="btn btn-warning btn-lg">
                        <i class="fas fa-play me-2"></i>Continue Assessment
                    </a>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Today's Assessments -->
    <?php if (!empty($todayAssessments)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header py-3" style="background: linear-gradient(145deg, #000 0%, #617186 100%);">
                <h5 class="card-title mb-0 text-warning">
                    <i class="fas fa-clock me-2"></i>Today's Assessments
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">Subject</th>
                                <th class="py-3">Title</th>
                                <th class="py-3">Time</th>
                                <th class="py-3">Questions</th>
                                <th class="py-3">Status</th>
                                <th class="py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayAssessments as $assessment):
                                $now = date('H:i:s');
                                $startTime = $assessment['start_time'] ?? '00:00:00';
                                $endTime = $assessment['end_time'] ?? '23:59:59';
                                $allowLateSubmission = $assessment['allow_late_submission'] ?? false;

                                // Fixed availability logic: 
                                // Available if within time window OR past end time with late submission allowed
                                $isWithinTimeWindow = ($now >= $startTime && $now <= $endTime);
                                $isLateSubmissionAllowed = ($now > $endTime && $allowLateSubmission);
                                $isAvailable = $isWithinTimeWindow || $isLateSubmissionAllowed;
                                
                                $isThisInProgress = ($inProgressAssessment && $assessment['assessment_id'] == $inProgressAssessment['assessment_id']);
                                $canTakeAssessment = !$inProgressAssessment || $isThisInProgress;
                            ?>
                                <tr class="<?php echo $isThisInProgress ? 'table-warning assessment-in-progress' : ''; ?>">
                                    <td class="py-3">
                                        <?php echo htmlspecialchars($assessment['subject_name']); ?>
                                        <?php if ($assessment['enrollment_type'] === 'special'): ?>
                                            <span class="badge bg-warning text-dark ms-1" title="Special Enrollment">
                                                <i class="fas fa-star"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($isThisInProgress): ?>
                                            <span class="badge bg-warning text-dark ms-2">
                                                <i class="fas fa-clock"></i> In Progress
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3"><?php echo htmlspecialchars($assessment['title']); ?></td>
                                    <td class="py-3">
                                        <?php if ($startTime != '00:00:00' || $endTime != '23:59:59'): ?>
                                            <?php echo date('g:i A', strtotime($startTime)); ?> -
                                            <?php echo date('g:i A', strtotime($endTime)); ?>
                                        <?php else: ?>
                                            <span class="text-muted">All Day</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <div class="question-info">
                                            <?php if ($assessment['is_pool']): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-layer-group me-1"></i><?php echo $assessment['question_display']; ?>
                                                </span>
                                                <div class="small text-muted">Question Pool</div>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?php echo $assessment['question_display']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($assessment['result_status']): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($isThisInProgress): ?>
                                            <span class="badge bg-warning text-dark">In Progress</span>
                                        <?php elseif ($now < $startTime): ?>
                                            <span class="badge" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                                                Opens at <?php echo date('g:i A', strtotime($startTime)); ?>
                                            </span>
                                        <?php elseif ($isWithinTimeWindow): ?>
                                            <span class="badge bg-info">Available Now</span>
                                        <?php elseif ($isLateSubmissionAllowed): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Late Submission
                                            </span>
                                        <?php elseif ($now > $endTime): ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($assessment['result_status']): ?>
                                            <a href="view_result.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>"
                                                class="btn btn-sm btn-warning">
                                                <i class="fas fa-eye me-1"></i>View Result
                                            </a>
                                        <?php elseif ($isThisInProgress): ?>
                                            <a href="take_assessment.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>"
                                                class="btn btn-sm btn-warning">
                                                <i class="fas fa-play me-1"></i>Continue Assessment
                                            </a>
                                        <?php elseif ($isAvailable && $canTakeAssessment): ?>
                                            <a href="take_assessment.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>"
                                                class="btn btn-sm btn-warning">
                                                <i class="fas fa-pencil-alt me-1"></i>
                                                <?php echo $isLateSubmissionAllowed ? 'Take (Late)' : 'Take Assessment'; ?>
                                            </a>
                                        <?php elseif (!$canTakeAssessment): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled 
                                                    title="Complete your current assessment first"
                                                    data-bs-toggle="tooltip">
                                                <i class="fas fa-lock me-1"></i>Assessment Locked
                                            </button>
                                        <?php elseif ($now < $startTime): ?>
                                            <button class="btn btn-sm btn-secondary" disabled
                                                    title="Opens at <?php echo date('g:i A', strtotime($startTime)); ?>"
                                                    data-bs-toggle="tooltip">
                                                <i class="fas fa-clock me-1"></i>Opens at <?php echo date('g:i A', strtotime($startTime)); ?>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary" disabled>
                                                <i class="fas fa-times-circle me-1"></i>Not Available
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Upcoming Assessments -->
    <div class="card shadow-sm mb-4">
        <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-calendar-alt me-2"></i>Upcoming Assessments
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($upcomingAssessments)): ?>
                <div class="p-4 text-center">
                    <i class="fas fa-calendar-check text-warning fa-2x mb-2"></i>
                    <p class="text-muted mb-0">No upcoming assessments scheduled.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">Subject</th>
                                <th class="py-3">Title</th>
                                <th class="py-3">Date</th>
                                <th class="py-3">Time</th>
                                <th class="py-3">Questions</th>
                                <th class="py-3">Status</th>
                                <th class="py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingAssessments as $assessment): 
                                $canTakeAssessment = !$inProgressAssessment;
                            ?>
                                <tr class="<?php echo !$canTakeAssessment ? 'assessment-locked' : ''; ?>">
                                    <td class="py-3">
                                        <?php echo htmlspecialchars($assessment['subject_name']); ?>
                                        <?php if ($assessment['enrollment_type'] === 'special'): ?>
                                            <span class="badge bg-warning text-dark ms-1" title="Special Enrollment">
                                                <i class="fas fa-star"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3"><?php echo htmlspecialchars($assessment['title']); ?></td>
                                    <td class="py-3"><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                    <td class="py-3">
                                        <?php if ($assessment['start_time'] != '00:00:00' || $assessment['end_time'] != '23:59:59'): ?>
                                            <?php echo date('g:i A', strtotime($assessment['start_time'])); ?> -
                                            <?php echo date('g:i A', strtotime($assessment['end_time'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">All Day</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <div class="question-info">
                                            <?php if ($assessment['is_pool']): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-layer-group me-1"></i><?php echo $assessment['question_display']; ?>
                                                </span>
                                                <div class="small text-muted">Question Pool</div>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?php echo $assessment['question_display']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <?php if (!$canTakeAssessment): ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-lock"></i> Locked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">Upcoming</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <?php if (!$canTakeAssessment): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled 
                                                    title="Complete your current assessment first"
                                                    data-bs-toggle="tooltip">
                                                <i class="fas fa-lock me-1"></i>Assessment Locked
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled>
                                                <i class="fas fa-clock me-1"></i>Not Available Yet
                                            </button>
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

    <!-- Past Assessments -->
    <div class="card shadow-sm">
        <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #d35400 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-history me-2"></i>Past Assessments
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($pastAssessments)): ?>
                <div class="p-4 text-center">
                    <i class="fas fa-history text-warning fa-2x mb-2"></i>
                    <p class="text-muted mb-0">No past assessments found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">Subject</th>
                                <th class="py-3">Title</th>
                                <th class="py-3">Teacher</th>
                                <th class="py-3">Date</th>
                                <th class="py-3">Questions</th>
                                <th class="py-3">Status</th>
                                <th class="py-3">Score</th>
                                <th class="py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastAssessments as $assessment): 
                                // Check if this past assessment still allows late submission
                                $now = date('H:i:s');
                                $currentDate = date('Y-m-d');
                                $allowLateSubmission = $assessment['allow_late_submission'] ?? false;
                                $canTakeAssessment = !$inProgressAssessment;
                                
                                // For past assessments, only allow if late submission is enabled and not completed
                                $canTakeLate = ($allowLateSubmission && 
                                              !$assessment['result_status'] && 
                                              $canTakeAssessment);
                            ?>
                                <tr>
                                    <td class="py-3">
                                        <?php echo htmlspecialchars($assessment['subject_name']); ?>
                                        <?php if ($assessment['enrollment_type'] === 'special'): ?>
                                            <span class="badge bg-warning text-dark ms-1" title="Special Enrollment">
                                                <i class="fas fa-star"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3"><?php echo htmlspecialchars($assessment['title']); ?></td>
                                    <td class="py-3">
                                        <?php if ($assessment['teacher_first_name'] && $assessment['teacher_last_name']): ?>
                                            <?php echo htmlspecialchars($assessment['teacher_first_name'] . ' ' . $assessment['teacher_last_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3"><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                    <td class="py-3">
                                        <div class="question-info">
                                            <?php if ($assessment['is_pool']): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-layer-group me-1"></i><?php echo $assessment['question_display']; ?>
                                                </span>
                                                <div class="small text-muted">Question Pool</div>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?php echo $assessment['question_display']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($assessment['result_status'] === 'completed'): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($canTakeLate): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Late Submission Available
                                            </span>
                                        <?php elseif (!$canTakeAssessment && $allowLateSubmission): ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-lock me-1"></i>Locked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Expired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($assessment['result_status'] === 'completed'): ?>
                                            <?php 
                                            // Calculate percentage based on total possible score
                                            if ($assessment['use_question_limit'] && $assessment['questions_to_answer']) {
                                                // For pool assessments, we need to calculate based on actual questions answered
                                                $stmt = $db->prepare("SELECT SUM(max_score) FROM questions q JOIN studentanswers sa ON q.question_id = sa.question_id WHERE sa.assessment_id = ? AND sa.student_id = ?");
                                                $stmt->execute([$assessment['assessment_id'], $studentInfo['student_id']]);
                                                $totalPossible = $stmt->fetchColumn() ?: 1;
                                            } else {
                                                // Normal calculation
                                                $stmt = $db->prepare("SELECT SUM(max_score) FROM questions WHERE assessment_id = ?");
                                                $stmt->execute([$assessment['assessment_id']]);
                                                $totalPossible = $stmt->fetchColumn() ?: 1;
                                            }
                                            $percentage = ($assessment['score'] / $totalPossible) * 100;
                                            ?>
                                            <?php echo number_format($percentage, 1); ?>%
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($assessment['result_status'] === 'completed'): ?>
                                            <a href="view_result.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>"
                                                class="btn btn-sm btn-warning">
                                                <i class="fas fa-eye me-1"></i>View Result
                                            </a>
                                        <?php elseif ($canTakeLate): ?>
                                            <a href="take_assessment.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>"
                                                class="btn btn-sm btn-warning"
                                                title="Late submission allowed">
                                                <i class="fas fa-pencil-alt me-1"></i>Take (Late)
                                            </a>
                                        <?php elseif (!$canTakeAssessment && $allowLateSubmission): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled 
                                                    title="Complete your current assessment first"
                                                    data-bs-toggle="tooltip">
                                                <i class="fas fa-lock me-1"></i>Assessment Locked
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary" disabled>
                                                <i class="fas fa-times-circle me-1"></i>Not Attempted
                                            </button>
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
</main>

<style>
    .card {
        border: none;
        transition: transform 0.2s;
        margin-bottom: 1.5rem;
    }

    .card:hover {
        transform: translateY(-2px);
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

    /* In-progress assessment styling */
    .assessment-in-progress {
        background-color: rgba(255, 193, 7, 0.1) !important;
        border-left: 4px solid #ffc107 !important;
    }

    @keyframes pulse-highlight {
        0% { 
            background-color: rgba(255, 193, 7, 0.1); 
        }
        50% { 
            background-color: rgba(255, 193, 7, 0.25); 
        }
        100% { 
            background-color: rgba(255, 193, 7, 0.1); 
        }
    }

    .assessment-in-progress {
        animation: pulse-highlight 2s infinite;
    }

    .assessment-in-progress:hover {
        background-color: rgba(255, 193, 7, 0.2) !important;
        transform: translateX(2px);
        transition: all 0.3s ease;
    }

    /* Locked assessment styling */
    .assessment-locked {
        background-color: rgba(108, 117, 125, 0.1) !important;
        opacity: 0.7;
    }

    .assessment-locked td {
        color: #6c757d !important;
    }

    /* Question info styling */
    .question-info .badge {
        margin-bottom: 2px;
    }

    .question-info .small {
        font-size: 0.75rem;
        margin-top: 2px;
    }

    /* Alert styling */
    .alert-warning {
        background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        border-left: 4px solid #ffc107;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .alert-warning .alert-heading {
        color: #856404;
        font-weight: 600;
    }

    .alert-warning .btn-warning {
        background: #ffc107;
        border-color: #ffc107;
        color: #000;
        font-weight: 600;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .alert-warning .btn-warning:hover {
        background: #e0a800;
        border-color: #d39e00;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    /* Responsive design */
    @media (max-width: 768px) {
        .alert-warning .d-flex {
            flex-direction: column;
            gap: 1rem;
            text-align: center;
        }

        .alert-warning .ms-3 {
            margin-left: 0 !important;
        }

        .assessment-in-progress:hover {
            transform: none;
        }

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

        .card-header h5 {
            font-size: 1.1rem;
        }

        .question-info {
            text-align: center;
        }
    }

    /* Button disabled states */
    .btn:disabled {
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn:disabled[title] {
        cursor: help;
    }

    /* Enhanced button hover effects */
    .btn-sm:not(:disabled):hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        new bootstrap.Tooltip(tooltip);
    });

    // Auto-dismiss in-progress alert after 20 seconds
    const inProgressAlert = document.getElementById('inProgressAlert');
    if (inProgressAlert) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(inProgressAlert);
            bsAlert.close();
        }, 20000);
    }

    // Add click handlers for locked assessments
    const lockedButtons = document.querySelectorAll('.btn:disabled[title*="Complete your current assessment"]');
    lockedButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            showAssessmentLockedModal();
        });
    });

    // Make in-progress rows clickable
    const inProgressRows = document.querySelectorAll('.assessment-in-progress');
    inProgressRows.forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on buttons or links
            if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && !e.target.closest('button, a')) {
                const continueButton = row.querySelector('a[href*="take_assessment.php"]');
                if (continueButton) {
                    window.location.href = continueButton.href;
                }
            }
        });
    });

    // Function to show assessment locked modal
    function showAssessmentLockedModal() {
        <?php if ($inProgressAssessment): ?>
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-lock me-2"></i>Assessment Locked
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                            <h5>You have an assessment in progress!</h5>
                            <p>Please complete your current assessment before starting a new one.</p>
                        </div>
                        <div class="alert alert-info">
                            <strong>Current Assessment:</strong><br>
                            <?php echo htmlspecialchars($inProgressAssessment['title']); ?> (<?php echo htmlspecialchars($inProgressAssessment['subject_name']); ?>)
                        </div>
                        <div class="text-center">
                            <small class="text-muted">
                                Started: <?php echo date('M d, Y g:i A', strtotime($inProgressAssessment['start_time'])); ?>
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <a href="take_assessment.php?id=<?php echo $inProgressAssessment['assessment_id']; ?>" class="btn btn-warning">
                            <i class="fas fa-play me-2"></i>Continue Assessment
                        </a>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        // Remove modal from DOM when hidden
        modal.addEventListener('hidden.bs.modal', function() {
            document.body.removeChild(modal);
        });
        <?php endif; ?>
        }

    // Add smooth animations for card hover effects
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 15px rgba(0,0,0,0.1)';
            this.style.transition = 'all 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>