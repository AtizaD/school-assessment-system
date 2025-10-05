<?php
/**
 * Comprehensive Academic Performance Report Dashboard
 * Complete replacement with enhanced analytics
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once BASEPATH . '/vendor/autoload.php';

    $semester_id = filter_input(INPUT_GET, 'semester_id', FILTER_VALIDATE_INT);
    $class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
    $level = isset($_GET['level']) ? trim($_GET['level']) : '';
    $assessment_type_id = filter_input(INPUT_GET, 'assessment_type_id', FILTER_VALIDATE_INT);

    // Get database connection
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get current semester if none selected
    if (!$semester_id) {
        $stmt = $db->query("SELECT semester_id FROM semesters
                           WHERE CURDATE() BETWEEN start_date AND end_date
                           ORDER BY start_date DESC LIMIT 1");
        $current = $stmt->fetch();
        if (!$current) {
            $stmt = $db->query("SELECT semester_id FROM semesters ORDER BY start_date DESC LIMIT 1");
            $current = $stmt->fetch();
        }
        $semester_id = $current ? $current['semester_id'] : null;
    }

    if (!$semester_id) {
        die('No semester data available for export.');
    }

    // Get filter names for display
    $stmt = $db->prepare("SELECT semester_name FROM semesters WHERE semester_id = ?");
    $stmt->execute([$semester_id]);
    $semester_name = $stmt->fetchColumn();

    $filter_text = "Semester: " . $semester_name;
    if ($level) $filter_text .= " | Level: " . $level;
    if ($class_id) {
        $stmt = $db->prepare("SELECT CONCAT(p.program_name, ' - ', c.class_name) FROM classes c JOIN programs p ON c.program_id = p.program_id WHERE c.class_id = ?");
        $stmt->execute([$class_id]);
        $filter_text .= " | Class: " . $stmt->fetchColumn();
    }
    if ($subject_id) {
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $filter_text .= " | Subject: " . $stmt->fetchColumn();
    }
    if ($assessment_type_id) {
        $stmt = $db->prepare("SELECT type_name FROM assessment_types WHERE type_id = ?");
        $stmt->execute([$assessment_type_id]);
        $filter_text .= " | Type: " . $stmt->fetchColumn();
    }

    require_once BASEPATH . '/admin/export_performance_pdf.php';
    exit;
}

$error = '';
$semester_id = filter_input(INPUT_GET, 'semester_id', FILTER_VALIDATE_INT);
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$level = isset($_GET['level']) ? trim($_GET['level']) : '';
$assessment_type_id = filter_input(INPUT_GET, 'assessment_type_id', FILTER_VALIDATE_INT);

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get filter options
    $stmt = $db->query("SELECT semester_id, semester_name FROM semesters ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();

    $stmt = $db->query("SELECT c.class_id, c.class_name, c.level, p.program_name
                        FROM classes c JOIN programs p ON c.program_id = p.program_id
                        ORDER BY c.level, p.program_name, c.class_name");
    $classes = $stmt->fetchAll();

    // Get subjects with their associated class IDs
    $stmt = $db->query("
        SELECT s.subject_id, s.subject_name,
               GROUP_CONCAT(DISTINCT cs.class_id ORDER BY cs.class_id) as class_ids
        FROM subjects s
        LEFT JOIN classsubjects cs ON s.subject_id = cs.subject_id
        GROUP BY s.subject_id, s.subject_name
        ORDER BY s.subject_name
    ");
    $subjects = $stmt->fetchAll();

    $stmt = $db->query("SELECT type_id, type_name FROM assessment_types WHERE is_active = 1 ORDER BY sort_order");
    $assessment_types = $stmt->fetchAll();

    $stmt = $db->query("SELECT DISTINCT level FROM classes ORDER BY level");
    $levels = $stmt->fetchAll();

    // Get current semester if none selected
    if (!$semester_id) {
        $stmt = $db->query("SELECT semester_id FROM semesters
                           WHERE CURDATE() BETWEEN start_date AND end_date
                           ORDER BY start_date DESC LIMIT 1");
        $current = $stmt->fetch();
        $semester_id = $current ? $current['semester_id'] : ($semesters[0]['semester_id'] ?? null);
    }

} catch (Exception $e) {
    logError("Performance dashboard error: " . $e->getMessage());
    $error = "Error loading dashboard: " . $e->getMessage();
}

$pageTitle = 'Academic Performance Reports';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
.stat-card {
    border-left: 4px solid #ffd700;
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-5px);
}
.report-section {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}
.section-header {
    background: linear-gradient(135deg, #000000 0%, #1a1a1a 100%);
    color: #ffd700;
    padding: 15px 20px;
    border-radius: 8px 8px 0 0;
}
.performance-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}
.badge-excellent { background: #28a745; color: white; }
.badge-good { background: #17a2b8; color: white; }
.badge-average { background: #ffc107; color: #000; }
.badge-poor { background: #dc3545; color: white; }
</style>

<main class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0" style="color: #ffd700;">📊 Academic Performance Reports</h1>
        <div>
            <button class="btn btn-warning" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-danger">
                <i class="fas fa-file-pdf me-2"></i>Export to PDF
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="report-section mb-4">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="p-4">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label class="form-label">Semester</label>
                    <select name="semester_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['semester_id'] ?>" <?= $semester_id == $sem['semester_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sem['semester_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Level</label>
                    <select name="level" class="form-select" id="levelFilter" onchange="filterClasses(); this.form.submit();">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $lvl): ?>
                            <option value="<?= htmlspecialchars($lvl['level']) ?>" <?= $level == $lvl['level'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lvl['level']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Class</label>
                    <select name="class_id" class="form-select" id="classFilter" onchange="filterSubjects(); this.form.submit();">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['class_id'] ?>"
                                    data-level="<?= htmlspecialchars($class['level'] ?? '') ?>"
                                    <?= $class_id == $class['class_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Subject</label>
                    <select name="subject_id" class="form-select" id="subjectFilter" onchange="this.form.submit()">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= $subject['subject_id'] ?>"
                                    data-classes="<?= htmlspecialchars($subject['class_ids'] ?? '') ?>"
                                    <?= $subject_id == $subject['subject_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subject['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Assessment Type</label>
                    <select name="assessment_type_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <?php foreach ($assessment_types as $type): ?>
                            <option value="<?= $type['type_id'] ?>" <?= $assessment_type_id == $type['type_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['type_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($semester_id): ?>

    <!-- Section 1: Semester Performance Overview -->
    <?php
    $where = ["a.semester_id = ?"];
    $params = [$semester_id];
    $joins = "JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id";

    if ($level) {
        $joins .= " JOIN classes cls ON ac.class_id = cls.class_id";
        $where[] = "cls.level = ?";
        $params[] = $level;
    }
    if ($class_id) {
        $where[] = "ac.class_id = ?";
        $params[] = $class_id;
    }
    if ($subject_id) {
        $where[] = "ac.subject_id = ?";
        $params[] = $subject_id;
    }
    if ($assessment_type_id) {
        $where[] = "a.assessment_type_id = ?";
        $params[] = $assessment_type_id;
    }
    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT r.result_id) as total_submissions,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_submissions,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score,
            MIN(CASE WHEN r.status = 'completed' THEN r.score END) as min_score,
            MAX(CASE WHEN r.status = 'completed' THEN r.score END) as max_score,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' AND r.score >= 10 THEN r.result_id END) as passed,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' AND r.score < 10 THEN r.result_id END) as failed
        FROM assessments a
        $joins
        LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.status = 'completed'
        WHERE $whereClause
    ");
    $stmt->execute($params);
    $overview = $stmt->fetch();
    $pass_rate = $overview['completed_submissions'] > 0 ?
        round(($overview['passed'] / $overview['completed_submissions']) * 100, 1) : 0;
    ?>

    <div class="report-section">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>1. Semester Performance Overview</h5>
        </div>
        <div class="p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="stat-card p-3 bg-light">
                        <div class="text-muted small">Total Assessments</div>
                        <h3 class="mb-0"><?= $overview['total_assessments'] ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3 bg-light">
                        <div class="text-muted small">Completed Submissions</div>
                        <h3 class="mb-0"><?= $overview['completed_submissions'] ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3 bg-light">
                        <div class="text-muted small">Pass Rate</div>
                        <h3 class="mb-0 <?= $pass_rate >= 70 ? 'text-success' : ($pass_rate >= 50 ? 'text-warning' : 'text-danger') ?>">
                            <?= $pass_rate ?>%
                        </h3>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-4">
                    <canvas id="passFailChart" style="max-height: 250px;"></canvas>
                </div>
                <div class="col-md-8">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tr><th>Metric</th><th>Value</th></tr>
                            <tr><td>Minimum Score</td><td><?= $overview['min_score'] ?? 'N/A' ?>%</td></tr>
                            <tr><td>Maximum Score</td><td><?= $overview['max_score'] ?? 'N/A' ?>%</td></tr>
                            <tr><td>Passed</td><td class="text-success"><?= $overview['passed'] ?></td></tr>
                            <tr><td>Failed</td><td class="text-danger"><?= $overview['failed'] ?></td></tr>
                            <tr><td>Completion Rate</td><td>
                                <?= $overview['total_submissions'] > 0 ?
                                    round(($overview['completed_submissions'] / $overview['total_submissions']) * 100, 1) : 0 ?>%
                            </td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Class Performance Comparison -->
    <?php
    $whereClass = ["a.semester_id = ?"];
    $paramsClass = [$semester_id];
    if ($subject_id) {
        $whereClass[] = "ac.subject_id = ?";
        $paramsClass[] = $subject_id;
    }
    if ($level) {
        $whereClass[] = "c.level = ?";
        $paramsClass[] = $level;
    }
    if ($assessment_type_id) {
        $whereClass[] = "a.assessment_type_id = ?";
        $paramsClass[] = $assessment_type_id;
    }
    $whereClassClause = implode(' AND ', $whereClass);

    $stmt = $db->prepare("
        SELECT
            c.class_id,
            p.program_name,
            c.class_name,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(r.result_id) as total_submissions,
            COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as completed_submissions,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score,
            COUNT(CASE WHEN r.status = 'completed' AND r.score >= 10 THEN 1 END) as passed,
            COUNT(CASE WHEN r.status = 'completed' AND r.score < 10 THEN 1 END) as failed
        FROM classes c
        JOIN programs p ON c.program_id = p.program_id
        LEFT JOIN students s ON c.class_id = s.class_id
        LEFT JOIN assessmentclasses ac ON c.class_id = ac.class_id AND ac.class_id = c.class_id
        LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id
        LEFT JOIN results r ON s.student_id = r.student_id AND r.assessment_id = a.assessment_id AND r.status = 'completed'
        WHERE $whereClassClause
        GROUP BY c.class_id, p.program_name, c.class_name
        HAVING completed_submissions >= 50
        ORDER BY avg_score DESC
    ");
    $stmt->execute($paramsClass);
    $classPerformance = $stmt->fetchAll();
    ?>

    <div class="report-section">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-school me-2"></i>2. Class Performance Comparison</h5>
        </div>
        <div class="p-4">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Rank</th>
                            <th>Program</th>
                            <th>Class</th>
                            <th>Students</th>
                            <th>Submissions</th>
                            <th>Avg Score</th>
                            <th>Pass Rate</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classPerformance as $idx => $class):
                            $class_pass_rate = $class['completed_submissions'] > 0 ?
                                round((($class['passed']) / $class['completed_submissions']) * 100, 1) : 0;
                            $perfClass = $class['avg_score'] >= 70 ? 'badge-excellent' :
                                        ($class['avg_score'] >= 50 ? 'badge-good' :
                                        ($class['avg_score'] >= 30 ? 'badge-average' : 'badge-poor'));
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars($class['program_name']) ?></td>
                            <td><?= htmlspecialchars($class['class_name']) ?></td>
                            <td><?= $class['total_students'] ?></td>
                            <td><?= $class['total_submissions'] ?></td>
                            <td><strong><?= $class['avg_score'] ?>%</strong></td>
                            <td><?= $class_pass_rate ?>%</td>
                            <td>
                                <span class="performance-badge <?= $perfClass ?>">
                                    <?= $class['avg_score'] >= 70 ? 'Excellent' :
                                       ($class['avg_score'] >= 50 ? 'Good' :
                                       ($class['avg_score'] >= 30 ? 'Average' : 'Needs Improvement')) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 3: Subject Performance Analysis -->
    <?php
    $whereSubj = ["a.semester_id = ?"];
    $paramsSubj = [$semester_id];
    if ($class_id) {
        $whereSubj[] = "ac.class_id = ?";
        $paramsSubj[] = $class_id;
    }
    if ($level) {
        $whereSubj[] = "EXISTS (SELECT 1 FROM classes c WHERE c.class_id = ac.class_id AND c.level = ?)";
        $paramsSubj[] = $level;
    }
    if ($assessment_type_id) {
        $whereSubj[] = "a.assessment_type_id = ?";
        $paramsSubj[] = $assessment_type_id;
    }
    $whereSubjClause = implode(' AND ', $whereSubj);

    $stmt = $db->prepare("
        SELECT
            sub.subject_id,
            sub.subject_name,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(r.result_id) as total_attempts,
            COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as completed_attempts,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score,
            MIN(CASE WHEN r.status = 'completed' THEN r.score END) as min_score,
            MAX(CASE WHEN r.status = 'completed' THEN r.score END) as max_score,
            ROUND(
                100.0 * COUNT(CASE WHEN r.status = 'completed' AND r.score >= 10 THEN 1 END) /
                NULLIF(COUNT(CASE WHEN r.status = 'completed' THEN 1 END), 0),
                2
            ) as pass_rate
        FROM subjects sub
        JOIN assessmentclasses ac ON sub.subject_id = ac.subject_id
        JOIN assessments a ON ac.assessment_id = a.assessment_id
        LEFT JOIN results r ON a.assessment_id = r.assessment_id
        WHERE $whereSubjClause
        GROUP BY sub.subject_id, sub.subject_name
        HAVING completed_attempts >= 10
        ORDER BY avg_score DESC
    ");
    $stmt->execute($paramsSubj);
    $subjectPerformance = $stmt->fetchAll();
    ?>

    <div class="report-section">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-book me-2"></i>3. Subject Performance Analysis</h5>
        </div>
        <div class="p-4">
            <div class="mb-4">
                <canvas id="subjectChart" height="80"></canvas>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Rank</th>
                            <th>Subject</th>
                            <th>Assessments</th>
                            <th>Attempts</th>
                            <th>Avg Score</th>
                            <th>Min</th>
                            <th>Max</th>
                            <th>Pass Rate</th>
                            <th>Difficulty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjectPerformance as $idx => $subj):
                            $difficulty = $subj['avg_score'] >= 70 ? 'Easy' :
                                         ($subj['avg_score'] >= 50 ? 'Moderate' : 'Difficult');
                            $diffClass = $subj['avg_score'] >= 70 ? 'text-success' :
                                        ($subj['avg_score'] >= 50 ? 'text-warning' : 'text-danger');
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($subj['subject_name']) ?></strong></td>
                            <td><?= $subj['total_assessments'] ?></td>
                            <td><?= $subj['total_attempts'] ?></td>
                            <td><strong><?= $subj['avg_score'] ?>%</strong></td>
                            <td><?= $subj['min_score'] ?>%</td>
                            <td><?= $subj['max_score'] ?>%</td>
                            <td><?= $subj['pass_rate'] ?>%</td>
                            <td class="<?= $diffClass ?>"><?= $difficulty ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 4: Top Performing Students -->
    <?php
    $topWhere = ["r.status = 'completed'", "a.semester_id = ?"];
    $topParams = [$semester_id];

    if ($subject_id) {
        $topWhere[] = "EXISTS (SELECT 1 FROM assessmentclasses ac WHERE ac.assessment_id = a.assessment_id AND ac.subject_id = ?)";
        $topParams[] = $subject_id;
    }
    if ($class_id) {
        $topWhere[] = "s.class_id = ?";
        $topParams[] = $class_id;
    }
    if ($level) {
        $topWhere[] = "c.level = ?";
        $topParams[] = $level;
    }
    if ($assessment_type_id) {
        $topWhere[] = "a.assessment_type_id = ?";
        $topParams[] = $assessment_type_id;
    }

    // Lower threshold when filtering by subject (fewer assessments per subject)
    $minCompleted = $subject_id ? 1 : 3;

    $stmt = $db->prepare("
        SELECT
            s.student_id,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            c.class_name,
            p.program_name,
            COUNT(r.result_id) as total_completed,
            ROUND(AVG(r.score), 2) as avg_score,
            MAX(r.score) as highest_score,
            MIN(r.score) as lowest_score
        FROM results r
        JOIN students s ON r.student_id = s.student_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN programs p ON c.program_id = p.program_id
        JOIN assessments a ON r.assessment_id = a.assessment_id
        WHERE " . implode(' AND ', $topWhere) . "
        GROUP BY s.student_id, s.first_name, s.last_name, c.class_name, p.program_name
        HAVING total_completed >= $minCompleted AND avg_score >= 10
        ORDER BY avg_score DESC, total_completed DESC
        LIMIT 20
    ");
    $stmt->execute($topParams);
    $topStudents = $stmt->fetchAll();
    ?>

    <div class="report-section">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>4. Top Performing Students (Top 20)</h5>
        </div>
        <div class="p-4">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Rank</th>
                            <th>Student Name</th>
                            <th>Program</th>
                            <th>Class</th>
                            <th>Completed</th>
                            <th>Avg Score</th>
                            <th>Highest Score</th>
                            <th>Award</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topStudents as $idx => $student): ?>
                        <tr>
                            <td>
                                <?php if ($idx < 3): ?>
                                    <span class="badge bg-warning">🏆 <?= $idx + 1 ?></span>
                                <?php else: ?>
                                    <?= $idx + 1 ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($student['student_name']) ?></strong></td>
                            <td><?= htmlspecialchars($student['program_name']) ?></td>
                            <td><?= htmlspecialchars($student['class_name']) ?></td>
                            <td><?= $student['total_completed'] ?></td>
                            <td><strong><?= $student['avg_score'] ?>%</strong></td>
                            <td><?= $student['highest_score'] ?>%</td>
                            <td>
                                <?php if ($student['avg_score'] >= 90): ?>
                                    <span class="badge bg-success">Distinction</span>
                                <?php elseif ($student['avg_score'] >= 70): ?>
                                    <span class="badge bg-info">Merit</span>
                                <?php elseif ($student['avg_score'] >= 50): ?>
                                    <span class="badge bg-primary">Pass</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Section 5: Assessment Analytics -->
    <?php
    $assessWhere = ["a.semester_id = ?"];
    $assessParams = [$semester_id];
    if ($class_id) {
        $assessWhere[] = "ac.class_id = ?";
        $assessParams[] = $class_id;
    }
    if ($subject_id) {
        $assessWhere[] = "ac.subject_id = ?";
        $assessParams[] = $subject_id;
    }
    if ($level) {
        $assessWhere[] = "EXISTS (SELECT 1 FROM classes c WHERE c.class_id = ac.class_id AND c.level = ?)";
        $assessParams[] = $level;
    }
    if ($assessment_type_id) {
        $assessWhere[] = "a.assessment_type_id = ?";
        $assessParams[] = $assessment_type_id;
    }

    $stmt = $db->prepare("
        SELECT
            a.assessment_id,
            a.title,
            sub.subject_name,
            a.date,
            COUNT(DISTINCT r.student_id) as students_attempted,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.student_id END) as students_completed,
            COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as total_completed,
            ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score,
            MIN(CASE WHEN r.status = 'completed' THEN r.score END) as min_score,
            MAX(CASE WHEN r.status = 'completed' THEN r.score END) as max_score,
            (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) as question_count
        FROM assessments a
        JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
        JOIN subjects sub ON ac.subject_id = sub.subject_id
        LEFT JOIN results r ON a.assessment_id = r.assessment_id
        WHERE " . implode(' AND ', $assessWhere) . "
        GROUP BY a.assessment_id, a.title, sub.subject_name, a.date
        HAVING question_count > 0
        ORDER BY a.date DESC
        LIMIT 15
    ");
    $stmt->execute($assessParams);
    $assessmentAnalytics = $stmt->fetchAll();
    ?>

    <div class="report-section">
        <div class="section-header">
            <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>5. Assessment Analytics (Recent 15)</h5>
        </div>
        <div class="p-4">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Assessment</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Attempted</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                            <th>Avg Score</th>
                            <th>Range</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessmentAnalytics as $assess):
                            $completion_rate = $assess['students_attempted'] > 0 ?
                                round(($assess['students_completed'] / $assess['students_attempted']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($assess['title']) ?></strong></td>
                            <td><?= htmlspecialchars($assess['subject_name']) ?></td>
                            <td><?= date('M d, Y', strtotime($assess['date'])) ?></td>
                            <td><?= $assess['students_attempted'] ?></td>
                            <td><?= $assess['students_completed'] ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar <?= $completion_rate >= 70 ? 'bg-success' : 'bg-warning' ?>"
                                         style="width: <?= $completion_rate ?>%">
                                        <?= $completion_rate ?>%
                                    </div>
                                </div>
                            </td>
                            <td><strong><?= $assess['avg_score'] ?? 'N/A' ?>%</strong></td>
                            <td><?= $assess['min_score'] ?? '-' ?>% - <?= $assess['max_score'] ?? '-' ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Cascading Filter Logic
function filterClasses() {
    const levelSelect = document.getElementById('levelFilter');
    const classSelect = document.getElementById('classFilter');
    const selectedLevel = levelSelect.value;

    // Show/hide class options based on selected level
    Array.from(classSelect.options).forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
            return;
        }

        const optionLevel = option.getAttribute('data-level');
        if (!selectedLevel || optionLevel === selectedLevel) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
            if (option.selected) {
                classSelect.value = '';
            }
        }
    });

    // Reset class selection if current selection is hidden
    if (classSelect.selectedIndex > 0 && classSelect.options[classSelect.selectedIndex].style.display === 'none') {
        classSelect.value = '';
    }

    filterSubjects();
}

function filterSubjects() {
    const classSelect = document.getElementById('classFilter');
    const subjectSelect = document.getElementById('subjectFilter');
    const selectedClassId = classSelect.value;

    if (!selectedClassId) {
        // Show all subjects if no class selected
        Array.from(subjectSelect.options).forEach(option => {
            option.style.display = 'block';
        });
        return;
    }

    // Show/hide subject options based on selected class
    Array.from(subjectSelect.options).forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
            return;
        }

        const subjectClassIds = option.getAttribute('data-classes');
        if (!subjectClassIds) {
            option.style.display = 'block';
            return;
        }

        const classIds = subjectClassIds.split(',');
        if (classIds.includes(selectedClassId)) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
            if (option.selected) {
                subjectSelect.value = '';
            }
        }
    });

    // Reset subject selection if current selection is hidden
    if (subjectSelect.selectedIndex > 0 && subjectSelect.options[subjectSelect.selectedIndex].style.display === 'none') {
        subjectSelect.value = '';
    }
}

// Initialize filters on page load
document.addEventListener('DOMContentLoaded', function() {
    filterClasses();
});

// Pass/Fail Chart
<?php if (isset($overview) && $overview['completed_submissions'] > 0): ?>
new Chart(document.getElementById('passFailChart'), {
    type: 'doughnut',
    data: {
        labels: ['Passed', 'Failed'],
        datasets: [{
            data: [<?= $overview['passed'] ?>, <?= $overview['failed'] ?>],
            backgroundColor: ['#28a745', '#dc3545']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            title: { display: true, text: 'Pass/Fail Distribution' },
            legend: {
                position: 'bottom'
            }
        }
    }
});
<?php endif; ?>

// Subject Performance Chart
<?php if (!empty($subjectPerformance)): ?>
new Chart(document.getElementById('subjectChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($subjectPerformance, 'subject_name')) ?>,
        datasets: [{
            label: 'Average Score (%)',
            data: <?= json_encode(array_column($subjectPerformance, 'avg_score')) ?>,
            backgroundColor: '#ffd700'
        }, {
            label: 'Pass Rate (%)',
            data: <?= json_encode(array_column($subjectPerformance, 'pass_rate')) ?>,
            backgroundColor: '#28a745'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, max: 100 }
        },
        plugins: {
            title: { display: true, text: 'Subject Performance Comparison' }
        }
    }
});
<?php endif; ?>
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>
