<?php
// teacher/index.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/teacher_semester_selector.php';

// Ensure user is logged in and has teacher role
requireRole('teacher');

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get current/selected semester using shared component
    $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
    $allSemesters = getAllSemestersForTeacher($db);

    // Get teacher basic info and stats - classes assigned to teacher, assessments filtered by current semester
    $stmt = $db->prepare("
        SELECT t.*, 
            COUNT(DISTINCT tca.class_id) as total_classes,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT CASE WHEN a.semester_id = ? THEN a.assessment_id END) as total_assessments
        FROM teachers t
        LEFT JOIN teacherclassassignments tca ON t.teacher_id = tca.teacher_id
        LEFT JOIN students s ON tca.class_id = s.class_id
        LEFT JOIN assessmentclasses ac ON tca.class_id = ac.class_id AND tca.subject_id = ac.subject_id
        LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id
        WHERE t.user_id = ?
        GROUP BY t.teacher_id
    ");
    $stmt->execute([$currentSemester['semester_id'], $_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacherInfo) {
        throw new Exception("Teacher information not found");
    }
    
    // Ensure we have valid counts (in case teacher has no assignments this semester)
    $teacherInfo['total_classes'] = $teacherInfo['total_classes'] ?? 0;
    $teacherInfo['total_students'] = $teacherInfo['total_students'] ?? 0;
    $teacherInfo['total_assessments'] = $teacherInfo['total_assessments'] ?? 0;

    // Get upcoming assessments
    $stmt = $db->prepare("
        SELECT a.*, c.class_name, s.subject_name,
            (SELECT COUNT(*) FROM students WHERE class_id = c.class_id) as total_students,
            (SELECT COUNT(*) FROM assessmentattempts WHERE assessment_id = a.assessment_id) as attempts_count
        FROM assessments a
        JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
        JOIN teacherclassassignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
        JOIN teachers t ON tca.teacher_id = t.teacher_id
        JOIN classes c ON ac.class_id = c.class_id
        JOIN subjects s ON ac.subject_id = s.subject_id
        WHERE t.user_id = ? 
        AND a.semester_id = ?
        AND (
            (a.date > CURDATE()) OR 
            (a.date = CURDATE() AND TIME(a.end_time) > CURRENT_TIME())
        )
        AND a.status = 'pending'
        ORDER BY a.date ASC, a.start_time ASC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id'], $currentSemester['semester_id']]);
    $upcomingAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get class overview - all assigned classes, assessments filtered by current semester
    $stmt = $db->prepare("
        SELECT c.class_id, c.class_name, s.subject_id, s.subject_name,
            COUNT(DISTINCT st.student_id) as student_count,
            COUNT(DISTINCT CASE WHEN a.semester_id = ? THEN a.assessment_id END) as assessment_count,
            COUNT(DISTINCT CASE WHEN a.semester_id = ? AND a.status = 'completed' THEN a.assessment_id END) as completed_count
        FROM teacherclassassignments tca
        JOIN teachers t ON tca.teacher_id = t.teacher_id
        JOIN classes c ON tca.class_id = c.class_id
        JOIN subjects s ON tca.subject_id = s.subject_id
        LEFT JOIN students st ON c.class_id = st.class_id
        LEFT JOIN assessmentclasses ac ON tca.class_id = ac.class_id AND tca.subject_id = ac.subject_id
        LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id
        WHERE t.user_id = ?
        GROUP BY c.class_id, s.subject_id
        ORDER BY c.class_name, s.subject_name
    ");
    $stmt->execute([
        $currentSemester['semester_id'],
        $currentSemester['semester_id'],
        $_SESSION['user_id']
    ]);
    $classOverview = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group assessments by class and subject for current semester
    $stmt = $db->prepare("
        SELECT c.class_id, c.class_name, s.subject_id, s.subject_name, a.*,
            (SELECT COUNT(*) FROM students WHERE class_id = c.class_id) as total_students,
            (SELECT COUNT(*) FROM assessmentattempts WHERE assessment_id = a.assessment_id AND status = 'completed') as completed_attempts,
            (SELECT AVG(score) FROM results WHERE assessment_id = a.assessment_id AND status = 'completed') as average_score
        FROM assessments a
        JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
        JOIN teacherclassassignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
        JOIN teachers t ON tca.teacher_id = t.teacher_id
        JOIN classes c ON ac.class_id = c.class_id
        JOIN subjects s ON ac.subject_id = s.subject_id
        WHERE t.user_id = ? 
        AND a.semester_id = ?
        ORDER BY c.class_name, s.subject_name, a.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $currentSemester['semester_id']]);
    $allAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group assessments
    $groupedAssessments = [];
    foreach ($allAssessments as $assessment) {
        $key = $assessment['class_id'] . '-' . $assessment['subject_id'];
        if (!isset($groupedAssessments[$key])) {
            $groupedAssessments[$key] = [
                'class_name' => $assessment['class_name'],
                'subject_name' => $assessment['subject_name'],
                'assessments' => []
            ];
        }
        // Only keep the 5 most recent assessments per group
        if (count($groupedAssessments[$key]['assessments']) < 5) {
            $groupedAssessments[$key]['assessments'][] = $assessment;
        }
    }
} catch (Exception $e) {
    logError("Teacher Dashboard Error: " . $e->getMessage());
    $error = $e->getMessage();
}

$pageTitle = "Teacher Dashboard";
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    :root {
        --primary-yellow: #ffd700;
        --dark-yellow: #ccac00;
        --light-yellow: #fff7cc;
        --black: #000000;
        --white: #ffffff;
    }

    .dashboard-container {
        padding: 1.5rem;
    }

    .page-header {
        background: linear-gradient(135deg, var(--black), var(--primary-yellow));
        padding: 1.5rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
        color: var(--white);
    }

    .stats-card {
        background: var(--white);
        border-radius: 0.5rem;
        padding: 1.25rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        height: 100%;
        border-left: 4px solid var(--primary-yellow);
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: translateY(-3px);
    }

    .stats-icon {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, var(--black), var(--primary-yellow));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        margin-bottom: 1rem;
    }

    .section-card {
        background: var(--white);
        border-radius: 0.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        margin-bottom: 1.5rem;
    }

    .section-header {
        background: linear-gradient(90deg, var(--black), var(--primary-yellow));
        color: var(--white);
        padding: 1rem 1.5rem;
        border-radius: 0.5rem 0.5rem 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .btn-custom {
        background: linear-gradient(135deg, var(--black), var(--primary-yellow));
        color: var(--white);
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 0.25rem;
        transition: all 0.2s;
    }

    .btn-custom:hover {
        transform: translateY(-1px);
        color: var(--white);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .assessment-item {
        padding: 1rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        transition: background-color 0.2s;
    }

    .assessment-item:hover {
        background-color: var(--light-yellow);
    }

    .progress {
        height: 6px;
        background-color: rgba(0, 0, 0, 0.05);
    }

    .progress-bar {
        background: linear-gradient(90deg, var(--black), var(--primary-yellow));
    }

    .badge-custom {
        background: linear-gradient(135deg, var(--black), var(--primary-yellow));
        color: var(--white);
        padding: 0.4em 0.8em;
        border-radius: 1rem;
        font-size: 0.75rem;
    }

    /* Table Styles */
    .table thead th {
        background: linear-gradient(90deg, rgba(0, 0, 0, 0.05), var(--light-yellow));
        border: none;
    }

    .table td {
        vertical-align: middle;
    }

    /* Mobile Card View Styles */
    .mobile-class-list .class-card {
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }

    .mobile-class-list .class-card:last-child {
        border-bottom: none;
    }

    /* Accordion Styles */
    .accordion-item {
        background: transparent;
    }

    .accordion-button {
        background: transparent;
        padding: 1rem;
        font-weight: 500;
    }

    .accordion-button:not(.collapsed) {
        background: var(--light-yellow);
        color: var(--black);
        box-shadow: none;
    }

    .accordion-button:focus {
        box-shadow: none;
        border-color: rgba(0, 0, 0, 0.1);
    }

    .accordion-button::after {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23000'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
    }

    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .dashboard-container {
            padding: 1rem;
        }

        .page-header {
            padding: 1rem;
            margin: -1rem -1rem 1rem -1rem;
            border-radius: 0;
        }

        /* Row and column adjustments */
        .row.g-3.mb-4 {
            margin-bottom: 0 !important;
        }

        .col-12.col-sm-6.col-lg-3 {
            width: 50%;
            padding: 0.5rem;
        }

        /* Stats card adjustments */
        .stats-card {
            margin-bottom: 0;
            height: 100%;
            padding: 1rem;
        }

        .stats-icon {
            width: 40px;
            height: 40px;
        }

        .stats-card h6 {
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }

        .stats-card h3 {
            font-size: 1.5rem;
        }

        /* Section adjustments */
        .section-header {
            padding: 0.75rem 1rem;
        }

        .section-header h5 {
            font-size: 1rem;
        }

        .assessment-item {
            padding: 0.75rem;
        }

        /* Table adjustments */
        .table-responsive {
            margin: 0 -1rem;
            width: calc(100% + 2rem);
        }

        /* Mobile class list adjustments */
        .mobile-class-list .class-card {
            padding: 1rem;
        }

        .mobile-class-list .class-card h6 {
            font-size: 0.9rem;
        }

        /* Button adjustments */
        .btn-custom {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }

        /* Progress bar adjustments */
        .progress {
            height: 4px;
        }
    }

    /* Extra Small Device Optimizations */
    @media (max-width: 375px) {
        .col-12.col-sm-6.col-lg-3 {
            width: 50%;
        }

        .stats-card {
            padding: 0.75rem;
        }

        .stats-card h6 {
            font-size: 0.75rem;
        }

        .stats-card h3 {
            font-size: 1.25rem;
        }

        .stats-icon {
            width: 32px;
            height: 32px;
            margin-bottom: 0.5rem;
        }

        .section-header h5 {
            font-size: 0.9rem;
        }

        .badge-custom {
            font-size: 0.7rem;
            padding: 0.3em 0.6em;
        }
    }

    /* Print Styles */
    @media print {
        .dashboard-container {
            padding: 0;
        }

        .btn-custom,
        .stats-card:hover {
            transform: none;
        }

        .section-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .progress-bar {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
</style>

<div class="dashboard-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h3 mb-1">Welcome, <?php echo htmlspecialchars($teacherInfo['first_name']); ?>!</h1>
                <p class="mb-0">
                    Viewing Semester: <?php echo htmlspecialchars($currentSemester['semester_name']); ?>
                    <?php if ($currentSemester['is_double_track']): ?>
                        <span class="badge bg-warning text-dark ms-2">Double Track</span>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo addSemesterToUrl('create_assessment.php', $currentSemester['semester_id']); ?>" class="btn btn-custom">
                <i class="fas fa-plus-circle me-2"></i>New Assessment
            </a>
        </div>
        
    </div>

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <h6 class="text-uppercase mb-2">Classes</h6>
                <h3 class="mb-0"><?php echo $teacherInfo['total_classes']; ?></h3>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h6 class="text-uppercase mb-2">Students</h6>
                <h3 class="mb-0"><?php echo $teacherInfo['total_students']; ?></h3>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <h6 class="text-uppercase mb-2">Assessments</h6>
                <h3 class="mb-0"><?php echo $teacherInfo['total_assessments']; ?></h3>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h6 class="text-uppercase mb-2">Active Tests</h6>
                <h3 class="mb-0"><?php echo count($upcomingAssessments); ?></h3>
            </div>
        </div>
    </div>

    <!-- Class Overview -->
    <div class="section-card mb-4">
        <div class="section-header">
            <h5 class="mb-0">
                <i class="fas fa-chalkboard-teacher me-2"></i>Class Overview
            </h5>
        </div>

        <!-- Desktop Table View -->
        <div class="d-none d-lg-block">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Subject</th>
                            <th class="text-center">Students</th>
                            <th class="text-center">Tests</th>
                            <th class="text-center">Progress</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classOverview as $class):
                            $progressPercentage = $class['assessment_count'] > 0 ?
                                ($class['completed_count'] / $class['assessment_count']) * 100 : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-graduation-cap text-warning me-2"></i>
                                        <span><?php echo htmlspecialchars($class['class_name']); ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($class['subject_name']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark">
                                        <?php echo $class['student_count']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark">
                                        <?php echo $class['assessment_count']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="progress mx-auto" style="width: 100px;">
                                        <div class="progress-bar"
                                            style="width: <?php echo $progressPercentage; ?>%"
                                            title="<?php echo $class['completed_count']; ?>/<?php echo $class['assessment_count']; ?> completed">
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="class_details.php?id=<?php echo $class['class_id']; ?>&subject_id=<?php echo $class['subject_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                        class="btn btn-sm btn-custom">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile Card View -->
        <div class="d-lg-none">
            <div class="mobile-class-list">
                <?php foreach ($classOverview as $class):
                    $progressPercentage = $class['assessment_count'] > 0 ?
                        ($class['completed_count'] / $class['assessment_count']) * 100 : 0;
                ?>
                    <div class="class-card p-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="mb-1 d-flex align-items-center">
                                    <i class="fas fa-graduation-cap text-warning me-2"></i>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </h6>
                                <p class="mb-2 text-muted"><?php echo htmlspecialchars($class['subject_name']); ?></p>
                            </div>
                            <a href="class_details.php?id=<?php echo $class['class_id']; ?>&subject_id=<?php echo $class['subject_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                class="btn btn-sm btn-custom">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <div class="px-3 py-2 bg-light rounded text-center">
                                    <small class="d-block text-muted mb-1">Students</small>
                                    <span class="fw-medium"><?php echo $class['student_count']; ?></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="px-3 py-2 bg-light rounded text-center">
                                    <small class="d-block text-muted mb-1">Tests</small>
                                    <span class="fw-medium"><?php echo $class['assessment_count']; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="progress mb-1" style="height: 6px;">
                            <div class="progress-bar"
                                style="width: <?php echo $progressPercentage; ?>%"
                                title="<?php echo $class['completed_count']; ?>/<?php echo $class['assessment_count']; ?> completed">
                            </div>
                        </div>
                        <div class="text-center small text-muted">
                            <?php echo $class['completed_count']; ?>/<?php echo $class['assessment_count']; ?> tests completed
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Recent Assessments -->
    <div class="row">
        <!-- Upcoming Assessments -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>Upcoming Assessments
                    </h5>
                    <span class="badge bg-white text-dark">Next 5</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingAssessments)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                            <p class="mb-0">No upcoming assessments</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcomingAssessments as $assessment): ?>
                            <div class="assessment-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($assessment['title']); ?></h6>
                                        <p class="mb-1 small">
                                            <i class="fas fa-graduation-cap me-1"></i>
                                            <?php echo htmlspecialchars($assessment['class_name']); ?> -
                                            <?php echo htmlspecialchars($assessment['subject_name']); ?>
                                        </p>
                                    </div>
                                    <span class="badge-custom">
                                        <?php echo date('M d', strtotime($assessment['date'])); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center small">
                                    <span class="text-muted">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('h:i A', strtotime($assessment['start_time'])); ?> -
                                        <?php echo date('h:i A', strtotime($assessment['end_time'])); ?>
                                    </span>
                                    <a href="edit_assessment.php?id=<?php echo $assessment['assessment_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                        class="btn btn-sm btn-custom">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Assessments by Class -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Assessments
                    </h5>
                    <a href="assessments.php?semester=<?php echo $currentSemester['semester_id']; ?>" class="btn btn-sm btn-custom">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($groupedAssessments)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <p class="mb-0">No recent assessments</p>
                        </div>
                    <?php else: ?>
                        <div class="accordion" id="recentAssessmentsAccordion">
                            <?php foreach ($groupedAssessments as $groupKey => $group): ?>
                                <div class="accordion-item border-0">
                                    <h2 class="accordion-header" id="heading<?php echo $groupKey; ?>">
                                        <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#collapse<?php echo $groupKey; ?>">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            <?php echo htmlspecialchars($group['class_name']); ?> -
                                            <?php echo htmlspecialchars($group['subject_name']); ?>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo $groupKey; ?>"
                                        class="accordion-collapse collapse"
                                        data-bs-parent="#recentAssessmentsAccordion">
                                        <div class="accordion-body p-0">
                                            <?php foreach ($group['assessments'] as $assessment):
                                                $completionRate = $assessment['total_students'] > 0 ?
                                                    ($assessment['completed_attempts'] / $assessment['total_students']) * 100 : 0;
                                            ?>
                                                <div class="assessment-item">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($assessment['title']); ?></h6>
                                                            <p class="mb-1 small text-muted">
                                                                <i class="far fa-calendar me-1"></i>
                                                                <?php echo date('M d, Y', strtotime($assessment['date'])); ?>
                                                            </p>
                                                        </div>
                                                        <span class="badge-custom">
                                                            <?php echo ucfirst($assessment['status']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="mb-2">
                                                        <div class="progress mb-1">
                                                            <div class="progress-bar"
                                                                style="width: <?php echo $completionRate; ?>%">
                                                            </div>
                                                        </div>
                                                        <div class="d-flex justify-content-between align-items-center small text-muted">
                                                            <span>
                                                                <i class="fas fa-users me-1"></i>
                                                                <?php echo $assessment['completed_attempts']; ?>/<?php echo $assessment['total_students']; ?>
                                                                Submissions
                                                            </span>
                                                            <?php if (isset($assessment['average_score'])): ?>
                                                                <span class="text-success">
                                                                    <i class="fas fa-chart-line me-1"></i>
                                                                    <?php echo number_format($assessment['average_score'], 1); ?>% Avg
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex justify-content-end gap-2">
                                                        <a href="view_results.php?id=<?php echo $assessment['assessment_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                                            class="btn btn-sm btn-custom">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </a>
                                                        <?php if ($assessment['status'] === 'pending'): ?>
                                                            <a href="edit_assessment.php?id=<?php echo $assessment['assessment_id']; ?>&semester=<?php echo $currentSemester['semester_id']; ?>"
                                                                class="btn btn-sm btn-custom">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Open first accordion item by default
        const firstAccordionButton = document.querySelector('.accordion-button');
        const firstAccordionCollapse = document.querySelector('.accordion-collapse');
        if (firstAccordionButton && firstAccordionCollapse) {
            firstAccordionButton.classList.remove('collapsed');
            firstAccordionCollapse.classList.add('show');
        }

        // Add touch support for cards on mobile
        if ('ontouchstart' in window) {
            const cards = document.querySelectorAll('.stats-card, .assessment-item');
            cards.forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                card.addEventListener('touchend', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        }

        // Initialize Bootstrap tooltips
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });

        // Remove any default show classes from accordion items
        const accordionItems = document.querySelectorAll('.accordion-collapse');
        accordionItems.forEach(item => {
            item.classList.remove('show');
        });
        const accordionButtons = document.querySelectorAll('.accordion-button');
        accordionButtons.forEach(button => {
            button.classList.add('collapsed');
        });
    });
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>