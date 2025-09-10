<?php
// teacher/export_results_pdf.php
// Start output buffering right at the beginning
ob_start();

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Teacher.php';
require_once BASEPATH . '/vendor/autoload.php'; // Include Composer autoloader for TCPDF

// Helper function to convert number to ordinal
function getOrdinal($number) {
    if (!in_array(($number % 100), [11, 12, 13])) {
        switch ($number % 10) {
            case 1:  return $number . 'st';
            case 2:  return $number . 'nd';
            case 3:  return $number . 'rd';
        }
    }
    return $number . 'th';
}

// Require teacher role
requireRole('teacher');

// Check if the required parameters are present
if (!isset($_POST['class_id']) || !isset($_POST['subject_id']) || !isset($_POST['assessment_id'])) {
    ob_end_clean(); // Clear the buffer before redirecting
    header('Location: generate_class_results.php');
    exit;
}

$classId = (int)$_POST['class_id'];
$subjectId = (int)$_POST['subject_id'];
$assessmentId = (int)$_POST['assessment_id'];
$teacherId = null;
$classInfo = null;
$results = [];
$stats = [];

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

    // Verify teacher has access to this class and subject
    $stmt = $db->prepare(
        "SELECT 1
         FROM teacherclassassignments
         WHERE teacher_id = ?
         AND class_id = ?
         AND subject_id = ?
         LIMIT 1"
    );
    $stmt->execute([$teacherId, $classId, $subjectId]);
    $hasAccess = $stmt->fetchColumn();

    if (!$hasAccess) {
        throw new Exception('You do not have permission to access this data');
    }

    // Get class, subject and assessment details
    $stmt = $db->prepare(
        "SELECT c.class_name, p.program_name, s.subject_name, 
                a.title as assessment_title, DATE_FORMAT(a.date, '%d/%m/%Y') as assessment_date, 
                a.description, sem.semester_name
         FROM classes c
         JOIN programs p ON c.program_id = p.program_id
         JOIN subjects s ON s.subject_id = ?
         JOIN assessments a ON a.assessment_id = ?
         JOIN semesters sem ON a.semester_id = sem.semester_id
         WHERE c.class_id = ?"
    );
    $stmt->execute([$subjectId, $assessmentId, $classId]);
    $classInfo = $stmt->fetch();

    if (!$classInfo) {
        throw new Exception('Information not found');
    }

    // Get all students in the class
    $stmt = $db->prepare(
        "SELECT COUNT(*) as total_students
         FROM students s
         WHERE s.class_id = ?"
    );
    $stmt->execute([$classId]);
    $totalStudents = $stmt->fetchColumn();

    // Get results for the assessment
    $stmt = $db->prepare(
        "SELECT r.student_id, s.first_name, s.last_name, r.score, r.created_at
         FROM results r
         JOIN students s ON r.student_id = s.student_id
         WHERE r.assessment_id = ?
         AND s.class_id = ?
         AND r.status = 'completed'
         ORDER BY r.score DESC"
    );
    $stmt->execute([$assessmentId, $classId]);
    $results = $stmt->fetchAll();

    // Add position (rank) to each result
    $rank = 1;
    $prevScore = null;
    $sameRankCount = 0;
    
    foreach ($results as $key => $result) {
        if ($prevScore !== null && $prevScore != $result['score']) {
            $rank += $sameRankCount;
            $sameRankCount = 1;
        } else {
            $sameRankCount++;
        }
        
        $results[$key]['position'] = $rank;
        $prevScore = $result['score'];
    }

    // Calculate statistics
    $stats = [
        'total_students' => $totalStudents,
        'students_attempted' => count($results),
        'participation_rate' => $totalStudents > 0 ? round((count($results) / $totalStudents) * 100, 1) : 0,
        'highest_score' => !empty($results) ? round($results[0]['score']) : 0,
        'lowest_score' => !empty($results) ? round($results[count($results) - 1]['score']) : 0,
        'average_score' => 0
    ];

    if (!empty($results)) {
        $totalScore = array_reduce($results, function($carry, $item) {
            return $carry + $item['score'];
        }, 0);
        $stats['average_score'] = round($totalScore / count($results));
    }

    // Create PDF using TCPDF
    class MYPDF extends TCPDF {
        // Page header
        public function Header() {
            // Set font for the school name (larger and bold)
            $this->SetFont('helvetica', 'B', 20);
            
            // School Name with underline
            $this->Cell(0, 10, 'BREMAN ASIKUMA SENIOR HIGH SCHOOL', 0, 1, 'C');
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'Online Mid-Sem Result', 0, 1, 'C');
            $this->Line(10, $this->GetY(), $this->getPageWidth()-10, $this->GetY());
            
            $this->Ln(5);
        }

        // Page footer
        public function Footer() {
            // Position at 15 mm from bottom
            $this->SetY(-15);
            // Set font
            $this->SetFont('helvetica', 'I', 8);
            // Page number
            $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }

    // Create new PDF document
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('School Management System');
    $pdf->SetTitle('Assessment Results');
    $pdf->SetSubject('Class Assessment Results Report');

    // Set default header data
    $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

    // Set margins
    $pdf->SetMargins(10, 40, 10); // Increased top margin for header
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();
    
    // Add info in the three-column layout
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 7, 'Program: ' . $classInfo['program_name'], 0, 0, 'L');
    $pdf->Cell(60, 7, 'Class: ' . $classInfo['class_name'], 0, 0, 'L');
    $pdf->Cell(70, 7, 'Subject: ' . $classInfo['subject_name'], 0, 1, 'L');

    $pdf->Ln(3);

    // Second line
    $pdf->Cell(60, 7, 'Registed Students: ' . ($stats['total_students']), 0, 0, 'L');
    $pdf->Cell(60, 7, 'Students Who Attempted: ' . $stats['students_attempted'], 0, 0, 'L');
    $pdf->Cell(70, 7, 'Assessment Date: ' . $classInfo['assessment_date'], 0, 1, 'L');

    $pdf->Ln(3);

    // Third line
    $pdf->Cell(60, 7, 'Highest mark: ' . $stats['highest_score'], 0, 0, 'L');
    $pdf->Cell(70, 7, 'Lowest mark: ' . $stats['lowest_score'], 0, 1, 'L');

    $pdf->Line(10, $pdf->GetY() + 5, $pdf->getPageWidth()-10, $pdf->GetY() + 5);
    $pdf->Ln(10);

    // Results Table
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'STUDENT RESULTS', 0, 1, 'C');
    
    // Table header
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(10, 7, 'S/N', 1, 0, 'C', true);
    $pdf->Cell(100, 7, 'Student Name', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Score', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Position', 1, 1, 'C', true);
    
    // Table rows
    $pdf->SetFont('helvetica', '', 10);
    $i = 1;
    foreach ($results as $result) {
        $pdf->Cell(10, 7, $i++, 1, 0, 'C');
        $pdf->Cell(100, 7, $result['last_name'] . ', ' . $result['first_name'], 1, 0, 'L');
        $pdf->Cell(30, 7, round($result['score']), 1, 0, 'C'); // Rounding scores to remove decimals
        $pdf->Cell(45, 7, getOrdinal($result['position']), 1, 1, 'C');
    }

    // IMPORTANT: Clean the output buffer before sending the PDF
    ob_end_clean();
    
    // Output the PDF
    $fileName = 'Class_' . $classId . '_' . $classInfo['subject_name'] . '_' . $classInfo['assessment_title'] . '_Results.pdf';
    $pdf->Output($fileName, 'D'); // 'D' means download
    exit;
    
} catch (Exception $e) {
    ob_end_clean(); // Clear the buffer before redirecting
    logError("Export results PDF error: " . $e->getMessage());
    $_SESSION['error'] = "Error generating PDF: " . $e->getMessage();
    header('Location: generate_class_results.php?class=' . $classId . '&subject=' . $subjectId . '&assessment=' . $assessmentId);
    exit;
}