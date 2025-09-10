<?php
// admin/generate_student_reports.php
ob_start();
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once BASEPATH . '/vendor/autoload.php';

// Ensure user is admin
requireRole('admin');

// Initialize variables
$classes = [];
$programs = [];
$subjects = [];
$assessments = [];
$studentReports = [];
$error = '';
$success = '';
$exportFormat = '';
$reportType = isset($_GET['report_type']) ? sanitizeInput($_GET['report_type']) : 'individual';

// Get filter values
$filter_program = isset($_GET['program']) ? sanitizeInput($_GET['program']) : '';
$filter_class = isset($_GET['class']) ? sanitizeInput($_GET['class']) : '';
$filter_subject = isset($_GET['subject']) ? sanitizeInput($_GET['subject']) : '';
$filter_assessment = isset($_GET['assessment']) ? sanitizeInput($_GET['assessment']) : '';
$filter_level = isset($_GET['level']) ? sanitizeInput($_GET['level']) : '';
$filter_semester = isset($_GET['semester']) ? sanitizeInput($_GET['semester']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Fetch all programs for filter dropdown
    $stmt = $db->query("SELECT program_id, program_name FROM programs ORDER BY program_name");
    $programs = $stmt->fetchAll();
    
    // Fetch all subjects for filter dropdown
    $stmt = $db->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $subjects = $stmt->fetchAll();
    
    // Fetch all classes for filter dropdown
    $classQuery = "SELECT c.class_id, c.class_name, p.program_name, p.program_id, c.level 
                  FROM classes c 
                  JOIN programs p ON c.program_id = p.program_id 
                  ORDER BY p.program_name, c.level, c.class_name";
    $stmt = $db->query($classQuery);
    $classes = $stmt->fetchAll();
    
    // Fetch all assessments for filter dropdown
    $assessmentQuery = "SELECT a.assessment_id, a.title, a.date, se.semester_name 
                        FROM assessments a 
                        JOIN semesters se ON a.semester_id = se.semester_id 
                        ORDER BY a.date DESC, a.title";
    $stmt = $db->query($assessmentQuery);
    $assessments = $stmt->fetchAll();
    
    // Fetch all semesters for filter dropdown
    $semesterQuery = "SELECT semester_id, semester_name FROM semesters ORDER BY start_date DESC";
    $stmt = $db->query($semesterQuery);
    $semesters = $stmt->fetchAll();
    
    // Get unique class levels
    $levelQuery = "SELECT DISTINCT level FROM classes ORDER BY level";
    $stmt = $db->query($levelQuery);
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Handle export request
    if (isset($_POST['export_format']) && validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $exportFormat = sanitizeInput($_POST['export_format']);
        $reportTitle = "Student Report - " . date('Y-m-d H:i');
        $filtersJson = $_POST['export_filters'] ?? '{}';
        $filters = json_decode($filtersJson, true);
        
        // Apply filters from the export request
        if (!empty($filters)) {
            $filter_program = $filters['program'] ?? '';
            $filter_class = $filters['class'] ?? '';
            $filter_subject = $filters['subject'] ?? '';
            $filter_assessment = $filters['assessment'] ?? '';
            $filter_level = $filters['level'] ?? '';
            $filter_semester = $filters['semester'] ?? '';
            $filter_date_from = $filters['date_from'] ?? '';
            $filter_date_to = $filters['date_to'] ?? '';
            $search = $filters['search'] ?? '';
            $reportType = $filters['report_type'] ?? 'individual';
        }
        
        // Generate the report data based on filters
        $reportData = generateReportData($db, $reportType, $filter_program, $filter_class, $filter_subject, 
                                        $filter_assessment, $filter_level, $filter_semester, 
                                        $filter_date_from, $filter_date_to, $search);
        
        // Export based on selected format
        switch ($exportFormat) {
            case 'excel':
                exportExcel($reportData, $reportType, $reportTitle);
                exit;
            case 'csv':
                exportCSV($reportData, $reportType, $reportTitle);
                exit;
            case 'pdf':
                exportPDF($reportData, $reportType, $reportTitle);
                exit;
            default:
                $error = "Invalid export format selected.";
        }
    }
    
    // Handle report generation for display
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
        // Fetch student report data based on filters
        $studentReports = generateReportData($db, $reportType, $filter_program, $filter_class, $filter_subject, 
                                            $filter_assessment, $filter_level, $filter_semester, 
                                            $filter_date_from, $filter_date_to, $search);
    }
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    logError("Generate student reports error: " . $e->getMessage());
}

/**
 * Generate report data based on filters
 */
function generateReportData($db, $reportType, $program, $class, $subject, $assessment, $level, $semester, $dateFrom, $dateTo, $search) {
    $data = [];
    $params = [];
    $whereConditions = [];
    
    try {
        if ($reportType === 'individual') {
            // Base query for individual student reports
            $query = "
                SELECT 
                    s.student_id,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                    c.class_id,
                    c.class_name,
                    p.program_name,
                    c.level,
                    a.assessment_id,
                    a.title AS assessment_title,
                    a.date AS assessment_date,
                    sem.semester_name,
                    sub.subject_id,
                    sub.subject_name,
                    r.score,
                    r.status AS result_status,
                    SUM(q.max_score) AS max_possible_score,
                    att.start_time,
                    att.end_time,
                    att.status AS attempt_status
                FROM 
                    students s
                JOIN 
                    classes c ON s.class_id = c.class_id
                JOIN 
                    programs p ON c.program_id = p.program_id
                LEFT JOIN 
                    assessmentattempts att ON att.student_id = s.student_id
                LEFT JOIN 
                    assessments a ON att.assessment_id = a.assessment_id
                LEFT JOIN 
                    semesters sem ON a.semester_id = sem.semester_id
                LEFT JOIN 
                    assessmentclasses ac ON a.assessment_id = ac.assessment_id AND ac.class_id = c.class_id
                LEFT JOIN 
                    subjects sub ON ac.subject_id = sub.subject_id
                LEFT JOIN 
                    results r ON r.assessment_id = a.assessment_id AND r.student_id = s.student_id
                LEFT JOIN 
                    questions q ON q.assessment_id = a.assessment_id
                WHERE 1=1
            ";
            
            // Apply filters
            if (!empty($program)) {
                $whereConditions[] = "p.program_name = ?";
                $params[] = $program;
            }
            
            if (!empty($class)) {
                $whereConditions[] = "c.class_id = ?";
                $params[] = $class;
            }
            
            if (!empty($subject)) {
                $whereConditions[] = "sub.subject_id = ?";
                $params[] = $subject;
            }
            
            if (!empty($assessment)) {
                $whereConditions[] = "a.assessment_id = ?";
                $params[] = $assessment;
            }
            
            if (!empty($level)) {
                $whereConditions[] = "c.level = ?";
                $params[] = $level;
            }
            
            if (!empty($semester)) {
                $whereConditions[] = "sem.semester_id = ?";
                $params[] = $semester;
            }
            
            if (!empty($dateFrom)) {
                $whereConditions[] = "a.date >= ?";
                $params[] = $dateFrom;
            }
            
            if (!empty($dateTo)) {
                $whereConditions[] = "a.date <= ?";
                $params[] = $dateTo;
            }
            
            if (!empty($search)) {
                $searchTerm = "%$search%";
                $whereConditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR c.class_name LIKE ? OR a.title LIKE ? OR sub.subject_name LIKE ?)";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            // Append where conditions
            if (!empty($whereConditions)) {
                $query .= " AND " . implode(" AND ", $whereConditions);
            }
            
            // Group by and order
            $query .= " 
                GROUP BY 
                    s.student_id, a.assessment_id, sub.subject_id
                ORDER BY 
                    c.class_name, 
                    student_name, 
                    a.date DESC, 
                    sub.subject_name
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process data for easier access in the view
            $data = groupStudentData($results);
            
        } else if ($reportType === 'class') {
            // Class report query - get all subjects and assessments for a specific class
            if (empty($class)) {
                return ['error' => 'A class must be selected for class reports'];
            }
            
            // 1. Get class information
            $classQuery = "
                SELECT 
                    c.class_id, 
                    c.class_name, 
                    p.program_name, 
                    c.level,
                    COUNT(DISTINCT s.student_id) as student_count
                FROM 
                    classes c
                JOIN 
                    programs p ON c.program_id = p.program_id
                LEFT JOIN 
                    students s ON s.class_id = c.class_id
                WHERE 
                    c.class_id = ?
                GROUP BY 
                    c.class_id
            ";
            
            $stmt = $db->prepare($classQuery);
            $stmt->execute([$class]);
            $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classInfo) {
                return ['error' => 'Class not found'];
            }
            
            // 2. Get all subjects assigned to this class
            $subjectQuery = "
                SELECT DISTINCT
                    s.subject_id,
                    s.subject_name
                FROM 
                    classsubjects cs
                JOIN 
                    subjects s ON cs.subject_id = s.subject_id
                WHERE 
                    cs.class_id = ?
                ORDER BY 
                    s.subject_name
            ";
            
            $stmt = $db->prepare($subjectQuery);
            $stmt->execute([$class]);
            $classSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. Get all students in the class
            $studentsQuery = "
                SELECT 
                    s.student_id,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name
                FROM 
                    students s
                WHERE 
                    s.class_id = ?
                ORDER BY 
                    s.last_name, s.first_name
            ";
            
            $stmt = $db->prepare($studentsQuery);
            $stmt->execute([$class]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 4. Get assessment results for all students
            $params = [$class];
            $assessmentWhereConditions = ["ac.class_id = ?"];
            
            if (!empty($semester)) {
                $assessmentWhereConditions[] = "a.semester_id = ?";
                $params[] = $semester;
            }
            
            if (!empty($dateFrom)) {
                $assessmentWhereConditions[] = "a.date >= ?";
                $params[] = $dateFrom;
            }
            
            if (!empty($dateTo)) {
                $assessmentWhereConditions[] = "a.date <= ?";
                $params[] = $dateTo;
            }
            
            $assessmentsQuery = "
                SELECT 
                    a.assessment_id,
                    a.title,
                    a.date,
                    sem.semester_name,
                    ac.subject_id,
                    s.subject_name,
                    (SELECT AVG(r.score) FROM results r WHERE r.assessment_id = a.assessment_id) AS class_average
                FROM 
                    assessments a
                JOIN 
                    assessmentclasses ac ON a.assessment_id = ac.assessment_id
                JOIN 
                    subjects s ON ac.subject_id = s.subject_id
                JOIN
                    semesters sem ON a.semester_id = sem.semester_id
                WHERE " . implode(" AND ", $assessmentWhereConditions) . " 
                ORDER BY 
                    a.date DESC, s.subject_name
            ";
            
            $stmt = $db->prepare($assessmentsQuery);
            $stmt->execute($params);
            $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 5. Get individual student scores for all assessments
            $scoresQuery = "
                SELECT 
                    r.student_id,
                    r.assessment_id,
                    r.score,
                    r.status
                FROM 
                    results r
                JOIN 
                    students s ON r.student_id = s.student_id
                JOIN 
                    assessments a ON r.assessment_id = a.assessment_id
                JOIN 
                    assessmentclasses ac ON a.assessment_id = ac.assessment_id
                WHERE 
                    s.class_id = ? AND ac.class_id = ?
            ";
            
            $params = [$class, $class];
            
            if (!empty($semester)) {
                $scoresQuery .= " AND a.semester_id = ?";
                $params[] = $semester;
            }
            
            if (!empty($dateFrom)) {
                $scoresQuery .= " AND a.date >= ?";
                $params[] = $dateFrom;
            }
            
            if (!empty($dateTo)) {
                $scoresQuery .= " AND a.date <= ?";
                $params[] = $dateTo;
            }
            
            $stmt = $db->prepare($scoresQuery);
            $stmt->execute($params);
            $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Organize scores by student and assessment
            $studentScores = [];
            foreach ($scores as $score) {
                $studentScores[$score['student_id']][$score['assessment_id']] = [
                    'score' => $score['score'],
                    'status' => $score['status']
                ];
            }
            
            // 6. Calculate subject averages for each student
            $subjectAverages = [];
            foreach ($students as $student) {
                $studentId = $student['student_id'];
                $subjectAverages[$studentId] = [];
                
                foreach ($classSubjects as $subject) {
                    $subjectId = $subject['subject_id'];
                    $subjectScores = [];
                    
                    foreach ($assessments as $assessment) {
                        if ($assessment['subject_id'] == $subjectId && 
                            isset($studentScores[$studentId][$assessment['assessment_id']])) {
                            $subjectScores[] = $studentScores[$studentId][$assessment['assessment_id']]['score'];
                        }
                    }
                    
                    // Calculate average if there are scores
                    if (!empty($subjectScores)) {
                        $subjectAverages[$studentId][$subjectId] = array_sum($subjectScores) / count($subjectScores);
                    } else {
                        $subjectAverages[$studentId][$subjectId] = null;
                    }
                }
            }
            
            // 7. Calculate overall averages for each student
            $overallAverages = [];
            foreach ($students as $student) {
                $studentId = $student['student_id'];
                $allScores = [];
                
                foreach ($assessments as $assessment) {
                    if (isset($studentScores[$studentId][$assessment['assessment_id']])) {
                        $allScores[] = $studentScores[$studentId][$assessment['assessment_id']]['score'];
                    }
                }
                
                // Calculate overall average
                if (!empty($allScores)) {
                    $overallAverages[$studentId] = array_sum($allScores) / count($allScores);
                } else {
                    $overallAverages[$studentId] = null;
                }
            }
            
            // Compile the full report data
            $data = [
                'class_info' => $classInfo,
                'subjects' => $classSubjects,
                'students' => $students,
                'assessments' => $assessments,
                'student_scores' => $studentScores,
                'subject_averages' => $subjectAverages,
                'overall_averages' => $overallAverages
            ];
        }
        
        return $data;
    } catch (Exception $e) {
        logError("Report generation error: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * Group student data by class, student, and subject for easier access in the view
 */
function groupStudentData($results) {
    $grouped = [];
    
    foreach ($results as $row) {
        $classId = $row['class_id'];
        $studentId = $row['student_id'];
        $assessmentId = $row['assessment_id'];
        $subjectId = $row['subject_id'];
        
        // Initialize structures if not exists
        if (!isset($grouped[$classId])) {
            $grouped[$classId] = [
                'class_name' => $row['class_name'],
                'program_name' => $row['program_name'],
                'level' => $row['level'],
                'students' => []
            ];
        }
        
        if (!isset($grouped[$classId]['students'][$studentId])) {
            $grouped[$classId]['students'][$studentId] = [
                'student_name' => $row['student_name'],
                'student_id' => $studentId,
                'assessments' => []
            ];
        }
        
        // Skip if assessment is null (student has no assessments)
        if ($assessmentId === null) continue;
        
        if (!isset($grouped[$classId]['students'][$studentId]['assessments'][$assessmentId])) {
            $grouped[$classId]['students'][$studentId]['assessments'][$assessmentId] = [
                'assessment_id' => $assessmentId,
                'assessment_title' => $row['assessment_title'],
                'assessment_date' => $row['assessment_date'],
                'semester_name' => $row['semester_name'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'attempt_status' => $row['attempt_status'],
                'subjects' => []
            ];
        }
        
        // Skip if subject is null
        if ($subjectId === null) continue;
        
        $grouped[$classId]['students'][$studentId]['assessments'][$assessmentId]['subjects'][$subjectId] = [
            'subject_id' => $subjectId,
            'subject_name' => $row['subject_name'],
            'score' => $row['score'],
            'max_possible_score' => $row['max_possible_score'],
            'result_status' => $row['result_status'],
            'percentage' => ($row['max_possible_score'] > 0) ? 
                round(($row['score'] / $row['max_possible_score']) * 100, 2) : 0
        ];
    }
    
    return $grouped;
}

/**
 * Export data as Excel
 */
function exportExcel($data, $reportType, $reportTitle) {
    require_once BASEPATH . '/vendor/autoload.php';
    
    // Create new spreadsheet
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Student Report');
    
    // Set report title
    $sheet->setCellValue('A1', $reportTitle);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->mergeCells('A1:H1');
    
    // Set up headers based on report type
    if ($reportType === 'individual') {
        $headers = ['Class', 'Student Name', 'Assessment', 'Date', 'Subject', 'Score', 'Max Score', 'Percentage'];
        $sheet->fromArray($headers, null, 'A3');
        
        // Style headers
        $sheet->getStyle('A3:H3')->getFont()->setBold(true);
        $sheet->getStyle('A3:H3')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDDDDD');
        
        // Populate data
        $row = 4;
        foreach ($data as $classData) {
            foreach ($classData['students'] as $studentData) {
                foreach ($studentData['assessments'] as $assessmentData) {
                    foreach ($assessmentData['subjects'] as $subjectData) {
                        $sheet->setCellValue('A' . $row, $classData['class_name']);
                        $sheet->setCellValue('B' . $row, $studentData['student_name']);
                        $sheet->setCellValue('C' . $row, $assessmentData['assessment_title']);
                        $sheet->setCellValue('D' . $row, date('Y-m-d', strtotime($assessmentData['assessment_date'])));
                        $sheet->setCellValue('E' . $row, $subjectData['subject_name']);
                        $sheet->setCellValue('F' . $row, $subjectData['score']);
                        $sheet->setCellValue('G' . $row, $subjectData['max_possible_score']);
                        $sheet->setCellValue('H' . $row, $subjectData['percentage'] . '%');
                        
                        // Color-code scores
                        if ($subjectData['percentage'] < 50) {
                            $sheet->getStyle('H' . $row)->getFill()
                                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                                ->getStartColor()->setRGB('FFCCCC');
                        } elseif ($subjectData['percentage'] >= 80) {
                            $sheet->getStyle('H' . $row)->getFill()
                                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                                ->getStartColor()->setRGB('CCFFCC');
                        }
                        
                        $row++;
                    }
                }
            }
        }
    } else if ($reportType === 'class') {
        if (isset($data['error'])) {
            $sheet->setCellValue('A3', 'Error: ' . $data['error']);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="class_report_error.xlsx"');
            header('Cache-Control: max-age=0');
            $writer->save('php://output');
            return;
        }
        
        $classInfo = $data['class_info'];
        $subjects = $data['subjects'];
        $students = $data['students'];
        
        // Add class information
        $sheet->setCellValue('A2', 'Class: ' . $classInfo['class_name']);
        $sheet->setCellValue('A3', 'Program: ' . $classInfo['program_name']);
        $sheet->setCellValue('A4', 'Level: ' . $classInfo['level']);
        $sheet->setCellValue('A5', 'Total Students: ' . $classInfo['student_count']);
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);
        
        // Headers - Student name in first column, then all subjects, and Overall Average
        $sheet->setCellValue('A7', 'Student Name');
        $sheet->getStyle('A7')->getFont()->setBold(true);
        
        $col = 'B';
        foreach ($subjects as $subject) {
            $sheet->setCellValue($col . '7', $subject['subject_name']);
            $sheet->getStyle($col . '7')->getFont()->setBold(true);
            $col++;
        }
        
        $sheet->setCellValue($col . '7', 'Overall Average');
        $sheet->getStyle($col . '7')->getFont()->setBold(true);
        $overallCol = $col;
        
        // Style headers
        $lastCol = $col;
        $sheet->getStyle('A7:' . $lastCol . '7')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDDDDD');
        
        // Student data
        $row = 8;
        foreach ($students as $student) {
            $studentId = $student['student_id'];
            
            $sheet->setCellValue('A' . $row, $student['student_name']);
            
            $col = 'B';
            foreach ($subjects as $subject) {
                $subjectId = $subject['subject_id'];
                $average = $data['subject_averages'][$studentId][$subjectId] ?? null;
                
                if ($average !== null) {
                    $sheet->setCellValue($col . $row, round($average, 2));
                    
                    // Color-code averages
                    if ($average < 50) {
                        $sheet->getStyle($col . $row)->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB('FFCCCC');
                    } elseif ($average >= 80) {
                        $sheet->getStyle($col . $row)->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB('CCFFCC');
                    }
                } else {
                    $sheet->setCellValue($col . $row, 'N/A');
                }
                
                $col++;
            }
            
            // Overall average
            $overallAvg = $data['overall_averages'][$studentId] ?? null;
            if ($overallAvg !== null) {
                $sheet->setCellValue($overallCol . $row, round($overallAvg, 2));
                
                // Color-code overall average
                if ($overallAvg < 50) {
                    $sheet->getStyle($overallCol . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FFCCCC');
                } elseif ($overallAvg >= 80) {
                    $sheet->getStyle($overallCol . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('CCFFCC');
                }
            } else {
                $sheet->setCellValue($overallCol . $row, 'N/A');
            }
            
            $row++;
        }
        
        // Add assessment details in a new sheet
        $assessmentSheet = $spreadsheet->createSheet();
        $assessmentSheet->setTitle('Assessments');
        
        $assessmentSheet->setCellValue('A1', 'Assessments Details');
        $assessmentSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $assessmentSheet->mergeCells('A1:E1');
        
        $assessmentSheet->setCellValue('A3', 'Assessment Title');
        $assessmentSheet->setCellValue('B3', 'Subject');
        $assessmentSheet->setCellValue('C3', 'Date');
        $assessmentSheet->setCellValue('D3', 'Semester');
        $assessmentSheet->setCellValue('E3', 'Class Average');
        
        $assessmentSheet->getStyle('A3:E3')->getFont()->setBold(true);
        $assessmentSheet->getStyle('A3:E3')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDDDDD');
        
        $row = 4;
        foreach ($data['assessments'] as $assessment) {
            $assessmentSheet->setCellValue('A' . $row, $assessment['title']);
            $assessmentSheet->setCellValue('B' . $row, $assessment['subject_name']);
            $assessmentSheet->setCellValue('C' . $row, date('Y-m-d', strtotime($assessment['date'])));
            $assessmentSheet->setCellValue('D' . $row, $assessment['semester_name']);
            $assessmentSheet->setCellValue('E' . $row, round($assessment['class_average'] ?? 0, 2));
            $row++;
        }
        
        // Auto-size columns for both sheets
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        foreach (range('A', 'E') as $col) {
            $assessmentSheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    // Set up borders for all used cells
    $lastRow = $sheet->getHighestRow();
    $lastCol = $sheet->getHighestColumn();
    $sheet->getStyle('A3:' . $lastCol . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    
    // Save as Excel file
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . str_replace(' ', '_', $reportTitle) . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
}

/**
 * Export data as CSV
 */
function exportCSV($data, $reportType, $reportTitle) {
    // Set headers
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="' . str_replace(' ', '_', $reportTitle) . '.csv"');
    
    // Create a file pointer
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 encoding
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write the report title
    fputcsv($output, [$reportTitle]);
    fputcsv($output, []); // Empty line
    
    if ($reportType === 'individual') {
        // Add headers
        fputcsv($output, ['Class', 'Student Name', 'Assessment', 'Date', 'Subject', 'Score', 'Max Score', 'Percentage']);
        
        // Add data
        foreach ($data as $classData) {
            foreach ($classData['students'] as $studentData) {
                foreach ($studentData['assessments'] as $assessmentData) {
                    foreach ($assessmentData['subjects'] as $subjectData) {
                        fputcsv($output, [
                            $classData['class_name'],
                            $studentData['student_name'],
                            $assessmentData['assessment_title'],
                            date('Y-m-d', strtotime($assessmentData['assessment_date'])),
                            $subjectData['subject_name'],
                            $subjectData['score'],
                            $subjectData['max_possible_score'],
                            $subjectData['percentage'] . '%'
                        ]);
                    }
                }
            }
        }
    } else if ($reportType === 'class') {
        if (isset($data['error'])) {
            fputcsv($output, ['Error: ' . $data['error']]);
            return;
        }
        
        $classInfo = $data['class_info'];
        $subjects = $data['subjects'];
        $students = $data['students'];
        
        // Add class information
        fputcsv($output, ['Class:', $classInfo['class_name']]);
        fputcsv($output, ['Program:', $classInfo['program_name']]);
        fputcsv($output, ['Level:', $classInfo['level']]);
        fputcsv($output, ['Total Students:', $classInfo['student_count']]);
        fputcsv($output, []); // Empty line
        
        // Create headers row
        $headers = ['Student Name'];
        foreach ($subjects as $subject) {
            $headers[] = $subject['subject_name'];
        }
        $headers[] = 'Overall Average';
        
        fputcsv($output, $headers);
        
        // Add student data
        foreach ($students as $student) {
            $studentId = $student['student_id'];
            $row = [$student['student_name']];
            
            foreach ($subjects as $subject) {
                $subjectId = $subject['subject_id'];
                $average = $data['subject_averages'][$studentId][$subjectId] ?? null;
                $row[] = ($average !== null) ? round($average, 2) : 'N/A';
            }
            
            $overallAvg = $data['overall_averages'][$studentId] ?? null;
            $row[] = ($overallAvg !== null) ? round($overallAvg, 2) : 'N/A';
            
            fputcsv($output, $row);
        }
        
        // Add assessment details in a new section
        fputcsv($output, []); // Empty line
        fputcsv($output, []); // Empty line
        fputcsv($output, ['Assessments Details']);
        fputcsv($output, []); // Empty line
        
        fputcsv($output, ['Assessment Title', 'Subject', 'Date', 'Semester', 'Class Average']);
        
        foreach ($data['assessments'] as $assessment) {
            fputcsv($output, [
                $assessment['title'],
                $assessment['subject_name'],
                date('Y-m-d', strtotime($assessment['date'])),
                $assessment['semester_name'],
                round($assessment['class_average'] ?? 0, 2)
            ]);
        }
    }
    
    // Close the file pointer
    fclose($output);
}

/**
 * Export data as PDF using TCPDF
 */
function exportPDF($data, $reportType, $reportTitle) {
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Check if TCPDF is installed
    if (!class_exists('\TCPDF')) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='padding: 20px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px;'>";
        echo "<h3>Error: Missing TCPDF Library</h3>";
        echo "<p>The TCPDF library is required for PDF exports. Please install it using Composer:</p>";
        echo "<pre>composer require tecnickcom/tcpdf</pre>";
        echo "<p><a href='javascript:history.back()'>← Go Back</a></p>";
        echo "</div>";
        exit;
    }

    // Create new TCPDF instance
    $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('School Management System');
    $pdf->SetAuthor('Administrator');
    $pdf->SetTitle($reportTitle);
    $pdf->SetSubject('Student Reports');
    
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
    
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Add a page in landscape mode
    $pdf->AddPage('L');
    
    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $reportTitle, 0, 1, 'C');
    $pdf->Ln(5);
    
    // Content based on report type
    if ($reportType === 'individual') {
        $pdf->SetFont('helvetica', 'B', 11);
        
        // Table header
        $header = ['Class', 'Student Name', 'Assessment', 'Date', 'Subject', 'Score', 'Max', '%'];
        $widths = [30, 40, 50, 25, 40, 20, 20, 20];
        
        // Colors for header
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetLineWidth(0.3);
        
        // Header
        for($i = 0; $i < count($header); $i++) {
            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
        }
        $pdf->Ln();
        
        // Data
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetFillColor(224, 235, 255);
        $fill = 0;
        
        foreach ($data as $classData) {
            foreach ($classData['students'] as $studentData) {
                foreach ($studentData['assessments'] as $assessmentData) {
                    foreach ($assessmentData['subjects'] as $subjectData) {
                        // Set color based on percentage
                        if ($subjectData['percentage'] < 50) {
                            $pdf->SetFillColor(255, 200, 200);
                            $fill = 1;
                        } elseif ($subjectData['percentage'] >= 80) {
                            $pdf->SetFillColor(200, 255, 200);
                            $fill = 1;
                        } else {
                            $pdf->SetFillColor(224, 235, 255);
                            $fill = !$fill;
                        }
                        
                        $pdf->Cell($widths[0], 6, $classData['class_name'], 'LR', 0, 'L', $fill);
                        $pdf->Cell($widths[1], 6, $studentData['student_name'], 'LR', 0, 'L', $fill);
                        $pdf->Cell($widths[2], 6, $assessmentData['assessment_title'], 'LR', 0, 'L', $fill);
                        $pdf->Cell($widths[3], 6, date('Y-m-d', strtotime($assessmentData['assessment_date'])), 'LR', 0, 'C', $fill);
                        $pdf->Cell($widths[4], 6, $subjectData['subject_name'], 'LR', 0, 'L', $fill);
                        $pdf->Cell($widths[5], 6, $subjectData['score'], 'LR', 0, 'C', $fill);
                        $pdf->Cell($widths[6], 6, $subjectData['max_possible_score'], 'LR', 0, 'C', $fill);
                        $pdf->Cell($widths[7], 6, $subjectData['percentage'] . '%', 'LR', 0, 'C', $fill);
                        $pdf->Ln();
                        
                        // Reset fill color
                        $pdf->SetFillColor(224, 235, 255);
                    }
                }
            }
        }
        
        // Closing line
        $pdf->Cell(array_sum($widths), 0, '', 'T');
        
    } else if ($reportType === 'class') {
        if (isset($data['error'])) {
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 10, 'Error: ' . $data['error'], 0, 1, 'L');
            $pdf->Output('class_report_error.pdf', 'I');
            return;
        }
        
        $classInfo = $data['class_info'];
        $subjects = $data['subjects'];
        $students = $data['students'];
        
        // Add class information
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 10, 'Class:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(100, 10, $classInfo['class_name'], 0, 1, 'L');
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 10, 'Program:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(100, 10, $classInfo['program_name'], 0, 1, 'L');
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 10, 'Level:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(100, 10, $classInfo['level'], 0, 1, 'L');
        
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(40, 10, 'Total Students:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(100, 10, $classInfo['student_count'], 0, 1, 'L');
        
        $pdf->Ln(5);
        
        // Calculate column widths
        $numSubjects = count($subjects);
        $studentColWidth = 50; // Width for student name column
        $subjectColWidth = min(25, (277 - $studentColWidth) / ($numSubjects + 1)); // +1 for overall average
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetLineWidth(0.3);
        
        // Student name header
        $pdf->Cell($studentColWidth, 7, 'Student Name', 1, 0, 'C', 1);
        
        // Subject headers
        foreach ($subjects as $subject) {
            $pdf->Cell($subjectColWidth, 7, $subject['subject_name'], 1, 0, 'C', 1);
        }
        
        // Overall average header
        $pdf->Cell($subjectColWidth, 7, 'Overall', 1, 0, 'C', 1);
        $pdf->Ln();
        
        // Student data
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetFillColor(224, 235, 255);
        $fill = 0;
        
        foreach ($students as $student) {
            $studentId = $student['student_id'];
            
            // Alternate row colors
            $fill = !$fill;
            
            $pdf->Cell($studentColWidth, 6, $student['student_name'], 1, 0, 'L', $fill);
            
            foreach ($subjects as $subject) {
                $subjectId = $subject['subject_id'];
                $average = $data['subject_averages'][$studentId][$subjectId] ?? null;
                
                // Determine cell color based on average
                if ($average !== null) {
                    if ($average < 50) {
                        $pdf->SetFillColor(255, 200, 200);
                    } elseif ($average >= 80) {
                        $pdf->SetFillColor(200, 255, 200);
                    } else {
                        $pdf->SetFillColor(224, 235, 255);
                    }
                    
                    $pdf->Cell($subjectColWidth, 6, round($average, 2), 1, 0, 'C', 1);
                } else {
                    $pdf->Cell($subjectColWidth, 6, 'N/A', 1, 0, 'C', $fill);
                }
                
                // Reset fill color for next cell
                $pdf->SetFillColor($fill ? 224 : 255, $fill ? 235 : 255, $fill ? 255 : 255);
            }
            
            // Overall average
            $overallAvg = $data['overall_averages'][$studentId] ?? null;
            if ($overallAvg !== null) {
                if ($overallAvg < 50) {
                    $pdf->SetFillColor(255, 200, 200);
                } elseif ($overallAvg >= 80) {
                    $pdf->SetFillColor(200, 255, 200);
                } else {
                    $pdf->SetFillColor(224, 235, 255);
                }
                
                $pdf->Cell($subjectColWidth, 6, round($overallAvg, 2), 1, 0, 'C', 1);
            } else {
                $pdf->Cell($subjectColWidth, 6, 'N/A', 1, 0, 'C', $fill);
            }
            
            $pdf->Ln();
        }
        
        // Add a new page for assessment details
        $pdf->AddPage('L');
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Assessment Details - ' . $classInfo['class_name'], 0, 1, 'C');
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 11);
        $header = ['Assessment Title', 'Subject', 'Date', 'Semester', 'Class Average'];
        $widths = [80, 60, 30, 50, 30];
        
        // Colors for header
        $pdf->SetFillColor(200, 200, 200);
        
        // Header
        for($i = 0; $i < count($header); $i++) {
            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', 1);
        }
        $pdf->Ln();
        
        // Data
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetFillColor(224, 235, 255);
        $fill = 0;
        
        foreach ($data['assessments'] as $assessment) {
            // Alternate row colors
            $fill = !$fill;
            
            $pdf->Cell($widths[0], 6, $assessment['title'], 1, 0, 'L', $fill);
            $pdf->Cell($widths[1], 6, $assessment['subject_name'], 1, 0, 'L', $fill);
            $pdf->Cell($widths[2], 6, date('Y-m-d', strtotime($assessment['date'])), 1, 0, 'C', $fill);
            $pdf->Cell($widths[3], 6, $assessment['semester_name'], 1, 0, 'L', $fill);
            $pdf->Cell($widths[4], 6, round($assessment['class_average'] ?? 0, 2), 1, 0, 'C', $fill);
            $pdf->Ln();
        }
    }
    
    // Output PDF
    $pdf->Output(str_replace(' ', '_', $reportTitle) . '.pdf', 'I');
}

$pageTitle = 'Generate Student Reports';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-warning">Generate Student Reports</h1>
            <p class="text-muted">Generate detailed student performance reports with various export options</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-warning" id="exportBtn">
                <i class="fas fa-file-export me-1"></i> Export Report
            </button>
            <button type="button" class="btn btn-warning" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
            </button>
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

    <!-- Report Type Selection -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Report Type</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="report_type" id="report_type_individual" value="individual" 
                               <?php echo $reportType === 'individual' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-warning" for="report_type_individual">Individual Student</label>
                        
                        <input type="radio" class="btn-check" name="report_type" id="report_type_class" value="class" 
                               <?php echo $reportType === 'class' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-warning" for="report_type_class">Class Report</label>
                    </div>
                </div>
                
                <div class="col-md-9">
                    <div class="alert alert-warning p-2 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="report_type_info">
                            <?php if ($reportType === 'individual'): ?>
                                Individual reports show detailed performance for each student across all assessments.
                            <?php else: ?>
                                Class reports provide an overview of all students in a class with subject averages.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="report_type" id="hidden_report_type" value="<?php echo $reportType; ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select" id="program_select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo htmlspecialchars($program['program_name']); ?>" 
                                        <?php echo $filter_program === $program['program_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-select" id="level_select">
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
                        <label class="form-label">Class</label>
                        <select name="class" class="form-select" id="class_select">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>" 
                                        data-program="<?php echo htmlspecialchars($class['program_name']); ?>"
                                        data-level="<?php echo htmlspecialchars($class['level']); ?>"
                                        <?php echo $filter_class == $class['class_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
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
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?php echo $semester['semester_id']; ?>" 
                                        <?php echo $filter_semester == $semester['semester_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Assessment</label>
                        <select name="assessment" class="form-select">
                            <option value="">All Assessments</option>
                            <?php foreach ($assessments as $assessment): ?>
                                <option value="<?php echo $assessment['assessment_id']; ?>" 
                                        <?php echo $filter_assessment == $assessment['assessment_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($assessment['title'] . ' (' . $assessment['date'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $filter_date_from; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $filter_date_to; ?>">
                    </div>
                    
                    <div class="col-md-8">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by student name, class, assessment title..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-4 text-end">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <a href="generate_student_reports.php" class="btn btn-outline-secondary flex-grow-1">
                                <i class="fas fa-redo me-1"></i> Reset Filters
                            </a>
                            <button type="submit" class="btn btn-warning flex-grow-1">
                                <i class="fas fa-file-alt me-1"></i> Generate Report
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card shadow">
        <div class="card-body">
            <?php if (empty($studentReports)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                    <h5>No Report Generated</h5>
                    <p class="text-muted">Please select filters and click "Generate Report" to view student reports.</p>
                </div>
            <?php elseif (isset($studentReports['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $studentReports['error']; ?>
                </div>
            <?php else: ?>
                <!-- Individual Report View -->
                <?php if ($reportType === 'individual'): ?>
                    <?php if (empty($studentReports)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No student data found matching the selected criteria.
                        </div>
                    <?php else: ?>
                        <!-- Individual Student Reports -->
                        <?php foreach ($studentReports as $classId => $classData): ?>
                            <div class="report-class mb-4">
                                <h4 class="text-warning mb-3">
                                    <i class="fas fa-school me-2"></i><?php echo htmlspecialchars($classData['class_name']); ?> - 
                                    <?php echo htmlspecialchars($classData['program_name']); ?> (<?php echo htmlspecialchars($classData['level']); ?>)
                                </h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Student</th>
                                                <th>Assessment</th>
                                                <th>Date</th>
                                                <th>Subject</th>
                                                <th>Score</th>
                                                <th>Max Score</th>
                                                <th>Percentage</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($classData['students'] as $studentId => $studentData): ?>
                                                <?php $rowspanStudent = 0; ?>
                                                <?php foreach ($studentData['assessments'] as $assessmentId => $assessmentData): ?>
                                                    <?php foreach ($assessmentData['subjects'] as $subjectId => $subjectData): ?>
                                                        <?php $rowspanStudent++; ?><?php endforeach; ?>
                                                <?php endforeach; ?>
                                                
                                                <?php $assessmentRowspans = []; ?>
                                                <?php foreach ($studentData['assessments'] as $assessmentId => $assessmentData): ?>
                                                    <?php $assessmentRowspans[$assessmentId] = count($assessmentData['subjects']); ?>
                                                <?php endforeach; ?>
                                                
                                                <?php $rowCount = 0; ?>
                                                <?php foreach ($studentData['assessments'] as $assessmentId => $assessmentData): ?>
                                                    <?php $firstAssessmentRow = true; ?>
                                                    <?php foreach ($assessmentData['subjects'] as $subjectId => $subjectData): ?>
                                                        <tr>
                                                            <?php if ($rowCount === 0): ?>
                                                                <td rowspan="<?php echo $rowspanStudent; ?>" class="align-middle">
                                                                    <strong><?php echo htmlspecialchars($studentData['student_name']); ?></strong>
                                                                </td>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($firstAssessmentRow): ?>
                                                                <td rowspan="<?php echo $assessmentRowspans[$assessmentId]; ?>" class="align-middle">
                                                                    <?php echo htmlspecialchars($assessmentData['assessment_title']); ?>
                                                                </td>
                                                                <td rowspan="<?php echo $assessmentRowspans[$assessmentId]; ?>" class="align-middle">
                                                                    <?php echo date('M d, Y', strtotime($assessmentData['assessment_date'])); ?>
                                                                </td>
                                                                <?php $firstAssessmentRow = false; ?>
                                                            <?php endif; ?>
                                                            
                                                            <td><?php echo htmlspecialchars($subjectData['subject_name']); ?></td>
                                                            <td class="text-center"><?php echo $subjectData['score']; ?></td>
                                                            <td class="text-center"><?php echo $subjectData['max_possible_score']; ?></td>
                                                            <td class="text-center">
                                                                <div class="d-flex align-items-center">
                                                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                                        <div class="progress-bar <?php echo $subjectData['percentage'] < 50 ? 'bg-danger' : ($subjectData['percentage'] >= 80 ? 'bg-success' : 'bg-warning'); ?>" 
                                                                             role="progressbar" 
                                                                             style="width: <?php echo $subjectData['percentage']; ?>%" 
                                                                             aria-valuenow="<?php echo $subjectData['percentage']; ?>" 
                                                                             aria-valuemin="0" aria-valuemax="100"></div>
                                                                    </div>
                                                                    <span class="small"><?php echo $subjectData['percentage']; ?>%</span>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($subjectData['result_status'] === 'completed'): ?>
                                                                    <span class="badge bg-success">Completed</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning text-dark">Pending</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php $rowCount++; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Class Report View -->
                    <?php if (isset($studentReports['class_info'])): ?>
                        <div class="report-class mb-4">
                            <div class="report-header d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <h4 class="text-warning mb-1">
                                        <i class="fas fa-school me-2"></i><?php echo htmlspecialchars($studentReports['class_info']['class_name']); ?>
                                    </h4>
                                    <div>
                                        <strong>Program:</strong> <?php echo htmlspecialchars($studentReports['class_info']['program_name']); ?> |
                                        <strong>Level:</strong> <?php echo htmlspecialchars($studentReports['class_info']['level']); ?> |
                                        <strong>Total Students:</strong> <?php echo $studentReports['class_info']['student_count']; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div><strong>Report Date:</strong> <?php echo date('F d, Y'); ?></div>
                                    <?php if (!empty($filter_semester)): ?>
                                        <?php foreach ($semesters as $semester): ?>
                                            <?php if ($semester['semester_id'] == $filter_semester): ?>
                                                <div><strong>Semester:</strong> <?php echo htmlspecialchars($semester['semester_name']); ?></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" rowspan="2" style="vertical-align: middle;">Student Name</th>
                                            <?php if (!empty($studentReports['subjects'])): ?>
                                                <th class="text-center" colspan="<?php echo count($studentReports['subjects']); ?>">Subjects</th>
                                            <?php endif; ?>
                                            <th class="text-center" rowspan="2" style="vertical-align: middle;">Overall Average</th>
                                        </tr>
                                        <tr>
                                            <?php foreach ($studentReports['subjects'] as $subject): ?>
                                                <th class="text-center" style="min-width: 120px;"><?php echo htmlspecialchars($subject['subject_name']); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($studentReports['students'] as $student): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                
                                                <?php foreach ($studentReports['subjects'] as $subject): ?>
                                                    <?php 
                                                        $subjectId = $subject['subject_id'];
                                                        $studentId = $student['student_id'];
                                                        $average = $studentReports['subject_averages'][$studentId][$subjectId] ?? null;
                                                        
                                                        $cellClass = '';
                                                        if ($average !== null) {
                                                            if ($average < 50) {
                                                                $cellClass = 'table-danger';
                                                            } elseif ($average >= 80) {
                                                                $cellClass = 'table-success';
                                                            }
                                                        }
                                                    ?>
                                                    
                                                    <td class="text-center <?php echo $cellClass; ?>">
                                                        <?php if ($average !== null): ?>
                                                            <?php echo round($average, 2); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                
                                                <?php
                                                    $overallAvg = $studentReports['overall_averages'][$studentId] ?? null;
                                                    $overallClass = '';
                                                    if ($overallAvg !== null) {
                                                        if ($overallAvg < 50) {
                                                            $overallClass = 'table-danger';
                                                        } elseif ($overallAvg >= 80) {
                                                            $overallClass = 'table-success';
                                                        }
                                                    }
                                                ?>
                                                
                                                <td class="text-center fw-bold <?php echo $overallClass; ?>">
                                                    <?php if ($overallAvg !== null): ?>
                                                        <?php echo round($overallAvg, 2); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Assessments included in this report -->
                            <div class="mt-5">
                                <h5 class="text-warning mb-3">
                                    <i class="fas fa-clipboard-list me-2"></i>Assessments Included in This Report
                                </h5>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-sm">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Assessment Title</th>
                                                <th>Subject</th>
                                                <th>Date</th>
                                                <th>Semester</th>
                                                <th>Class Average</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($studentReports['assessments'])): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No assessments found for the selected filters.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($studentReports['assessments'] as $assessment): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($assessment['title']); ?></td>
                                                        <td><?php echo htmlspecialchars($assessment['subject_name']); ?></td>
                                                        <td><?php echo date('M d, Y', strtotime($assessment['date'])); ?></td>
                                                        <td><?php echo htmlspecialchars($assessment['semester_name']); ?></td>
                                                        <td class="fw-bold">
                                                            <?php
                                                                $avg = $assessment['class_average'] ?? 0;
                                                                $avgClass = '';
                                                                if ($avg < 50) {
                                                                    $avgClass = 'text-danger';
                                                                } elseif ($avg >= 80) {
                                                                    $avgClass = 'text-success';
                                                                }
                                                            ?>
                                                            <span class="<?php echo $avgClass; ?>"><?php echo round($avg, 2); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>Please select a class to generate a class report.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-warning">
                <h5 class="modal-title">Export Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="exportForm" action="generate_student_reports.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="export_filters" id="exportFilters">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select class="form-select" name="export_format">
                            <option value="excel">Excel (.xlsx)</option>
                            <option value="csv">CSV (.csv)</option>
                            <option value="pdf">PDF (.pdf)</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        The report will be exported with all current filters applied.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle report type change
    const individualRadio = document.getElementById('report_type_individual');
    const classRadio = document.getElementById('report_type_class');
    const hiddenReportType = document.getElementById('hidden_report_type');
    const reportTypeInfo = document.getElementById('report_type_info');
    
    function updateReportTypeInfo() {
        if (individualRadio.checked) {
            reportTypeInfo.textContent = 'Individual reports show detailed performance for each student across all assessments.';
        } else {
            reportTypeInfo.textContent = 'Class reports provide an overview of all students in a class with subject averages.';
        }
    }
    
    individualRadio.addEventListener('change', function() {
        if (this.checked) {
            hiddenReportType.value = 'individual';
            updateReportTypeInfo();
        }
    });
    
    classRadio.addEventListener('change', function() {
        if (this.checked) {
            hiddenReportType.value = 'class';
            updateReportTypeInfo();
        }
    });
    
    // Handle class filtering based on program and level
    const programSelect = document.getElementById('program_select');
    const levelSelect = document.getElementById('level_select');
    const classSelect = document.getElementById('class_select');
    
    function filterClasses() {
        const selectedProgram = programSelect.value;
        const selectedLevel = levelSelect.value;
        
        Array.from(classSelect.options).forEach(option => {
            if (option.value === '') return; // Skip the "All Classes" option
            
            const program = option.getAttribute('data-program');
            const level = option.getAttribute('data-level');
            
            const programMatch = !selectedProgram || program === selectedProgram;
            const levelMatch = !selectedLevel || level === selectedLevel;
            
            option.hidden = !(programMatch && levelMatch);
        });
    }
    
    programSelect.addEventListener('change', filterClasses);
    levelSelect.addEventListener('change', filterClasses);
    
    // Handle export button
    document.getElementById('exportBtn').addEventListener('click', function() {
        // Set current filters as JSON in hidden field
        const filters = {
            program: '<?php echo addslashes($filter_program); ?>',
            class: '<?php echo addslashes($filter_class); ?>',
            subject: '<?php echo addslashes($filter_subject); ?>',
            assessment: '<?php echo addslashes($filter_assessment); ?>',
            level: '<?php echo addslashes($filter_level); ?>',
            semester: '<?php echo addslashes($filter_semester); ?>',
            date_from: '<?php echo addslashes($filter_date_from); ?>',
            date_to: '<?php echo addslashes($filter_date_to); ?>',
            search: '<?php echo addslashes($search); ?>',
            report_type: '<?php echo addslashes($reportType); ?>'
        };
        
        document.getElementById('exportFilters').value = JSON.stringify(filters);
        
        // Show export modal
        new bootstrap.Modal(document.getElementById('exportModal')).show();
    });
    
    // Initial filter classes
    filterClasses();
});
</script>

<style>
/* Custom Styles for Student Reports Page */
.table > :not(caption) > * > * {
    padding: 0.75rem;
}

.table-dark th {
    background: linear-gradient(45deg, #000000, #333333);
    color: #ffd700;
    font-weight: 500;
}

.progress {
    background-color: #f2f2f2;
    border-radius: 10px;
    height: 8px;
}

.progress-bar {
    border-radius: 10px;
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

.card-title {
    font-weight: 600;
    margin-bottom: 15px;
}

/* Print styles */
@media print {
    .navbar, #sidebar-wrapper, 
    .btn, button, 
    .card-header, .form-control, .form-select,
    #filterForm, .actions, #exportBtn {
        display: none !important;
    }
    
    .card {
        border: none;
        margin-bottom: 1rem;
        box-shadow: none !important;
    }
    
    .container-fluid {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    body {
        font-size: 12pt;
    }
    
    table {
        width: 100% !important;
        page-break-inside: avoid;
    }
    
    .report-class {
        page-break-before: always;
    }
    
    .report-class:first-child {
        page-break-before: avoid;
    }
    
    @page {
        size: landscape;
        margin: 1cm;
    }
}
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>