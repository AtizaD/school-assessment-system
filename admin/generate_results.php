<?php
// admin/generate_results.php
ob_start(); 
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

// Create TCPDF extension class with custom footer
class MYPDF extends TCPDF {
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        // Set text color to gray
        $this->SetTextColor(128, 128, 128);
        // Page number
        $this->Cell(0, 10, 'Student Results Report - Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

// Only admin and teachers can access this page
requireRole(['admin', 'teacher']);

// Handle form submission
$filters = [];
$results = [];
$title = 'Student Results Report';
$filterApplied = false;
$semesterId = null;
$className = '';
$subjectName = '';
$programName = '';
$levelName = '';
$dateFromFilter = '';
$dateToFilter = '';
$exportRequested = false;

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get all classes for dropdown
    $stmt = $db->prepare("SELECT c.class_id, c.class_name, c.level, p.program_name 
                         FROM classes c JOIN programs p ON c.program_id = p.program_id 
                         ORDER BY p.program_name, c.level, c.class_name");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // Get all unique levels for dropdown
    $stmt = $db->prepare("SELECT DISTINCT level FROM classes ORDER BY level");
    $stmt->execute();
    $levels = $stmt->fetchAll();
    
    // Get all subjects for dropdown
    $stmt = $db->prepare("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $stmt->execute();
    $subjects = $stmt->fetchAll();
    
    // Get all semesters for dropdown
    $stmt = $db->prepare("SELECT semester_id, semester_name FROM semesters ORDER BY start_date DESC");
    $stmt->execute();
    $semesters = $stmt->fetchAll();
    
    // Get current/active semester
    $stmt = $db->prepare(
        "SELECT semester_id, semester_name FROM semesters 
         WHERE CURDATE() BETWEEN start_date AND end_date 
         ORDER BY start_date DESC LIMIT 1"
    );
    $stmt->execute();
    $currentSemester = $stmt->fetch();
    $currentSemesterId = $currentSemester ? $currentSemester['semester_id'] : null;
    
    // Set reasonable date range limits (allow 2 years back and 1 year forward)
    $currentYear = date('Y');
    $minDate = ($currentYear - 2) . '-01-01';
    $maxDate = ($currentYear + 1) . '-12-31';
    
    // Process filter form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $filterApplied = true;
        
        // Get filter values
        $classId = !empty($_POST['class_id']) ? $_POST['class_id'] : null;
        $level = !empty($_POST['level']) ? $_POST['level'] : null;
        $subjectId = !empty($_POST['subject_id']) ? $_POST['subject_id'] : null;
        $semesterId = !empty($_POST['semester_id']) ? $_POST['semester_id'] : $currentSemesterId;
        $studentId = !empty($_POST['student_id']) ? $_POST['student_id'] : null;
        $dateFrom = !empty($_POST['date_from']) ? $_POST['date_from'] : null;
        $dateTo = !empty($_POST['date_to']) ? $_POST['date_to'] : null;
        $exportFormat = $_POST['export_format'] ?? 'pdf';
        
        // Validate date range
        if ($dateFrom && $dateTo && strtotime($dateFrom) > strtotime($dateTo)) {
            throw new Exception('Start date cannot be after end date');
        }
        
        // Check if user wants to export
        $exportRequested = isset($_POST['export']) && $_POST['export'] === 'true';
        
        // Store filters for display
        $filters = [
            'class_id' => $classId,
            'level' => $level,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'student_id' => $studentId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];
        
        $dateFromFilter = $dateFrom;
        $dateToFilter = $dateTo;
        
        // Build the base query - UPDATED to match schema relationships
        $baseQuery = "
            SELECT 
                s.student_id, 
                s.first_name, 
                s.last_name,
                c.class_id,
                c.class_name,
                c.level,
                p.program_name,
                sb.subject_id,
                sb.subject_name,
                a.title as assessment_title,
                a.date as assessment_date,
                r.score,
                COUNT(DISTINCT q.question_id) as total_questions,
                COUNT(DISTINCT sa.answer_id) as answered_questions,
                SUM(q.max_score) as total_possible_score
            FROM students s
            JOIN classes c ON s.class_id = c.class_id
            JOIN programs p ON c.program_id = p.program_id
            JOIN assessmentclasses ac ON c.class_id = ac.class_id
            JOIN subjects sb ON ac.subject_id = sb.subject_id
            JOIN assessments a ON ac.assessment_id = a.assessment_id
            LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.student_id = s.student_id
            LEFT JOIN questions q ON a.assessment_id = q.assessment_id
            LEFT JOIN studentanswers sa ON q.question_id = sa.question_id AND sa.student_id = s.student_id AND sa.assessment_id = a.assessment_id
            WHERE 1=1";
        
        $params = [];
        
        // Add filters to query
        if ($classId) {
            $baseQuery .= " AND s.class_id = ?";
            $params[] = $classId;
            
            // Get class name for report title
            $stmtClass = $db->prepare("
                SELECT c.class_name, c.level, p.program_name 
                FROM classes c 
                JOIN programs p ON c.program_id = p.program_id 
                WHERE c.class_id = ?
            ");
            $stmtClass->execute([$classId]);
            $classInfo = $stmtClass->fetch();
            if ($classInfo) {
                $className = $classInfo['class_name'];
                $levelName = $classInfo['level'];
                $programName = $classInfo['program_name'];
            }
        }
        
        // Add level filter
        if ($level) {
            $baseQuery .= " AND c.level = ?";
            $params[] = $level;
            $levelName = $level;
        }
        
        if ($subjectId) {
            $baseQuery .= " AND ac.subject_id = ?";
            $params[] = $subjectId;
            
            // Get subject name for report title
            $stmtSubject = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
            $stmtSubject->execute([$subjectId]);
            $subjectInfo = $stmtSubject->fetch();
            if ($subjectInfo) {
                $subjectName = $subjectInfo['subject_name'];
            }
        }
        
        if ($semesterId) {
            $baseQuery .= " AND a.semester_id = ?";
            $params[] = $semesterId;
        }
        
        if ($studentId) {
            $baseQuery .= " AND s.student_id = ?";
            $params[] = $studentId;
        }
        
        // Add date range filters
        if ($dateFrom) {
            $baseQuery .= " AND a.date >= ?";
            $params[] = $dateFrom;
        }
        
        if ($dateTo) {
            $baseQuery .= " AND a.date <= ?";
            $params[] = $dateTo;
        }
        
        // Group and order the results
        $baseQuery .= " 
            GROUP BY s.student_id, s.first_name, s.last_name, c.class_name, c.level, p.program_name, 
                     sb.subject_name, a.title, a.date, r.score, c.class_id, sb.subject_id
            ORDER BY s.last_name, s.first_name, sb.subject_name, a.date
        ";
        
        // Execute the final query
        $stmt = $db->prepare($baseQuery);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        // Build report title based on filters
        $title = 'Student Results Report';
        
        if ($levelName) {
            $title .= " - Level $levelName";
        }
        
        if ($className && $subjectName) {
            $title .= " - $className - $subjectName";
        } elseif ($className) {
            $title .= " - $className";
        } elseif ($subjectName) {
            $title .= " - $subjectName";
        }
        
        if ($programName) {
            $title .= " ($programName)";
        }
        
        // Add date range to title if specified
        if ($dateFrom && $dateTo) {
            $title .= " (" . date('M d, Y', strtotime($dateFrom)) . " - " . date('M d, Y', strtotime($dateTo)) . ")";
        } elseif ($dateFrom) {
            $title .= " (From " . date('M d, Y', strtotime($dateFrom)) . ")";
        } elseif ($dateTo) {
            $title .= " (Until " . date('M d, Y', strtotime($dateTo)) . ")";
        }
        
        // Generate the PDF if requested
        if ($exportRequested && !empty($results)) {
            if ($exportFormat === 'pdf') {
                generatePDF($results, $title, $filters, $semesterId, $db);
                // Note: generatePDF will exit the script after sending the file
            }
        }
    }
    
} catch (Exception $e) {
    logError("Generate results error: " . $e->getMessage());
    $error = "Error loading data: " . $e->getMessage();
}

// Function to abbreviate subject names
function abbreviateSubject($subject) {
    // Common subject abbreviations
    $abbreviations = [
        'Mathematics' => 'Maths',
        'English Language' => 'Eng Lang',
        'Christian Religious Studies' => 'CRS',
        'Elective Mathematics' => 'E-Maths',
        'Social Studies' => 'Soc Stud',
        'Physical Education' => 'PE',
        'Business Studies' => 'Bus St',
        'Agricultural Science' => 'Agric',
        'Information Technology' => 'IT',
        'Computer Science' => 'Comp Sci',
        'Integrated Science' => 'Int Sci',
        'General Science' => 'Science',
        'Biology' => 'Bio',
        'Chemistry' => 'Chem',
        'Physics' => 'Phys',
        'Geography' => 'Geog',
        'Economics' => 'Econs',
        'Government' => 'Govt',
        'Animal Husbandry' => 'Husbandry',
        'Home Economics' => 'Home Eco',
        'Visual Arts' => 'Vis Arts',
        'Islamic Religious Studies' => 'IRS'
    ];
    
    // Check if we have a predefined abbreviation
    if (isset($abbreviations[$subject])) {
        return $abbreviations[$subject];
    }
    
    // For other subjects, create an abbreviation from the first letter of each word
    if (strlen($subject) > 10) {
        $words = explode(' ', $subject);
        $abbr = '';
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                $abbr .= strtoupper(substr($word, 0, 1));
            }
        }
        return $abbr;
    }
    
    // If it's already short, return as is
    return $subject;
}

function generatePDF($results, $title, $filters, $semesterId, $db) {
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Create new TCPDF object with our custom class - LANDSCAPE orientation
    $pdf = new MYPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('School Assessment System');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle($title);
    $pdf->SetSubject('Student Results Report');
    
    // Remove default header
    $pdf->setPrintHeader(false);
    
    // Enable footer for page numbers
    $pdf->setPrintFooter(true);
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont('courier');
    
    // Set margins - ADJUSTED FOR LANDSCAPE
    $pdf->SetMargins(15, 15, 15);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    
    // Get semester name
    $semesterName = '';
    if ($semesterId) {
        $stmt = $db->prepare("SELECT semester_name FROM semesters WHERE semester_id = ?");
        $stmt->execute([$semesterId]);
        $semesterInfo = $stmt->fetch();
        if ($semesterInfo) {
            $semesterName = $semesterInfo['semester_name'];
        }
    }
    
    // Organize data by class first
    $classData = [];
    foreach ($results as $row) {
        $classId = $row['class_id'];
        $className = $row['class_name'];
        $programName = $row['program_name'];
        $level = $row['level'];
        $studentId = $row['student_id'];
        $studentName = $row['first_name'] . ' ' . $row['last_name'];
        $subjectId = $row['subject_id'];
        $subjectName = $row['subject_name'];
        
        // Initialize class data if not exists
        if (!isset($classData[$classId])) {
            $classData[$classId] = [
                'class_name' => $className,
                'program_name' => $programName,
                'level' => $level,
                'students' => [],
                'subjects' => []
            ];
        }
        
        // Track all unique subjects for this class
        if (!in_array($subjectName, $classData[$classId]['subjects'])) {
            $classData[$classId]['subjects'][] = $subjectName;
        }
        
        // Initialize student data if not exists
        if (!isset($classData[$classId]['students'][$studentId])) {
            $classData[$classId]['students'][$studentId] = [
                'name' => $studentName,
                'subject_scores' => [],
                'has_results' => false,
                'average_score' => 0,
                'total_score' => 0,
                'subject_count' => 0
            ];
        }
        
        // Add score data (using subject as the key)
        if (isset($row['score'])) {
            $classData[$classId]['students'][$studentId]['subject_scores'][$subjectName] = [
                'score' => $row['score'],
                'date' => $row['assessment_date'],
                'questions_total' => $row['total_questions'],
                'questions_answered' => $row['answered_questions']
            ];
            // Mark student as having results
            $classData[$classId]['students'][$studentId]['has_results'] = true;
            
            // Add to total score for calculating average
            $classData[$classId]['students'][$studentId]['total_score'] += $row['score'];
            $classData[$classId]['students'][$studentId]['subject_count']++;
        }
    }
    
    // Calculate average scores and sort students by their scores
    foreach ($classData as $classId => &$class) {
        foreach ($class['students'] as $studentId => &$student) {
            if ($student['subject_count'] > 0) {
                $student['average_score'] = $student['total_score'] / $student['subject_count'];
            }
        }
    }
    
    // Sort classes by class name
    uksort($classData, function($a, $b) use ($classData) {
        return strcasecmp($classData[$a]['class_name'], $classData[$b]['class_name']);
    });
    
    // Set global styles
    $pdf->SetFillColor(50, 50, 50); // Dark header background
    $pdf->SetTextColor(255, 255, 255); // White text for headers
    
    // For each class, create a page
    foreach ($classData as $classId => $class) {
        // Add a new page for this class
        $pdf->AddPage();
        
        // Set font for title
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(0, 0, 0); // Black text for title
        
        // Class title
        $classTitle = $class['program_name']. ' - ' . ($class['class_name']);
        $pdf->Cell(0, 12, $classTitle, 0, 1, 'C');
        
        // Semester info with improved styling
        $pdf->SetFont('helvetica', '', 12);
        if ($semesterName) {
            $pdf->Cell(0, 8, "Semester: $semesterName", 0, 1, 'C');
        }
        
        // Add date range information if filters are applied
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $dateRangeText = "Assessment Period: ";
            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $dateRangeText .= date('M d, Y', strtotime($filters['date_from'])) . " - " . date('M d, Y', strtotime($filters['date_to']));
            } elseif (!empty($filters['date_from'])) {
                $dateRangeText .= "From " . date('M d, Y', strtotime($filters['date_from']));
            } elseif (!empty($filters['date_to'])) {
                $dateRangeText .= "Until " . date('M d, Y', strtotime($filters['date_to']));
            }
            $pdf->Cell(0, 8, $dateRangeText, 0, 1, 'C');
        }
        
        $pdf->SetTextColor(100, 100, 100); // Gray text for date
        $pdf->Cell(0, 8, "Generated on: " . date('F j, Y'), 0, 1, 'C');
        $pdf->Ln(2);
        
        // Sort subjects by name for consistent order
        sort($class['subjects']);
        
        // Create table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(50, 50, 50);
        $pdf->SetTextColor(255, 255, 255);
        
        // Calculate column widths based on number of subjects - ADJUSTED FOR LANDSCAPE
        $studentNameWidth = 60; // Wider for student name
        $rankWidth = 15; // Width for rank column
        $maxSubjects = min(count($class['subjects']), 8); // Can fit more in landscape
        $totalWidth = $pdf->getPageWidth() - 30; // Page width minus margins
        $subjectWidth = ($totalWidth - $studentNameWidth - $rankWidth) / $maxSubjects;
        
        // Print table header
        $pdf->Cell($rankWidth, 10, 'Rank', 1, 0, 'C', true);
        $pdf->Cell($studentNameWidth, 10, 'Student Name', 1, 0, 'L', true);
        
        for ($i = 0; $i < $maxSubjects; $i++) {
            if (isset($class['subjects'][$i])) {
                // Use abbreviations for subject names
                $abbr = abbreviateSubject($class['subjects'][$i]);
                $pdf->Cell($subjectWidth, 10, $abbr, 1, 0, 'C', true);
            }
        }
        $pdf->Ln();
        
        // Sort students alphabetically, keeping those who didn't take the test at the end
        $students = $class['students'];
        uksort($students, function($a, $b) use ($students) {
            // First sort by whether they have results or not
            if ($students[$a]['has_results'] && !$students[$b]['has_results']) {
                return -1;
            } elseif (!$students[$a]['has_results'] && $students[$b]['has_results']) {
                return 1;
            }
            
            // If both have results or both don't have results, sort alphabetically by name
            return strcasecmp($students[$a]['name'], $students[$b]['name']);
        });
        
        // Print student rows
        $pdf->SetFont('helvetica', '', 10);
        $rank = 1;
        $rowCount = 0;
        
        foreach ($students as $studentId => $student) {
            // Alternate row colors for better readability
            if ($rowCount % 2 === 0) {
                $pdf->SetFillColor(255, 255, 255); // White for even rows
                $rowFill = true;
            } else {
                $pdf->SetFillColor(240, 240, 240); // Light gray for odd rows
                $rowFill = true;
            }
            $pdf->SetTextColor(0, 0, 0); // Reset to black text for content
            
            if ($student['has_results']) {
                $pdf->Cell($rankWidth, 10, $rank, 1, 0, 'C', $rowFill);
                $rank++;
            } else {
                $pdf->Cell($rankWidth, 10, '-', 1, 0, 'C', $rowFill);
            }
            
            $pdf->Cell($studentNameWidth, 10, $student['name'], 1, 0, 'L', $rowFill);
            
            for ($i = 0; $i < $maxSubjects; $i++) {
                if (isset($class['subjects'][$i])) {
                    $subjectName = $class['subjects'][$i];
                    
                    if (isset($student['subject_scores'][$subjectName])) {
                        $score = $student['subject_scores'][$subjectName]['score'];
                        
                        // Set color based on score
                        if ($score >= 15) {
                            $pdf->SetTextColor(0, 128, 0); // Green
                        } elseif ($score >= 10) {
                            $pdf->SetTextColor(255, 128, 0); // Orange
                        } else {
                            $pdf->SetTextColor(255, 0, 0); // Red
                        }
                        
                        $pdf->Cell($subjectWidth, 10, $score, 1, 0, 'C', $rowFill);
                        $pdf->SetTextColor(0, 0, 0); // Reset to black
                    } else {
                        $pdf->SetTextColor(100, 100, 100); // Gray text for absent
                        $pdf->Cell($subjectWidth, 10, 'Absent', 1, 0, 'C', $rowFill);
                        $pdf->SetTextColor(0, 0, 0); // Reset to black
                    }
                }
            }
            $pdf->Ln();
            $rowCount++;
        }
        
        // Add subject details after the table with improved styling
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(50, 50, 50); // Dark gray for headings
        $pdf->Cell(0, 8, 'Subject Legend:', 0, 1);
        
        // Create a nice legend with abbreviations and full names
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        
        // Calculate number of columns based on page width
        $legendColWidth = 80; // Width for each legend item
        $maxLegendCols = floor(($pdf->getPageWidth() - 30) / $legendColWidth);
        $legendItems = [];
        
        // Create the legend items with abbreviations and full names
        foreach ($class['subjects'] as $index => $subjectName) {
            if ($index < count($class['subjects'])) {
                $abbr = abbreviateSubject($subjectName);
                $legendItems[] = $abbr . ' - ' . $subjectName;
            }
        }
        
        // Arrange in columns
        $itemsPerCol = ceil(count($legendItems) / $maxLegendCols);
        $legendY = $pdf->GetY();
        $originalY = $legendY;
        
        for ($col = 0; $col < $maxLegendCols; $col++) {
            $legendY = $originalY;
            for ($row = 0; $row < $itemsPerCol; $row++) {
                $itemIndex = $col * $itemsPerCol + $row;
                if ($itemIndex < count($legendItems)) {
                    $pdf->SetXY($pdf->GetX() + ($col * $legendColWidth), $legendY);
                    $pdf->Cell($legendColWidth, 8, $legendItems[$itemIndex], 0, 1);
                    $legendY += 8;
                }
            }
        }
        
        // If there are more than 8 subjects, add them on a second page
        if (count($class['subjects']) > $maxSubjects) {
            $pdf->AddPage();
            
            // Add class title again
            $pdf->SetFont('helvetica', 'B', 18);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 12, $classTitle . ' (continued)', 0, 1, 'C');
            
            // Semester info
            $pdf->SetFont('helvetica', '', 12);
            if ($semesterName) {
                $pdf->Cell(0, 8, "Semester: $semesterName", 0, 1, 'C');
            }
            
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(0, 8, "Generated on: " . date('F j, Y'), 0, 1, 'C');
            $pdf->Ln(2);
            
            // Calculate how many subjects for the next page
            $remainingSubjects = count($class['subjects']) - $maxSubjects;
            $nextPageMaxSubjects = min($remainingSubjects, 8); // Can fit 8 in landscape
            
            // Create table header for remaining subjects
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(50, 50, 50);
            $pdf->SetTextColor(255, 255, 255);
            
            $pdf->Cell($rankWidth, 10, 'Rank', 1, 0, 'C', true);
            $pdf->Cell($studentNameWidth, 10, 'Student Name', 1, 0, 'L', true);
            
            for ($i = 0; $i < $nextPageMaxSubjects; $i++) {
                $subjectIndex = $maxSubjects + $i;
                if (isset($class['subjects'][$subjectIndex])) {
                    $abbr = abbreviateSubject($class['subjects'][$subjectIndex]);
                    $pdf->Cell($subjectWidth, 10, $abbr, 1, 0, 'C', true);
                }
            }
            $pdf->Ln();
            
            // Print student rows for remaining subjects
            $pdf->SetFont('helvetica', '', 10);
            $rank = 1;
            $rowCount = 0;
            
            // Reset rank counter for second page
            $rank = 1;
            
            foreach ($students as $studentId => $student) {
                // Alternate row colors
                if ($rowCount % 2 === 0) {
                    $pdf->SetFillColor(255, 255, 255); // White for even rows
                    $rowFill = true;
                } else {
                    $pdf->SetFillColor(240, 240, 240); // Light gray for odd rows
                    $rowFill = true;
                }
                $pdf->SetTextColor(0, 0, 0);
                
                if ($student['has_results']) {
                    $pdf->Cell($rankWidth, 10, $rank, 1, 0, 'C', $rowFill);
                    $rank++;
                } else {
                    $pdf->Cell($rankWidth, 10, '-', 1, 0, 'C', $rowFill);
                }
                
                $pdf->Cell($studentNameWidth, 10, $student['name'], 1, 0, 'L', $rowFill);
                
                for ($i = 0; $i < $nextPageMaxSubjects; $i++) {
                    $subjectIndex = $maxSubjects + $i;
                    if (isset($class['subjects'][$subjectIndex])) {
                        $subjectName = $class['subjects'][$subjectIndex];
                        
                        if (isset($student['subject_scores'][$subjectName])) {
                            $score = $student['subject_scores'][$subjectName]['score'];
                            
                            // Set color based on score
                            if ($score >= 15) {
                                $pdf->SetTextColor(0, 128, 0); // Green
                            } elseif ($score >= 10) {
                                $pdf->SetTextColor(255, 128, 0); // Orange
                            } else {
                                $pdf->SetTextColor(255, 0, 0); // Red
                            }
                            
                            $pdf->Cell($subjectWidth, 10, $score, 1, 0, 'C', $rowFill);
                            $pdf->SetTextColor(0, 0, 0); // Reset to black
                        } else {
                            $pdf->SetTextColor(100, 100, 100); // Gray for absent
                            $pdf->Cell($subjectWidth, 10, 'Absent', 1, 0, 'C', $rowFill);
                            $pdf->SetTextColor(0, 0, 0); // Reset to black
                        }
                    }
                }
                $pdf->Ln();
                $rowCount++;
            }
            
            // Add remaining subject legend
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->Cell(0, 8, 'Subject Legend (continued):', 0, 1);
            
            // Create legend for continued subjects
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            
            $legendItems = [];
            for ($i = $maxSubjects; $i < count($class['subjects']); $i++) {
                $abbr = abbreviateSubject($class['subjects'][$i]);
                $legendItems[] = $abbr . ' - ' . $class['subjects'][$i];
            }
            
            // Arrange in columns
            $itemsPerCol = ceil(count($legendItems) / $maxLegendCols);
            $legendY = $pdf->GetY();
            $originalY = $legendY;
            
            for ($col = 0; $col < $maxLegendCols; $col++) {
                $legendY = $originalY;
                for ($row = 0; $row < $itemsPerCol; $row++) {
                    $itemIndex = $col * $itemsPerCol + $row;
                    if ($itemIndex < count($legendItems)) {
                        $pdf->SetXY($pdf->GetX() + ($col * $legendColWidth), $legendY);
                        $pdf->Cell($legendColWidth, 8, $legendItems[$itemIndex], 0, 1);
                        $legendY += 8;
                    }
                }
            }
        }
    }
    
    // Output the PDF
    $filename = 'class_results_' . date('Y-m-d_H-i-s') . '.pdf';
    $pdf->Output($filename, 'D'); // 'D' means download
    exit;
}

// Only include HTML if not exporting
if (!$exportRequested) {
    $pageTitle = 'Generate Student Results Report';
    require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning mb-0">Generate Student Results Report</h1>
    </div>
    
    <?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo $error; ?>
    </div>
    <?php endif; ?>
    
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Filter Options</h5>
        </div>
        <div class="card-body">
            <form method="post" action="" id="filterForm">
                <div class="row g-3">
                    <!-- Level filter dropdown -->
                    <div class="col-md-3">
                        <label for="level" class="form-label">Level</label>
                        <select name="level" id="level" class="form-select">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?php echo $level['level']; ?>"
                                    <?php if (isset($filters['level']) && $filters['level'] == $level['level']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($level['level']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="class_id" class="form-label">Class</label>
                        <select name="class_id" id="class_id" class="form-select">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                    <?php if (isset($filters['class_id']) && $filters['class_id'] == $class['class_id']) echo 'selected'; ?>
                                    data-level="<?php echo htmlspecialchars($class['level']); ?>">
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['level'] . ' - ' . $class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="subject_id" class="form-label">Subject</label>
                        <select name="subject_id" id="subject_id" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>"
                                    <?php if (isset($filters['subject_id']) && $filters['subject_id'] == $subject['subject_id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="semester_id" class="form-label">Semester</label>
                        <select name="semester_id" id="semester_id" class="form-select">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>"
                                    <?php 
                                        if (isset($filters['semester_id']) && $filters['semester_id'] == $semester['semester_id']) {
                                            echo 'selected';
                                        } elseif (!isset($filters['semester_id']) && $currentSemesterId == $semester['semester_id']) {
                                            echo 'selected';
                                        }
                                    ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="student_id" class="form-label">Student (Optional)</label>
                        <select name="student_id" id="student_id" class="form-select" disabled>
                            <option value="">Select Class First</option>
                        </select>
                    </div>
                    
                    <!-- NEW: Date Range Filters -->
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">From Date</label>
                        <input type="date" name="date_from" id="date_from" class="form-control"
                               value="<?php echo isset($filters['date_from']) ? $filters['date_from'] : ''; ?>"
                               min="<?php echo $minDate; ?>"
                               max="<?php echo $maxDate; ?>">
                        <small class="text-muted">Assessment start date</small>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">To Date</label>
                        <input type="date" name="date_to" id="date_to" class="form-control"
                               value="<?php echo isset($filters['date_to']) ? $filters['date_to'] : ''; ?>"
                               min="<?php echo $minDate; ?>"
                               max="<?php echo $maxDate; ?>">
                        <small class="text-muted">Assessment end date</small>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="export_format" class="form-label">Export Format</label>
                        <select name="export_format" id="export_format" class="form-select">
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    
                    <div class="col-12">
                        <div class="d-flex gap-2 align-items-center">
                            <button type="submit" name="preview" class="btn btn-warning">
                                <i class="fas fa-filter me-1"></i>Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="resetBtn">
                                <i class="fas fa-undo me-1"></i>Reset Filters
                            </button>
                            <!-- Quick date range buttons -->
                            <div class="btn-group ms-3" role="group" aria-label="Quick date ranges">
                                <button type="button" class="btn btn-outline-info btn-sm" id="thisWeekBtn">This Week</button>
                                <button type="button" class="btn btn-outline-info btn-sm" id="thisMonthBtn">This Month</button>
                                <button type="button" class="btn btn-outline-info btn-sm" id="lastMonthBtn">Last Month</button>
                                <button type="button" class="btn btn-outline-info btn-sm" id="thisYearBtn">This Year</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($filterApplied && empty($results)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>No results found for the selected filters.
    </div>
    <?php elseif ($filterApplied): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">Report Preview</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>Report generated successfully with <?php echo count($results); ?> results.
                <?php if ($dateFromFilter && $dateToFilter): ?>
                    <br><small class="text-muted">Date Range: <?php echo date('M d, Y', strtotime($dateFromFilter)) . ' - ' . date('M d, Y', strtotime($dateToFilter)); ?></small>
                <?php elseif ($dateFromFilter): ?>
                    <br><small class="text-muted">From: <?php echo date('M d, Y', strtotime($dateFromFilter)); ?></small>
                <?php elseif ($dateToFilter): ?>
                    <br><small class="text-muted">Until: <?php echo date('M d, Y', strtotime($dateToFilter)); ?></small>
                <?php endif; ?>
            </div>
            
            <form method="post" action="">
                <!-- Hidden fields to preserve filter state -->
                <?php foreach ($filters as $key => $value): ?>
                    <?php if ($value): ?>
                    <input type="hidden" name="<?php echo $key; ?>" value="<?php echo $value; ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="export_format" value="<?php echo htmlspecialchars($_POST['export_format'] ?? 'pdf'); ?>">
                <input type="hidden" name="export" value="true">
                
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-download me-1"></i>Download Report
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const levelSelect = document.getElementById('level');
    const classSelect = document.getElementById('class_id');
    const studentSelect = document.getElementById('student_id');
    const resetBtn = document.getElementById('resetBtn');
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    
    // Quick date range buttons
    const thisWeekBtn = document.getElementById('thisWeekBtn');
    const thisMonthBtn = document.getElementById('thisMonthBtn');
    const lastMonthBtn = document.getElementById('lastMonthBtn');
    const thisYearBtn = document.getElementById('thisYearBtn');
    
    // Date validation
    dateFromInput.addEventListener('change', function() {
        if (dateToInput.value && this.value > dateToInput.value) {
            alert('Start date cannot be after end date');
            this.value = '';
        }
        dateToInput.min = this.value;
    });
    
    dateToInput.addEventListener('change', function() {
        if (dateFromInput.value && this.value < dateFromInput.value) {
            alert('End date cannot be before start date');
            this.value = '';
        }
        dateFromInput.max = this.value;
    });
    
    // Quick date range functions
    function getWeekRange() {
        const today = new Date();
        const firstDay = new Date(today.setDate(today.getDate() - today.getDay()));
        const lastDay = new Date(today.setDate(today.getDate() - today.getDay() + 6));
        return {
            from: firstDay.toISOString().split('T')[0],
            to: lastDay.toISOString().split('T')[0]
        };
    }
    
    function getMonthRange(monthOffset = 0) {
        const today = new Date();
        const year = today.getFullYear();
        const month = today.getMonth() + monthOffset;
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        return {
            from: firstDay.toISOString().split('T')[0],
            to: lastDay.toISOString().split('T')[0]
        };
    }
    
    function getYearRange() {
        const today = new Date();
        const year = today.getFullYear();
        return {
            from: `${year}-01-01`,
            to: `${year}-12-31`
        };
    }
    
    // Quick date range button event listeners
    thisWeekBtn.addEventListener('click', function() {
        const range = getWeekRange();
        dateFromInput.value = range.from;
        dateToInput.value = range.to;
    });
    
    thisMonthBtn.addEventListener('click', function() {
        const range = getMonthRange();
        dateFromInput.value = range.from;
        dateToInput.value = range.to;
    });
    
    lastMonthBtn.addEventListener('click', function() {
        const range = getMonthRange(-1);
        dateFromInput.value = range.from;
        dateToInput.value = range.to;
    });
    
    thisYearBtn.addEventListener('click', function() {
        const range = getYearRange();
        dateFromInput.value = range.from;
        dateToInput.value = range.to;
    });
    
    // Filter classes by level
    levelSelect.addEventListener('change', function() {
        const selectedLevel = this.value;
        
        // Reset class selection
        classSelect.value = '';
        
        // Enable/disable class options based on level
        Array.from(classSelect.options).forEach(option => {
            if (option.value === '') return; // Skip the "All Classes" option
            
            const optionLevel = option.getAttribute('data-level');
            if (!selectedLevel || optionLevel === selectedLevel) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
        
        // Reset student dropdown
        studentSelect.innerHTML = '<option value="">Select Class First</option>';
        studentSelect.disabled = true;
    });
    
    // Load students based on class selection
    classSelect.addEventListener('change', function() {
        const classId = this.value;
        
        if (classId) {
            studentSelect.disabled = true;
            studentSelect.innerHTML = '<option value="">Loading...</option>';
            
            fetch(`../api/get_students.php?class_id=${classId}`)
                .then(response => response.json())
                .then(data => {
                    studentSelect.innerHTML = '<option value="">All Students</option>';
                    
                    data.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.student_id;
                        option.textContent = `${student.last_name}, ${student.first_name}`;
                        studentSelect.appendChild(option);
                    });
                    
                    studentSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching students:', error);
                    studentSelect.innerHTML = '<option value="">Error loading students</option>';
                    studentSelect.disabled = true;
                });
        } else {
            studentSelect.innerHTML = '<option value="">Select Class First</option>';
            studentSelect.disabled = true;
        }
        
        // If a class is selected, update the level dropdown to match
        if (classId) {
            const selectedOption = classSelect.options[classSelect.selectedIndex];
            const classLevel = selectedOption.getAttribute('data-level');
            if (classLevel) {
                levelSelect.value = classLevel;
            }
        }
    });
    
    // Reset button functionality
    resetBtn.addEventListener('click', function() {
        document.getElementById('filterForm').reset();
        
        // Reset the student dropdown
        studentSelect.innerHTML = '<option value="">Select Class First</option>';
        studentSelect.disabled = true;
        
        // Make all class options visible again
        Array.from(classSelect.options).forEach(option => {
            option.style.display = '';
        });
        
        // Reset date inputs
        dateFromInput.value = '';
        dateToInput.value = '';
        dateFromInput.removeAttribute('max');
        dateToInput.removeAttribute('min');
        
        // Set default semester if available
        if (document.getElementById('semester_id')) {
            const currentSemesterOptions = document.getElementById('semester_id').querySelectorAll('option[selected]');
            if (currentSemesterOptions.length > 0) {
                document.getElementById('semester_id').value = currentSemesterOptions[0].value;
            }
        }
    });
    
    // Set initial state of student dropdown based on class selection
    if (classSelect.value) {
        // Trigger the change event to load students
        const event = new Event('change');
        classSelect.dispatchEvent(event);
    }
    
    // Apply level filter on page load if a level is selected
    if (levelSelect.value) {
        const event = new Event('change');
        levelSelect.dispatchEvent(event);
    }
    
    // Form validation
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        const exportFormat = document.getElementById('export_format').value;
        
        // Date range validation
        const dateFrom = dateFromInput.value;
        const dateTo = dateToInput.value;
        
        if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
            e.preventDefault();
            alert('Start date cannot be after end date');
            return false;
        }
        
        // Check if at least one filter is selected
        const hasSelection = levelSelect.value || 
                            classSelect.value || 
                            document.getElementById('subject_id').value || 
                            document.getElementById('semester_id').value ||
                            studentSelect.value ||
                            dateFrom ||
                            dateTo;
        
        if (!hasSelection) {
            e.preventDefault();
            alert('Please select at least one filter option before generating the report.');
            return false;
        }
    });
});
</script>

<?php
    require_once INCLUDES_PATH . '/bass/base_footer.php';
}
?>