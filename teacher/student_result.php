<?php
// teacher/student_result.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

requireRole('teacher');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get teacher info
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();

    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    $teacherId = $teacherInfo['teacher_id'];

    // Get parameters from URL
    $studentId = isset($_GET['student']) ? (int)$_GET['student'] : 0;
    $subjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
    
    // Get current/selected semester using shared component
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);
    $selectedSemester = $currentSemester['semester_id'];

    if (!$studentId) {
        throw new Exception('Student ID is required');
    }

    // If no subject is specified, we'll show results for all subjects
    $showAllSubjects = ($subjectId === 0);

    // Get student information
    $stmt = $db->prepare(
        "SELECT 
            s.*, 
            c.class_name, 
            p.program_name 
        FROM students s
        JOIN classes c ON s.class_id = c.class_id
        JOIN programs p ON c.program_id = p.program_id
        WHERE s.student_id = ?"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        throw new Exception('Student not found');
    }

    // Verify teacher has access to this student and at least one subject
    $stmt = $db->prepare(
        "SELECT 1
         FROM teacherclassassignments tca
         JOIN students s ON tca.class_id = s.class_id
         WHERE tca.teacher_id = ?
         AND s.student_id = ?
         " . ($subjectId ? "AND tca.subject_id = ?" : "") . "
         LIMIT 1"
    );

    if ($subjectId) {
        $stmt->execute([$teacherId, $studentId, $subjectId]);
    } else {
        $stmt->execute([$teacherId, $studentId]);
    }

    $hasAccess = $stmt->fetchColumn();

    if (!$hasAccess) {
        throw new Exception('You do not have permission to view this student\'s results');
    }

    // Get subject name if a specific subject is selected
    $subjectName = '';
    if ($subjectId) {
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
        $stmt->execute([$subjectId]);
        $subject = $stmt->fetch();

        if (!$subject) {
            throw new Exception('Subject not found');
        }
        
        $subjectName = $subject['subject_name'];
    } else {
        // Get all subjects the teacher teaches to this student
        $stmt = $db->prepare(
            "SELECT DISTINCT s.subject_id, s.subject_name
             FROM subjects s
             JOIN teacherclassassignments tca ON s.subject_id = tca.subject_id
             JOIN students st ON tca.class_id = st.class_id
             WHERE tca.teacher_id = ?
             AND st.student_id = ?
             ORDER BY s.subject_name"
        );
        $stmt->execute([$teacherId, $studentId]);
        $subjects = $stmt->fetchAll();
    }

    // Get assessments for this student in this subject (or all subjects) for selected semester
    $queryParams = [$studentId, $studentId, $teacherId, $selectedSemester];
    $subjectCondition = "";

    if ($subjectId) {
        $subjectCondition = "AND ac.subject_id = ?";
        $queryParams[] = $subjectId;
    }

    $stmt = $db->prepare(
        "SELECT 
            a.assessment_id,
            a.title,
            a.description,
            a.date,
            a.status as assessment_status,
            r.score,
            r.status as submission_status,
            r.created_at as submission_time,
            s.subject_name,
            s.subject_id,
            c.class_name,
            at.type_name,
            at.type_id
         FROM assessments a
         LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         JOIN classes c ON ac.class_id = c.class_id
         JOIN students st ON st.class_id = c.class_id AND st.student_id = ?
         LEFT JOIN results r ON (a.assessment_id = r.assessment_id AND r.student_id = ?)
         WHERE EXISTS (
             SELECT 1 
             FROM teacherclassassignments tca 
             WHERE tca.teacher_id = ? 
             AND tca.subject_id = ac.subject_id 
             AND tca.class_id = ac.class_id
         )
         AND a.semester_id = ?
         $subjectCondition
         ORDER BY s.subject_name, a.date DESC"
    );
    $stmt->execute($queryParams);
    $assessments = $stmt->fetchAll();

    // Calculate statistics
    $stats = [
        'total_assessments' => count($assessments),
        'completed_assessments' => 0,
        'average_score' => 0,
        'highest_score' => 0,
        'lowest_score' => 100
    ];

    $totalScore = 0;
    $subjectStats = [];
    
    foreach ($assessments as $assessment) {
        // Initialize subject stats if not already done
        if (!isset($subjectStats[$assessment['subject_id']])) {
            $subjectStats[$assessment['subject_id']] = [
                'subject_name' => $assessment['subject_name'],
                'total_assessments' => 0,
                'completed_assessments' => 0,
                'average_score' => 0,
                'total_score' => 0
            ];
        }
        
        // Increment subject assessment count
        $subjectStats[$assessment['subject_id']]['total_assessments']++;
        
        if ($assessment['submission_status'] === 'completed') {
            $stats['completed_assessments']++;
            $totalScore += $assessment['score'];
            $subjectStats[$assessment['subject_id']]['completed_assessments']++;
            $subjectStats[$assessment['subject_id']]['total_score'] += $assessment['score'];
            
            if ($assessment['score'] > $stats['highest_score']) {
                $stats['highest_score'] = $assessment['score'];
            }
            
            if ($assessment['score'] < $stats['lowest_score']) {
                $stats['lowest_score'] = $assessment['score'];
            }
        }
    }

    // Calculate averages for each subject
    foreach ($subjectStats as $subjectId => &$subjStat) {
        if ($subjStat['completed_assessments'] > 0) {
            $subjStat['average_score'] = round($subjStat['total_score'] / $subjStat['completed_assessments'], 2);
        }
    }
    unset($subjStat); // Break the reference

    // Calculate overall average
    if ($stats['completed_assessments'] > 0) {
        $stats['average_score'] = round($totalScore / $stats['completed_assessments'], 2);
    } else {
        $stats['lowest_score'] = 0;
    }

    // Calculate assessment type statistics
    $assessmentTypeStats = [];
    foreach ($assessments as $assessment) {
        $typeId = $assessment['type_id'] ?? 0;
        $typeName = $assessment['type_name'] ?? 'Unassigned';
        
        if (!isset($assessmentTypeStats[$typeId])) {
            $assessmentTypeStats[$typeId] = [
                'type_name' => $typeName,
                'total_assessments' => 0,
                'completed_assessments' => 0,
                'average_score' => 0,
                'total_score' => 0
            ];
        }
        
        $assessmentTypeStats[$typeId]['total_assessments']++;
        
        if ($assessment['submission_status'] === 'completed') {
            $assessmentTypeStats[$typeId]['completed_assessments']++;
            $assessmentTypeStats[$typeId]['total_score'] += $assessment['score'];
        }
    }
    
    // Calculate averages for each assessment type
    foreach ($assessmentTypeStats as $typeId => &$typeStat) {
        if ($typeStat['completed_assessments'] > 0) {
            $typeStat['average_score'] = round($typeStat['total_score'] / $typeStat['completed_assessments'], 2);
        }
    }
    unset($typeStat);

    // Group assessments by subject if showing all subjects
    $assessmentsBySubject = [];
    if ($showAllSubjects) {
        foreach ($assessments as $assessment) {
            $subjectId = $assessment['subject_id'];
            if (!isset($assessmentsBySubject[$subjectId])) {
                $assessmentsBySubject[$subjectId] = [
                    'subject_name' => $assessment['subject_name'],
                    'assessments' => []
                ];
            }
            $assessmentsBySubject[$subjectId]['assessments'][] = $assessment;
        }
    }

} catch (Exception $e) {
    logError("Student results error: " . $e->getMessage());
    $error = $e->getMessage();
}

$pageTitle = 'Student Assessment Results';
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
        <!-- Result Header -->
        <div class="header-gradient mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1 class="h3 text-white mb-1">
                        Student Assessment Results
                    </h1>
                    <p class="text-white-50 mb-0">
                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> |
                        <?php echo htmlspecialchars($student['program_name']); ?> |
                        <?php echo htmlspecialchars($student['class_name']); ?>
                        <?php if (!$showAllSubjects): ?>
                            | <?php echo htmlspecialchars($subjectName); ?>
                        <?php endif; ?>
                        | Semester: <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
                    </p>
                </div>
                <div>
                    <a href="students.php?semester=<?php echo $selectedSemester; ?>" class="btn btn-light me-2">
                        <i class="fas fa-users me-2"></i>All Students
                    </a>
                    <?php if (!$showAllSubjects): ?>
                    <a href="class_results.php?class=<?php echo $student['class_id']; ?>&subject=<?php echo $subjectId; ?>&semester=<?php echo $selectedSemester; ?>" 
                        class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Class Results
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                <i class="fas fa-percentage fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Average Score</h6>
                                <h4 class="mb-0"><?php echo $stats['average_score']; ?>%</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                <i class="fas fa-check-circle fa-2x text-success"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Completed Assessments</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['completed_assessments']; ?>/<?php echo $stats['total_assessments']; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3">
                                <i class="fas fa-trophy fa-2x text-info"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Highest Score</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['highest_score']; ?>%
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                <i class="fas fa-chart-line fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Lowest Score</h6>
                                <h4 class="mb-0">
                                    <?php echo $stats['lowest_score']; ?>%
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($assessmentTypeStats)): ?>
        <!-- Assessment Type Performance -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-gradient py-3">
                <h5 class="card-title mb-0">Performance by Assessment Type</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Assessment Type</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">Average Score</th>
                                <th class="text-center">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessmentTypeStats as $typeId => $type): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold"><?php echo htmlspecialchars($type['type_name']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $type['completed_assessments']; ?>/<?php echo $type['total_assessments']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($type['completed_assessments'] > 0): ?>
                                            <span class="fw-bold"><?php echo $type['average_score']; ?>%</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $completion = $type['total_assessments'] > 0 ? 
                                            ($type['completed_assessments'] / $type['total_assessments']) * 100 : 0;
                                        ?>
                                        <div class="progress mx-auto" style="width: 100px;">
                                            <div class="progress-bar" style="width: <?php echo $completion; ?>%"
                                                 title="<?php echo number_format($completion, 1); ?>% complete">
                                            </div>
                                        </div>
                                        <small class="text-muted"><?php echo number_format($completion, 1); ?>%</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showAllSubjects && !empty($subjectStats)): ?>
        <!-- Subject Performance -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-gradient py-3">
                <h5 class="card-title mb-0">Subject Performance</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">Average Score</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjectStats as $subjectId => $subject): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td class="text-center">
                                        <?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($subject['completed_assessments'] > 0): ?>
                                            <span class="fw-bold"><?php echo $subject['average_score']; ?>%</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="student_result.php?student=<?php echo $studentId; ?>&subject=<?php echo $subjectId; ?>&semester=<?php echo $selectedSemester; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-search me-1"></i> View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assessments Table -->
        <?php if ($showAllSubjects): ?>
            <?php foreach ($assessmentsBySubject as $subjectId => $subjectData): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-gradient py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($subjectData['subject_name']); ?> - Assessment Results</h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($subjectData['assessments'])): ?>
                            <div class="text-center py-4">
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <h5>No Assessments Found</h5>
                                    <p class="text-muted">There are no assessments assigned to this student for this subject.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Assessment</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Score</th>
                                            <th>Submission Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjectData['assessments'] as $assessment): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($assessment['title']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($assessment['description']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($assessment['type_name']): ?>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($assessment['type_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                                <td>
                                                    <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                        <span class="badge bg-success">Completed</span>
                                                    <?php elseif ($assessment['assessment_status'] === 'pending'): ?>
                                                        <span class="badge bg-warning">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not Attempted</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                        <span class="fw-bold"><?php echo $assessment['score']; ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($assessment['submission_time']): ?>
                                                        <?php echo date('M d, Y g:i A', strtotime($assessment['submission_time'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                        <a href="view_result.php?assessment=<?php echo $assessment['assessment_id']; ?>&student=<?php echo $studentId; ?>&semester=<?php echo $selectedSemester; ?>" 
                                                           class="btn btn-sm btn-primary">
                                                            <i class="fas fa-eye me-1"></i> View Details
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary" disabled>
                                                            <i class="fas fa-eye-slash me-1"></i> Not Available
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
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-gradient py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Assessment Results</h5>
                        <button onclick="exportResults()" class="btn btn-light btn-sm">
                            <i class="fas fa-download me-2"></i>Export
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Assessment</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Submission Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($assessments) > 0): ?>
                                    <?php foreach ($assessments as $assessment): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($assessment['title']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($assessment['description']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($assessment['type_name']): ?>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($assessment['type_name']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                            <td>
                                                <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php elseif ($assessment['assessment_status'] === 'pending'): ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Not Attempted</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                    <span class="fw-bold"><?php echo $assessment['score']; ?>%</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($assessment['submission_time']): ?>
                                                    <?php echo date('M d, Y g:i A', strtotime($assessment['submission_time'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($assessment['submission_status'] === 'completed'): ?>
                                                    <a href="view_result.php?assessment=<?php echo $assessment['assessment_id']; ?>&student=<?php echo $studentId; ?>&semester=<?php echo $selectedSemester; ?>" 
                                                       class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye me-1"></i> View Details
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-secondary" disabled>
                                                        <i class="fas fa-eye-slash me-1"></i> Not Available
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <div class="empty-state">
                                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                                <h5>No Assessments Found</h5>
                                                <p class="text-muted">There are no assessments assigned to this student for this subject.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<style>
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff9e6;
        --primary-black: #000000;
        --primary-white: #ffffff;
    }

    /* Clean and Professional Layout */
    .container-fluid {
        padding: 2rem;
    }

    /* Header Section */
    .header-gradient {
        background: var(--primary-black);
        padding: 1.5rem;
        border-radius: 4px;
        margin-bottom: 1.5rem;
    }

    .header-gradient h1 {
        color: var(--primary-yellow);
        font-weight: 600;
    }

    .header-gradient .text-white-50 {
        color: var(--primary-white) !important;
    }

    /* Statistics Cards */
    .card {
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        box-shadow: none;
        transition: box-shadow 0.2s;
    }

    .card:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transform: none;
    }

    .card-body {
        padding: 1.25rem;
    }

    .rounded-circle {
        background-color: var(--light-yellow) !important;
    }

    .rounded-circle i {
        color: var(--dark-yellow) !important;
    }

    /* Table Styles */
    .card-header.bg-gradient {
        background: var(--primary-black);
        border-radius: 4px 4px 0 0;
        padding: 1rem;
        color: white;
    }

    .table thead th {
        background-color: #f8f9fa;
        border-bottom: 2px solid #e0e0e0;
        font-weight: 600;
    }

    .table td {
        vertical-align: middle;
        padding: 0.75rem;
    }

    /* Empty State */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }

    .empty-state i {
        color: #ccc;
        margin-bottom: 1rem;
    }

    /* Badges */
    .badge {
        padding: 0.4rem 0.8rem;
        font-weight: normal;
        border-radius: 4px;
    }

    .badge.bg-success {
        background: var(--dark-yellow) !important;
    }

    .badge.bg-secondary {
        background: var(--primary-black) !important;
    }

    /* Export Button */
    .btn-light {
        background: var(--primary-white);
        border: 1px solid var(--dark-yellow);
        color: var(--primary-black);
        padding: 0.4rem 1rem;
        border-radius: 4px;
    }

    .btn-light:hover {
        background: var(--light-yellow);
        border-color: var(--dark-yellow);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .container-fluid {
            padding: 1rem;
        }
        
        .header-gradient {
            padding: 1rem;
        }
        
        .card-body {
            padding: 1rem;
        }
    }
</style>

<script>
    // Export results function
    function exportResults() {
        const studentName = document.querySelector('.text-white-50').textContent.split('|')[0].trim();
        const subjectName = document.querySelector('.text-white-50').textContent.split('|')[3]?.trim() || 'All Subjects';
        
        let csv = 'Student Assessment Results Report\n';
        csv += `Student: ${studentName}\n`;
        csv += `Subject: ${subjectName}\n\n`;
        
        csv += 'Assessment Title,Date,Status,Score,Submission Date\n';
        
        const table = document.querySelector('table');
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 5) {
                const title = cells[0].querySelector('.fw-bold')?.textContent.trim() || '';
                const date = cells[1].textContent.trim();
                const status = cells[2].querySelector('.badge')?.textContent.trim() || '';
                const score = cells[3].textContent.trim();
                const submissionDate = cells[4].textContent.trim();
                
                // Escape any commas in the text fields
                const escapeCsv = (text) => `"${text.replace(/"/g, '""')}"`;
                
                csv += `${escapeCsv(title)},${escapeCsv(date)},${escapeCsv(status)},${escapeCsv(score)},${escapeCsv(submissionDate)}\n`;
            }
        });
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', `${studentName}_${subjectName.replace(/\s+/g, '_')}_Results.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });

        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);
        });
    });
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>