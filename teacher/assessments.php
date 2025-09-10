<?php
// teacher/assessments.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Assessment.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

requireRole('teacher');

// Start output buffering
ob_start();

// Check for existing flash messages
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

$assessments = [];

try {
    // Get teacher ID and semester information
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
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

    // Function to automatically update expired assessment statuses
    function updateExpiredAssessmentStatuses($db)
    {
        try {
            $stmt = $db->prepare(
                "UPDATE Assessments 
                 SET status = 'completed', updated_at = CURRENT_TIMESTAMP 
                 WHERE status = 'pending' 
                 AND (
                     date < CURDATE() 
                     OR (date = CURDATE() AND end_time < CURTIME())
                 )"
            );
            $stmt->execute();

            $updatedCount = $stmt->rowCount();
            if ($updatedCount > 0) {
                logSystemActivity("Assessment Management", "Auto-updated $updatedCount expired assessments to 'completed' status", "INFO");
            }

            return $updatedCount;
        } catch (Exception $e) {
            logError("Failed to auto-update assessment statuses: " . $e->getMessage());
            return 0;
        }
    }

    // Call the function to update expired assessments whenever the page loads
    $updatedCount = updateExpiredAssessmentStatuses($db);
    if ($updatedCount > 0) {
        $_SESSION['success'] = "$updatedCount expired assessments were automatically marked as completed.";
    }

    // Handle status updates and deletions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid request');
            }

            switch ($_POST['action']) {
                case 'update_status':
                    $assessmentId = sanitizeInput($_POST['assessment_id']);
                    $newStatus = sanitizeInput($_POST['status']);

                    $db->beginTransaction();

                    // Verify teacher owns this assessment using the correct table structure
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM Assessments a
                         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                         JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id 
                              AND ac.subject_id = tca.subject_id
                         WHERE a.assessment_id = ? AND tca.teacher_id = ? AND a.semester_id = ? AND tca.semester_id = ?"
                    );
                    $stmt->execute([$assessmentId, $teacherId, $semesterId, $semesterId]);
                    if ($stmt->fetchColumn() == 0) {
                        throw new Exception('Not authorized to modify this assessment');
                    }

                    $stmt = $db->prepare(
                        "UPDATE Assessments 
                         SET status = ?, updated_at = CURRENT_TIMESTAMP 
                         WHERE assessment_id = ?"
                    );
                    if (!$stmt->execute([$newStatus, $assessmentId])) {
                        throw new Exception('Failed to update assessment status');
                    }

                    $db->commit();
                    $_SESSION['success'] = 'Assessment status updated successfully';
                    break;

                case 'delete_from_class':
                    $assessmentId = sanitizeInput($_POST['assessment_id']);
                    $classId = sanitizeInput($_POST['class_id']);
                    $subjectId = sanitizeInput($_POST['subject_id']);

                    $db->beginTransaction();

                    // Verify teacher owns this assessment-class assignment and it's pending
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM Assessments a
                         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                         JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id 
                              AND ac.subject_id = tca.subject_id
                         WHERE a.assessment_id = ? AND ac.class_id = ? 
                         AND ac.subject_id = ? AND tca.teacher_id = ? 
                         AND a.status = 'pending' AND a.semester_id = ? AND tca.semester_id = ?"
                    );
                    $stmt->execute([$assessmentId, $classId, $subjectId, $teacherId, $semesterId, $semesterId]);
                    if ($stmt->fetchColumn() == 0) {
                        throw new Exception('Not authorized to remove this assessment from this class');
                    }

                    // Delete any student answers, results, and attempts for this specific class
                    $stmt = $db->prepare(
                        "DELETE sa FROM StudentAnswers sa
                         INNER JOIN Students s ON sa.student_id = s.student_id
                         WHERE sa.assessment_id = ? AND s.class_id = ?"
                    );
                    $stmt->execute([$assessmentId, $classId]);

                    $stmt = $db->prepare(
                        "DELETE r FROM Results r
                         INNER JOIN Students s ON r.student_id = s.student_id
                         WHERE r.assessment_id = ? AND s.class_id = ?"
                    );
                    $stmt->execute([$assessmentId, $classId]);

                    $stmt = $db->prepare(
                        "DELETE aa FROM AssessmentAttempts aa
                         INNER JOIN Students s ON aa.student_id = s.student_id
                         WHERE aa.assessment_id = ? AND s.class_id = ?"
                    );
                    $stmt->execute([$assessmentId, $classId]);

                    // Delete the assessment-class association
                    $stmt = $db->prepare(
                        "DELETE FROM AssessmentClasses 
                         WHERE assessment_id = ? AND class_id = ? AND subject_id = ?"
                    );
                    if (!$stmt->execute([$assessmentId, $classId, $subjectId])) {
                        throw new Exception('Failed to remove assessment from class');
                    }

                    // Check if the assessment is still associated with any classes
                    $stmt = $db->prepare("SELECT COUNT(*) FROM AssessmentClasses WHERE assessment_id = ?");
                    $stmt->execute([$assessmentId]);
                    if ($stmt->fetchColumn() == 0) {
                        // If no more associations, delete the entire assessment and its questions
                        $stmt = $db->prepare("DELETE FROM Questions WHERE assessment_id = ?");
                        $stmt->execute([$assessmentId]);

                        $stmt = $db->prepare("DELETE FROM Assessments WHERE assessment_id = ?");
                        if (!$stmt->execute([$assessmentId])) {
                            throw new Exception('Failed to delete assessment');
                        }
                        $message = 'Assessment deleted completely as it is no longer assigned to any class';
                    } else {
                        $message = 'Assessment removed from this class successfully';
                    }

                    $db->commit();
                    $_SESSION['success'] = $message;
                    break;

                case 'delete':
                    $assessmentId = sanitizeInput($_POST['assessment_id']);

                    $db->beginTransaction();

                    // Verify teacher owns this assessment and it's pending
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM Assessments a
                         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                         JOIN TeacherClassAssignments tca ON ac.class_id = tca.class_id 
                              AND ac.subject_id = tca.subject_id
                         WHERE a.assessment_id = ? AND tca.teacher_id = ? 
                         AND a.status = 'pending' AND a.semester_id = ? AND tca.semester_id = ?"
                    );
                    $stmt->execute([$assessmentId, $teacherId, $semesterId, $semesterId]);
                    if ($stmt->fetchColumn() == 0) {
                        throw new Exception('Not authorized to delete this assessment');
                    }

                    // Delete related records
                    // Since foreign keys with ON DELETE CASCADE are used, we only need to delete the assessment
                    // AssessmentAttempts, Results, StudentAnswers, etc. will be automatically deleted
                    $stmt = $db->prepare("DELETE FROM Assessments WHERE assessment_id = ?");
                    if (!$stmt->execute([$assessmentId])) {
                        throw new Exception('Failed to delete assessment');
                    }

                    $db->commit();
                    $_SESSION['success'] = 'Assessment deleted successfully from all classes';
                    break;
            }

            // Redirect after successful action with semester parameter
            $redirectUrl = $_SERVER['PHP_SELF'];
            if (isset($_GET['semester'])) {
                $redirectUrl .= '?semester=' . urlencode($_GET['semester']);
            }
            header("Location: " . $redirectUrl);
            ob_end_clean();
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e->getMessage();
            logError("Assessment management error: " . $e->getMessage());

            $redirectUrl = $_SERVER['PHP_SELF'];
            if (isset($_GET['semester'])) {
                $redirectUrl .= '?semester=' . urlencode($_GET['semester']);
            }
            header("Location: " . $redirectUrl);
            ob_end_clean();
            exit;
        }
    }

    // Fetch assessments with class and subject information including question pool data
    $stmt = $db->prepare(
        "SELECT a.assessment_id, 
                a.title, 
                a.description, 
                a.date, 
                a.end_time,
                a.status, 
                a.created_at,
                a.use_question_limit,
                a.questions_to_answer,
                at.type_name,
                at.type_id,
                c.class_id,
                c.class_name, 
                s.subject_id,
                s.subject_name,
                COUNT(DISTINCT q.question_id) as question_count,
                (SELECT COUNT(DISTINCT r.result_id) FROM Results r 
                 JOIN Students st ON r.student_id = st.student_id
                 WHERE r.assessment_id = a.assessment_id AND st.class_id = c.class_id) as submission_count,
                (SELECT COUNT(*) FROM Students WHERE class_id = c.class_id) as total_students
         FROM Assessments a
         LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Classes c ON ac.class_id = c.class_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id 
              AND ac.subject_id = tca.subject_id
         LEFT JOIN Questions q ON a.assessment_id = q.assessment_id
         WHERE tca.teacher_id = ? AND a.semester_id = ? AND tca.semester_id = ?
         GROUP BY a.assessment_id, a.title, a.description, a.date, a.status, 
                  a.created_at, at.type_name, at.type_id, c.class_id, c.class_name, s.subject_id, s.subject_name, 
                  a.end_time, a.use_question_limit, a.questions_to_answer
         ORDER BY a.date DESC, a.created_at DESC"
    );
    $stmt->execute([$teacherId, $semesterId, $semesterId]);
    $assessments = $stmt->fetchAll();
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    logError("Assessment page load error: " . $e->getMessage());
}

$pageTitle = 'Manage Assessments';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <style>
        .page-gradient {
            min-height: 100vh;
            padding: 1rem;
            position: relative;
        }

        .page-gradient::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            z-index: 0;
        }

        .page-content {
            position: relative;
            z-index: 1;
        }

        .header-gradient {
            background: linear-gradient(90deg, #000000 0%, #ffd700 50%, #ffeb80 100%);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header-gradient h1 {
            color: white;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
            margin: 0;
        }

        .card {
            background: linear-gradient(45deg, rgba(0, 0, 0, 0.02) 0%, #ffffff 100%);
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
            border: none;
            color: white;
            font-weight: 500;
        }

        .btn-custom {
            background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
            border: none;
            color: white;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-custom:hover {
            background: linear-gradient(45deg, #ffd700 0%, #000000 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .status-select {
            background: linear-gradient(90deg, #ffffff 0%, #fff9e6 100%);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .table thead {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.05) 0%, #fff9e6 100%);
        }

        .progress {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.1) 0%, #e5e5e5 100%);
            height: 8px;
            border-radius: 4px;
        }

        .progress-bar {
            background: linear-gradient(90deg, #000000 0%, #ffd700 100%);
        }

        .badge {
            background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
            color: white;
            padding: 0.5em 1em;
        }

        .badge-pool {
            background: linear-gradient(45deg, #ff6b35 0%, #ff8e53 100%);
            color: white;
            font-size: 0.85em;
        }

        .action-btn {
            background: linear-gradient(45deg, rgba(0, 0, 0, 0.05) 0%, #fff4b8 100%);
            border: 1px solid #ffd700;
            color: #000000;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: linear-gradient(45deg, #000000 0%, #ffd700 100%);
            color: white;
            transform: translateY(-1px);
        }

        .delete-btn {
            background: linear-gradient(45deg, rgba(0, 0, 0, 0.05) 0%, #ffe6e6 100%);
            border: 1px solid #ff4d4d;
            color: #ff4d4d;
        }

        .delete-btn:hover {
            background: linear-gradient(45deg, #ff4d4d 0%, #000000 100%);
            color: white;
        }

        .table tr:hover {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.02) 0%, rgba(255, 215, 0, 0.05) 100%);
        }

        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .dropdown-item {
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: linear-gradient(45deg, #f9f9f9 0%, #fff9e6 100%);
        }

        .dropdown-item.danger:hover {
            background: linear-gradient(45deg, #fff9f9 0%, #ffe6e6 100%);
            color: #ff4d4d;
        }

        .semester-selector {
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 249, 230, 0.95) 100%);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        .semester-selector .form-select {
            background: linear-gradient(90deg, #ffffff 0%, #fff9e6 100%);
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .semester-selector .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
        }

        .question-pool-info {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            align-items: center;
        }

        .pool-label {
            font-size: 0.75em;
            background: rgba(255, 107, 53, 0.1);
            color: #d63384;
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid rgba(255, 107, 53, 0.2);
        }

        @media (max-width: 768px) {
            .card {
                margin-bottom: 1rem;
            }

            .header-gradient {
                padding: 1rem;
            }

            .question-pool-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
            }
        }
    </style>

    <div class="page-gradient">
        <div class="page-content">
            <div class="header-gradient d-flex justify-content-between align-items-center">
                <h1 class="h3">Manage Assessments</h1>
                <div class="d-flex gap-2">
                    <a href="<?php echo addSemesterToUrl('create_assessment.php', $semesterId); ?>" class="btn btn-custom">
                        <i class="fas fa-plus-circle me-2"></i>Create New Assessment
                    </a>
                    <a href="<?php echo addSemesterToUrl('edit_assessment.php', $semesterId); ?>" class="btn btn-custom">
                        <i class="fas fa-edit me-2"></i>Edit Assessment
                    </a>
                </div>
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

            <?php
            // Render semester selector
            renderSemesterSelector($currentSemester, $allSemesters);
            ?>

            <?php
            // Group assessments by class and subject
            $groupedAssessments = [];
            foreach ($assessments as $assessment) {
                $key = $assessment['class_name'] . ' - ' . $assessment['subject_name'];
                if (!isset($groupedAssessments[$key])) {
                    $groupedAssessments[$key] = [
                        'class_name' => $assessment['class_name'],
                        'subject_name' => $assessment['subject_name'],
                        'assessments' => []
                    ];
                }
                $groupedAssessments[$key]['assessments'][] = $assessment;
            }
            ?>

            <div class="row g-4">
                <?php foreach ($groupedAssessments as $group): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-graduation-cap me-2"></i>
                                    <?php echo htmlspecialchars($group['class_name']); ?> -
                                    <?php echo htmlspecialchars($group['subject_name']); ?>
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="px-3">Title</th>
                                                <th class="px-3 d-none d-sm-table-cell">Type</th>
                                                <th class="px-3 d-none d-md-table-cell">Date</th>
                                                <th class="px-3 d-none d-lg-table-cell">Questions</th>
                                                <th class="px-3">Submissions</th>
                                                <th class="px-3">Status</th>
                                                <th class="px-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($group['assessments'] as $assessment):
                                                $hasExpired = hasAssessmentExpired($assessment['date'], $assessment['end_time']);
                                            ?>
                                                <tr>
                                                    <td class="px-3">
                                                        <div class="d-flex flex-column">
                                                            <span class="fw-medium"><?php echo htmlspecialchars($assessment['title']); ?></span>
                                                            <?php if ($assessment['description']): ?>
                                                                <small class="text-muted d-none d-md-block">
                                                                    <?php echo htmlspecialchars(mb_strimwidth($assessment['description'], 0, 50, '...')); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 d-none d-sm-table-cell">
                                                        <?php if ($assessment['type_name']): ?>
                                                            <span class="badge bg-secondary">
                                                                <?php echo htmlspecialchars($assessment['type_name']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-3 d-none d-md-table-cell">
                                                        <?php
                                                        $date = strtotime($assessment['date']);
                                                        $today = strtotime('today');
                                                        $dateClass = $date < $today ? 'text-danger' : ($date == $today ? 'text-success' : 'text-dark');
                                                        ?>
                                                        <span class="<?php echo $dateClass; ?>">
                                                            <?php echo date('M d, Y', $date); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-3 d-none d-lg-table-cell">
                                                        <div class="question-pool-info">
                                                            <?php if ($assessment['use_question_limit'] && $assessment['questions_to_answer']): ?>
                                                                <span class="badge badge-pool">
                                                                    <i class="fas fa-layer-group me-1"></i>
                                                                    <?php echo $assessment['questions_to_answer']; ?>/<?php echo $assessment['question_count']; ?>
                                                                </span>
                                                                <span class="pool-label">Pool</span>
                                                            <?php else: ?>
                                                                <span class="badge">
                                                                    <?php echo $assessment['question_count']; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3">
                                                        <div style="min-width: 120px;">
                                                            <div class="progress">
                                                                <?php
                                                                $submissionRate = $assessment['total_students'] ?
                                                                    ($assessment['submission_count'] / $assessment['total_students']) * 100 : 0;
                                                                ?>
                                                                <div class="progress-bar"
                                                                    style="width: <?php echo $submissionRate; ?>%"></div>
                                                            </div>
                                                            <small class="mt-1 d-block text-center">
                                                                <?php echo $assessment['submission_count']; ?>/<?php echo $assessment['total_students']; ?>
                                                            </small>
                                                        </div>
                                                    </td>
                                                    <td class="px-3">
                                                        <select class="form-select form-select-sm status-select"
                                                            data-assessment-id="<?php echo $assessment['assessment_id']; ?>"
                                                            <?php echo ($hasExpired || $assessment['status'] === 'completed') ? 'disabled' : ''; ?>>
                                                            <option value="pending" <?php echo $assessment['status'] === 'pending' ? 'selected' : ''; ?>>
                                                                Pending
                                                            </option>
                                                            <option value="completed" <?php echo $assessment['status'] === 'completed' ? 'selected' : ''; ?>>
                                                                Completed
                                                            </option>
                                                            <option value="archived" <?php echo $assessment['status'] === 'archived' ? 'selected' : ''; ?>>
                                                                Archived
                                                            </option>
                                                        </select>
                                                    </td>
                                                    <td class="px-3">
                                                        <div class="dropdown">
                                                            <button class="btn btn-sm action-btn dropdown-toggle" type="button" id="actionDropdown<?php echo $assessment['assessment_id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                                <i class="fas fa-ellipsis-v"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="actionDropdown<?php echo $assessment['assessment_id']; ?>">
                                                                <?php if (!$hasExpired && $assessment['status'] === 'pending'): ?>
                                                                    <li>
                                                                        <a class="dropdown-item" href="<?php echo addSemesterToUrl('manage_questions.php?id=' . $assessment['assessment_id'], $semesterId); ?>">
                                                                            <i class="fas fa-edit me-2"></i> Edit Questions
                                                                        </a>
                                                                    </li>
                                                                <?php endif; ?>

                                                                <li>
                                                                    <a class="dropdown-item" href="<?php echo addSemesterToUrl('view_results.php?id=' . $assessment['assessment_id'] . '&class_id=' . $assessment['class_id'], $semesterId); ?>">
                                                                        <i class="fas fa-chart-bar me-2"></i> View Results
                                                                    </a>
                                                                </li>

                                                                <?php if (!$hasExpired && $assessment['status'] === 'pending'): ?>
                                                                    <li>
                                                                        <a class="dropdown-item danger" href="#"
                                                                            onclick="event.preventDefault(); deleteAssessmentFromClass(
                                                                                <?php echo $assessment['assessment_id']; ?>, 
                                                                                <?php echo $assessment['class_id']; ?>, 
                                                                                <?php echo $assessment['subject_id']; ?>
                                                                            )">
                                                                            <i class="fas fa-minus-circle me-2"></i> Remove from This Class
                                                                        </a>
                                                                    </li>

                                                                    <li>
                                                                        <hr class="dropdown-divider">
                                                                    </li>

                                                                    <li>
                                                                        <a class="dropdown-item danger" href="#"
                                                                            onclick="event.preventDefault(); deleteAssessment(<?php echo $assessment['assessment_id']; ?>)">
                                                                            <i class="fas fa-trash me-2"></i> Delete from All Classes
                                                                        </a>
                                                                    </li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Status changes handler
        document.querySelectorAll('.status-select').forEach(select => {
            select.addEventListener('change', function() {
                if (this.disabled) return;
                const assessmentId = this.dataset.assessmentId;
                const newStatus = this.value;

                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo generateCSRFToken(); ?>';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'update_status';

                const assessmentInput = document.createElement('input');
                assessmentInput.type = 'hidden';
                assessmentInput.name = 'assessment_id';
                assessmentInput.value = assessmentId;

                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = newStatus;

                form.appendChild(csrfInput);
                form.appendChild(actionInput);
                form.appendChild(assessmentInput);
                form.appendChild(statusInput);

                document.body.appendChild(form);
                form.submit();
            });
        });

        // Delete assessment from a specific class function
        window.deleteAssessmentFromClass = function(assessmentId, classId, subjectId) {
            if (confirm('Are you sure you want to remove this assessment from this class only? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo generateCSRFToken(); ?>';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_from_class';

                const assessmentInput = document.createElement('input');
                assessmentInput.type = 'hidden';
                assessmentInput.name = 'assessment_id';
                assessmentInput.value = assessmentId;

                const classInput = document.createElement('input');
                classInput.type = 'hidden';
                classInput.name = 'class_id';
                classInput.value = classId;

                const subjectInput = document.createElement('input');
                subjectInput.type = 'hidden';
                subjectInput.name = 'subject_id';
                subjectInput.value = subjectId;

                form.appendChild(csrfInput);
                form.appendChild(actionInput);
                form.appendChild(assessmentInput);
                form.appendChild(classInput);
                form.appendChild(subjectInput);

                document.body.appendChild(form);
                form.submit();
            }
        };

        // Delete assessment from all classes function
        window.deleteAssessment = function(assessmentId) {
            if (confirm('Are you sure you want to delete this assessment from ALL classes? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo generateCSRFToken(); ?>';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';

                const assessmentInput = document.createElement('input');
                assessmentInput.type = 'hidden';
                assessmentInput.name = 'assessment_id';
                assessmentInput.value = assessmentId;

                form.appendChild(csrfInput);
                form.appendChild(actionInput);
                form.appendChild(assessmentInput);

                document.body.appendChild(form);
                form.submit();
            }
        };

        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 5000);

        });
    });
</script>

<?php
function hasAssessmentExpired($date, $endTime)
{
    $assessmentEndDateTime = new DateTime($date . ' ' . $endTime);
    $currentDateTime = new DateTime();
    return $currentDateTime > $assessmentEndDateTime;
}

function calculateTimeDifferenceInMinutes($startTime, $endTime)
{
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $interval = $start->diff($end);
    return ($interval->h * 60) + $interval->i;
}

ob_end_flush();
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>