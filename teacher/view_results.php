<?php
// teacher/view_results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
requireRole('teacher');

// Start output buffering
ob_start();

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

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
    
    // Get semester information
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $semesterId = $currentSemester['semester_id'];

    // Get assessment ID from URL
    $assessmentId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    // Get specific class ID if provided (this is the new parameter we're adding)
    $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

    // Get specific student if provided
    $studentId = isset($_GET['student']) ? intval($_GET['student']) : 0;

    if (!$assessmentId) {
        throw new Exception('Assessment ID is required');
    }

    // Verify teacher has access to this assessment and filter by semester
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name, sem.semester_name, c.class_id
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN classes c ON ac.class_id = c.class_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         JOIN semesters sem ON a.semester_id = sem.semester_id
         JOIN teacherclassassignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE a.assessment_id = ? AND tca.teacher_id = ? AND a.semester_id = ?" .
         ($classId ? " AND c.class_id = ?" : "")
    );
    
    $queryParams = [$assessmentId, $teacherId, $semesterId];
    if ($classId) {
        $queryParams[] = $classId;
    }
    
    $stmt->execute($queryParams);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found or access denied');
    }
    
    // Use the class ID from the assessment if we have it
    $classId = $classId ?: $assessment['class_id'];

    // Get all questions for this assessment
    $stmt = $db->prepare(
        "SELECT question_id, question_text, question_type, max_score
         FROM questions 
         WHERE assessment_id = ?
         ORDER BY question_id"
    );
    $stmt->execute([$assessmentId]);
    $questions = $stmt->fetchAll();

    // Get the classes for this assessment that this teacher has access to
    // If a specific class ID is provided, just use that one
    if ($classId) {
        $classIds = [$classId];
    } else {
        $stmt = $db->prepare(
            "SELECT ac.class_id 
             FROM assessmentclasses ac 
             JOIN teacherclassassignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
             JOIN assessments a ON ac.assessment_id = a.assessment_id
             WHERE ac.assessment_id = ? AND tca.teacher_id = ? AND a.semester_id = ?"
        );
        $stmt->execute([$assessmentId, $teacherId, $semesterId]);
        $classIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($classIds)) {
        throw new Exception('No classes found for this assessment');
    }

    // Get all students and their results
    $baseQuery = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            r.score,
            r.status as result_status,
            aa.start_time,
            aa.end_time,
            aa.status as attempt_status,
            (
                SELECT COUNT(*)
                FROM studentanswers sa
                WHERE sa.assessment_id = ? 
                AND sa.student_id = s.student_id
            ) as answered_questions,
            (
                SELECT GROUP_CONCAT(
                    CONCAT(
                        sa.question_id, 
                        ':', 
                        sa.answer_text,
                        ':', 
                        COALESCE(sa.score, 0)
                    ) SEPARATOR '|'
                )
                FROM studentanswers sa
                WHERE sa.assessment_id = ? 
                AND sa.student_id = s.student_id
            ) as answers
        FROM students s
        JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN results r ON r.assessment_id = ? AND r.student_id = s.student_id
        LEFT JOIN assessmentattempts aa ON aa.assessment_id = ? 
            AND aa.student_id = s.student_id
        WHERE s.class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")";

    $params = [
        $assessmentId,
        $assessmentId,
        $assessmentId,
        $assessmentId
    ];
    $params = array_merge($params, $classIds);

    if ($studentId) {
        $baseQuery .= " AND s.student_id = ?";
        $params[] = $studentId;
    }

    $baseQuery .= " ORDER BY s.last_name, s.first_name";

    $stmt = $db->prepare($baseQuery);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Calculate statistics
    $totalStudents = count($results);
    $submittedCount = 0;
    $totalScore = 0;
    $scores = [];

    foreach ($results as $result) {
        if ($result['result_status'] === 'completed') {
            $submittedCount++;
            $totalScore += $result['score'];
            $scores[] = $result['score'];
        }
    }

    $averageScore = $submittedCount ? round($totalScore / $submittedCount, 1) : 0;
    $submissionRate = $totalStudents ? round(($submittedCount / $totalStudents) * 100, 1) : 0;

    if (!empty($scores)) {
        sort($scores);
        $minScore = $scores[0];
        $maxScore = end($scores);
        $medianScore = count($scores) % 2 === 0 ?
            ($scores[count($scores) / 2 - 1] + $scores[count($scores) / 2]) / 2 :
            $scores[floor(count($scores) / 2)];
    } else {
        $minScore = $maxScore = $medianScore = 0;
    }

    // Calculate question statistics
    $questionStats = [];
    foreach ($questions as $question) {
        $questionStats[$question['question_id']] = [
            'correct_count' => 0,
            'attempt_count' => 0,
            'average_score' => 0,
            'total_score' => 0
        ];
    }

    foreach ($results as $result) {
        if ($result['answers']) {
            $answers = array_filter(explode('|', $result['answers']));
            foreach ($answers as $answer) {
                list($qId, $answerText, $score) = explode(':', $answer);
                if (isset($questionStats[$qId])) {
                    $questionStats[$qId]['attempt_count']++;
                    $questionStats[$qId]['total_score'] += floatval($score);
                    if ($score > 0) {
                        $questionStats[$qId]['correct_count']++;
                    }
                }
            }
        }
    }

    foreach ($questionStats as &$stat) {
        if ($stat['attempt_count'] > 0) {
            $stat['average_score'] = round($stat['total_score'] / $stat['attempt_count'], 2);
            $stat['correct_rate'] = round(($stat['correct_count'] / $stat['attempt_count']) * 100, 1);
        }
    }
    unset($stat);
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("View results page error: " . $e->getMessage());
}

$pageTitle = "View Assessment Results";
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    .page-gradient {
        min-height: 100vh;
        padding: 1rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #fff9e6 100%);
    }

    .header-gradient {
        background: linear-gradient(90deg, #000000 0%, #ffd700 50%, #ffeb80 100%);
        padding: 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        color: white;
    }

    .assessment-info {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
    }

    .stats-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        height: 100%;
        border-left: 4px solid #ffd700;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-5px);
    }

    .results-table {
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .progress {
        height: 8px;
        border-radius: 4px;
        background-color: #f0f0f0;
    }

    .progress-bar {
        background: linear-gradient(90deg, #000000, #ffd700);
    }

    .question-stats {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
    }

    .status-completed {
        background-color: #d4edda;
        color: #155724;
    }

    .status-pending {
        background-color: #fff3cd;
        color: #856404;
    }

    .status-expired {
        background-color: #f8d7da;
        color: #721c24;
    }

    .score-badge {
        background: #f8f9fa;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.875rem;
        color: #495057;
    }

    .chart-container {
        height: 300px;
        margin-bottom: 2rem;
    }

    @media (max-width: 768px) {
        .header-gradient {
            padding: 1rem;
            text-align: center;
        }

        .stats-card {
            margin-bottom: 1rem;
        }

        .results-table {
            margin: 0 -1rem;
            width: calc(100% + 2rem);
        }

        .results-table-scroll {
            overflow-x: auto;
            margin: 0 1rem;
        }

        .question-stats {
            padding: 1rem;
        }

        .chart-container {
            height: 200px;
            margin: 0 -1rem 1rem;
            padding: 0 1rem;
        }
    }

    /* Table styles */
    .table> :not(caption)>*>* {
        padding: 1rem;
    }

    .table thead th {
        background: #f8f9fa;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }

    .table tbody tr:hover {
        background-color: #fff9e6;
    }

    /* Button styles */
    .btn-yellow {
        background: linear-gradient(45deg, #000000, #ffd700);
        border: none;
        color: white;
        transition: all 0.3s ease;
    }

    .btn-yellow:hover {
        background: linear-gradient(45deg, #ffd700, #000000);
        transform: translateY(-1px);
        color: white;
    }
</style>

<div class="page-gradient">
    <div class="container-fluid px-4">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($assessment)): ?>
            <div class="header-gradient">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <div class="mb-3 mb-md-0">
                        <h1 class="h3 mb-0"><?php echo htmlspecialchars($assessment['title']); ?></h1>
                        <p class="mb-0 mt-2">
                            <?php echo htmlspecialchars($assessment['class_name']); ?> |
                            <?php echo htmlspecialchars($assessment['subject_name']); ?> |
                            <?php echo htmlspecialchars($assessment['semester_name']); ?>
                        </p>
                    </div>
                    <div>
                        <a href="<?php echo addSemesterToUrl('results.php', $semesterId); ?>" class="btn btn-light">
                            <i class="fas fa-arrow-left me-2"></i>Back to Results
                        </a>
                    </div>
                </div>
            </div>


            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Total Students</h6>
                        <h3 class="mb-0"><?php echo $totalStudents; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Submission Rate</h6>
                        <h3 class="mb-0"><?php echo $submissionRate; ?>%</h3>
                        <small class="text-muted">
                            <?php echo $submittedCount; ?> submitted
                        </small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Average Score</h6>
                        <h3 class="mb-0"><?php echo $averageScore; ?>%</h3>
                        <div class="d-flex gap-2 mt-1">
                            <small class="text-muted">Min: <?php echo $minScore; ?>%</small>
                            <small class="text-muted">Max: <?php echo $maxScore; ?>%</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Median Score</h6>
                        <h3 class="mb-0"><?php echo round($medianScore, 1); ?>%</h3>
                    </div>
                </div>
            </div>

            <!-- Question Statistics -->
            <div class="question-stats mb-4">
                <h5 class="mb-4">Question Analysis</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th class="text-center">Type</th>
                                <th class="text-center">Max Score</th>
                                <th class="text-center">Attempts</th>
                                <th class="text-center">Average Score</th>
                                <th class="text-center">Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td>
                                        <?php
                                        echo htmlspecialchars(mb_strimwidth(
                                            $question['question_text'],
                                            0,
                                            100,
                                            '...'
                                        ));
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary">
                                            <?php echo $question['question_type']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo $question['max_score']; ?></td>
                                    <td class="text-center">
                                        <?php echo $questionStats[$question['question_id']]['attempt_count']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $questionStats[$question['question_id']]['average_score']; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="progress" style="width: 100px; margin: 0 auto;">
                                            <?php
                                            $successRate = $questionStats[$question['question_id']]['correct_rate'];
                                            ?>
                                            <div class="progress-bar"
                                                style="width: <?php echo $successRate; ?>%"
                                                title="<?php echo $successRate; ?>%">
                                            </div>
                                        </div>
                                        <small class="d-block mt-1">
                                            <?php echo $successRate; ?>%
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Student Results -->
            <div class="results-table">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Start Time</th>
                                <th class="text-center">Duration</th>
                                <th class="text-center">Questions Answered</th>
                                <th class="text-center">Score</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result):
                                $hasStarted = !empty($result['start_time']);
                                $hasCompleted = $result['result_status'] === 'completed';
                                $duration = null;

                                if ($hasStarted && !empty($result['end_time'])) {
                                    $start = new DateTime($result['start_time']);
                                    $end = new DateTime($result['end_time']);
                                    $duration = $end->diff($start);
                                }
                            ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $result['last_name'] . ', ' . $result['first_name']
                                        ); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($hasCompleted): ?>
                                            <span class="status-badge status-completed">Completed</span>
                                        <?php elseif ($hasStarted): ?>
                                            <span class="status-badge status-pending">In Progress</span>
                                        <?php else: ?>
                                            <span class="status-badge status-expired">Not Attempted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($hasStarted): ?>
                                            <?php echo date('M d, Y H:i', strtotime($result['start_time'])); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        if ($duration) {
                                            $minutes = ($duration->days * 24 * 60) +
                                                ($duration->h * 60) +
                                                $duration->i;
                                            echo $minutes . ' min';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($hasStarted): ?>
                                            <?php
                                            echo $result['answered_questions'] . ' / ' .
                                                count($questions);
                                            ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($hasCompleted): ?>
                                            <span class="fw-bold">
                                                <?php echo number_format($result['score'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($hasStarted): ?>
                                            <a href="<?php echo addSemesterToUrl('view_result.php?assessment=' . $assessmentId . '&student=' . $result['student_id'], $semesterId); ?>"
                                                class="btn btn-sm btn-yellow">
                                                <i class="fas fa-search me-1"></i>View Details
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);
        });
    });
</script>

<?php
ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>