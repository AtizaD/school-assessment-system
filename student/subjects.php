<?php
// student/subjects.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // First get student info
    $stmt = $db->prepare(
        "SELECT s.student_id, s.class_id, c.class_name, p.program_name 
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

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id FROM semesters 
         WHERE CURRENT_DATE BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    
    if (!$currentSemester) {
        // Fallback to most recent semester if no current one
        $stmt = $db->prepare(
            "SELECT semester_id FROM semesters 
             ORDER BY end_date DESC LIMIT 1"
        );
        $stmt->execute();
        $currentSemester = $stmt->fetch();
    }
    
    $semesterId = $currentSemester ? $currentSemester['semester_id'] : null;

    // Updated subjects query with total scores - includes both regular class subjects and special enrollments
    $stmt = $db->prepare(
        "SELECT DISTINCT
            s.subject_id,
            s.subject_name,
            s.description,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                ELSE ?
            END as enrolled_class_id,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN 'special'
                ELSE 'regular'
            END as enrollment_type,
            sc.notes as special_notes,
            (
                SELECT COUNT(DISTINCT a.assessment_id)
                FROM assessments a
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE ?
                END
                AND ac.subject_id = s.subject_id
                AND a.semester_id = ?
            ) as total_assessments,
            (
                SELECT COUNT(DISTINCT r.assessment_id)
                FROM results r
                JOIN assessments a ON r.assessment_id = a.assessment_id
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE ?
                END
                AND ac.subject_id = s.subject_id
                AND r.student_id = ?
                AND r.status = 'completed'
                AND a.semester_id = ?
            ) as completed_assessments,
            (
                SELECT AVG(r.score)
                FROM results r
                JOIN assessments a ON r.assessment_id = a.assessment_id
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE ?
                END
                AND ac.subject_id = s.subject_id
                AND r.student_id = ?
                AND r.status = 'completed'
                AND a.semester_id = ?
            ) as average_score,
            (
                SELECT SUM(r.score)
                FROM results r
                JOIN assessments a ON r.assessment_id = a.assessment_id
                JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE ac.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE ?
                END
                AND ac.subject_id = s.subject_id
                AND r.student_id = ?
                AND r.status = 'completed'
                AND a.semester_id = ?
            ) as total_score,
            (
                SELECT GROUP_CONCAT(CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ')
                FROM teacherclassassignments tca
                JOIN teachers t ON tca.teacher_id = t.teacher_id
                WHERE tca.class_id = CASE 
                    WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                    ELSE ?
                END
                AND tca.subject_id = s.subject_id
                AND tca.semester_id = ?
            ) as teachers
         FROM subjects s
         LEFT JOIN classsubjects cs ON s.subject_id = cs.subject_id AND cs.class_id = ?
         LEFT JOIN special_class sc ON s.subject_id = sc.subject_id 
                                   AND sc.student_id = ? 
                                   AND sc.status = 'active'
         WHERE (cs.class_id IS NOT NULL OR sc.sp_id IS NOT NULL)
         ORDER BY s.subject_name"
    );
    
    // Execute with correct parameters
    $params = [
        $studentInfo['class_id'],    // For enrolled_class_id CASE ELSE
        $studentInfo['class_id'],    // For total_assessments CASE ELSE
        $semesterId,                 // For total_assessments semester filter
        $studentInfo['class_id'],    // For completed_assessments CASE ELSE
        $studentInfo['student_id'],  // For completed_assessments student filter
        $semesterId,                 // For completed_assessments semester filter
        $studentInfo['class_id'],    // For average_score CASE ELSE
        $studentInfo['student_id'],  // For average_score student filter
        $semesterId,                 // For average_score semester filter
        $studentInfo['class_id'],    // For total_score CASE ELSE
        $studentInfo['student_id'],  // For total_score student filter
        $semesterId,                 // For total_score semester filter
        $studentInfo['class_id'],    // For teachers CASE ELSE
        $semesterId,                 // For teachers semester filter
        $studentInfo['class_id'],    // For classsubjects JOIN
        $studentInfo['student_id']   // For special_class JOIN
    ];
    
    $stmt->execute($params);
    $subjects = $stmt->fetchAll();

} catch (Exception $e) {
    logError("Student subjects error: " . $e->getMessage());
    $error = "Error loading subjects data: " . $e->getMessage();
}

$pageTitle = 'My Subjects';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>
<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning">My Subjects</h1>
        <div class="text-muted">
            <?php echo htmlspecialchars($studentInfo['program_name'] ?? ''); ?> | 
            <?php echo htmlspecialchars($studentInfo['class_name'] ?? ''); ?>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($subjects)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No subjects are currently assigned to your class.
        </div>
    <?php else: ?>
        <!-- Subjects Grid -->
        <div class="row g-4">
            <?php foreach ($subjects as $subject): ?>
                <div class="col-xl-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="card-title mb-0 text-warning">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        <?php if ($subject['enrollment_type'] === 'special'): ?>
                                            <span class="badge bg-warning text-dark ms-2" title="Special Enrollment">
                                                <i class="fas fa-star"></i> Special
                                            </span>
                                        <?php endif; ?>
                                    </h5>
                                    <?php if ($subject['enrollment_type'] === 'special' && $subject['special_notes']): ?>
                                        <small class="text-warning-emphasis">
                                            <i class="fas fa-info-circle"></i> 
                                            <?php echo htmlspecialchars($subject['special_notes']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div class="rounded-circle bg-warning bg-opacity-10 p-2">
                                    <i class="fas <?php echo $subject['enrollment_type'] === 'special' ? 'fa-star' : 'fa-book'; ?> text-warning"></i>
                                </div>
                            </div>

                            <!-- Subject Description -->
                            <p class="card-text text-white mb-3">
                                <?php echo htmlspecialchars($subject['description'] ?: 'No description available'); ?>
                            </p>

                            <!-- Teachers -->
                            <div class="mb-3">
                                <div class="d-flex align-items-center">
                                    <span class="text-warning me-2">Teacher:</span>
                                    <?php if ($subject['teachers']): ?>
                                        <span class="text-white">
                                            <?php echo htmlspecialchars($subject['teachers']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-white">No teachers assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Progress Section -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-warning">Progress</span>
                                    <span class="text-white">
                                        <?php echo (int)$subject['completed_assessments']; ?>/<?php echo (int)$subject['total_assessments']; ?> 
                                        Assessments
                                    </span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <?php
                                    $progressPercentage = $subject['total_assessments'] > 0 
                                        ? ($subject['completed_assessments'] / $subject['total_assessments']) * 100 
                                        : 0;
                                    ?>
                                    <div class="progress-bar" 
                                         style="width: <?php echo $progressPercentage; ?>%; background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);"></div>
                                </div>
                            </div>

                            <!-- Scores -->
                            <?php if ($subject['completed_assessments'] > 0): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-warning">Total Score</span>
                                    <span class="badge" style="background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);">
                                        <?php echo number_format($subject['total_score'] ?? 0, 1); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-warning">Average Score</span>
                                    <span class="badge" style="background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);">
                                        <?php echo number_format($subject['average_score'] ?? 0, 1); ?>%
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer border-0" style="background: linear-gradient(145deg, #34495e 0%, #2c3e50 100%);">
                            <div class="d-grid">
                                <a href="subject_detail.php?id=<?php echo $subject['subject_id']; ?>" 
                                   class="btn btn-warning">
                                    <i class="fas fa-info-circle me-2"></i>View Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<style>
.card {
    transition: transform 0.2s;
    margin-bottom: 0.5rem;
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
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>