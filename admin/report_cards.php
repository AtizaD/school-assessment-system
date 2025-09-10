<?php
// admin/report_cards.php
ob_start();
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only admin and teachers can access this page
requireRole(['admin', 'teacher']);

$error = '';
$success = '';
$reportCardData = null;
$selectedStudent = null;
$selectedSemester = null;

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get all classes for dropdown
    $stmt = $db->prepare("SELECT c.class_id, c.class_name, c.level, p.program_name 
                         FROM classes c JOIN programs p ON c.program_id = p.program_id 
                         ORDER BY p.program_name, c.level, c.class_name");
    $stmt->execute();
    $classes = $stmt->fetchAll();

    // Get all semesters for dropdown
    $stmt = $db->prepare("SELECT semester_id, semester_name FROM semesters ORDER BY start_date DESC");
    $stmt->execute();
    $semesters = $stmt->fetchAll();

    // Get current semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name FROM semesters 
         WHERE CURDATE() BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
        $studentId = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        $classId = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
        $semesterId = isset($_POST['semester_id']) ? intval($_POST['semester_id']) : 0;
        $generatePDF = isset($_POST['generate_pdf']) && $_POST['generate_pdf'] === '1';
        $generateBulkPDF = isset($_POST['generate_bulk_pdf']) && $_POST['generate_bulk_pdf'] === '1';
        

        // Handle form submission based on form type
        if ($formType === 'bulk' || $generateBulkPDF) {
            // Handle bulk report card generation
            if ($classId && $semesterId) {
                generateBulkReportCardsPDF($db, $classId, $semesterId);
                exit;
            } else {
                $error = "Please select both a class and semester for bulk generation.";
            }
        } else {
            // Handle individual report card generation
            if ($studentId && $semesterId) {
                $reportCardData = generateReportCardData($db, $studentId, $semesterId);
                
                if ($reportCardData) {
                    $selectedStudent = $studentId;
                    $selectedSemester = $semesterId;
                    
                    if ($generatePDF) {
                        generateReportCardPDF($reportCardData);
                        exit;
                    }
                } else {
                    $error = "No data found for the selected student and semester.";
                }
            } else {
                // Only show this error for individual report cards
                $error = "Please select both a student and semester.";
            }
        }
    }

} catch (Exception $e) {
    logError("Report card error: " . $e->getMessage());
    $error = "Error loading data: " . $e->getMessage();
}

/**
 * Generate comprehensive report card data for a student in a semester
 */
function generateReportCardData($db, $studentId, $semesterId) {
    // Get student information
    $stmt = $db->prepare(
        "SELECT s.*, c.class_name, c.level, p.program_name, p.program_id
         FROM students s
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE s.student_id = ?"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        return null;
    }

    // Get semester information
    $stmt = $db->prepare("SELECT * FROM semesters WHERE semester_id = ?");
    $stmt->execute([$semesterId]);
    $semester = $stmt->fetch();

    // Get all subjects for this student (regular class + special enrollments) with assessment data
    $stmt = $db->prepare(
        "SELECT DISTINCT 
            s.subject_id,
            s.subject_name,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                ELSE ?
            END as assessment_class_id,
            CASE 
                WHEN sc.sp_id IS NOT NULL THEN 'special'
                ELSE 'regular'
            END as enrollment_type,
            sc.notes as special_notes,
            COUNT(DISTINCT a.assessment_id) as total_assessments,
            COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments
         FROM subjects s
         LEFT JOIN special_class sc ON s.subject_id = sc.subject_id 
                                   AND sc.student_id = ? 
                                   AND sc.status = 'active'
         JOIN assessmentclasses ac ON s.subject_id = ac.subject_id 
                                  AND ac.class_id = CASE 
                                      WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                                      ELSE ?
                                  END
         JOIN assessments a ON ac.assessment_id = a.assessment_id
         LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
         WHERE (ac.class_id = ? OR sc.sp_id IS NOT NULL) AND a.semester_id = ?
         GROUP BY s.subject_id, s.subject_name, assessment_class_id, enrollment_type, sc.notes
         ORDER BY s.subject_name"
    );
    $stmt->execute([$student['class_id'], $studentId, $student['class_id'], $studentId, $student['class_id'], $semesterId]);
    $subjects = $stmt->fetchAll();

    $subjectResults = [];
    
    foreach ($subjects as $subject) {
        // Get assessment type breakdown for this subject
        $stmt = $db->prepare(
            "SELECT 
                COALESCE(at.type_name, 'Unassigned') as type_name,
                COALESCE(at.weight_percentage, 0) as weight_percentage,
                AVG(r.score) as average_score,
                COUNT(DISTINCT a.assessment_id) as total_assessments,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_assessments
             FROM assessments a
             JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
             LEFT JOIN assessment_types at ON a.assessment_type_id = at.type_id
             LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
             WHERE ac.subject_id = ? AND ac.class_id = ? AND a.semester_id = ?
             GROUP BY COALESCE(at.type_name, 'Unassigned'), at.weight_percentage
             ORDER BY COALESCE(at.type_name, 'Unassigned')"
        );
        $stmt->execute([$studentId, $subject['subject_id'], $subject['assessment_class_id'], $semesterId]);
        $assessmentTypes = $stmt->fetchAll();

        // Calculate weighted score for the subject
        $totalWeightedScore = 0;
        $totalWeight = 0;
        $unweightedScores = [];
        
        foreach ($assessmentTypes as $type) {
            if ($type['average_score'] !== null) {
                if ($type['weight_percentage'] > 0) {
                    $totalWeightedScore += ($type['average_score'] * $type['weight_percentage'] / 100);
                    $totalWeight += $type['weight_percentage'];
                } else {
                    $unweightedScores[] = $type['average_score'];
                }
            }
        }

        // If we have unweighted scores, average them and give remaining weight
        if (!empty($unweightedScores) && $totalWeight < 100) {
            $remainingWeight = 100 - $totalWeight;
            $unweightedAverage = array_sum($unweightedScores) / count($unweightedScores);
            $totalWeightedScore += ($unweightedAverage * $remainingWeight / 100);
            $totalWeight = 100;
        }

        // Calculate final subject score
        $finalScore = $totalWeight > 0 ? $totalWeightedScore : 0;
        
        // Get letter grade and remarks
        $gradeInfo = calculateGrade($finalScore);

        $subjectResults[] = [
            'subject_id' => $subject['subject_id'],
            'subject_name' => $subject['subject_name'],
            'enrollment_type' => $subject['enrollment_type'],
            'special_notes' => $subject['special_notes'],
            'assessment_class_id' => $subject['assessment_class_id'],
            'assessment_types' => $assessmentTypes,
            'total_assessments' => $subject['total_assessments'],
            'completed_assessments' => $subject['completed_assessments'],
            'final_score' => round($finalScore, 1),
            'letter_grade' => $gradeInfo['grade'],
            'grade_point' => $gradeInfo['points'],
            'remarks' => $gradeInfo['remarks']
        ];
    }

    // Calculate overall GPA
    $totalPoints = 0;
    $subjectCount = 0;
    $totalScore = 0;
    
    foreach ($subjectResults as $result) {
        if ($result['final_score'] > 0) {
            $totalPoints += $result['grade_point'];
            $totalScore += $result['final_score'];
            $subjectCount++;
        }
    }
    
    $gpa = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;
    $overallAverage = $subjectCount > 0 ? round($totalScore / $subjectCount, 1) : 0;
    $overallGrade = calculateGrade($overallAverage);

    return [
        'student' => $student,
        'semester' => $semester,
        'subjects' => $subjectResults,
        'summary' => [
            'total_subjects' => count($subjectResults),
            'gpa' => $gpa,
            'overall_average' => $overallAverage,
            'overall_grade' => $overallGrade['grade'],
            'overall_remarks' => $overallGrade['remarks']
        ]
    ];
}

/**
 * Calculate letter grade and grade points based on score
 */
function calculateGrade($score) {
    if ($score >= 80) {
        return ['grade' => 'A1', 'points' => 4.0, 'remarks' => 'Excellent'];
    } elseif ($score >= 75) {
        return ['grade' => 'B2', 'points' => 3.5, 'remarks' => 'Very Good'];
    } elseif ($score >= 70) {
        return ['grade' => 'B3', 'points' => 3.0, 'remarks' => 'Good'];
    } elseif ($score >= 65) {
        return ['grade' => 'C4', 'points' => 2.5, 'remarks' => 'Credit'];
    } elseif ($score >= 60) {
        return ['grade' => 'C5', 'points' => 2.0, 'remarks' => 'Credit'];
    } elseif ($score >= 55) {
        return ['grade' => 'C6', 'points' => 1.5, 'remarks' => 'Credit'];
    } elseif ($score >= 50) {
        return ['grade' => 'D7', 'points' => 1.0, 'remarks' => 'Pass'];
    } elseif ($score >= 45) {
        return ['grade' => 'E8', 'points' => 0.5, 'remarks' => 'Pass'];
    } else {
        return ['grade' => 'F9', 'points' => 0.0, 'remarks' => 'Fail'];
    }
}

/**
 * Generate bulk report cards for all students in a class
 */
function generateBulkReportCardsPDF($db, $classId, $semesterId) {
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Get class information
    $stmt = $db->prepare(
        "SELECT c.class_name, c.level, p.program_name 
         FROM classes c 
         JOIN programs p ON c.program_id = p.program_id 
         WHERE c.class_id = ?"
    );
    $stmt->execute([$classId]);
    $classInfo = $stmt->fetch();

    // Get semester information
    $stmt = $db->prepare("SELECT * FROM semesters WHERE semester_id = ?");
    $stmt->execute([$semesterId]);
    $semester = $stmt->fetch();

    // Get all students in the class
    $stmt = $db->prepare(
        "SELECT student_id, first_name, last_name 
         FROM students 
         WHERE class_id = ? 
         ORDER BY last_name, first_name"
    );
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll();

    if (empty($students)) {
        throw new Exception("No students found in the selected class.");
    }

    // Create ZIP file for bulk download
    $zip = new ZipArchive();
    $zipFilename = tempnam(sys_get_temp_dir(), 'report_cards') . '.zip';
    
    if ($zip->open($zipFilename, ZipArchive::CREATE) !== TRUE) {
        throw new Exception("Cannot create ZIP file");
    }

    $successCount = 0;
    $errors = [];

    foreach ($students as $student) {
        try {
            $reportCardData = generateReportCardData($db, $student['student_id'], $semesterId);
            
            if ($reportCardData && !empty($reportCardData['subjects'])) {
                // Generate PDF content for this student
                $pdfContent = generateSingleReportCardPDFContent($reportCardData);
                
                // Add to ZIP
                $filename = $classInfo['class_name'] . '_' . 
                           $student['last_name'] . '_' . 
                           $student['first_name'] . '_' . 
                           $semester['semester_name'] . '.pdf';
                
                // Clean filename
                $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);
                
                $zip->addFromString($filename, $pdfContent);
                $successCount++;
            } else {
                $errors[] = $student['first_name'] . ' ' . $student['last_name'] . ' (No assessment data)';
            }
        } catch (Exception $e) {
            $errors[] = $student['first_name'] . ' ' . $student['last_name'] . ' (Error: ' . $e->getMessage() . ')';
        }
    }

    $zip->close();

    if ($successCount === 0) {
        unlink($zipFilename);
        throw new Exception("No report cards could be generated. " . implode(', ', $errors));
    }

    // Download the ZIP file
    $downloadFilename = $classInfo['class_name'] . '_Report_Cards_' . $semester['semester_name'] . '.zip';
    $downloadFilename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $downloadFilename);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . filesize($zipFilename));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    readfile($zipFilename);
    unlink($zipFilename); // Clean up temporary file
}

/**
 * Generate PDF content for a single report card (returns content instead of outputting)
 */
function generateSingleReportCardPDFContent($data) {
    // Create new PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('School Assessment System');
    $pdf->SetTitle('Student Report Card');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    
    $pdf->AddPage();
    
    // School header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, SYSTEM_NAME, 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'STUDENT REPORT CARD', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Student information
    $student = $data['student'];
    $semester = $data['semester'];
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(40, 6, 'Student Name:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 6, $student['first_name'] . ' ' . $student['last_name'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Class:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $student['class_name'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(40, 6, 'Program:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 6, $student['program_name'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Semester:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $semester['semester_name'], 0, 1, 'L');
    
    $pdf->Ln(8);
    
    // Subject results table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(50, 50, 50);
    $pdf->SetTextColor(255, 255, 255);
    
    // Table headers
    $pdf->Cell(60, 8, 'Subject', 1, 0, 'L', true);
    $pdf->Cell(20, 8, 'Score', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Grade', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Points', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Remarks', 1, 1, 'C', true);
    
    // Subject rows
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    foreach ($data['subjects'] as $index => $subject) {
        $fill = ($index % 2 == 0);
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(60, 8, $subject['subject_name'], 1, 0, 'L', $fill);
        $pdf->Cell(20, 8, $subject['final_score'] . '%', 1, 0, 'C', $fill);
        $pdf->Cell(20, 8, $subject['letter_grade'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 8, $subject['grade_point'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 8, $subject['remarks'], 1, 1, 'C', $fill);
    }
    
    // Summary section
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'SEMESTER SUMMARY', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 11);
    $summary = $data['summary'];
    
    $pdf->Cell(50, 6, 'Total Subjects:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 6, $summary['total_subjects'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'GPA:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['gpa'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 6, 'Overall Average:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 6, $summary['overall_average'] . '%', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Overall Grade:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['overall_grade'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 6, 'Remarks:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['overall_remarks'], 0, 1, 'L');
    
    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Generated on: ' . date('F j, Y'), 0, 1, 'C');
    
    // Return PDF content as string
    return $pdf->Output('', 'S');
}

/**
 * Generate PDF report card
 */
function generateReportCardPDF($data) {
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Create new PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('School Assessment System');
    $pdf->SetTitle('Student Report Card');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    
    $pdf->AddPage();
    
    // School header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, SYSTEM_NAME, 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'STUDENT REPORT CARD', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Student information
    $student = $data['student'];
    $semester = $data['semester'];
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(40, 6, 'Student Name:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 6, $student['first_name'] . ' ' . $student['last_name'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Class:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $student['class_name'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(40, 6, 'Program:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(60, 6, $student['program_name'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Semester:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $semester['semester_name'], 0, 1, 'L');
    
    $pdf->Ln(8);
    
    // Subject results table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(50, 50, 50);
    $pdf->SetTextColor(255, 255, 255);
    
    // Table headers
    $pdf->Cell(60, 8, 'Subject', 1, 0, 'L', true);
    $pdf->Cell(20, 8, 'Score', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Grade', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Points', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Remarks', 1, 1, 'C', true);
    
    // Subject rows
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    foreach ($data['subjects'] as $index => $subject) {
        $fill = ($index % 2 == 0);
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(60, 8, $subject['subject_name'], 1, 0, 'L', $fill);
        $pdf->Cell(20, 8, $subject['final_score'] . '%', 1, 0, 'C', $fill);
        $pdf->Cell(20, 8, $subject['letter_grade'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 8, $subject['grade_point'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 8, $subject['remarks'], 1, 1, 'C', $fill);
    }
    
    // Summary section
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'SEMESTER SUMMARY', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 11);
    $summary = $data['summary'];
    
    $pdf->Cell(50, 6, 'Total Subjects:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 6, $summary['total_subjects'], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'GPA:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['gpa'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 6, 'Overall Average:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(40, 6, $summary['overall_average'] . '%', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(30, 6, 'Overall Grade:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['overall_grade'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(50, 6, 'Remarks:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, $summary['overall_remarks'], 0, 1, 'L');
    
    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Generated on: ' . date('F j, Y'), 0, 1, 'C');
    
    // Output PDF
    $filename = 'report_card_' . $student['first_name'] . '_' . $student['last_name'] . '_' . $semester['semester_name'] . '.pdf';
    $pdf->Output($filename, 'D');
}

$pageTitle = 'Student Report Cards';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning mb-0">Student Report Cards</h1>
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

    <!-- Filter Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Generate Report Cards</h5>
        </div>
        <div class="card-body">
            <!-- Tab Navigation -->
            <ul class="nav nav-pills mb-4" id="reportCardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="individual-tab" data-bs-toggle="pill" data-bs-target="#individual" type="button" role="tab">
                        <i class="fas fa-user me-2"></i>Individual Report Card
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bulk-tab" data-bs-toggle="pill" data-bs-target="#bulk" type="button" role="tab">
                        <i class="fas fa-users me-2"></i>Bulk Report Cards
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="reportCardTabsContent">
                <!-- Individual Report Card Tab -->
                <div class="tab-pane fade show active" id="individual" role="tabpanel">
                    <form method="post" action="" id="individualReportCardForm">
                        <!-- Hidden field to explicitly mark this as individual form -->
                        <input type="hidden" name="form_type" value="individual">
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="class_id_individual" class="form-label">Select Class</label>
                                <select name="class_id" id="class_id_individual" class="form-select" required>
                                    <option value="">Choose a class...</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['class_id']; ?>">
                                            <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="student_id" class="form-label">Select Student</label>
                                <select name="student_id" id="student_id" class="form-select" required disabled>
                                    <option value="">Select class first...</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="semester_id_individual" class="form-label">Select Semester</label>
                                <select name="semester_id" id="semester_id_individual" class="form-select" required>
                                    <option value="">Choose a semester...</option>
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?php echo $semester['semester_id']; ?>"
                                            <?php if ($currentSemester && $currentSemester['semester_id'] == $semester['semester_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($semester['semester_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <button type="submit" name="generate_preview" class="btn btn-warning">
                                    <i class="fas fa-eye me-2"></i>Preview Report Card
                                </button>
                                <button type="submit" name="generate_pdf" value="1" class="btn btn-success" id="pdfBtn" disabled>
                                    <i class="fas fa-file-pdf me-2"></i>Download PDF
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Bulk Report Cards Tab -->
                <div class="tab-pane fade" id="bulk" role="tabpanel">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Bulk Generation:</strong> This will generate report cards for ALL students in the selected class and download them as a ZIP file.
                    </div>
                    
                    <form method="post" action="" id="bulkReportCardForm">
                        <!-- Hidden field to explicitly mark this as bulk form -->
                        <input type="hidden" name="form_type" value="bulk">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="class_id_bulk" class="form-label">Select Class</label>
                                <select name="class_id" id="class_id_bulk" class="form-select" required>
                                    <option value="">Choose a class...</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['class_id']; ?>">
                                            <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="semester_id_bulk" class="form-label">Select Semester</label>
                                <select name="semester_id" id="semester_id_bulk" class="form-select" required>
                                    <option value="">Choose a semester...</option>
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?php echo $semester['semester_id']; ?>"
                                            <?php if ($currentSemester && $currentSemester['semester_id'] == $semester['semester_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($semester['semester_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <button type="button" id="previewBulkBtn" class="btn btn-info">
                                    <i class="fas fa-list me-2"></i>Preview Student List
                                </button>
                                <button type="submit" name="generate_bulk_pdf" value="1" class="btn btn-primary" id="bulkPdfBtn" disabled>
                                    <i class="fas fa-download me-2"></i>Download All Report Cards (ZIP)
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Student List Preview -->
                    <div id="studentListPreview" class="mt-4" style="display: none;">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Students in Selected Class</h6>
                            </div>
                            <div class="card-body">
                                <div id="studentListContent">
                                    <!-- Student list will be populated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Card Preview -->
    <?php if ($reportCardData): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Report Card Preview</h5>
        </div>
        <div class="card-body">
            <!-- Student Information -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <h6 class="fw-bold">Student Information</h6>
                    <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($reportCardData['student']['first_name'] . ' ' . $reportCardData['student']['last_name']); ?></p>
                    <p class="mb-1"><strong>Class:</strong> <?php echo htmlspecialchars($reportCardData['student']['class_name']); ?></p>
                    <p class="mb-0"><strong>Program:</strong> <?php echo htmlspecialchars($reportCardData['student']['program_name']); ?></p>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold">Semester Information</h6>
                    <p class="mb-1"><strong>Semester:</strong> <?php echo htmlspecialchars($reportCardData['semester']['semester_name']); ?></p>
                    <p class="mb-0"><strong>Period:</strong> <?php echo date('M d, Y', strtotime($reportCardData['semester']['start_date'])) . ' - ' . date('M d, Y', strtotime($reportCardData['semester']['end_date'])); ?></p>
                </div>
            </div>

            <!-- Subject Results -->
            <div class="table-responsive mb-4">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Subject</th>
                            <th class="text-center">Assessments</th>
                            <th class="text-center">Score</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Points</th>
                            <th class="text-center">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportCardData['subjects'] as $subject): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                <br><small class="text-muted"><?php echo $subject['completed_assessments']; ?>/<?php echo $subject['total_assessments']; ?> completed</small>
                            </td>
                            <td class="text-center">
                                <div class="progress" style="height: 20px;">
                                    <?php 
                                    $completion = $subject['total_assessments'] > 0 ? 
                                        ($subject['completed_assessments'] / $subject['total_assessments']) * 100 : 0;
                                    ?>
                                    <div class="progress-bar bg-info" style="width: <?php echo $completion; ?>%">
                                        <?php echo round($completion); ?>%
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold text-primary"><?php echo $subject['final_score']; ?>%</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $subject['letter_grade']; ?></span>
                            </td>
                            <td class="text-center"><?php echo $subject['grade_point']; ?></td>
                            <td class="text-center">
                                <span class="<?php echo $subject['grade_point'] >= 2.0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $subject['remarks']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary -->
            <div class="row">
                <div class="col-md-8">
                    <h6 class="fw-bold">Semester Summary</h6>
                    <div class="row">
                        <div class="col-sm-6">
                            <p class="mb-1"><strong>Total Subjects:</strong> <?php echo $reportCardData['summary']['total_subjects']; ?></p>
                            <p class="mb-1"><strong>Overall Average:</strong> <?php echo $reportCardData['summary']['overall_average']; ?>%</p>
                        </div>
                        <div class="col-sm-6">
                            <p class="mb-1"><strong>GPA:</strong> <?php echo $reportCardData['summary']['gpa']; ?></p>
                            <p class="mb-1"><strong>Overall Grade:</strong> 
                                <span class="badge bg-primary"><?php echo $reportCardData['summary']['overall_grade']; ?></span>
                            </p>
                        </div>
                    </div>
                    <p class="mb-0"><strong>Remarks:</strong> 
                        <span class="<?php echo $reportCardData['summary']['gpa'] >= 2.0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $reportCardData['summary']['overall_remarks']; ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h2 class="display-4 text-primary mb-0"><?php echo $reportCardData['summary']['gpa']; ?></h2>
                            <p class="text-muted mb-0">Grade Point Average</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enable PDF download after preview -->
            <script>
                document.getElementById('pdfBtn').disabled = false;
            </script>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Individual report card elements
    const classSelectIndividual = document.getElementById('class_id_individual');
    const studentSelect = document.getElementById('student_id');
    
    // Bulk report card elements  
    const classSelectBulk = document.getElementById('class_id_bulk');
    const previewBulkBtn = document.getElementById('previewBulkBtn');
    const bulkPdfBtn = document.getElementById('bulkPdfBtn');
    const studentListPreview = document.getElementById('studentListPreview');
    const studentListContent = document.getElementById('studentListContent');

    // Load students for individual report card
    classSelectIndividual.addEventListener('change', function() {
        const classId = this.value;
        
        if (classId) {
            studentSelect.disabled = true;
            studentSelect.innerHTML = '<option value="">Loading students...</option>';
            
            fetch(`../api/get_students.php?class_id=${classId}`)
                .then(response => response.json())
                .then(data => {
                    studentSelect.innerHTML = '<option value="">Select a student...</option>';
                    
                    data.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.student_id;
                        option.textContent = `${student.last_name}, ${student.first_name}`;
                        studentSelect.appendChild(option);
                    });
                    
                    studentSelect.disabled = false;
                })
                .catch(error => {
                    studentSelect.innerHTML = '<option value="">Error loading students</option>';
                });
        } else {
            studentSelect.innerHTML = '<option value="">Select class first...</option>';
            studentSelect.disabled = true;
        }
    });

    // Preview student list for bulk generation
    previewBulkBtn.addEventListener('click', function() {
        const classId = classSelectBulk.value;
        
        if (!classId) {
            alert('Please select a class first.');
            return;
        }

        // Show loading
        studentListContent.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading students...</p></div>';
        studentListPreview.style.display = 'block';

        fetch(`../api/get_students.php?class_id=${classId}`)
            .then(response => response.json())
            .then(data => {
                if (data.length === 0) {
                    studentListContent.innerHTML = '<div class="alert alert-warning">No students found in this class.</div>';
                    bulkPdfBtn.disabled = true;
                } else {
                    let html = `
                        <div class="alert alert-success">
                            <strong>${data.length}</strong> students found in this class. Report cards will be generated for all of them.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    data.forEach((student, index) => {
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${student.last_name}, ${student.first_name}</td>
                                <td><span class="badge bg-success">Ready</span></td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table></div>';
                    studentListContent.innerHTML = html;
                    // Keep download button enabled (it should already be enabled from checkBulkFormValidity)
                    bulkPdfBtn.disabled = false;
                }
            })
            .catch(error => {
                studentListContent.innerHTML = '<div class="alert alert-danger">Error loading students. Please try again.</div>';
                bulkPdfBtn.disabled = true;
            });
    });

    // Enable bulk PDF generation button when both class and semester are selected
    function checkBulkFormValidity() {
        const classId = classSelectBulk.value;
        const semesterId = document.getElementById('semester_id_bulk').value;
        
        if (classId && semesterId) {
            previewBulkBtn.disabled = false;
            bulkPdfBtn.disabled = false; // Enable download button when class + semester selected
        } else {
            previewBulkBtn.disabled = true;
            bulkPdfBtn.disabled = true;
            studentListPreview.style.display = 'none';
        }
    }

    classSelectBulk.addEventListener('change', checkBulkFormValidity);
    document.getElementById('semester_id_bulk').addEventListener('change', checkBulkFormValidity);

    // Handle bulk form submission with loading indicator
    document.getElementById('bulkReportCardForm').addEventListener('submit', function(e) {
        const submitBtn = bulkPdfBtn;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Generating Report Cards...';
        
        // Re-enable button after 10 seconds in case of issues
        setTimeout(function() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-download me-2"></i>Download All Report Cards (ZIP)';
        }, 10000);
    });

    // Initialize form state
    checkBulkFormValidity();
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>