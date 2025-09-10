<?php
// Start output buffering
ob_start();

// Suppress notices and warnings during PDF generation
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// admin/export_performance_pdf.php
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only admin and teachers can access this page
requireRole(['admin', 'teacher']);

// Check if required parameters are provided (now including level)
if (!isset($_GET['level']) && !isset($_GET['class_id']) && !isset($_GET['subject_id']) && !isset($_GET['semester_id'])) {
    header('HTTP/1.0 400 Bad Request');
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Get filter parameters
$level = isset($_GET['level']) ? sanitizeInput($_GET['level']) : null;
$classId = isset($_GET['class_id']) ? $_GET['class_id'] : null;
$subjectId = isset($_GET['subject_id']) ? $_GET['subject_id'] : null;
$semesterId = isset($_GET['semester_id']) ? $_GET['semester_id'] : null;

try {
    // Import the functions from get_student_performance.php without executing the API code
    require_once BASEPATH . '/api/get_student_performance.php';

    // Get database connection
    $db = DatabaseConfig::getInstance()->getConnection();

    // Get filter descriptions for report title
    $filterDescriptions = [];
    
    if ($level) {
        $filterDescriptions[] = "Level: {$level}";
    }
    
    if ($classId) {
        $stmt = $db->prepare("SELECT c.class_name, c.level, p.program_name FROM classes c JOIN programs p ON c.program_id = p.program_id WHERE c.class_id = ?");
        $stmt->execute([$classId]);
        $classInfo = $stmt->fetch();
        if ($classInfo) {
            $filterDescriptions[] = "Class: {$classInfo['class_name']} ({$classInfo['program_name']})";
        }
    }
    
    if ($subjectId) {
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
        $stmt->execute([$subjectId]);
        $subjectInfo = $stmt->fetch();
        if ($subjectInfo) {
            $filterDescriptions[] = "Subject: {$subjectInfo['subject_name']}";
        }
    }
    
    if ($semesterId) {
        $stmt = $db->prepare("SELECT semester_name FROM semesters WHERE semester_id = ?");
        $stmt->execute([$semesterId]);
        $semesterInfo = $stmt->fetch();
        if ($semesterInfo) {
            $filterDescriptions[] = "Semester: {$semesterInfo['semester_name']}";
        }
    }
    
    $filterText = !empty($filterDescriptions) ? implode(' | ', $filterDescriptions) : 'All Data';

    // Directly call the functions to get data with level parameter
    $data = [
        'summary' => get_summary_stats($db, $level, $classId, $subjectId, $semesterId),
        'top_performers' => get_top_performers($db, $level, $classId, $subjectId, $semesterId),
        'class_performance' => get_class_performance($db, $level, $classId, $subjectId, $semesterId),
        'assessment_stats' => get_assessment_stats($db, $level, $classId, $subjectId, $semesterId)
    ];

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set document information
    $pdf->SetCreator('Student Performance Dashboard');
    $pdf->SetAuthor('School Management System');
    $pdf->SetTitle('Student Performance Report');
    $pdf->SetSubject('Performance Data');

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    // Set default font subsetting mode
    $pdf->setFontSubsetting(true);

    // Set font
    $pdf->SetFont('helvetica', '', 10);

    // Add a page for Cover and Summary
    $pdf->AddPage();

    // Custom header with school logo and title
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 10, 'STUDENT PERFORMANCE REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y, g:i a'), 0, 1, 'C');
    $pdf->Cell(0, 10, $filterText, 0, 1, 'C');
    $pdf->Ln(10);

    // School Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetFillColor(69, 69, 69);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'REPORT SUMMARY', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);

    // Summary Cards in a 2x2 grid
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(85, 10, 'Total Students', 1, 0, 'L', true);
    $pdf->Cell(85, 10, 'Total Subjects', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(85, 15, $data['summary']['total_students'] ?? 0, 1, 0, 'C');
    $pdf->Cell(85, 15, $data['summary']['total_subjects'] ?? 0, 1, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(85, 10, 'Total Assessments', 1, 0, 'L', true);
    $pdf->Cell(85, 10, 'Overall Average Score', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(85, 15, $data['summary']['total_assessments'] ?? 0, 1, 0, 'C');
    
    // Format and colorize the average score
    $avgScore = $data['summary']['overall_avg_score'] ?? 0;
    if ($avgScore >= 15) {
        $pdf->SetTextColor(0, 128, 0); // Green for good scores
    } elseif ($avgScore >= 10) {
        $pdf->SetTextColor(255, 128, 0); // Orange for average scores
    } else {
        $pdf->SetTextColor(255, 0, 0); // Red for poor scores
    }
    $pdf->Cell(85, 15, $avgScore, 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0); // Reset text color
    
    $pdf->Ln(10);

    // Table of Contents
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetFillColor(69, 69, 69);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'TABLE OF CONTENTS', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(10, 8, '1.', 0, 0);
    $pdf->Cell(0, 8, 'Top Performers by Subject', 0, 1);
    $pdf->Cell(10, 8, '2.', 0, 0);
    $pdf->Cell(0, 8, 'Class Performance by Subject', 0, 1);
    $pdf->Cell(10, 8, '3.', 0, 0);
    $pdf->Cell(0, 8, 'Assessment Participation Statistics', 0, 1);
    $pdf->Ln(10);

    // Add a page for Top Performers
    if (!empty($data['top_performers'])) {
        $pdf->AddPage();

        // Section header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetFillColor(52, 73, 94);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 12, '1. TOP PERFORMERS BY SUBJECT', 0, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // Loop through each subject
        foreach ($data['top_performers'] as $subject) {
            // Subject header
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetFillColor(242, 196, 70); // Gold/yellow for subject headers
            $pdf->Cell(0, 10, $subject['subject_name'], 0, 1, 'L', true);
            $pdf->Ln(2);

            // Set up table header styles
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            
            // Calculate column widths based on available page width (180 units) - now including level
            $rankWidth = 15;
            $nameWidth = 45;
            $classWidth = 25;
            $levelWidth = 20;
            $programWidth = 35;
            $scoreWidth = 20;
            $timeWidth = 20;
            
            // Table headers
            $pdf->Cell($rankWidth, 8, 'Rank', 1, 0, 'C', true);
            $pdf->Cell($nameWidth, 8, 'Student Name', 1, 0, 'L', true);
            $pdf->Cell($classWidth, 8, 'Class', 1, 0, 'L', true);
            $pdf->Cell($levelWidth, 8, 'Level', 1, 0, 'C', true);
            $pdf->Cell($programWidth, 8, 'Program', 1, 0, 'L', true);
            $pdf->Cell($scoreWidth, 8, 'Avg Score', 1, 0, 'C', true);
            $pdf->Cell($timeWidth, 8, 'Best Time', 1, 1, 'C', true);

            // Table data
            $pdf->SetFont('helvetica', '', 8);
            if (empty($subject['students'])) {
                $pdf->Cell(180, 8, 'No students data available for this subject.', 1, 1, 'C');
            } else {
                foreach ($subject['students'] as $index => $student) {
                    // Set row background colors alternating for better readability
                    $fillColor = $index % 2 === 0 ? false : true;
                    if ($fillColor) {
                        $pdf->SetFillColor(250, 250, 250);
                    }
                    
                    $pdf->Cell($rankWidth, 8, ($index + 1), 1, 0, 'C', $fillColor);
                    $pdf->Cell($nameWidth, 8, $student['student_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($classWidth, 8, $student['class_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($levelWidth, 8, $student['level'] ?? 'N/A', 1, 0, 'C', $fillColor);
                    $pdf->Cell($programWidth, 8, $student['program_name'], 1, 0, 'L', $fillColor);
                    
                    // Set color based on score
                    $avgScore = $student['avg_score'];
                    if ($avgScore >= 15) {
                        $pdf->SetTextColor(0, 128, 0); // Green for good scores
                    } elseif ($avgScore >= 10) {
                        $pdf->SetTextColor(255, 128, 0); // Orange for average scores
                    } else {
                        $pdf->SetTextColor(255, 0, 0); // Red for poor scores
                    }
                    $pdf->Cell($scoreWidth, 8, $avgScore, 1, 0, 'C', $fillColor);
                    $pdf->SetTextColor(0, 0, 0); // Reset text color
                    
                    $pdf->Cell($timeWidth, 8, $student['best_completion_time'], 1, 1, 'C', $fillColor);
                }
            }
            $pdf->Ln(10);
            
            // Add a page break if this is not the last subject
            if (next($data['top_performers']) !== false) {
                $pdf->AddPage();
            }
            // Reset the array pointer
            prev($data['top_performers']);
        }
    }

    // Add a page for Class Performance
    if (!empty($data['class_performance'])) {
        $pdf->AddPage();

        // Section header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetFillColor(52, 73, 94);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 12, '2. CLASS PERFORMANCE BY SUBJECT', 0, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // Loop through each subject
        foreach ($data['class_performance'] as $subject) {
            // Subject header
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetFillColor(242, 196, 70); // Gold/yellow for subject headers
            $pdf->Cell(0, 10, $subject['subject_name'], 0, 1, 'L', true);
            $pdf->Ln(2);

            // Set up table header styles
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            
            // Calculate column widths - now including level
            $rankWidth = 15;
            $classWidth = 30;
            $levelWidth = 20;
            $programWidth = 35;
            $studentsWidth = 20;
            $avgScoreWidth = 20;
            $minScoreWidth = 18;
            $maxScoreWidth = 18;
            
            // Table headers
            $pdf->Cell($rankWidth, 8, 'Rank', 1, 0, 'C', true);
            $pdf->Cell($classWidth, 8, 'Class', 1, 0, 'L', true);
            $pdf->Cell($levelWidth, 8, 'Level', 1, 0, 'C', true);
            $pdf->Cell($programWidth, 8, 'Program', 1, 0, 'L', true);
            $pdf->Cell($studentsWidth, 8, 'Students', 1, 0, 'C', true);
            $pdf->Cell($avgScoreWidth, 8, 'Avg Score', 1, 0, 'C', true);
            $pdf->Cell($minScoreWidth, 8, 'Min Score', 1, 0, 'C', true);
            $pdf->Cell($maxScoreWidth, 8, 'Max Score', 1, 1, 'C', true);

            // Table data
            $pdf->SetFont('helvetica', '', 8);
            if (empty($subject['classes'])) {
                $pdf->Cell(176, 8, 'No class data available for this subject.', 1, 1, 'C');
            } else {
                foreach ($subject['classes'] as $index => $classItem) {
                    // Set row background colors alternating for better readability
                    $fillColor = $index % 2 === 0 ? false : true;
                    if ($fillColor) {
                        $pdf->SetFillColor(250, 250, 250);
                    }
                    
                    $pdf->Cell($rankWidth, 8, ($index + 1), 1, 0, 'C', $fillColor);
                    $pdf->Cell($classWidth, 8, $classItem['class_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($levelWidth, 8, $classItem['level'] ?? 'N/A', 1, 0, 'C', $fillColor);
                    $pdf->Cell($programWidth, 8, $classItem['program_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($studentsWidth, 8, $classItem['total_students'], 1, 0, 'C', $fillColor);
                    
                    // Color-code average score
                    $avgScore = $classItem['avg_score'];
                    if ($avgScore >= 15) {
                        $pdf->SetTextColor(0, 128, 0); // Green for good scores
                    } elseif ($avgScore >= 10) {
                        $pdf->SetTextColor(255, 128, 0); // Orange for average scores
                    } else {
                        $pdf->SetTextColor(255, 0, 0); // Red for poor scores
                    }
                    $pdf->Cell($avgScoreWidth, 8, $avgScore, 1, 0, 'C', $fillColor);
                    $pdf->SetTextColor(0, 0, 0); // Reset text color
                    
                    $pdf->Cell($minScoreWidth, 8, $classItem['min_score'], 1, 0, 'C', $fillColor);
                    $pdf->Cell($maxScoreWidth, 8, $classItem['max_score'], 1, 1, 'C', $fillColor);
                }
            }
            
            // Add performance distribution chart (if there are multiple classes)
            if (count($subject['classes']) > 1) {
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 8, 'Performance Distribution', 0, 1);
                $pdf->Ln(2);
                
                // Draw a simple bar chart representation of class performance
                $pdf->SetFont('helvetica', '', 9);
                $maxBarWidth = 120; // Maximum width for bars
                
                foreach ($subject['classes'] as $index => $classItem) {
                    $barWidth = ($classItem['avg_score'] / 20) * $maxBarWidth; // Scale to max 20 points
                    
                    // Set bar color based on score
                    if ($classItem['avg_score'] >= 15) {
                        $pdf->SetFillColor(40, 167, 69); // Green
                    } elseif ($classItem['avg_score'] >= 10) {
                        $pdf->SetFillColor(255, 193, 7); // Yellow/Orange
                    } else {
                        $pdf->SetFillColor(220, 53, 69); // Red
                    }
                    
                    // Draw label
                    $pdf->Cell(40, 6, $classItem['class_name'], 0, 0);
                    
                    // Draw bar
                    $pdf->Cell($barWidth, 6, '', 0, 0, 'L', true);
                    
                    // Draw score value
                    $pdf->Cell(20, 6, $classItem['avg_score'], 0, 1);
                }
            }
            
            $pdf->Ln(10);
            
            // Add a page break if this is not the last subject
            if (next($data['class_performance']) !== false) {
                $pdf->AddPage();
            }
            // Reset the array pointer
            prev($data['class_performance']);
        }
    }

    // Add a page for Assessment Stats
    if (!empty($data['assessment_stats'])) {
        $pdf->AddPage();

        // Section header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetFillColor(52, 73, 94);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 12, '3. ASSESSMENT PARTICIPATION STATISTICS', 0, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // Loop through each subject
        foreach ($data['assessment_stats'] as $subject) {
            // Subject header
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetFillColor(242, 196, 70);
            $pdf->Cell(0, 10, $subject['subject_name'], 0, 1, 'L', true);
            $pdf->Ln(2);

            // Subject summary box
            $pdf->SetFillColor(247, 247, 247);
            $pdf->RoundedRect(15, $pdf->GetY(), 180, 30, 3.50, '1111', 'DF');
            
            // Title inside the box
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetY($pdf->GetY() + 2);
            $pdf->Cell(180, 8, 'Subject Participation Summary', 0, 1, 'C');
            
            // Participation data
            $pdf->SetFont('helvetica', '', 10);
            
            // Calculate Y position for the data row
            $dataY = $pdf->GetY();
            
            // Draw progress bar to visualize completion rate
            $completionRate = round(($subject['students_taken'] / ($subject['total_students'] ?: 1)) * 100);
            $barWidth = 1.5 * $completionRate; // Scale to 150 max width
            
            // Total students
            $pdf->SetXY(20, $dataY);
            $pdf->Cell(40, 8, 'Total Students:', 0, 0);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(20, 8, $subject['total_students'], 0, 0);
            
            // Students taken
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(90, $dataY);
            $pdf->Cell(50, 8, 'Completion Rate:', 0, 0);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(20, 8, $completionRate . '%', 0, 1);
            
            // Students taken vs not taken
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(20, $dataY + 8);
            $pdf->Cell(40, 8, 'Students Taken:', 0, 0);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(20, 8, $subject['students_taken'], 0, 0);
            
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(90, $dataY + 8);
            $pdf->Cell(50, 8, 'Students Not Taken:', 0, 0);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(20, 8, $subject['students_not_taken'], 0, 1);
            
            // Progress bar for visualization
            $pdf->Ln(5);
            
            // Draw progress bar background
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Rect(20, $pdf->GetY(), 150, 6, 'F');
            
            // Draw completion part of the bar
            if ($completionRate >= 70) {
                $pdf->SetFillColor(40, 167, 69); // Green
            } elseif ($completionRate >= 40) {
                $pdf->SetFillColor(255, 193, 7); // Yellow
            } else {
                $pdf->SetFillColor(220, 53, 69); // Red
            }
            $pdf->Rect(20, $pdf->GetY(), $barWidth, 6, 'F');
            
            $pdf->Ln(10);

            // Class stats table
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            
            // Table headers - More compact to fit well on the page, now including level
            $classWidth = 20;
            $levelWidth = 15;
            $programWidth = 25;
            $totalWidth = 15;
            $takenWidth = 15;
            $notTakenWidth = 18;
            $assmtWidth = 18;
            $rateWidth = 18;
            
            $pdf->Cell($classWidth, 8, 'Class', 1, 0, 'L', true);
            $pdf->Cell($levelWidth, 8, 'Level', 1, 0, 'C', true);
            $pdf->Cell($programWidth, 8, 'Program', 1, 0, 'L', true);
            $pdf->Cell($totalWidth, 8, 'Total', 1, 0, 'C', true);
            $pdf->Cell($takenWidth, 8, 'Taken', 1, 0, 'C', true);
            $pdf->Cell($notTakenWidth, 8, 'Not Taken', 1, 0, 'C', true);
            $pdf->Cell($assmtWidth, 8, 'Assessments', 1, 0, 'C', true);
            $pdf->Cell($rateWidth, 8, 'Rate (%)', 1, 1, 'C', true);

            // Table data
            $pdf->SetFont('helvetica', '', 8);
            if (empty($subject['class_stats'])) {
                $pdf->Cell(144, 8, 'No class statistics available for this subject.', 1, 1, 'C');
            } else {
                foreach ($subject['class_stats'] as $index => $classStats) {
                    // Set row background colors alternating
                    $fillColor = $index % 2 === 0 ? false : true;
                    if ($fillColor) {
                        $pdf->SetFillColor(250, 250, 250);
                    }
                    
                    $pdf->Cell($classWidth, 7, $classStats['class_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($levelWidth, 7, $classStats['level'] ?? 'N/A', 1, 0, 'C', $fillColor);
                    $pdf->Cell($programWidth, 7, $classStats['program_name'], 1, 0, 'L', $fillColor);
                    $pdf->Cell($totalWidth, 7, $classStats['total_students'], 1, 0, 'C', $fillColor);
                    $pdf->Cell($takenWidth, 7, $classStats['students_taken'], 1, 0, 'C', $fillColor);
                    $pdf->Cell($notTakenWidth, 7, $classStats['students_not_taken'], 1, 0, 'C', $fillColor);
                    $pdf->Cell($assmtWidth, 7, $classStats['total_assessments'], 1, 0, 'C', $fillColor);
                    
                    // Color-code completion rate
                    $rateValue = intval($classStats['completion_rate']);
                    if ($rateValue >= 70) {
                        $pdf->SetTextColor(0, 128, 0); // Green
                    } elseif ($rateValue >= 40) {
                        $pdf->SetTextColor(255, 128, 0); // Orange
                    } else {
                        $pdf->SetTextColor(255, 0, 0); // Red
                    }
                    $pdf->Cell($rateWidth, 7, $classStats['completion_rate'], 1, 1, 'C', $fillColor);
                    $pdf->SetTextColor(0, 0, 0); // Reset text color
                }
            }
            
            $pdf->Ln(10);
            
            // Add a page break if this is not the last subject
            if (next($data['assessment_stats']) !== false) {
                $pdf->AddPage();
            }
            // Reset the array pointer
            prev($data['assessment_stats']);
        }
    }

    // Final notes and footer
    $pdf->AddPage();
    
    // Conclusions and recommendations
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetFillColor(52, 73, 94);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 10, 'CONCLUSIONS AND RECOMMENDATIONS', 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);
    
    // Generate some generic conclusions based on data
    $pdf->SetFont('helvetica', '', 11);
    
    // Calculate overall statistics
    $avgScore = $data['summary']['overall_avg_score'] ?? 0;
    $totalStudents = $data['summary']['total_students'] ?? 0;
    $totalAssessments = $data['summary']['total_assessments'] ?? 0;
    
    // Performance analysis
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Performance Analysis:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    
    if ($avgScore >= 15) {
        $pdf->MultiCell(0, 6, 'The overall performance is excellent with an average score of ' . $avgScore . '. Students have demonstrated strong understanding across subjects. Consider introducing more challenging assessments to further enhance learning.', 0, 'L');
    } else {
        $pdf->MultiCell(0, 6, 'The overall performance needs improvement with an average score of ' . $avgScore . '. Students appear to be struggling across multiple subjects. Consider reviewing teaching methodologies and providing additional support sessions.', 0, 'L');
    }
    
    $pdf->Ln(5);
    
    // Participation analysis
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Participation Analysis:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    
    // Generate general participation recommendation
    $participationIssues = false;
    $lowParticipationSubjects = [];
    
    foreach ($data['assessment_stats'] as $subject) {
        $completionRate = round(($subject['students_taken'] / ($subject['total_students'] ?: 1)) * 100);
        if ($completionRate < 70) {
            $participationIssues = true;
            $lowParticipationSubjects[] = $subject['subject_name'];
        }
    }
    
    if ($participationIssues) {
        $pdf->MultiCell(0, 6, 'Participation issues have been identified in some subjects. The following subjects have lower than expected participation rates: ' . implode(', ', $lowParticipationSubjects) . '. Consider implementing reminders or incentives to improve assessment completion rates.', 0, 'L');
    } else {
        $pdf->MultiCell(0, 6, 'Student participation is satisfactory across all subjects. Continue to maintain the current engagement strategies to ensure consistent participation.', 0, 'L');
    }
    
    $pdf->Ln(5);
    
    // Level-specific analysis (if level filter is applied)
    if ($level) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Level-Specific Analysis:', 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        
        $pdf->MultiCell(0, 6, 'This report focuses specifically on Level ' . $level . '. The performance data shows trends unique to this educational level. Consider comparing these results with other levels to identify best practices that can be shared across the institution.', 0, 'L');
        $pdf->Ln(5);
    }
    
    // Recommendations
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Recommendations:', 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    
    $pdf->Write(6, '• ');
    $pdf->MultiCell(0, 6, 'Review the performance of classes with below-average scores and consider implementing targeted interventions.', 0, 'L');
    
    $pdf->Write(6, '• ');
    $pdf->MultiCell(0, 6, 'Recognize and reward top-performing students to maintain motivation and encourage improvement.', 0, 'L');
    
    $pdf->Write(6, '• ');
    $pdf->MultiCell(0, 6, 'For subjects with lower participation rates, investigate the reasons behind non-participation and address any barriers.', 0, 'L');
    
    $pdf->Write(6, '• ');
    $pdf->MultiCell(0, 6, 'Consider peer learning programs where high-performing students can support those who are struggling.', 0, 'L');
    
    $pdf->Write(6, '• ');
    $pdf->MultiCell(0, 6, 'Schedule regular reviews of student performance data to identify trends and adjust teaching strategies accordingly.', 0, 'L');
    
    if ($level) {
        $pdf->Write(6, '• ');
        $pdf->MultiCell(0, 6, 'For Level ' . $level . ' specifically, consider level-appropriate teaching methodologies and assessment strategies that align with the developmental stage of students at this level.', 0, 'L');
    }
    
    $pdf->Ln(10);
    
    // Filter summary section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Report Filter Summary:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(40, 6, 'Level Filter:', 0, 0);
    $pdf->Cell(0, 6, $level ? $level : 'All Levels', 0, 1);
    
    $pdf->Cell(40, 6, 'Class Filter:', 0, 0);
    $pdf->Cell(0, 6, $classId ? (isset($classInfo) ? $classInfo['class_name'] : 'Class ID: ' . $classId) : 'All Classes', 0, 1);
    
    $pdf->Cell(40, 6, 'Subject Filter:', 0, 0);
    $pdf->Cell(0, 6, $subjectId ? (isset($subjectInfo) ? $subjectInfo['subject_name'] : 'Subject ID: ' . $subjectId) : 'All Subjects', 0, 1);
    
    $pdf->Cell(40, 6, 'Semester Filter:', 0, 0);
    $pdf->Cell(0, 6, $semesterId ? (isset($semesterInfo) ? $semesterInfo['semester_name'] : 'Semester ID: ' . $semesterId) : 'All Semesters', 0, 1);
    
    $pdf->Ln(5);
    
    // Footer with signatures
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'This report is system-generated and requires no signature.', 0, 1, 'C');
    $pdf->Cell(0, 10, 'For any discrepancies or queries, please contact the Assessment Administration Office.', 0, 1, 'C');
    
    // Add document generation timestamp in footer
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s') . ' | Report ID: ' . uniqid('PERF-'), 0, 1, 'C');
    
    // Clear the buffer before outputting PDF
    ob_end_clean();

    // Close and output PDF document
    $filename = 'student_performance_report_' . date('Ymd_His');
    if ($level) $filename .= '_level_' . str_replace(' ', '_', $level);
    if ($classId && isset($classInfo)) $filename .= '_class_' . str_replace(' ', '_', $classInfo['class_name']);
    if ($subjectId && isset($subjectInfo)) $filename .= '_subject_' . str_replace(' ', '_', $subjectInfo['subject_name']);
    $filename .= '.pdf';
    
    $pdf->Output($filename, 'D');
} catch (Exception $e) {
    // Clear the buffer in case of error
    ob_end_clean();

    // Log error
    error_log("PDF Export error: " . $e->getMessage());

    // Return error response
    header('HTTP/1.0 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to generate PDF: ' . $e->getMessage()]);
    exit;
}
?>