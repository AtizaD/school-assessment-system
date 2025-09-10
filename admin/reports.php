<?php
// admin/reports.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure only admins can access this page
requireRole('admin');

$error = '';
$success = '';
$report_type = $_GET['type'] ?? 'overview';
$semester_id = filter_input(INPUT_GET, 'semester_id', FILTER_VALIDATE_INT) ?: null;
$format = $_GET['format'] ?? 'html'; // For export functionality

$db = DatabaseConfig::getInstance()->getConnection();

// Get available semesters for filter
$stmt = $db->query(
    "SELECT semester_id, semester_name, start_date, end_date 
     FROM Semesters 
     ORDER BY start_date DESC"
);
$semesters = $stmt->fetchAll();

// Function to get current semester if none selected
function getCurrentSemester($db) {
    $stmt = $db->query(
        "SELECT semester_id 
         FROM Semesters 
         WHERE CURRENT_DATE BETWEEN start_date AND end_date 
         LIMIT 1"
    );
    $result = $stmt->fetch();
    
    if (!$result) {
        // If no current semester, get the most recent one
        $stmt = $db->query(
            "SELECT semester_id 
             FROM Semesters 
             ORDER BY end_date DESC 
             LIMIT 1"
        );
        $result = $stmt->fetch();
    }
    
    return $result['semester_id'] ?? null;
}

if (!$semester_id) {
    $semester_id = getCurrentSemester($db);
}

// Validate semester_id exists
if ($semester_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM Semesters WHERE semester_id = ?");
    $stmt->execute([$semester_id]);
    if ($stmt->fetchColumn() == 0) {
        $semester_id = getCurrentSemester($db);
    }
}

// Function to get semester name
function getSemesterName($db, $semester_id) {
    if (!$semester_id) return 'All Time';
    
    $stmt = $db->prepare("SELECT semester_name FROM Semesters WHERE semester_id = ?");
    $stmt->execute([$semester_id]);
    return $stmt->fetch()['semester_name'] ?? 'Unknown Semester';
}

$semester_name = getSemesterName($db, $semester_id);

// Function to get report data based on type
function getReportData($db, $report_type, $semester_id) {
    switch ($report_type) {
        case 'overview':
            // Get system overview statistics
            $data = [];
            
            // User statistics
            $stmt = $db->query("SELECT role, COUNT(*) as count FROM Users GROUP BY role");
            $data['users'] = $stmt->fetchAll();
            
            // Program and class statistics
            $stmt = $db->query(
                "SELECT p.program_id, p.program_name, 
                        COUNT(DISTINCT c.class_id) as class_count,
                        COUNT(DISTINCT s.student_id) as student_count
                 FROM Programs p
                 LEFT JOIN Classes c ON p.program_id = c.program_id
                 LEFT JOIN Students s ON c.class_id = s.class_id
                 GROUP BY p.program_id
                 ORDER BY p.program_name"
            );
            $data['programs'] = $stmt->fetchAll();
            
            // Total counts for summary
            $data['totals'] = [
                'programs' => count($data['programs']),
                'classes' => array_sum(array_column($data['programs'], 'class_count')),
                'students' => array_sum(array_column($data['programs'], 'student_count'))
            ];
            
            // Assessment statistics
            if ($semester_id) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) as total,
                            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                            COUNT(CASE WHEN status = 'archived' THEN 1 END) as archived
                     FROM Assessments 
                     WHERE semester_id = ?"
                );
                $stmt->execute([$semester_id]);
                $data['assessments'] = $stmt->fetch();
                
                // Add assessment completion rate
                if ($data['assessments']['total'] > 0) {
                    $data['assessments']['completion_rate'] = round(
                        ($data['assessments']['completed'] / $data['assessments']['total']) * 100, 
                        1
                    );
                } else {
                    $data['assessments']['completion_rate'] = 0;
                }
                
                // Get average score data
                $stmt = $db->prepare(
                    "SELECT ROUND(AVG(r.score), 2) as avg_score,
                            MIN(r.score) as min_score,
                            MAX(r.score) as max_score
                     FROM Results r
                     JOIN Assessments a ON r.assessment_id = a.assessment_id
                     WHERE a.semester_id = ? AND r.status = 'completed'"
                );
                $stmt->execute([$semester_id]);
                $data['scores'] = $stmt->fetch();
                
                // Get student participation data
                $stmt = $db->prepare(
                    "SELECT COUNT(DISTINCT s.student_id) as total_students,
                            COUNT(DISTINCT r.student_id) as participating_students
                     FROM Students s
                     LEFT JOIN Results r ON s.student_id = r.student_id
                     LEFT JOIN Assessments a ON r.assessment_id = a.assessment_id
                     WHERE a.semester_id = ? OR a.semester_id IS NULL"
                );
                $stmt->execute([$semester_id]);
                $participation = $stmt->fetch();
                $data['participation'] = $participation;
                
                if ($participation['total_students'] > 0) {
                    $data['participation']['rate'] = round(
                        ($participation['participating_students'] / $participation['total_students']) * 100,
                        1
                    );
                } else {
                    $data['participation']['rate'] = 0;
                }
            }
            
            return $data;
            
        case 'class_performance':
            // Get class-wise assessment performance
            $stmt = $db->prepare(
                "SELECT c.class_id, c.class_name,
                        p.program_name,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT s.student_id) as total_students,
                        COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments,
                        ROUND(AVG(r.score), 2) as average_score,
                        MIN(r.score) as min_score,
                        MAX(r.score) as max_score
                 FROM Classes c
                 JOIN Programs p ON c.program_id = p.program_id
                 LEFT JOIN Students s ON c.class_id = s.class_id
                 LEFT JOIN Results r ON s.student_id = r.student_id
                 LEFT JOIN Assessments a ON r.assessment_id = a.assessment_id
                 WHERE (? IS NULL OR a.semester_id = ? OR a.semester_id IS NULL)
                 GROUP BY c.class_id
                 ORDER BY p.program_name, c.class_name"
            );
            $stmt->execute([$semester_id, $semester_id]);
            $classes = $stmt->fetchAll();
            
            // Calculate completion rates
            foreach ($classes as &$class) {
                if ($class['total_assessments'] * $class['total_students'] > 0) {
                    $class['completion_rate'] = round(
                        ($class['completed_assessments'] / ($class['total_assessments'] * $class['total_students'])) * 100,
                        1
                    );
                } else {
                    $class['completion_rate'] = 0;
                }
            }
            
            return $classes;
            
        case 'subject_statistics':
            // Get subject-wise performance statistics
            $stmt = $db->prepare(
                "SELECT s.subject_id, s.subject_name,
                        COUNT(DISTINCT tca.class_id) as assigned_classes,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT r.student_id) as total_students,
                        ROUND(AVG(r.score), 2) as average_score,
                        MIN(r.score) as min_score,
                        MAX(r.score) as max_score,
                        COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments
                 FROM Subjects s
                 LEFT JOIN TeacherClassAssignments tca ON s.subject_id = tca.subject_id
                 LEFT JOIN Assessments a ON tca.class_id = a.class_id AND s.subject_id = a.subject_id
                 LEFT JOIN Results r ON a.assessment_id = r.assessment_id
                 WHERE (? IS NULL OR a.semester_id = ? OR a.semester_id IS NULL)
                 GROUP BY s.subject_id
                 ORDER BY s.subject_name"
            );
            $stmt->execute([$semester_id, $semester_id]);
            $subjects = $stmt->fetchAll();
            
            // Calculate metrics for each subject
            foreach ($subjects as &$subject) {
                $subject['total_possible'] = $subject['assigned_classes'] * $subject['total_assessments'];
                if ($subject['total_possible'] > 0) {
                    $subject['assessment_rate'] = round(
                        ($subject['completed_assessments'] / $subject['total_possible']) * 100,
                        1
                    );
                } else {
                    $subject['assessment_rate'] = 0;
                }
            }
            
            return $subjects;
            
        case 'teacher_performance':
            // Get teacher performance statistics
            $stmt = $db->prepare(
                "SELECT t.teacher_id, t.first_name, t.last_name,
                        COUNT(DISTINCT tca.class_id) as assigned_classes,
                        COUNT(DISTINCT tca.subject_id) as assigned_subjects,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT s.student_id) as total_students,
                        ROUND(AVG(r.score), 2) as average_score,
                        MIN(r.score) as min_score,
                        MAX(r.score) as max_score,
                        COUNT(DISTINCT CASE WHEN a.status = 'completed' THEN a.assessment_id END) as completed_assessments
                 FROM Teachers t
                 LEFT JOIN TeacherClassAssignments tca ON t.teacher_id = tca.teacher_id
                 LEFT JOIN Assessments a ON tca.class_id = a.class_id AND tca.subject_id = a.subject_id
                 LEFT JOIN Results r ON a.assessment_id = r.assessment_id
                 LEFT JOIN Students s ON tca.class_id = s.class_id
                 WHERE (? IS NULL OR a.semester_id = ? OR a.semester_id IS NULL)
                 GROUP BY t.teacher_id
                 ORDER BY t.last_name, t.first_name"
            );
            $stmt->execute([$semester_id, $semester_id]);
            $teachers = $stmt->fetchAll();
            
            // Additional statistics for teachers
            foreach ($teachers as &$teacher) {
                if ($teacher['total_assessments'] > 0) {
                    $teacher['completion_rate'] = round(
                        ($teacher['completed_assessments'] / $teacher['total_assessments']) * 100,
                        1
                    );
                } else {
                    $teacher['completion_rate'] = 0;
                }
            }
            
            return $teachers;
            
        case 'student_progress':
            // New report type: Student progress tracking
            $stmt = $db->prepare(
                "SELECT s.student_id, s.first_name, s.last_name,
                        c.class_name, p.program_name,
                        COUNT(DISTINCT a.assessment_id) as total_assessments,
                        COUNT(DISTINCT r.result_id) as completed_assessments,
                        ROUND(AVG(r.score), 2) as average_score,
                        MIN(r.score) as min_score,
                        MAX(r.score) as max_score
                 FROM Students s
                 JOIN Classes c ON s.class_id = c.class_id
                 JOIN Programs p ON c.program_id = p.program_id
                 LEFT JOIN Results r ON s.student_id = r.student_id
                 LEFT JOIN Assessments a ON r.assessment_id = a.assessment_id
                 WHERE (? IS NULL OR a.semester_id = ? OR a.semester_id IS NULL)
                 GROUP BY s.student_id
                 ORDER BY p.program_name, c.class_name, s.last_name, s.first_name"
            );
            $stmt->execute([$semester_id, $semester_id]);
            $students = $stmt->fetchAll();
            
            // Calculate completion rate for each student
            foreach ($students as &$student) {
                if ($student['total_assessments'] > 0) {
                    $student['completion_rate'] = round(
                        ($student['completed_assessments'] / $student['total_assessments']) * 100,
                        1
                    );
                } else {
                    $student['completion_rate'] = 0;
                }
            }
            
            return $students;
            
        default:
            return null;
    }
}

// Initialize data and handle exports
$reportData = getReportData($db, $report_type, $semester_id);

// Handle export formats
if ($format !== 'html') {
    $filename = $report_type . '_report_' . date('Y-m-d') . '.' . $format;
    
    // Set appropriate headers based on format
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Create CSV output
        $output = fopen('php://output', 'w');
        
        // Add headers based on report type
        switch ($report_type) {
            case 'overview':
                fputcsv($output, ['System Overview Report - ' . $semester_name]);
                fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                
                // User distribution
                fputcsv($output, ['User Distribution']);
                fputcsv($output, ['Role', 'Count']);
                foreach ($reportData['users'] as $user) {
                    fputcsv($output, [ucfirst($user['role']), $user['count']]);
                }
                fputcsv($output, []);
                
                // Program statistics
                fputcsv($output, ['Program Statistics']);
                fputcsv($output, ['Program', 'Classes', 'Students']);
                foreach ($reportData['programs'] as $program) {
                    fputcsv($output, [
                        $program['program_name'], 
                        $program['class_count'], 
                        $program['student_count']
                    ]);
                }
                break;
                
            case 'class_performance':
                fputcsv($output, ['Class Performance Report - ' . $semester_name]);
                fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['Program', 'Class', 'Students', 'Assessments', 'Completed', 'Completion Rate (%)', 'Average Score', 'Min Score', 'Max Score']);
                
                foreach ($reportData as $class) {
                    fputcsv($output, [
                        $class['program_name'],
                        $class['class_name'],
                        $class['total_students'],
                        $class['total_assessments'],
                        $class['completed_assessments'],
                        $class['completion_rate'],
                        $class['average_score'],
                        $class['min_score'] ?: 'N/A',
                        $class['max_score'] ?: 'N/A'
                    ]);
                }
                break;
                
            case 'subject_statistics':
                fputcsv($output, ['Subject Statistics Report - ' . $semester_name]);
                fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['Subject', 'Assigned Classes', 'Assessments', 'Students', 'Completed Assessments', 'Assessment Rate (%)', 'Average Score', 'Min Score', 'Max Score']);
                
                foreach ($reportData as $subject) {
                    fputcsv($output, [
                        $subject['subject_name'],
                        $subject['assigned_classes'],
                        $subject['total_assessments'],
                        $subject['total_students'],
                        $subject['completed_assessments'],
                        $subject['assessment_rate'],
                        $subject['average_score'] ?: 'N/A',
                        $subject['min_score'] ?: 'N/A',
                        $subject['max_score'] ?: 'N/A'
                    ]);
                }
                break;
                
            case 'teacher_performance':
                fputcsv($output, ['Teacher Performance Report - ' . $semester_name]);
                fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['Teacher Name', 'Classes', 'Subjects', 'Students', 'Total Assessments', 'Completed', 'Completion Rate (%)', 'Average Score', 'Min Score', 'Max Score']);
                
                foreach ($reportData as $teacher) {
                    fputcsv($output, [
                        $teacher['first_name'] . ' ' . $teacher['last_name'],
                        $teacher['assigned_classes'],
                        $teacher['assigned_subjects'],
                        $teacher['total_students'],
                        $teacher['total_assessments'],
                        $teacher['completed_assessments'],
                        $teacher['completion_rate'],
                        $teacher['average_score'] ?: 'N/A',
                        $teacher['min_score'] ?: 'N/A',
                        $teacher['max_score'] ?: 'N/A'
                    ]);
                }
                break;
                
            case 'student_progress':
                fputcsv($output, ['Student Progress Report - ' . $semester_name]);
                fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['Student Name', 'Program', 'Class', 'Total Assessments', 'Completed', 'Completion Rate (%)', 'Average Score', 'Min Score', 'Max Score']);
                
                foreach ($reportData as $student) {
                    fputcsv($output, [
                        $student['first_name'] . ' ' . $student['last_name'],
                        $student['program_name'],
                        $student['class_name'],
                        $student['total_assessments'],
                        $student['completed_assessments'],
                        $student['completion_rate'],
                        $student['average_score'] ?: 'N/A',
                        $student['min_score'] ?: 'N/A',
                        $student['max_score'] ?: 'N/A'
                    ]);
                }
                break;
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'pdf') {
        // In a real implementation, you would integrate with a PDF library
        // like TCPDF, FPDF, or mPDF here
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "PDF export not implemented in this example.";
        exit;
    } elseif ($format === 'excel') {
        // In a real implementation, you would integrate with a library like
        // PhpSpreadsheet here
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Excel export not implemented in this example.";
        exit;
    }
}

$pageTitle = 'System Reports';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3">System Reports</h1>
            <p class="text-muted">
                <?php echo htmlspecialchars($semester_name); ?> 
                <?php if ($semester_id): ?>
                    <?php 
                        foreach ($semesters as $sem) {
                            if ($sem['semester_id'] == $semester_id) {
                                echo '(' . htmlspecialchars($sem['start_date']) . ' to ' . htmlspecialchars($sem['end_date']) . ')';
                                break;
                            }
                        }
                    ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <!-- Semester Filter -->
            <select class="form-select" onchange="updateSemester(this.value)">
                <option value="">All Time</option>
                <?php foreach ($semesters as $sem): ?>
                <option value="<?php echo $sem['semester_id']; ?>" 
                        <?php echo $sem['semester_id'] == $semester_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sem['semester_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <!-- Export Button -->
            <div class="dropdown">
                <button class="btn btn-success dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-download me-2"></i>Export
                </button>
                <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportReport('csv')">CSV</a></li>
                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportReport('excel')">Excel</a></li>
                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportReport('pdf')">PDF</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Report Navigation -->
    <div class="card mb-4">
        <div class="card-body">
            <ul class="nav nav-pills">
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'overview' ? 'active' : ''; ?>" 
                       href="?type=overview<?php echo $semester_id ? "&semester_id=$semester_id" : ''; ?>">
                        <i class="fas fa-chart-pie me-2"></i>System Overview
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'class_performance' ? 'active' : ''; ?>" 
                       href="?type=class_performance<?php echo $semester_id ? "&semester_id=$semester_id" : ''; ?>">
                        <i class="fas fa-school me-2"></i>Class Performance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'subject_statistics' ? 'active' : ''; ?>" 
                       href="?type=subject_statistics<?php echo $semester_id ? "&semester_id=$semester_id" : ''; ?>">
                        <i class="fas fa-book me-2"></i>Subject Statistics
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'teacher_performance' ? 'active' : ''; ?>" 
                       href="?type=teacher_performance<?php echo $semester_id ? "&semester_id=$semester_id" : ''; ?>">
                        <i class="fas fa-chalkboard-teacher me-2"></i>Teacher Performance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type === 'student_progress' ? 'active' : ''; ?>" 
                       href="?type=student_progress<?php echo $semester_id ? "&semester_id=$semester_id" : ''; ?>">
                        <i class="fas fa-user-graduate me-2"></i>Student Progress
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card">
        <div class="card-body">
            <?php if ($report_type === 'overview'): ?>
                <!-- System Overview Report -->
                <div class="row g-4">
                    <!-- Summary Cards -->
                    <div class="col-12">
                        <div class="row g-4">
                            <div class="col-md-3">
                                <div class="card bg-primary text-white h-100">
                                    <div class="card-body">
                                        <h5>Programs</h5>
                                        <h2 class="display-4"><?php echo $reportData['totals']['programs']; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white h-100">
                                    <div class="card-body">
                                        <h5>Classes</h5>
                                        <h2 class="display-4"><?php echo $reportData['totals']['classes']; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white h-100">
                                    <div class="card-body">
                                        <h5>Students</h5>
                                        <h2 class="display-4"><?php echo $reportData['totals']['students']; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-dark h-100">
                                    <div class="card-body">
                                        <h5>Assessment Completion</h5>
                                        <h2 class="display-4">
                                            <?php if (isset($reportData['assessments'])): ?>
                                                <?php echo $reportData['assessments']['completion_rate']; ?>%
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- User Statistics -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">User Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="position: relative; height:250px;">
                                    <canvas id="userChart"></canvas>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Role</th>
                                                <th class="text-end">Count</th>
                                                <th class="text-end">Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_users = array_sum(array_column($reportData['users'], 'count'));
                                            foreach ($reportData['users'] as $user): 
                                                $percentage = ($total_users > 0) ? round(($user['count'] / $total_users) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo ucfirst($user['role']); ?></td>
                                                <td class="text-end"><?php echo $user['count']; ?></td>
                                                <td class="text-end"><?php echo $percentage; ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-secondary">
                                                <td><strong>Total</strong></td>
                                                <td class="text-end"><strong><?php echo $total_users; ?></strong></td>
                                                <td class="text-end"><strong>100%</strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Program Statistics -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Program Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="position: relative; height:250px;">
                                    <canvas id="programsChart"></canvas>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Program</th>
                                                <th class="text-end">Classes</th>
                                                <th class="text-end">Students</th>
                                                <th class="text-end">Avg. per Class</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData['programs'] as $program): ?>
                                            <tr>
                                            <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                                <td class="text-end"><?php echo $program['class_count']; ?></td>
                                                <td class="text-end"><?php echo $program['student_count']; ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    if ($program['class_count'] > 0) {
                                                        echo round($program['student_count'] / $program['class_count'], 1);
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-secondary">
                                                <td><strong>Total</strong></td>
                                                <td class="text-end"><strong><?php echo $reportData['totals']['classes']; ?></strong></td>
                                                <td class="text-end"><strong><?php echo $reportData['totals']['students']; ?></strong></td>
                                                <td class="text-end">
                                                    <strong>
                                                    <?php 
                                                    if ($reportData['totals']['classes'] > 0) {
                                                        echo round($reportData['totals']['students'] / $reportData['totals']['classes'], 1);
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                    </strong>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assessment Statistics -->
                    <?php if (isset($reportData['assessments'])): ?>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Assessment Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="position: relative; height:250px;">
                                    <canvas id="assessmentChart"></canvas>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th class="text-end">Count</th>
                                                <th class="text-end">Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Completed</td>
                                                <td class="text-end"><?php echo $reportData['assessments']['completed']; ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    if ($reportData['assessments']['total'] > 0) {
                                                        echo round(($reportData['assessments']['completed'] / $reportData['assessments']['total']) * 100, 1);
                                                    } else {
                                                        echo '0';
                                                    }
                                                    ?>%
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>Pending</td>
                                                <td class="text-end"><?php echo $reportData['assessments']['pending']; ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    if ($reportData['assessments']['total'] > 0) {
                                                        echo round(($reportData['assessments']['pending'] / $reportData['assessments']['total']) * 100, 1);
                                                    } else {
                                                        echo '0';
                                                    }
                                                    ?>%
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>Archived</td>
                                                <td class="text-end"><?php echo $reportData['assessments']['archived']; ?></td>
                                                <td class="text-end">
                                                    <?php 
                                                    if ($reportData['assessments']['total'] > 0) {
                                                        echo round(($reportData['assessments']['archived'] / $reportData['assessments']['total']) * 100, 1);
                                                    } else {
                                                        echo '0';
                                                    }
                                                    ?>%
                                                </td>
                                            </tr>
                                            <tr class="table-secondary">
                                                <td><strong>Total</strong></td>
                                                <td class="text-end"><strong><?php echo $reportData['assessments']['total']; ?></strong></td>
                                                <td class="text-end"><strong>100%</strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Score Statistics -->
                    <?php if (isset($reportData['scores'])): ?>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Score Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container" style="position: relative; height:250px;">
                                    <canvas id="scoresChart"></canvas>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Metric</th>
                                                <th class="text-end">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Average Score</td>
                                                <td class="text-end"><?php echo $reportData['scores']['avg_score'] ?? 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <td>Minimum Score</td>
                                                <td class="text-end"><?php echo $reportData['scores']['min_score'] ?? 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <td>Maximum Score</td>
                                                <td class="text-end"><?php echo $reportData['scores']['max_score'] ?? 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <td>Student Participation</td>
                                                <td class="text-end">
                                                    <?php 
                                                    if (isset($reportData['participation'])) {
                                                        echo $reportData['participation']['participating_students'] . ' of ' . 
                                                            $reportData['participation']['total_students'] . ' (' . 
                                                            $reportData['participation']['rate'] . '%)';
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($report_type === 'class_performance'): ?>
                <!-- Class Performance Report -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Class Name</th>
                                <th class="text-end">Students</th>
                                <th class="text-end">Assessments</th>
                                <th class="text-end">Completed</th>
                                <th class="text-end">Completion Rate</th>
                                <th class="text-end">Avg. Score</th>
                                <th class="text-end">Min Score</th>
                                <th class="text-end">Max Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No class performance data available for the selected semester.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $class): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($class['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                    <td class="text-end"><?php echo $class['total_students']; ?></td>
                                    <td class="text-end"><?php echo $class['total_assessments']; ?></td>
                                    <td class="text-end"><?php echo $class['completed_assessments']; ?></td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo getProgressBarClass($class['completion_rate']); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $class['completion_rate']; ?>%;" 
                                                 aria-valuenow="<?php echo $class['completion_rate']; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?php echo $class['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo $class['average_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $class['min_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $class['max_score'] ?? 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type === 'subject_statistics'): ?>
                <!-- Subject Statistics Report -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-end">Assigned Classes</th>
                                <th class="text-end">Assessments</th>
                                <th class="text-end">Students</th>
                                <th class="text-end">Completed</th>
                                <th class="text-end">Assessment Rate</th>
                                <th class="text-end">Avg. Score</th>
                                <th class="text-end">Min Score</th>
                                <th class="text-end">Max Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No subject statistics data available for the selected semester.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td class="text-end"><?php echo $subject['assigned_classes']; ?></td>
                                    <td class="text-end"><?php echo $subject['total_assessments']; ?></td>
                                    <td class="text-end"><?php echo $subject['total_students']; ?></td>
                                    <td class="text-end"><?php echo $subject['completed_assessments']; ?></td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo getProgressBarClass($subject['assessment_rate']); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $subject['assessment_rate']; ?>%;" 
                                                 aria-valuenow="<?php echo $subject['assessment_rate']; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?php echo $subject['assessment_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo $subject['average_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $subject['min_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $subject['max_score'] ?? 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type === 'teacher_performance'): ?>
                <!-- Teacher Performance Report -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Teacher Name</th>
                                <th class="text-end">Classes</th>
                                <th class="text-end">Subjects</th>
                                <th class="text-end">Students</th>
                                <th class="text-end">Assessments</th>
                                <th class="text-end">Completed</th>
                                <th class="text-end">Completion Rate</th>
                                <th class="text-end">Avg. Score</th>
                                <th class="text-end">Min Score</th>
                                <th class="text-end">Max Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No teacher performance data available for the selected semester.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $teacher): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></td>
                                    <td class="text-end"><?php echo $teacher['assigned_classes']; ?></td>
                                    <td class="text-end"><?php echo $teacher['assigned_subjects']; ?></td>
                                    <td class="text-end"><?php echo $teacher['total_students']; ?></td>
                                    <td class="text-end"><?php echo $teacher['total_assessments']; ?></td>
                                    <td class="text-end"><?php echo $teacher['completed_assessments']; ?></td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo getProgressBarClass($teacher['completion_rate']); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $teacher['completion_rate']; ?>%;" 
                                                 aria-valuenow="<?php echo $teacher['completion_rate']; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?php echo $teacher['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo $teacher['average_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $teacher['min_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $teacher['max_score'] ?? 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type === 'student_progress'): ?>
                <!-- Student Progress Report -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Program</th>
                                <th>Class</th>
                                <th class="text-end">Assessments</th>
                                <th class="text-end">Completed</th>
                                <th class="text-end">Completion Rate</th>
                                <th class="text-end">Avg. Score</th>
                                <th class="text-end">Min Score</th>
                                <th class="text-end">Max Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No student progress data available for the selected semester.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                    <td class="text-end"><?php echo $student['total_assessments']; ?></td>
                                    <td class="text-end"><?php echo $student['completed_assessments']; ?></td>
                                    <td class="text-end">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo getProgressBarClass($student['completion_rate']); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $student['completion_rate']; ?>%;" 
                                                 aria-valuenow="<?php echo $student['completion_rate']; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?php echo $student['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><?php echo $student['average_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $student['min_score'] ?? 'N/A'; ?></td>
                                    <td class="text-end"><?php echo $student['max_score'] ?? 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
// Helper function for progress bar color classes
function getProgressBarClass($percentage) {
    if ($percentage >= 80) {
        return 'bg-success';
    } elseif ($percentage >= 60) {
        return 'bg-info';
    } elseif ($percentage >= 40) {
        return 'bg-warning';
    } else {
        return 'bg-danger';
    }
}
?>

<!-- Chart.js for visualizations -->
<script src="<?php echo BASE_URL; ?>/assets/js/external/chart-4.4.1.min.js"></script>

<script>
// JavaScript for report functionality
function updateSemester(semesterId) {
    const currentUrl = new URL(window.location.href);
    if (semesterId) {
        currentUrl.searchParams.set('semester_id', semesterId);
    } else {
        currentUrl.searchParams.delete('semester_id');
    }
    window.location.href = currentUrl.toString();
}

function exportReport(format) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('format', format);
    window.location.href = currentUrl.toString();
}

// Initialize charts if we're on the overview report
<?php if ($report_type === 'overview'): ?>
    // User Distribution Chart
    const userCtx = document.getElementById('userChart').getContext('2d');
    const userChart = new Chart(userCtx, {
        type: 'pie',
        data: {
            labels: [<?php 
                $labels = array_map(function($user) { 
                    return "'" . ucfirst($user['role']) . "'"; 
                }, $reportData['users']);
                echo implode(', ', $labels);
            ?>],
            datasets: [{
                data: [<?php 
                    $counts = array_map(function($user) { 
                        return $user['count']; 
                    }, $reportData['users']);
                    echo implode(', ', $counts);
                ?>],
                backgroundColor: [
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 206, 86, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(153, 102, 255, 0.7)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                }
            }
        }
    });

    // Programs Chart
    const programsCtx = document.getElementById('programsChart').getContext('2d');
    const programsChart = new Chart(programsCtx, {
        type: 'bar',
        data: {
            labels: [<?php 
                $labels = array_map(function($program) { 
                    return "'" . addslashes($program['program_name']) . "'"; 
                }, $reportData['programs']);
                echo implode(', ', $labels);
            ?>],
            datasets: [{
                label: 'Classes',
                data: [<?php 
                    $counts = array_map(function($program) { 
                        return $program['class_count']; 
                    }, $reportData['programs']);
                    echo implode(', ', $counts);
                ?>],
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderWidth: 1
            }, {
                label: 'Students',
                data: [<?php 
                    $counts = array_map(function($program) { 
                        return $program['student_count']; 
                    }, $reportData['programs']);
                    echo implode(', ', $counts);
                ?>],
                backgroundColor: 'rgba(255, 206, 86, 0.7)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    <?php if (isset($reportData['assessments'])): ?>
    // Assessment Status Chart
    const assessmentCtx = document.getElementById('assessmentChart').getContext('2d');
    const assessmentChart = new Chart(assessmentCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Pending', 'Archived'],
            datasets: [{
                data: [
                    <?php echo $reportData['assessments']['completed']; ?>,
                    <?php echo $reportData['assessments']['pending']; ?>,
                    <?php echo $reportData['assessments']['archived']; ?>
                ],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.7)',
                    'rgba(255, 193, 7, 0.7)',
                    'rgba(108, 117, 125, 0.7)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (isset($reportData['scores'])): ?>
    // Score Distribution Chart - Simulated histogram
    const scoresCtx = document.getElementById('scoresChart').getContext('2d');
    
    // Create bins for score distribution (simplified for demonstration)
    const scoreChart = new Chart(scoresCtx, {
        type: 'bar',
        data: {
            labels: ['0-20', '21-40', '41-60', '61-80', '81-100'],
            datasets: [{
                label: 'Score Distribution',
                data: [
                    // This would be calculated from actual data in a real implementation
                    Math.random() * 10,
                    Math.random() * 15,
                    Math.random() * 25,
                    Math.random() * 35,
                    Math.random() * 20
                ],
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Students'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Score Range'
                    }
                }
            }
        }
    });
    <?php endif; ?>
<?php endif; ?>
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>