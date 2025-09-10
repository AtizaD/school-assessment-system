<?php
// admin/generate_results_pdf.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once BASEPATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

// Ensure user is admin
requireRole('admin');
ob_start();

// Initialize variables
$classes = [];
$programs = [];
$subjects = [];
$semesters = [];
$levels = [];
$assessments = [];
$error = '';
$success = '';
$studentResults = [];
$exportMode = false;

// Get filter values
$filter_program = isset($_GET['program']) ? sanitizeInput($_GET['program']) : '';
$filter_class = isset($_GET['class']) ? sanitizeInput($_GET['class']) : '';
$filter_subject = isset($_GET['subject']) ? sanitizeInput($_GET['subject']) : '';
$filter_semester = isset($_GET['semester']) ? sanitizeInput($_GET['semester']) : '';
$filter_level = isset($_GET['level']) ? sanitizeInput($_GET['level']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $exportMode = true;
    
    // Get the class_id for report generation
    $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
    $semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
    
    if (!$class_id || !$semester_id) {
        $error = "Missing required parameters for report generation";
        $exportMode = false;
    }
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Fetch all programs for filter dropdown
    $stmt = $db->query("SELECT program_id, program_name FROM Programs ORDER BY program_name");
    $programs = $stmt->fetchAll();
    
    // Fetch all subjects for filter dropdown
    $stmt = $db->query("SELECT subject_id, subject_name FROM Subjects ORDER BY subject_name");
    $subjects = $stmt->fetchAll();
    
    // Fetch all semesters for filter dropdown
    $stmt = $db->query("SELECT semester_id, semester_name, start_date, end_date FROM Semesters ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
    
    // Fetch all classes for filter dropdown
    $classQuery = "SELECT c.class_id, c.class_name, p.program_name, c.level 
                  FROM Classes c 
                  JOIN Programs p ON c.program_id = p.program_id 
                  ORDER BY p.program_name, c.level, c.class_name";
    $stmt = $db->query($classQuery);
    $classes = $stmt->fetchAll();
    
    // Get unique class levels
    $levelQuery = "SELECT DISTINCT level FROM Classes ORDER BY level";
    $stmt = $db->query($levelQuery);
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch all assessments for filter dropdown
    $assessmentQuery = "SELECT assessment_id, title, date FROM Assessments ORDER BY date DESC, title";
    $stmt = $db->query($assessmentQuery);
    $assessments = $stmt->fetchAll();

    if ($exportMode) {
        // Generate PDF report for a specific class
        generateClassReport($db, $class_id, $semester_id);
    } else if (!empty($filter_class) && !empty($filter_semester)) {
        // Fetch results for the selected class and semester
        $class_id = intval($filter_class);
        $semester_id = intval($filter_semester);
        
        // Get class details
        $classStmt = $db->prepare("
            SELECT c.class_name, p.program_name, c.level
            FROM Classes c
            JOIN Programs p ON c.program_id = p.program_id
            WHERE c.class_id = ?
        ");
        $classStmt->execute([$class_id]);
        $classDetails = $classStmt->fetch();
        
        if (!$classDetails) {
            throw new Exception("Class not found");
        }
        
        // Get semester details
        $semesterStmt = $db->prepare("SELECT semester_name FROM Semesters WHERE semester_id = ?");
        $semesterStmt->execute([$semester_id]);
        $semesterDetails = $semesterStmt->fetch();
        
        if (!$semesterDetails) {
            throw new Exception("Semester not found");
        }
        
        // Get all students in the class
        $studentStmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name
            FROM Students s
            WHERE s.class_id = ?
            ORDER BY s.last_name, s.first_name
        ");
        $studentStmt->execute([$class_id]);
        $students = $studentStmt->fetchAll();
        
        if (empty($students)) {
            $success = "No students found in this class";
        } else {
            // Get all subjects for this class
            $subjectStmt = $db->prepare("
                SELECT DISTINCT s.subject_id, s.subject_name
                FROM ClassSubjects cs
                JOIN Subjects s ON cs.subject_id = s.subject_id
                WHERE cs.class_id = ?
                ORDER BY s.subject_name
            ");
            $subjectStmt->execute([$class_id]);
            $classSubjects = $subjectStmt->fetchAll();
            
            if (empty($classSubjects)) {
                $success = "No subjects assigned to this class";
            } else {
                // For each student, get their results for each subject
                $studentResults = [];
                
                foreach ($students as $student) {
                    $resultRow = [
                        'student_id' => $student['student_id'],
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'scores' => []
                    ];
                    
                    foreach ($classSubjects as $subject) {
                        // Get the average score for the student in this subject from all assessments in the semester
                        $scoreStmt = $db->prepare("
                            SELECT COALESCE(AVG(r.score), 0) as avg_score
                            FROM Results r
                            JOIN Assessments a ON r.assessment_id = a.assessment_id
                            JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                            WHERE r.student_id = ?
                            AND ac.subject_id = ?
                            AND a.semester_id = ?
                        ");
                        $scoreStmt->execute([$student['student_id'], $subject['subject_id'], $semester_id]);
                        $score = $scoreStmt->fetchColumn();
                        
                        $resultRow['scores'][$subject['subject_id']] = number_format($score, 2);
                    }
                    
                    $studentResults[] = $resultRow;
                }
                
                $success = count($studentResults) . " student results loaded successfully";
            }
        }
    }

} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    logError("Generate results error: " . $e->getMessage());
}

// Function to generate class report as PDF
function generateClassReport($db, $class_id, $semester_id) {
    try {
        // Get class details
        $classStmt = $db->prepare("
            SELECT c.class_name, p.program_name, c.level
            FROM Classes c
            JOIN Programs p ON c.program_id = p.program_id
            WHERE c.class_id = ?
        ");
        $classStmt->execute([$class_id]);
        $classDetails = $classStmt->fetch();
        
        if (!$classDetails) {
            throw new Exception("Class not found");
        }
        
        // Get semester details
        $semesterStmt = $db->prepare("SELECT semester_name FROM Semesters WHERE semester_id = ?");
        $semesterStmt->execute([$semester_id]);
        $semesterDetails = $semesterStmt->fetch();
        
        if (!$semesterDetails) {
            throw new Exception("Semester not found");
        }
        
        // Get all students in the class
        $studentStmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name
            FROM Students s
            WHERE s.class_id = ?
            ORDER BY s.last_name, s.first_name
        ");
        $studentStmt->execute([$class_id]);
        $students = $studentStmt->fetchAll();
        
        if (empty($students)) {
            throw new Exception("No students found in this class");
        }
        
        // Get all subjects for this class
        $subjectStmt = $db->prepare("
            SELECT DISTINCT s.subject_id, s.subject_name
            FROM ClassSubjects cs
            JOIN Subjects s ON cs.subject_id = s.subject_id
            WHERE cs.class_id = ?
            ORDER BY s.subject_name
        ");
        $subjectStmt->execute([$class_id]);
        $subjects = $subjectStmt->fetchAll();
        
        if (empty($subjects)) {
            throw new Exception("No subjects assigned to this class");
        }
        
        // For each student, get their results for each subject
        $studentResults = [];
        
        foreach ($students as $student) {
            $resultRow = [
                'student_id' => $student['student_id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'scores' => []
            ];
            
            foreach ($subjects as $subject) {
                // Get the average score for the student in this subject from all assessments in the semester
                $scoreStmt = $db->prepare("
                    SELECT COALESCE(AVG(r.score), 0) as avg_score
                    FROM Results r
                    JOIN Assessments a ON r.assessment_id = a.assessment_id
                    JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                    WHERE r.student_id = ?
                    AND ac.subject_id = ?
                    AND a.semester_id = ?
                ");
                $scoreStmt->execute([$student['student_id'], $subject['subject_id'], $semester_id]);
                $score = $scoreStmt->fetchColumn();
                
                $resultRow['scores'][$subject['subject_id']] = number_format($score, 2);
            }
            
            $studentResults[] = $resultRow;
        }
        
        // Initialize TCPDF
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator(SYSTEM_NAME);
        $pdf->SetAuthor('Administrator');
        $pdf->SetTitle($classDetails['program_name'] . ' - ' . $classDetails['class_name'] . ' Report');
        $pdf->SetSubject('Student Results Report');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont('courier');
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(true, 10);
        
        // Set image scale factor
        $pdf->setImageScale(1.25);
        
        // Set font
        $pdf->SetFont('helvetica', '', 10);
        
        // Add a page
        $pdf->AddPage();
        
        // Set header content
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, strtoupper($classDetails['program_name'] . ' - ' . $classDetails['class_name']), 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Semester: ' . $semesterDetails['semester_name'], 0, 1, 'C');
        $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y'), 0, 1, 'C');
        $pdf->Ln(5);
        
        // Create table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        
        $cellWidth = 40; // Width for student name cell
        $subjectWidth = (265 - $cellWidth) / count($subjects); // Width for each subject cell
        
        $pdf->Cell($cellWidth, 10, 'Student Name', 1, 0, 'C', 1);
        
        foreach ($subjects as $subject) {
            $pdf->Cell($subjectWidth, 10, $subject['subject_name'], 1, 0, 'C', 1);
        }
        
        $pdf->Ln();
        
        // Create table content
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
        
        $counter = 1;
        $fill = false;
        
        foreach ($studentResults as $result) {
            $fillColor = $fill ? 240 : 255;
            $pdf->SetFillColor($fillColor, $fillColor, $fillColor);
            
            $pdf->Cell($cellWidth, 10, $counter . '. ' . $result['student_name'], 1, 0, 'L', 1);
            
            foreach ($subjects as $subject) {
                $score = isset($result['scores'][$subject['subject_id']]) ? $result['scores'][$subject['subject_id']] : '0.00';
                
                // Color coding based on score
                if ($score < 10) {
                    $pdf->SetTextColor(255, 0, 0); // Red for failing
                } else if ($score < 12) {
                    $pdf->SetTextColor(255, 165, 0); // Orange for warning
                } else if ($score >= 16) {
                    $pdf->SetTextColor(0, 128, 0); // Green for excellent
                } else {
                    $pdf->SetTextColor(0, 0, 0); // Black for normal
                }
                
                $pdf->Cell($subjectWidth, 10, $score, 1, 0, 'C', 1);
                $pdf->SetTextColor(0, 0, 0); // Reset text color
            }
            
            $pdf->Ln();
            $counter++;
            $fill = !$fill;
        }
        
        // Add class statistics
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Class Statistics', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        
        // Calculate class averages for each subject
        $classAverages = [];
        $highestScores = [];
        $lowestScores = [];
        
        foreach ($subjects as $subject) {
            $subjectId = $subject['subject_id'];
            $scores = array_column(array_column($studentResults, 'scores'), $subjectId);
            $scores = array_map('floatval', $scores); // Convert to float for calculations
            
            $classAverages[$subjectId] = !empty($scores) ? array_sum($scores) / count($scores) : 0;
            $highestScores[$subjectId] = !empty($scores) ? max($scores) : 0;
            $lowestScores[$subjectId] = !empty($scores) ? min($scores) : 0;
        }
        
        // Print statistics table
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(60, 10, 'Subject', 1, 0, 'C', 1);
        $pdf->Cell(40, 10, 'Class Average', 1, 0, 'C', 1);
        $pdf->Cell(40, 10, 'Highest Score', 1, 0, 'C', 1);
        $pdf->Cell(40, 10, 'Lowest Score', 1, 0, 'C', 1);
        $pdf->Ln();
        
        foreach ($subjects as $subject) {
            $subjectId = $subject['subject_id'];
            
            $pdf->Cell(60, 10, $subject['subject_name'], 1, 0, 'L');
            $pdf->Cell(40, 10, number_format($classAverages[$subjectId], 2), 1, 0, 'C');
            $pdf->Cell(40, 10, number_format($highestScores[$subjectId], 2), 1, 0, 'C');
            $pdf->Cell(40, 10, number_format($lowestScores[$subjectId], 2), 1, 0, 'C');
            $pdf->Ln();
        }
        
        // Output the PDF
        $pdf->Output($classDetails['program_name'] . '_' . $classDetails['class_name'] . '_Report.pdf', 'D');
        exit;
        
    } catch (Exception $e) {
        // If PDF generation fails, redirect back with error message
        $_SESSION['error'] = "Failed to generate PDF: " . $e->getMessage();
        header("Location: generate_results_pdf.php");
        exit;
    }
}

$pageTitle = 'Generate Student Results PDF';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-warning">Generate Student Results Report</h1>
            <p class="text-muted">Generate and export class results in PDF format</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select" onchange="updateClassList()">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['program_id']; ?>" 
                                        <?php echo $filter_program == $program['program_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-select" onchange="updateClassList()">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo htmlspecialchars($level); ?>" 
                                        <?php echo $filter_level === $level ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Class <span class="text-danger">*</span></label>
                        <select name="class" id="classSelect" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                        data-program="<?php echo $class['program_name']; ?>"
                                        data-level="<?php echo $class['level']; ?>"
                                        <?php echo $filter_class == $class['class_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Semester <span class="text-danger">*</span></label>
                        <select name="semester" class="form-select" required>
                            <option value="">Select Semester</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>" 
                                        <?php echo $filter_semester == $semester['semester_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name'] . ' (' . $semester['start_date'] . ' - ' . $semester['end_date'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Subject</label>
                        <select name="subject" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>" 
                                        <?php echo $filter_subject == $subject['subject_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?php echo $filter_date_from; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?php echo $filter_date_to; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by name..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a href="generate_results_pdf.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-1"></i> Reset Filters
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                        <?php if (!empty($filter_class) && !empty($filter_semester)): ?>
                            <a href="generate_results_pdf.php?export=pdf&class_id=<?php echo $filter_class; ?>&semester_id=<?php echo $filter_semester; ?>" 
                               class="btn btn-warning" target="_blank">
                                <i class="fas fa-file-pdf me-1"></i> Generate PDF Report
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Results Preview -->
    <?php if (!empty($studentResults) && !empty($classSubjects)): ?>
        <div class="card shadow">
            <div class="card-header bg-dark text-warning py-3">
                <h5 class="mb-0">
                    <?php echo htmlspecialchars($classDetails['program_name'] . ' - ' . $classDetails['class_name']); ?> Results 
                    <span class="badge bg-primary"><?php echo htmlspecialchars($semesterDetails['semester_name']); ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">#</th>
                                <th width="20%">Student Name</th>
                                <?php foreach ($classSubjects as $subject): ?>
                                    <th><?php echo htmlspecialchars($subject['subject_name']); ?></th>
                                <?php endforeach; ?>
                                <th>Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentResults as $index => $result): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    
                                    <?php 
                                    $totalScore = 0;
                                    $subjectCount = 0;
                                    
                                    foreach ($classSubjects as $subject): 
                                        $score = isset($result['scores'][$subject['subject_id']]) ? $result['scores'][$subject['subject_id']] : '0.00';
                                        $totalScore += floatval($score);
                                        $subjectCount++;
                                        
                                        // Determine score color
                                        $scoreClass = '';
                                        if (floatval($score) < 10) {
                                            $scoreClass = 'text-danger fw-bold';
                                        } else if (floatval($score) < 12) {
                                            $scoreClass = 'text-warning fw-bold';
                                        } else if (floatval($score) >= 16) {
                                            $scoreClass = 'text-success fw-bold';
                                        }
                                    ?>
                                        <td class="<?php echo $scoreClass; ?>"><?php echo $score; ?></td>
                                    <?php endforeach; ?>
                                    
                                    <td class="fw-bold">
                                        <?php 
                                        $average = $subjectCount > 0 ? $totalScore / $subjectCount : 0; 
                                        echo number_format($average, 2);
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif (!empty($filter_class) && !empty($filter_semester)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i> No results found for the selected criteria. Please make sure assessments have been conducted and graded for this class and semester.
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i> Please select a class and semester to view and generate student results.
        </div>
    <?php endif; ?>
</div>

<script>
// Function to filter class dropdown based on selected program and level
function updateClassList() {
    const programSelect = document.querySelector('select[name="program"]');
    const levelSelect = document.querySelector('select[name="level"]');
    const classSelect = document.getElementById('classSelect');
    
    const selectedProgram = programSelect.value;
    const selectedLevel = levelSelect.value;
    
    // Show all options first
    Array.from(classSelect.options).forEach(option => {
        option.style.display = 'block';
    });
    
    // Hide options that don't match the selected filters
    if (selectedProgram || selectedLevel) {
        Array.from(classSelect.options).forEach(option => {
            if (option.value) { // Skip the placeholder option
                const showOption = 
                    (!selectedProgram || option.getAttribute('data-program') === programSelect.options[programSelect.selectedIndex].text) && 
                    (!selectedLevel || option.getAttribute('data-level') === selectedLevel);
                
                option.style.display = showOption ? 'block' : 'none';
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize the class filter
    updateClassList();
    
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
});
</script>

<style>
/* Custom Styles for Results Page */
.table > :not(caption) > * > * {
    padding: 0.75rem;
}

.table-dark th {
    background: linear-gradient(45deg, #000000, #333333);
    color: #ffd700;
    font-weight: 500;
    white-space: nowrap;
}

.bg-warning, .btn-warning {
    background: linear-gradient(45deg, #000000, #ffd700) !important;
    border: none;
    color: white !important;
}

.btn-warning:hover {
    background: linear-gradient(45deg, #ffd700, #000000) !important;
    color: white !important;
}

.card {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.text-warning {
    color: #ffd700 !important;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.8em;
}

/* For print layout */
@media print {
    .navbar, #sidebar-wrapper, 
    .btn, button, 
    .card-header, .form-control, .form-select,
    #filterForm, .d-flex.justify-content-end {
        display: none !important;
    }
    
    .card {
        border: 1px solid #ddd;
        margin-bottom: 1rem;
        box-shadow: none !important;
    }
    
    .container-fluid {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    table {
        width: 100% !important;
        font-size: 11px !important;
    }
    
    @page {
        size: landscape;
        margin: 0.5cm;
    }
}
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>