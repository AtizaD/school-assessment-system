<?php
// student/subject_detail.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$subjectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$subjectId) {
    redirectTo('subjects.php');
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student info first
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id, c.class_name, p.program_name 
         FROM Students s 
         JOIN Classes c ON s.class_id = c.class_id
         JOIN Programs p ON c.program_id = p.program_id
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Get current semester (assuming we need the most recent or current semester)
    $stmt = $db->prepare(
        "SELECT semester_id FROM Semesters 
         WHERE CURRENT_DATE BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    if (!$currentSemester) {
        // If no current semester, get the most recent one
        $stmt = $db->prepare("SELECT semester_id FROM Semesters ORDER BY end_date DESC LIMIT 1");
        $stmt->execute();
        $currentSemester = $stmt->fetch();
    }
    
    $semesterId = $currentSemester ? $currentSemester['semester_id'] : null;

    // Check for any in-progress assessment
    $stmt = $db->prepare(
        "SELECT aa.assessment_id, aa.attempt_id, a.title, s.subject_name, aa.start_time
         FROM AssessmentAttempts aa
         JOIN Assessments a ON aa.assessment_id = a.assessment_id
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         WHERE aa.student_id = ? 
         AND aa.status = 'in_progress'
         AND ac.class_id = ?
         ORDER BY aa.start_time DESC
         LIMIT 1"
    );
    $stmt->execute([$studentInfo['student_id'], $studentInfo['class_id']]);
    $inProgressAssessment = $stmt->fetch();

    // Get subject details and verify access
    $stmt = $db->prepare(
        "SELECT s.*, 
                COUNT(DISTINCT tca.teacher_id) as teacher_count
         FROM Subjects s
         JOIN ClassSubjects cs ON s.subject_id = cs.subject_id
         LEFT JOIN TeacherClassAssignments tca ON cs.class_id = tca.class_id 
            AND tca.subject_id = s.subject_id
         WHERE s.subject_id = ? 
         AND cs.class_id = ?
         GROUP BY s.subject_id"
    );
    $stmt->execute([$subjectId, $studentInfo['class_id']]);
    $subject = $stmt->fetch();

    if (!$subject) {
        throw new Exception('Subject not found or not assigned to your class');
    }

    // Get teachers for the current semester if available
    $teacherQuery = "SELECT DISTINCT 
            t.teacher_id,
            t.first_name,
            t.last_name,
            t.email
         FROM Teachers t
         JOIN TeacherClassAssignments tca ON t.teacher_id = tca.teacher_id
         WHERE tca.class_id = ?
         AND tca.subject_id = ?";
    
    if ($semesterId) {
        $teacherQuery .= " AND tca.semester_id = ?";
        $stmt = $db->prepare($teacherQuery);
        $stmt->execute([$studentInfo['class_id'], $subjectId, $semesterId]);
    } else {
        $stmt = $db->prepare($teacherQuery);
        $stmt->execute([$studentInfo['class_id'], $subjectId]);
    }
    
    $teachers = $stmt->fetchAll();

    // Get assessment history - using AssessmentClasses junction table with time validation fields
    $assessmentQuery = "SELECT 
            a.*,
            r.status as result_status,
            r.score,
            r.feedback,
            CASE WHEN aa.assessment_id IS NOT NULL THEN 1 ELSE 0 END as is_in_progress
         FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         LEFT JOIN Results r ON a.assessment_id = r.assessment_id 
            AND r.student_id = ?
         LEFT JOIN AssessmentAttempts aa ON (a.assessment_id = aa.assessment_id AND aa.student_id = ? AND aa.status = 'in_progress')
         WHERE ac.class_id = ?
         AND ac.subject_id = ?";
    
    if ($semesterId) {
        $assessmentQuery .= " AND a.semester_id = ?";
        $assessmentQuery .= " ORDER BY a.date DESC";
        $stmt = $db->prepare($assessmentQuery);
        $stmt->execute([
            $studentInfo['student_id'],
            $studentInfo['student_id'],
            $studentInfo['class_id'],
            $subjectId,
            $semesterId
        ]);
    } else {
        $assessmentQuery .= " ORDER BY a.date DESC";
        $stmt = $db->prepare($assessmentQuery);
        $stmt->execute([
            $studentInfo['student_id'],
            $studentInfo['student_id'],
            $studentInfo['class_id'],
            $subjectId
        ]);
    }
    
    $assessments = $stmt->fetchAll();

    // Calculate statistics
    $stats = [
        'total_assessments' => count($assessments),
        'completed_assessments' => 0,
        'average_score' => 0,
        'highest_score' => 0,
        'lowest_score' => null
    ];

    $totalScore = 0;
    foreach ($assessments as $assessment) {
        if ($assessment['result_status'] === 'completed') {
            $stats['completed_assessments']++;
            $totalScore += $assessment['score'];
            $stats['highest_score'] = max($stats['highest_score'], $assessment['score']);
            if ($stats['lowest_score'] === null) {
                $stats['lowest_score'] = $assessment['score'];
            } else {
                $stats['lowest_score'] = min($stats['lowest_score'], $assessment['score']);
            }
        }
    }

    if ($stats['completed_assessments'] > 0) {
        $stats['average_score'] = $totalScore / $stats['completed_assessments'];
    }

} catch (Exception $e) {
    logError("Subject detail error: " . $e->getMessage());
    $error = $e->getMessage();
}

// Function to determine assessment status and availability
function getAssessmentStatus($assessment, $inProgressAssessment = null) {
    $now = date('H:i:s');
    $currentDate = date('Y-m-d');
    $startTime = $assessment['start_time'] ?? '00:00:00';
    $endTime = $assessment['end_time'] ?? '23:59:59';
    $allowLateSubmission = $assessment['allow_late_submission'] ?? false;
    
    // If assessment is completed
    if ($assessment['result_status'] === 'completed') {
        return [
            'status' => 'completed',
            'class' => 'success',
            'text' => 'Completed',
            'canTake' => false,
            'action' => 'view_result'
        ];
    }
    
    // If this assessment is in progress
    if ($assessment['is_in_progress']) {
        return [
            'status' => 'in_progress',
            'class' => 'warning',
            'text' => 'In Progress',
            'canTake' => true,
            'action' => 'continue'
        ];
    }
    
    $isToday = ($assessment['date'] === $currentDate);
    $isPast = ($assessment['date'] < $currentDate);
    $isFuture = ($assessment['date'] > $currentDate);
    
    // Check if student can take assessment (no assessment in progress or this is the one in progress)
    $canTakeAssessment = !$inProgressAssessment || 
                        ($inProgressAssessment && $assessment['assessment_id'] == $inProgressAssessment['assessment_id']);
    
    // If it's today
    if ($isToday) {
        $isWithinTimeWindow = ($now >= $startTime && $now <= $endTime);
        $isLateAllowed = ($now > $endTime && $allowLateSubmission);
        
        if ($now < $startTime) {
            return [
                'status' => 'upcoming_today',
                'class' => 'info',
                'text' => 'Opens at ' . date('g:i A', strtotime($startTime)),
                'canTake' => false,
                'action' => 'wait'
            ];
        } elseif ($isWithinTimeWindow && $canTakeAssessment) {
            return [
                'status' => 'available',
                'class' => 'success',
                'text' => 'Available Now',
                'canTake' => true,
                'action' => 'take'
            ];
        } elseif ($isLateAllowed && $canTakeAssessment) {
            return [
                'status' => 'late_allowed',
                'class' => 'warning',
                'text' => 'Late Submission',
                'canTake' => true,
                'action' => 'take_late'
            ];
        } elseif (!$canTakeAssessment) {
            return [
                'status' => 'locked',
                'class' => 'secondary',
                'text' => 'Locked',
                'canTake' => false,
                'action' => 'locked'
            ];
        } else {
            return [
                'status' => 'expired',
                'class' => 'danger',
                'text' => 'Expired',
                'canTake' => false,
                'action' => 'expired'
            ];
        }
    }
    
    // If it's in the past
    if ($isPast) {
        if ($allowLateSubmission && $canTakeAssessment) {
            return [
                'status' => 'late_allowed',
                'class' => 'warning',
                'text' => 'Late Submission Available',
                'canTake' => true,
                'action' => 'take_late'
            ];
        } else {
            return [
                'status' => 'missed',
                'class' => 'danger',
                'text' => 'Missed',
                'canTake' => false,
                'action' => 'missed'
            ];
        }
    }
    
    // If it's in the future
    if ($isFuture) {
        if (!$canTakeAssessment) {
            return [
                'status' => 'locked',
                'class' => 'secondary',
                'text' => 'Locked',
                'canTake' => false,
                'action' => 'locked'
            ];
        } else {
            return [
                'status' => 'upcoming',
                'class' => 'primary',
                'text' => 'Upcoming',
                'canTake' => false,
                'action' => 'upcoming'
            ];
        }
    }
    
    // Default fallback
    return [
        'status' => 'unknown',
        'class' => 'secondary',
        'text' => 'Unknown',
        'canTake' => false,
        'action' => 'unknown'
    ];
}

$pageTitle = 'Subject Details';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php else: ?>
        <!-- Subject Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 text-warning mb-1"><?php echo htmlspecialchars($subject['subject_name']); ?></h1>
                <p class="text-muted mb-0">
                    <?php echo htmlspecialchars($studentInfo['program_name']); ?> | 
                    <?php echo htmlspecialchars($studentInfo['class_name']); ?>
                </p>
            </div>
            <a href="subjects.php" class="btn btn-warning">
                <i class="fas fa-arrow-left me-2"></i>Back to Subjects
            </a>
        </div>

        <?php if ($inProgressAssessment && 
                  $assessments && 
                  in_array($inProgressAssessment['assessment_id'], array_column($assessments, 'assessment_id'))): ?>
            <div class="alert alert-warning alert-dismissible fade show mb-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">Assessment in Progress!</h5>
                        <p class="mb-2">
                            You have an ongoing assessment in this subject: <strong>"<?php echo htmlspecialchars($inProgressAssessment['title']); ?>"</strong>
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

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body" style="background: linear-gradient(145deg, #2c3e50 0%, #3498db 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-warning text-uppercase mb-2">Assessments</h6>
                                <h4 class="text-white mb-0">
                                    <?php echo $stats['completed_assessments']; ?>/<?php echo $stats['total_assessments']; ?>
                                </h4>
                            </div>
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                                <i class="fas fa-tasks fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body" style="background: linear-gradient(145deg, #000 0%, #617186 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-warning text-uppercase mb-2">Average Score</h6>
                                <h4 class="text-white mb-0">
                                    <?php echo $stats['completed_assessments'] ? 
                                        number_format($stats['average_score'], 1) . '%' : 
                                        'N/A'; ?>
                                </h4>
                            </div>
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                                <i class="fas fa-chart-line fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-warning text-uppercase mb-2">Highest Score</h6>
                                <h4 class="text-white mb-0">
                                    <?php echo $stats['highest_score'] ? 
                                        number_format($stats['highest_score'], 1) . '%' : 
                                        'N/A'; ?>
                                </h4>
                            </div>
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                                <i class="fas fa-trophy fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body" style="background: linear-gradient(145deg, #2c3e50 0%, #d35400 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-warning text-uppercase mb-2">Teachers</h6>
                                <h4 class="text-white mb-0"><?php echo count($teachers); ?></h4>
                            </div>
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                                <i class="fas fa-chalkboard-teacher fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Subject Information -->
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">Subject Information</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            <?php echo htmlspecialchars($subject['description'] ?: 'No description available.'); ?>
                        </p>

                        <h6 class="text-warning mt-4 mb-3">Teachers</h6>
                        <?php if (empty($teachers)): ?>
                            <p class="text-muted">No teachers assigned yet.</p>
                        <?php else: ?>
                            <?php foreach ($teachers as $teacher): ?>
                                <div class="d-flex align-items-center mb-3">
                                    <div class="rounded-circle bg-light p-2 me-3">
                                        <i class="fas fa-user text-warning"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($teacher['email']); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Assessment History -->
            <div class="col-md-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                        <h5 class="card-title mb-0 text-warning">Assessment History</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($assessments)): ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-clipboard-list text-warning fa-2x mb-2"></i>
                                <p class="text-muted mb-0">No assessments available yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="py-3">Assessment</th>
                                            <th class="py-3">Date</th>
                                            <th class="py-3">Time</th>
                                            <th class="py-3">Status</th>
                                            <th class="py-3">Score</th>
                                            <th class="py-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assessments as $assessment): 
                                            $assessmentStatus = getAssessmentStatus($assessment, $inProgressAssessment);
                                            $isThisInProgress = $assessment['is_in_progress'];
                                        ?>
                                            <tr class="<?php echo $isThisInProgress ? 'table-warning assessment-in-progress' : ''; ?>">
                                                <td class="py-3">
                                                    <?php echo htmlspecialchars($assessment['title']); ?>
                                                    <?php if ($isThisInProgress): ?>
                                                        <br><small class="text-warning"><i class="fas fa-clock"></i> In Progress</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3"><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                                <td class="py-3">
                                                    <?php 
                                                    $startTime = $assessment['start_time'] ?? '00:00:00';
                                                    $endTime = $assessment['end_time'] ?? '23:59:59';
                                                    ?>
                                                    <?php if ($startTime != '00:00:00' || $endTime != '23:59:59'): ?>
                                                        <?php echo date('g:i A', strtotime($startTime)); ?> -
                                                        <?php echo date('g:i A', strtotime($endTime)); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">All Day</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3">
                                                    <span class="badge bg-<?php echo $assessmentStatus['class']; ?> 
                                                          <?php echo $assessmentStatus['class'] === 'warning' ? 'text-dark' : ''; ?>">
                                                        <?php echo $assessmentStatus['text']; ?>
                                                    </span>
                                                </td>
                                                <td class="py-3">
                                                    <?php if (isset($assessment['score']) && $assessment['result_status'] === 'completed'): ?>
                                                        <?php 
                                                        // Calculate percentage based on total possible score
                                                        $stmt = $db->prepare("SELECT SUM(max_score) FROM questions WHERE assessment_id = ?");
                                                        $stmt->execute([$assessment['assessment_id']]);
                                                        $totalPossible = $stmt->fetchColumn() ?: 1;
                                                        $percentage = ($assessment['score'] / $totalPossible) * 100;
                                                        ?>
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3">
                                                    <?php if ($assessmentStatus['action'] === 'view_result'): ?>
                                                        <a href="view_result.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                                           class="btn btn-sm btn-warning">
                                                            <i class="fas fa-eye me-1"></i>View Result
                                                        </a>
                                                    <?php elseif ($assessmentStatus['action'] === 'continue'): ?>
                                                        <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                                           class="btn btn-sm btn-warning">
                                                            <i class="fas fa-play me-1"></i>Continue
                                                        </a>
                                                    <?php elseif ($assessmentStatus['action'] === 'take'): ?>
                                                        <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                                           class="btn btn-sm btn-success">
                                                            <i class="fas fa-pencil-alt me-1"></i>Take Assessment
                                                        </a>
                                                    <?php elseif ($assessmentStatus['action'] === 'take_late'): ?>
                                                        <a href="take_assessment.php?id=<?php echo $assessment['assessment_id']; ?>" 
                                                           class="btn btn-sm btn-warning"
                                                           title="Late submission allowed">
                                                            <i class="fas fa-pencil-alt me-1"></i>Take (Late)
                                                        </a>
                                                    <?php elseif ($assessmentStatus['action'] === 'locked'): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" disabled
                                                                title="Complete your current assessment first"
                                                                data-bs-toggle="tooltip">
                                                            <i class="fas fa-lock me-1"></i>Locked
                                                        </button>
                                                    <?php elseif ($assessmentStatus['action'] === 'wait'): ?>
                                                        <button class="btn btn-sm btn-info" disabled
                                                                title="<?php echo $assessmentStatus['text']; ?>"
                                                                data-bs-toggle="tooltip">
                                                            <i class="fas fa-clock me-1"></i><?php echo date('g:i A', strtotime($assessment['start_time'])); ?>
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
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

.table > :not(caption) > * > * {
    padding: 1rem;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
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
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        new bootstrap.Tooltip(tooltip);
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