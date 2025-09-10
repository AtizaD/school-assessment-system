<?php
// admin/export_participation.php
define('BASEPATH', dirname(__DIR__));
// Prevent any output before headers
ob_start();

require_once BASEPATH . '/vendor/autoload.php';
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure user is admin
requireRole('admin');

try {
    // Check if request is POST and has valid CSRF token
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
        // Get export parameters
        $exportFormat = sanitizeInput($_POST['export_format'] ?? 'excel');
        $exportData = sanitizeInput($_POST['export_data'] ?? 'both');
        $includeStudentDetails = isset($_POST['include_student_details']) && $_POST['include_student_details'] == 1;
        
        // Get filters from JSON string
        $filterJson = $_POST['export_filters'] ?? '{}';
        $filters = json_decode($filterJson, true) ?: [];
        
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Build queries based on filters
        $params = [];
        $whereClause = " WHERE 1=1";
        
        if (!empty($filters['program'])) {
            $whereClause .= " AND p.program_name = ?";
            $params[] = $filters['program'];
        }
        
        if (!empty($filters['class'])) {
            $whereClause .= " AND c.class_id = ?";
            $params[] = $filters['class'];
        }
        
        if (!empty($filters['subject'])) {
            $whereClause .= " AND s.subject_id = ?";
            $params[] = $filters['subject'];
        }
        
        if (!empty($filters['assessment'])) {
            $whereClause .= " AND ac.assessment_id = ?";
            $params[] = $filters['assessment'];
        }
        
        if (!empty($filters['level'])) {
            $whereClause .= " AND c.level = ?";
            $params[] = $filters['level'];
        }
        
        if (!empty($filters['date_from'])) {
            $whereClause .= " AND a.date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $whereClause .= " AND a.date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = "%" . $filters['search'] . "%";
            $whereClause .= " AND (a.title LIKE ? OR c.class_name LIKE ? OR p.program_name LIKE ? OR s.subject_name LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        // Create different datasets based on export selection
        $datasets = [];
        
        // Low participation data
        if ($exportData === 'low_participation' || $exportData === 'both') {
            $lowParticipationQuery = "
                SELECT 
                    ac.assessment_id,
                    a.title AS assessment_title,
                    a.date AS assessment_date,
                    ac.class_id,
                    c.class_name,
                    p.program_name,
                    c.level,
                    s.subject_name,
                    COUNT(DISTINCT st.student_id) AS total_students,
                    COUNT(DISTINCT att.student_id) AS students_attempted,
                    ROUND((COUNT(DISTINCT att.student_id) / COUNT(DISTINCT st.student_id)) * 100, 2) AS participation_percentage
                FROM 
                    assessmentclasses ac
                JOIN 
                    assessments a ON ac.assessment_id = a.assessment_id
                JOIN 
                    classes c ON ac.class_id = c.class_id
                JOIN 
                    programs p ON c.program_id = p.program_id
                JOIN 
                    subjects s ON ac.subject_id = s.subject_id
                JOIN 
                    students st ON st.class_id = c.class_id
                LEFT JOIN 
                    assessmentattempts att ON att.assessment_id = ac.assessment_id AND att.student_id = st.student_id
                $whereClause
                GROUP BY 
                    ac.assessment_id, ac.class_id, c.class_name, p.program_name, c.level, s.subject_name, a.title, a.date
                HAVING 
                    (COUNT(DISTINCT att.student_id) / COUNT(DISTINCT st.student_id)) * 100 < ? 
                    AND COUNT(DISTINCT att.student_id) > 0
                ORDER BY 
                    participation_percentage ASC, 
                    a.date DESC,
                    a.title, 
                    c.class_name";
            
            $threshold = isset($filters['threshold']) ? (int)$filters['threshold'] : 40;
            $lowParticipationParams = array_merge($params, [$threshold]);
            
            $stmt = $db->prepare($lowParticipationQuery);
            $stmt->execute($lowParticipationParams);
            $datasets['low_participation'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // No participation data
        if ($exportData === 'no_participation' || $exportData === 'both') {
            $noParticipationQuery = "
                SELECT 
                    ac.assessment_id,
                    a.title AS assessment_title,
                    a.date AS assessment_date,
                    ac.class_id,
                    c.class_name,
                    p.program_name,
                    c.level,
                    s.subject_name,
                    COUNT(DISTINCT st.student_id) AS total_students
                FROM 
                    assessmentclasses ac
                JOIN 
                    assessments a ON ac.assessment_id = a.assessment_id
                JOIN 
                    classes c ON ac.class_id = c.class_id
                JOIN 
                    programs p ON c.program_id = p.program_id
                JOIN 
                    subjects s ON ac.subject_id = s.subject_id
                JOIN 
                    students st ON st.class_id = c.class_id
                $whereClause
                AND NOT EXISTS (
                    SELECT 1
                    FROM assessmentattempts att
                    JOIN students s ON att.student_id = s.student_id
                    WHERE att.assessment_id = ac.assessment_id AND s.class_id = ac.class_id
                )
                GROUP BY 
                    ac.assessment_id, ac.class_id, c.class_name, p.program_name, c.level, s.subject_name, a.title, a.date
                ORDER BY 
                    a.date DESC,
                    a.title, 
                    c.class_name";
            
            $stmt = $db->prepare($noParticipationQuery);
            $stmt->execute($params);
            $datasets['no_participation'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get student details if requested
        if ($includeStudentDetails) {
            // For each dataset (low and no participation), fetch student details
            foreach ($datasets as $type => $data) {
                foreach ($data as $index => $row) {
                    // Query for students who haven't attempted the assessment
                    $query = "
                        SELECT 
                            s.student_id,
                            s.first_name,
                            s.last_name,
                            c.class_name
                        FROM 
                            students s
                        JOIN 
                            classes c ON s.class_id = c.class_id
                        WHERE 
                            s.class_id = ?
                    ";
                    
                    // For low participation, only include students who haven't attempted
                    if ($type === 'low_participation') {
                        $query .= " AND NOT EXISTS (
                            SELECT 1 FROM assessmentattempts att
                            WHERE att.student_id = s.student_id AND att.assessment_id = ?
                        )";
                    }
                    
                    $query .= " ORDER BY s.last_name, s.first_name";
                    
                    $stmt = $db->prepare($query);
                    
                    if ($type === 'low_participation') {
                        $stmt->execute([$row['class_id'], $row['assessment_id']]);
                    } else {
                        $stmt->execute([$row['class_id']]);
                    }
                    
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $datasets[$type][$index]['students'] = $students;
                }
            }
        }
        
        // Generate filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "assessment_participation_{$timestamp}";
        
        // Clear any previous output
        ob_clean();
        
        // Create appropriate output based on selected format
        switch ($exportFormat) {
            case 'excel':
                // Check if PhpSpreadsheet class exists
                if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
                    throw new Exception('PhpSpreadsheet library not found. Please check your installation.');
                }
                
                // Implement Excel export with PhpSpreadsheet
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
                header('Cache-Control: max-age=0');
                
                // Use fully qualified namespace
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                
                $sheetIndex = 0;
                foreach ($datasets as $type => $data) {
                    if (empty($data)) continue;
                    
                    if ($sheetIndex > 0) {
                        $spreadsheet->createSheet();
                    }
                    
                    $sheet = $spreadsheet->getSheet($sheetIndex);
                    $title = ($type === 'low_participation') ? 'Low Participation' : 'No Participation';
                    $sheet->setTitle($title);
                    
                    // Set header row
                    $headers = array_keys($data[0]);
                    if (isset($data[0]['students'])) {
                        // Remove students array from headers
                        $headers = array_filter($headers, function($header) {
                            return $header !== 'students';
                        });
                    }
                    
                    $col = 1;
                    foreach ($headers as $header) {
                        $sheet->setCellValueByColumnAndRow($col++, 1, ucwords(str_replace('_', ' ', $header)));
                    }
                    
                    // Add data rows
                    $row = 2;
                    foreach ($data as $dataRow) {
                        $col = 1;
                        foreach ($headers as $header) {
                            $sheet->setCellValueByColumnAndRow($col++, $row, $dataRow[$header]);
                        }
                        $row++;
                        
                        // Add student details if included
                        if (isset($dataRow['students']) && !empty($dataRow['students'])) {
                            $sheet->setCellValueByColumnAndRow(1, $row, 'Student List:');
                            $row++;
                            
                            // Add header for student data
                            $sheet->setCellValueByColumnAndRow(2, $row, 'ID');
                            $sheet->setCellValueByColumnAndRow(3, $row, 'First Name');
                            $sheet->setCellValueByColumnAndRow(4, $row, 'Last Name');
                            $sheet->setCellValueByColumnAndRow(5, $row, 'Class');
                            $row++;
                            
                            foreach ($dataRow['students'] as $student) {
                                $sheet->setCellValueByColumnAndRow(2, $row, $student['student_id']);
                                $sheet->setCellValueByColumnAndRow(3, $row, $student['first_name']);
                                $sheet->setCellValueByColumnAndRow(4, $row, $student['last_name']);
                                $sheet->setCellValueByColumnAndRow(5, $row, $student['class_name']);
                                $row++;
                            }
                            
                            $row++; // Add extra row for spacing
                        }
                    }
                    
                    $sheetIndex++;
                }
                
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
                
            case 'csv':
                // Implement CSV export
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                foreach ($datasets as $type => $data) {
                    if (empty($data)) continue;
                    
                    // Add header indicating dataset type
                    fputcsv($output, [$type === 'low_participation' ? 'LOW PARTICIPATION DATA' : 'NO PARTICIPATION DATA']);
                    
                    // Add column headers
                    $headers = array_keys($data[0]);
                    if (isset($data[0]['students'])) {
                        // Remove students array from headers
                        $headers = array_filter($headers, function($header) {
                            return $header !== 'students';
                        });
                    }
                    fputcsv($output, array_map(function($header) {
                        return ucwords(str_replace('_', ' ', $header));
                    }, $headers));
                    
                    // Add data rows
                    foreach ($data as $row) {
                        $rowData = [];
                        foreach ($headers as $header) {
                            $rowData[] = $row[$header];
                        }
                        fputcsv($output, $rowData);
                        
                        // Add student details if included
                        if (isset($row['students']) && !empty($row['students'])) {
                            fputcsv($output, ['Student List:']);
                            fputcsv($output, ['ID', 'First Name', 'Last Name', 'Class']);
                            
                            foreach ($row['students'] as $student) {
                                fputcsv($output, [
                                    $student['student_id'],
                                    $student['first_name'],
                                    $student['last_name'],
                                    $student['class_name']
                                ]);
                            }
                            
                            fputcsv($output, []); // Add blank line for spacing
                        }
                    }
                    
                    // Add blank line between datasets
                    fputcsv($output, []);
                }
                
                fclose($output);
                exit;
                
            case 'pdf':
                // Check if TCPDF class exists
                if (!class_exists('TCPDF')) {
                    throw new Exception('TCPDF library not found. Please check your installation.');
                }
                
                // Implement PDF export (using TCPDF)
                $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
                $pdf->SetCreator('ClassTest System');
                $pdf->SetAuthor('Admin');
                $pdf->SetTitle('Assessment Participation Report');
                $pdf->SetHeaderData('', 0, 'Assessment Participation Report', date('Y-m-d H:i:s'));
                $pdf->setHeaderFont(['helvetica', '', 10]);
                $pdf->setFooterFont(['helvetica', '', 8]);
                $pdf->SetDefaultMonospacedFont('courier');
                $pdf->SetMargins(10, 20, 10);
                $pdf->SetHeaderMargin(10);
                $pdf->SetFooterMargin(10);
                $pdf->SetAutoPageBreak(TRUE, 15);
                
                foreach ($datasets as $type => $data) {
                    if (empty($data)) continue;
                    
                    $pdf->AddPage();
                    $title = ($type === 'low_participation') ? 'Low Participation Data' : 'No Participation Data';
                    $pdf->SetFont('helvetica', 'B', 16);
                    $pdf->Cell(0, 10, $title, 0, 1, 'C');
                    
                    // Create table header
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetFillColor(0, 0, 0);
                    $pdf->SetTextColor(255, 255, 255);
                    
                    $headers = array_keys($data[0]);
                    if (isset($data[0]['students'])) {
                        // Remove students array from headers
                        $headers = array_filter($headers, function($header) {
                            return $header !== 'students';
                        });
                    }
                    
                    // Calculate column widths based on headers
                    $totalWidth = 270; // A4 landscape usable width (approx)
                    $columnCount = count($headers);
                    $columnWidth = $totalWidth / $columnCount;
                    
                    foreach ($headers as $header) {
                        $pdf->Cell($columnWidth, 7, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', 1);
                    }
                    $pdf->Ln();
                    
                    // Add data rows
                    $pdf->SetFont('helvetica', '', 8);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->SetTextColor(0, 0, 0);
                    
                    $alternate = false;
                    foreach ($data as $row) {
                        $alternate = !$alternate;
                        $fill = $alternate ? 0.95 : 1;
                        
                        foreach ($headers as $header) {
                            $pdf->Cell($columnWidth, 6, $row[$header], 1, 0, 'L', $fill);
                        }
                        $pdf->Ln();
                        
                        // Add student details if included
                        if (isset($row['students']) && !empty($row['students']) && $includeStudentDetails) {
                            $pdf->SetFont('helvetica', 'B', 8);
                            $pdf->Cell($totalWidth, 6, 'Students Who Haven\'t Attempted:', 1, 1, 'L');
                            
                            // Add student table header
                            $pdf->SetFont('helvetica', 'B', 8);
                            $pdf->SetFillColor(220, 220, 220);
                            $pdf->Cell(20, 6, 'ID', 1, 0, 'C', 1);
                            $pdf->Cell(60, 6, 'First Name', 1, 0, 'C', 1);
                            $pdf->Cell(60, 6, 'Last Name', 1, 0, 'C', 1);
                            $pdf->Cell(60, 6, 'Class', 1, 1, 'C', 1);
                            
                            $pdf->SetFont('helvetica', '', 8);
                            $pdf->SetFillColor(255, 255, 255);
                            
                            foreach ($row['students'] as $student) {
                                $pdf->Cell(20, 6, $student['student_id'], 1, 0, 'L');
                                $pdf->Cell(60, 6, $student['first_name'], 1, 0, 'L');
                                $pdf->Cell(60, 6, $student['last_name'], 1, 0, 'L');
                                $pdf->Cell(60, 6, $student['class_name'], 1, 1, 'L');
                            }
                            
                            // Add space after student list
                            $pdf->Ln(5);
                        }
                    }
                }
                
                $pdf->Output($filename . '.pdf', 'D');
                exit;
            
            default:
                throw new Exception('Unsupported export format');
        }
        
    } else {
        throw new Exception('Invalid request or CSRF token');
    }
    
} catch (Exception $e) {
    // Log the error
    logError("Export participation data error: " . $e->getMessage());
    
    // Store error message in session
    $_SESSION['error'] = "Error exporting data: " . $e->getMessage();
    
    // Redirect back to participation dashboard
    header('Location: assessment_participation.php');
    exit;
}
?>