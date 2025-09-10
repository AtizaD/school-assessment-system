<?php
// admin/export_payments.php - Export payment data
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(__FILE__)));
}
// Supports: Excel (.xlsx) and PDF formats
// Features: Styled headers, alternating row colors, auto-sizing, summary statistics

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once BASEPATH . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Require admin role
requireRole('admin');

$format = $_GET['format'] ?? 'excel';
$filter = $_GET['filter'] ?? 'all';
$dateRange = $_GET['date_range'] ?? 'all';
$className = $_GET['class_name'] ?? '';

try {
    // Disable error output for clean export
    ini_set('display_errors', 0);
    error_reporting(0);
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Build query
    $whereConditions = [];
    $params = [];
    
    if ($filter === 'paid') {
        $whereConditions[] = "s.payment_status = 'paid'";
    } elseif ($filter === 'unpaid') {
        $whereConditions[] = "s.payment_status = 'unpaid'";
    }
    
    if ($dateRange !== 'all') {
        $whereConditions[] = "s.payment_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = (int)$dateRange;
    }
    
    if (!empty($className)) {
        $whereConditions[] = "c.class_name = ?";
        $params[] = $className;
        // When filtering by class, only show paid students
        if (!in_array("s.payment_status = 'paid'", $whereConditions)) {
            $whereConditions[] = "s.payment_status = 'paid'";
        }
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $stmt = $db->prepare("
        SELECT 
            u.username,
            CONCAT(s.first_name, ' ', s.last_name) as full_name,
            c.class_name,
            p.program_name,
            s.payment_status,
            s.payment_date,
            s.payment_amount,
            s.payment_reference,
            u.created_at as registration_date
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN programs p ON c.program_id = p.program_id
        $whereClause
        ORDER BY 
            CASE WHEN s.payment_status = 'paid' THEN s.payment_date ELSE u.created_at END DESC
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate filename
    $filename = 'payment_export_' . date('Y-m-d_H-i-s');
    if (!empty($className)) {
        $filename = 'payment_export_' . preg_replace('/[^a-zA-Z0-9]/', '_', $className) . '_' . date('Y-m-d_H-i-s');
    }
    
    if ($format === 'excel') {
        // Clean output buffer to prevent corruption
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Create Excel file
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payment Export');
        
        // Set headers
        $headers = [
            'A1' => 'Username',
            'B1' => 'Full Name', 
            'C1' => 'Class',
            'D1' => 'Program',
            'E1' => 'Payment Status',
            'F1' => 'Payment Date',
            'G1' => 'Amount (GHS)',
            'H1' => 'Reference',
            'I1' => 'Registration Date'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);
        
        // Add data
        $row = 2;
        foreach ($payments as $payment) {
            $sheet->setCellValue('A' . $row, $payment['username']);
            $sheet->setCellValue('B' . $row, $payment['full_name']);
            $sheet->setCellValue('C' . $row, $payment['class_name']);
            $sheet->setCellValue('D' . $row, $payment['program_name']);
            $sheet->setCellValue('E' . $row, ucfirst($payment['payment_status']));
            $sheet->setCellValue('F' . $row, $payment['payment_date'] ? date('Y-m-d H:i:s', strtotime($payment['payment_date'])) : '');
            $sheet->setCellValue('G' . $row, $payment['payment_amount'] ?? '');
            $sheet->setCellValue('H' . $row, $payment['payment_reference'] ?? '');
            $sheet->setCellValue('I' . $row, date('Y-m-d H:i:s', strtotime($payment['registration_date'])));
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // Add alternating row colors
        for ($i = 2; $i <= $row - 1; $i++) {
            if ($i % 2 == 0) {
                $sheet->getStyle('A' . $i . ':I' . $i)->getFill()
                     ->setFillType(Fill::FILL_SOLID)
                     ->getStartColor()->setRGB('F2F2F2');
            }
        }
        
        // Output Excel file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Expires: 0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } elseif ($format === 'pdf') {
        // Clean output buffer to prevent corruption
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Create PDF
        require_once BASEPATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('EduAssess Pro');
        $pdf->SetAuthor('Admin');
        $pdf->SetTitle('Payment Export');
        $pdf->SetSubject('Payment Records');
        
        // Set margins
        $pdf->SetMargins(10, 15, 10);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        
        // Add page
        $pdf->AddPage();
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Payment Export Report', 0, 1, 'C');
        $pdf->Cell(0, 5, 'Generated on: ' . date('F j, Y g:i A'), 0, 1, 'C');
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(68, 114, 196);
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->Cell(25, 8, 'Username', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Full Name', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Class', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Program', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Payment Date', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Amount', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Reference', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Registration', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        
        $fill = false;
        foreach ($payments as $payment) {
            $pdf->SetFillColor(242, 242, 242);
            
            $pdf->Cell(25, 6, $payment['username'], 1, 0, 'L', $fill);
            $pdf->Cell(35, 6, substr($payment['full_name'], 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, substr($payment['class_name'], 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, substr($payment['program_name'], 0, 18), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, ucfirst($payment['payment_status']), 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, $payment['payment_date'] ? date('M j, Y', strtotime($payment['payment_date'])) : '', 1, 0, 'C', $fill);
            $pdf->Cell(20, 6, $payment['payment_amount'] ? '₵' . number_format($payment['payment_amount'], 2) : '', 1, 0, 'R', $fill);
            $pdf->Cell(35, 6, substr($payment['payment_reference'] ?? '', 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, date('M j, Y', strtotime($payment['registration_date'])), 1, 1, 'C', $fill);
            
            $fill = !$fill;
        }
        
        // Summary
        $paidCount = count(array_filter($payments, function($p) { return $p['payment_status'] === 'paid'; }));
        $totalRevenue = array_sum(array_column(array_filter($payments, function($p) { return $p['payment_status'] === 'paid'; }), 'payment_amount'));
        
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Summary:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, 'Total Records: ' . count($payments), 0, 1, 'L');
        $pdf->Cell(0, 6, 'Paid Students: ' . $paidCount, 0, 1, 'L');
        $pdf->Cell(0, 6, 'Total Revenue: ₵' . number_format($totalRevenue, 2), 0, 1, 'L');
        
        // Output PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        
        $pdf->Output($filename . '.pdf', 'D');
        exit;
    }
    
} catch (Exception $e) {
    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set plain text response for errors
    header('Content-Type: text/plain');
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Export error: ' . $e->getMessage();
    logError("Payment export error: " . $e->getMessage());
    exit;
}
?>