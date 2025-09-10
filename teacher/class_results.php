<?php
// teacher/class_results.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

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

    // Get selected class and subject filters
    $selectedClass = isset($_GET['class']) ? filter_var($_GET['class'], FILTER_VALIDATE_INT) : 0;
    $selectedSubject = isset($_GET['subject']) ? filter_var($_GET['subject'], FILTER_VALIDATE_INT) : 0;
    $selectedSemester = isset($_GET['semester']) ? filter_var($_GET['semester'], FILTER_VALIDATE_INT) : 0;

    // Get teacher's classes and subjects for filters
    $teacherAssignments = getTeacherAssignments($db, $teacherId);

    // Get available semesters
    $semesters = getSemesters($db, $teacherId);

    // If no semester selected, use current or most recent
    if (!$selectedSemester) {
        $selectedSemester = getCurrentOrMostRecentSemester($db);
    }

    // Initialize student results array
    $studentResults = [];
    $assessments = [];
    $students = [];
    $classInfo = null;
    $subjectInfo = null;
    $semesterInfo = null;

    // Get semester info
    if ($selectedSemester) {
        $semesterInfo = getSemesterInfo($db, $selectedSemester);
    }

    // If class or subject is selected, fetch detailed results
    if ($selectedClass || $selectedSubject) {
        // Get class info if selected
        if ($selectedClass) {
            $classInfo = getClassInfo($db, $selectedClass);
        }
        
        // Get subject info if selected
        if ($selectedSubject) {
            $subjectInfo = getSubjectInfo($db, $selectedSubject);
        }
        
        // Query to get all assessments based on filters
        $assessments = getAssessments($db, $selectedSemester, $selectedClass, $selectedSubject);
        
        // Get students based on the selected class
        $students = getStudents($db, $selectedClass, $selectedSubject, $selectedSemester, $teacherId);
        
        // For each student, get their assessment scores
        foreach ($students as $student) {
            $studentResults[$student['student_id']] = [
                'student_info' => $student,
                'scores' => [],
                'total_score' => 0,
                'total_possible' => 0,
                'average_score' => 0
            ];
            
            foreach ($assessments as $assessment) {
                $score = getStudentScore($db, $assessment['assessment_id'], $student['student_id']);
                if ($score !== null) {
                    $studentResults[$student['student_id']]['scores'][$assessment['assessment_id']] = [
                        'raw_score' => $score['score'],
                        'max_possible' => $assessment['total_max_score']
                    ];
                    $studentResults[$student['student_id']]['total_score'] += $score['score'];
                    $studentResults[$student['student_id']]['total_possible'] += $assessment['total_max_score'];
                } else {
                    $studentResults[$student['student_id']]['scores'][$assessment['assessment_id']] = null;
                }
            }
            
            $studentResults[$student['student_id']]['average_score'] = 
                $studentResults[$student['student_id']]['total_possible'] > 0 ? 
                round(($studentResults[$student['student_id']]['total_score'] / $studentResults[$student['student_id']]['total_possible']) * 100, 1) : 0;
        }
    }

    // Handle export requests
    if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'csv'])) {
        $exportType = $_GET['export'];
        $classId = isset($_GET['class']) ? intval($_GET['class']) : 0;
        $subjectId = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
        $semesterId = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
        
        if (!$classId && !$subjectId) {
            $_SESSION['error'] = "Please select at least a class or subject to export results";
            header("Location: class_results.php");
            exit;
        }
        
        // Check if we have data to export
        if (empty($students) || empty($assessments)) {
            $_SESSION['error'] = "No data available to export";
            header("Location: class_results.php?class=$classId&subject=$subjectId&semester=$semesterId");
            exit;
        }
        
        // Process export request
        exportResults($exportType, $classId, $subjectId, $semesterId, $studentResults, $assessments, $students, $classInfo, $subjectInfo, $semesterInfo);
        exit; // Stop further execution after export
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Class results page error: " . $e->getMessage());
}

// Function to get teacher assignments
function getTeacherAssignments($db, $teacherId) {
    $stmt = $db->prepare(
        "SELECT DISTINCT c.class_id, c.class_name, s.subject_id, s.subject_name
         FROM teacherclassassignments tca
         JOIN classes c ON tca.class_id = c.class_id
         JOIN subjects s ON tca.subject_id = s.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY c.class_name, s.subject_name"
    );
    $stmt->execute([$teacherId]);
    return $stmt->fetchAll();
}

// Function to get semesters
function getSemesters($db, $teacherId) {
    $stmt = $db->prepare(
        "SELECT DISTINCT sem.semester_id, sem.semester_name, sem.start_date 
         FROM semesters sem
         JOIN assessments a ON sem.semester_id = a.semester_id
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN teacherclassassignments tca ON ac.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE tca.teacher_id = ?
         ORDER BY sem.start_date DESC"
    );
    $stmt->execute([$teacherId]);
    return $stmt->fetchAll();
}

// Function to get current or most recent semester
function getCurrentOrMostRecentSemester($db) {
    $stmt = $db->prepare(
        "SELECT semester_id FROM semesters 
         WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
         LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    return $currentSemester ? $currentSemester['semester_id'] : null;
}

// Function to get semester info
function getSemesterInfo($db, $semesterId) {
    $stmt = $db->prepare("SELECT semester_id, semester_name FROM semesters WHERE semester_id = ?");
    $stmt->execute([$semesterId]);
    return $stmt->fetch();
}

// Function to get class info
function getClassInfo($db, $classId) {
    $stmt = $db->prepare("SELECT class_id, class_name FROM classes WHERE class_id = ?");
    $stmt->execute([$classId]);
    return $stmt->fetch();
}

// Function to get subject info
function getSubjectInfo($db, $subjectId) {
    $stmt = $db->prepare("SELECT subject_id, subject_name FROM subjects WHERE subject_id = ?");
    $stmt->execute([$subjectId]);
    return $stmt->fetch();
}

// Function to get assessments
function getAssessments($db, $semesterId, $classId = null, $subjectId = null) {
    $query = "
        SELECT DISTINCT 
            a.assessment_id, 
            a.title, 
            a.date,
            (SELECT SUM(max_score) FROM questions WHERE assessment_id = a.assessment_id) as total_max_score
        FROM assessments a
        JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
        WHERE a.semester_id = ?";
    $params = [$semesterId];
    
    if ($classId) {
        $query .= " AND ac.class_id = ?";
        $params[] = $classId;
    }
    
    if ($subjectId) {
        $query .= " AND ac.subject_id = ?";
        $params[] = $subjectId;
    }
    
    $query .= " ORDER BY a.date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get students
function getStudents($db, $classId = null, $subjectId = null, $semesterId, $teacherId) {
    $query = "SELECT s.student_id, s.first_name, s.last_name, c.class_name
              FROM students s
              JOIN classes c ON s.class_id = c.class_id
              WHERE 1=1";
    $params = [];
    
    if ($classId) {
        $query .= " AND s.class_id = ?";
        $params[] = $classId;
    } else {
        if ($subjectId) {
            $query .= " AND s.class_id IN (
                SELECT DISTINCT tca.class_id 
                FROM teacherclassassignments tca 
                WHERE tca.teacher_id = ? AND tca.subject_id = ? AND tca.semester_id = ?
            )";
            $params[] = $teacherId;
            $params[] = $subjectId;
            $params[] = $semesterId;
        } else {
            $query .= " AND s.class_id IN (
                SELECT DISTINCT tca.class_id 
                FROM teacherclassassignments tca 
                WHERE tca.teacher_id = ? AND tca.semester_id = ?
            )";
            $params[] = $teacherId;
            $params[] = $semesterId;
        }
    }
    
    $query .= " ORDER BY s.last_name, s.first_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get student score
function getStudentScore($db, $assessmentId, $studentId) {
    $stmt = $db->prepare("
        SELECT r.score
        FROM results r
        WHERE r.assessment_id = ? AND r.student_id = ? AND r.status = 'completed'
        LIMIT 1
    ");
    $stmt->execute([$assessmentId, $studentId]);
    return $stmt->fetch();
}

// Function to export results
function exportResults($type, $classId, $subjectId, $semesterId, $studentResults, $assessments, $students, $classInfo, $subjectInfo, $semesterInfo) {
    // We are now passing all required data directly to the function
    
    $filename = 'class_results_' . date('Y-m-d');
    if ($classInfo) {
        $filename .= '_' . sanitizeFilename($classInfo['class_name']);
    }
    if ($subjectInfo) {
        $filename .= '_' . sanitizeFilename($subjectInfo['subject_name']);
    }
    
    if ($type === 'csv') {
        // Export as CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Header row
        $headerRow = ['Student ID', 'Last Name', 'First Name', 'Class'];
        
        foreach ($assessments as $assessment) {
            $headerRow[] = $assessment['title'] . ' (' . date('Y-m-d', strtotime($assessment['date'])) . ') - Score';
            $headerRow[] = $assessment['title'] . ' (' . date('Y-m-d', strtotime($assessment['date'])) . ') - Max';
        }
        
        $headerRow[] = 'Total Score';
        $headerRow[] = 'Total Possible';
        $headerRow[] = 'Average (%)';
        
        fputcsv($output, $headerRow);
        
        // Data rows
        foreach ($studentResults as $studentId => $result) {
            $row = [
                $studentId,
                $result['student_info']['last_name'],
                $result['student_info']['first_name'],
                $result['student_info']['class_name']
            ];
            
            foreach ($assessments as $assessment) {
                $scoreData = $result['scores'][$assessment['assessment_id']] ?? null;
                if ($scoreData) {
                    $row[] = $scoreData['raw_score'];
                    $row[] = $scoreData['max_possible'];
                } else {
                    $row[] = 'N/A';
                    $row[] = 'N/A';
                }
            }
            
            $row[] = $result['total_score'];
            $row[] = $result['total_possible'];
            $row[] = $result['average_score'];
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
        
    } elseif ($type === 'pdf') {
        // Export as PDF using TCPDF
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('School Management System');
        $pdf->SetAuthor('Teacher');
        $pdf->SetTitle('Class Raw Results');
        $pdf->SetSubject('Student Assessment Raw Scores');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', '', 10);
        
        // Title
        $title = 'Student Raw Assessment Scores';
        if ($semesterInfo) {
            $title .= ' - ' . $semesterInfo['semester_name'];
        }
        if ($classInfo) {
            $title .= ' - ' . $classInfo['class_name'];
        }
        if ($subjectInfo) {
            $title .= ' - ' . $subjectInfo['subject_name'];
        }
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $pdf->Ln(5);
        
        // Calculate column widths based on the number of assessments
        $studentInfoWidth = 80; // Width for student info columns
        $assessmentWidth = min(30, (($pdf->getPageWidth() - $studentInfoWidth - 40) / count($assessments)));
        $totalWidth = 30;
        
        // Table header
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetFont('helvetica', 'B', 8);
        
        $pdf->Cell(10, 7, 'ID', 1, 0, 'C', 1);
        $pdf->Cell(25, 7, 'Last Name', 1, 0, 'C', 1);
        $pdf->Cell(25, 7, 'First Name', 1, 0, 'C', 1);
        $pdf->Cell(20, 7, 'Class', 1, 0, 'C', 1);
        
        foreach ($assessments as $assessment) {
            $title = $assessment['title'];
            if (strlen($title) > 10) {
                $title = substr($title, 0, 10) . '...';
            }
            $pdf->Cell($assessmentWidth, 7, $title, 1, 0, 'C', 1);
        }
        
        $pdf->Cell($totalWidth, 7, 'Total / Possible', 1, 0, 'C', 1);
        $pdf->Cell(15, 7, 'Avg(%)', 1, 1, 'C', 1);
        
        // Table data
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetFillColor(255, 255, 255);
        
        $rowCount = 0;
        foreach ($studentResults as $studentId => $result) {
            $fill = $rowCount % 2 == 0;
            $fillColor = $fill ? 245 : 255;
            $pdf->SetFillColor($fillColor, $fillColor, $fillColor);
            
            $pdf->Cell(10, 6, $studentId, 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $result['student_info']['last_name'], 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, $result['student_info']['first_name'], 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, $result['student_info']['class_name'], 1, 0, 'L', $fill);
            
            foreach ($assessments as $assessment) {
                $scoreData = $result['scores'][$assessment['assessment_id']] ?? null;
                $displayText = $scoreData ? $scoreData['raw_score'] : 'N/A';
                $pdf->Cell($assessmentWidth, 6, $displayText, 1, 0, 'C', $fill);
            }
            
            $totalDisplay = $result['total_score'] . ' / ' . $result['total_possible'];
            $pdf->Cell($totalWidth, 6, $totalDisplay, 1, 0, 'C', $fill);
            $pdf->Cell(15, 6, $result['average_score'], 1, 1, 'C', $fill);
            $rowCount++;
        }
        
        // Output PDF
        $pdf->Output($filename . '.pdf', 'D');
        exit;
    }
}

// Utility function to sanitize filenames
function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-z0-9_\-]/i', '_', $filename);
    return strtolower($filename);
}

$pageTitle = "Class Raw Results";
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
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 2rem;
        color: white;
    }

    .filter-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .results-table {
        background: white;
        border-radius: 10px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        overflow-x: auto;
    }

    .score-cell {
        background-color: #f8f9fa;
        font-weight: 500;
        text-align: center;
    }

    .total-cell {
        background-color: #e9ecef;
        font-weight: 600;
        text-align: center;
    }

    .average-cell {
        background-color: #fff3cd;
        font-weight: 600;
        text-align: center;
    }

    .missing-score {
        color: #dc3545;
        font-style: italic;
    }

    .btn-export {
        background: linear-gradient(45deg, #000000, #ffd700);
        border: none;
        color: white;
        transition: all 0.3s ease;
        margin-left: 10px;
    }

    .btn-export:hover {
        background: linear-gradient(45deg, #ffd700, #000000);
        transform: translateY(-1px);
    }

    .export-section {
        background: white;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(0,0,0,0.1);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    table.dataTable {
        width: 100% !important;
    }

    .dataTables_wrapper .dataTables_filter {
        margin-bottom: 1rem;
    }

    @media (max-width: 768px) {
        .header-gradient {
            padding: 1rem;
            text-align: center;
        }

        .filter-section {
            padding: 1rem;
        }

        .export-section {
            text-align: center;
        }

        .btn-export {
            margin: 0.5rem 0;
            width: 100%;
        }
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

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="header-gradient d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div class="mb-3 mb-md-0">
                <h1 class="h3 mb-0">Class Raw Results</h1>
                <?php if (!empty($semesters)): ?>
                    <?php foreach ($semesters as $semester): ?>
                        <?php if ($semester['semester_id'] == $selectedSemester): ?>
                            <p class="mb-0">
                                <?php echo htmlspecialchars($semester['semester_name']); ?>
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <a href="results.php" class="btn btn-light">
                    <i class="fas fa-chart-pie me-2"></i>Results Overview
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3 align-items-end">
                <?php if (!empty($semesters)): ?>
                    <div class="col-md-3">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>"
                                    <?php echo $semester['semester_id'] == $selectedSemester ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="form-label">Class</label>
                    <select name="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php 
                        $seenClasses = [];
                        foreach ($teacherAssignments as $assignment):
                            if (!in_array($assignment['class_id'], $seenClasses)):
                                $seenClasses[] = $assignment['class_id'];
                        ?>
                            <option value="<?php echo $assignment['class_id']; ?>"
                                <?php echo $assignment['class_id'] == $selectedClass ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assignment['class_name']); ?>
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Subject</label>
                    <select name="subject" class="form-select">
                        <option value="">All Subjects</option>
                        <?php 
                        $seenSubjects = [];
                        foreach ($teacherAssignments as $assignment):
                            if (!in_array($assignment['subject_id'], $seenSubjects)):
                                $seenSubjects[] = $assignment['subject_id'];
                        ?>
                            <option value="<?php echo $assignment['subject_id']; ?>"
                                <?php echo $assignment['subject_id'] == $selectedSubject ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assignment['subject_name']); ?>
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <?php if ($selectedClass || $selectedSubject): ?>
            <!-- Export Options -->
            <div class="export-section mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <h5 class="mb-0">Export Results</h5>
                    <div class="d-flex flex-wrap mt-2 mt-md-0">
                        <a href="class_results.php?<?php echo http_build_query(['export' => 'pdf', 'class' => $selectedClass, 'subject' => $selectedSubject, 'semester' => $selectedSemester]); ?>" class="btn btn-export">
                            <i class="far fa-file-pdf me-2"></i>Export as PDF
                        </a>
                        <a href="class_results.php?<?php echo http_build_query(['export' => 'csv', 'class' => $selectedClass, 'subject' => $selectedSubject, 'semester' => $selectedSemester]); ?>" class="btn btn-export">
                            <i class="far fa-file-excel me-2"></i>Export as CSV
                        </a>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="results-table">
                <?php if (empty($students) || empty($assessments)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No assessment results found for the selected criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="resultsTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Class</th>
                                    <?php foreach ($assessments as $assessment): ?>
                                        <th class="text-center" title="<?php echo htmlspecialchars($assessment['title']); ?> (<?php echo date('Y-m-d', strtotime($assessment['date'])); ?>)">
                                            <?php 
                                            $title = $assessment['title'];
                                            echo (strlen($title) > 15) ? htmlspecialchars(substr($title, 0, 15) . '...') : htmlspecialchars($title);
                                            ?>
                                            <br>
                                            <small><?php echo date('Y-m-d', strtotime($assessment['date'])); ?></small>
                                            <br>
                                            <small>Max: <?php echo $assessment['total_max_score'] ?? 'N/A'; ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Total / Possible</th>
                                    <th class="text-center">Average (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentResults as $studentId => $result): ?>
                                    <tr>
                                        <td><?php echo $studentId; ?></td>
                                        <td><?php echo htmlspecialchars($result['student_info']['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['student_info']['first_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['student_info']['class_name']); ?></td>
                                        
                                        <?php foreach ($assessments as $assessment): ?>
                                            <td class="score-cell">
                                                <?php 
                                                $scoreData = $result['scores'][$assessment['assessment_id']] ?? null;
                                                if ($scoreData) {
                                                    echo $scoreData['raw_score'];
                                                } else {
                                                    echo '<span class="missing-score">N/A</span>';
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        
                                        <td class="total-cell">
                                            <?php echo $result['total_score']; ?> / <?php echo $result['total_possible']; ?>
                                        </td>
                                        <td class="average-cell"><?php echo $result['average_score']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5 bg-white rounded">
                <i class="fas fa-filter fa-3x text-muted mb-3"></i>
                <p class="text-muted">Please select a class or subject to view results.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize DataTables for the results table if it exists
        if (document.getElementById('resultsTable')) {
            $('#resultsTable').DataTable({
                "order": [
                    [1, "asc"]
                ], // Sort by last name by default
                "pageLength": 25,
                "lengthMenu": [
                    [10, 25, 50, 100, -1],
                    [10, 25, 50, 100, "All"]
                ],
                "columnDefs": [{
                    "orderable": false,
                    "targets": [<?php
                                // Make assessment score columns not sortable
                                if (!empty($assessments)) {
                                    $numCols = 4; // First 4 columns are sortable
                                    $notSortableCols = [];
                                    for ($i = $numCols; $i < $numCols + count($assessments); $i++) {
                                        $notSortableCols[] = $i;
                                    }
                                    echo implode(',', $notSortableCols);
                                }
                                ?>]
                }],
                "language": {
                    "emptyTable": "No assessment data available",
                    "zeroRecords": "No matching students found",
                    "info": "Showing _START_ to _END_ of _TOTAL_ students",
                    "infoEmpty": "Showing 0 to 0 of 0 students",
                    "infoFiltered": "(filtered from _MAX_ total students)"
                },
                "responsive": true
            });
        }
    });
</script>

<!-- Calculate statistics for average score by student -->
<?php if (!empty($studentResults)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Calculate statistics
            let scores = [];
            <?php foreach ($studentResults as $result): ?>
                scores.push(<?php echo $result['average_score']; ?>);
            <?php endforeach; ?>

            // Sort scores for percentile calculation
            scores.sort((a, b) => a - b);

            // Calculate statistics
            const sum = scores.reduce((a, b) => a + b, 0);
            const avg = (sum / scores.length).toFixed(1);
            const min = scores[0].toFixed(1);
            const max = scores[scores.length - 1].toFixed(1);

            // Calculate median
            let median;
            if (scores.length % 2 === 0) {
                median = ((scores[scores.length / 2 - 1] + scores[scores.length / 2]) / 2).toFixed(1);
            } else {
                median = scores[Math.floor(scores.length / 2)].toFixed(1);
            }

            // Display statistics at the top of the table if there's at least one student
            if (scores.length > 0) {
                const statsHtml = `
                <div class="mb-3 p-3 bg-light rounded">
                    <h6 class="mb-2">Class Statistics:</h6>
                    <div class="row g-2">
                        <div class="col-md-2 col-sm-4">
                            <div class="border rounded p-2 text-center">
                                <small>Average</small>
                                <h5 class="mb-0">${avg}%</h5>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4">
                            <div class="border rounded p-2 text-center">
                                <small>Median</small>
                                <h5 class="mb-0">${median}%</h5>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4">
                            <div class="border rounded p-2 text-center">
                                <small>Min</small>
                                <h5 class="mb-0">${min}%</h5>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4">
                            <div class="border rounded p-2 text-center">
                                <small>Max</small>
                                <h5 class="mb-0">${max}%</h5>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4">
                            <div class="border rounded p-2 text-center">
                                <small>Students</small>
                                <h5 class="mb-0">${scores.length}</h5>
                            </div>
                        </div>
                    </div>
                </div>
            `;

                // Insert before the table
                document.querySelector('.results-table').insertAdjacentHTML('afterbegin', statsHtml);
            }
        });
    </script>
<?php endif; ?>

<?php
// End output buffering and flush
$pageContent = ob_get_clean();
echo $pageContent;

// Include footer
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>