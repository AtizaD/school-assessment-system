<?php
/**
 * PDF Export for Performance Dashboard
 * Uses TCPDF library
 */

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('School Assessment System');
$pdf->SetAuthor('School Administration');
$pdf->SetTitle('Academic Performance Report');
$pdf->SetSubject('Performance Analytics');

// Define colors
$pdf->setHeaderData('', 0, 'Academic Performance Report', $filter_text, [0, 0, 0], [255, 215, 0]);

// Set header and footer fonts
$pdf->setHeaderFont(['helvetica', 'B', 11]);
$pdf->setFooterFont(['helvetica', '', 8]);

// Set margins
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 10);

// Define custom CSS styles for tables
$css = '<style>
    .header-row { background-color: #000000; color: #FFD700; font-weight: bold; }
    .metric-label { background-color: #f8f9fa; font-weight: bold; }
    .value-cell { background-color: #ffffff; }
    .rank-gold { background-color: #FFD700; font-weight: bold; }
    .rank-silver { background-color: #C0C0C0; font-weight: bold; }
    .rank-bronze { background-color: #CD7F32; font-weight: bold; }
    .section-title { color: #000000; background-color: #FFD700; padding: 8px; font-weight: bold; }
</style>';

// Build WHERE clauses (same as dashboard)
$where = ["a.semester_id = ?"];
$params = [$semester_id];
$joins = "JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id";

if ($level) {
    $joins .= " JOIN classes cls ON ac.class_id = cls.class_id";
    $where[] = "cls.level = ?";
    $params[] = $level;
}
if ($class_id) {
    $where[] = "ac.class_id = ?";
    $params[] = $class_id;
}
if ($subject_id) {
    $where[] = "ac.subject_id = ?";
    $params[] = $subject_id;
}
if ($assessment_type_id) {
    $where[] = "a.assessment_type_id = ?";
    $params[] = $assessment_type_id;
}
$whereClause = implode(' AND ', $where);

// Section 1: Overview
$stmt = $db->prepare("
    SELECT
        COUNT(DISTINCT a.assessment_id) as total_assessments,
        COUNT(DISTINCT r.result_id) as total_submissions,
        COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.result_id END) as completed_submissions,
        ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score,
        MIN(CASE WHEN r.status = 'completed' THEN r.score END) as min_score,
        MAX(CASE WHEN r.status = 'completed' THEN r.score END) as max_score,
        COUNT(DISTINCT CASE WHEN r.status = 'completed' AND r.score >= 10 THEN r.result_id END) as passed,
        COUNT(DISTINCT CASE WHEN r.status = 'completed' AND r.score < 10 THEN r.result_id END) as failed
    FROM assessments a
    $joins
    LEFT JOIN results r ON a.assessment_id = r.assessment_id AND r.status = 'completed'
    WHERE $whereClause
");
$stmt->execute($params);
$overview = $stmt->fetch();
$pass_rate = $overview['completed_submissions'] > 0 ?
    round(($overview['passed'] / $overview['completed_submissions']) * 100, 1) : 0;

// Section 1 Title with colored background
$pdf->SetFillColor(255, 215, 0); // Gold
$pdf->SetTextColor(0, 0, 0); // Black text
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '1. Semester Performance Overview', 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->Ln(2);

$html = '<table border="1" cellpadding="4" cellspacing="0" style="width:100%;">
    <tr style="background-color:#000000; color:#FFD700;">
        <th width="45%"><b>Metric</b></th>
        <th width="55%"><b>Value</b></th>
    </tr>
    <tr>
        <td>Total Assessments</td>
        <td>' . $overview['total_assessments'] . '</td>
    </tr>
    <tr>
        <td>Completed Submissions</td>
        <td>' . $overview['completed_submissions'] . '</td>
    </tr>
    <tr>
        <td>Average Score</td>
        <td>' . ($overview['avg_score'] ?? 'N/A') . '%</td>
    </tr>
    <tr>
        <td>Minimum Score</td>
        <td>' . ($overview['min_score'] ?? 'N/A') . '%</td>
    </tr>
    <tr>
        <td>Maximum Score</td>
        <td>' . ($overview['max_score'] ?? 'N/A') . '%</td>
    </tr>
    <tr>
        <td>Pass Rate</td>
        <td>' . $pass_rate . '%</td>
    </tr>
    <tr>
        <td>Passed</td>
        <td>' . $overview['passed'] . '</td>
    </tr>
    <tr>
        <td>Failed</td>
        <td>' . $overview['failed'] . '</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);

// Section 2: Top Performing Students
$topWhere = ["r.status = 'completed'", "a.semester_id = ?"];
$topParams = [$semester_id];

if ($subject_id) {
    $topWhere[] = "EXISTS (SELECT 1 FROM assessmentclasses ac WHERE ac.assessment_id = a.assessment_id AND ac.subject_id = ?)";
    $topParams[] = $subject_id;
}
if ($class_id) {
    $topWhere[] = "s.class_id = ?";
    $topParams[] = $class_id;
}
if ($level) {
    $topWhere[] = "c.level = ?";
    $topParams[] = $level;
}
if ($assessment_type_id) {
    $topWhere[] = "a.assessment_type_id = ?";
    $topParams[] = $assessment_type_id;
}

// Lower threshold when filtering by subject (fewer assessments per subject)
$minCompleted = $subject_id ? 1 : 3;

$stmt = $db->prepare("
    SELECT
        s.student_id,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        c.class_name,
        p.program_name,
        COUNT(r.result_id) as total_completed,
        ROUND(AVG(r.score), 2) as avg_score
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    JOIN classes c ON s.class_id = c.class_id
    JOIN programs p ON c.program_id = p.program_id
    JOIN assessments a ON r.assessment_id = a.assessment_id
    WHERE " . implode(' AND ', $topWhere) . "
    GROUP BY s.student_id, s.first_name, s.last_name, c.class_name, p.program_name
    HAVING total_completed >= $minCompleted AND avg_score >= 10
    ORDER BY avg_score DESC, total_completed DESC
    LIMIT 20
");
$stmt->execute($topParams);
$topStudents = $stmt->fetchAll();

// Section 2 Title with colored background
$pdf->SetFillColor(255, 215, 0); // Gold
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '2. Top Performing Students', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->Ln(2);

$html = '<table border="1" cellpadding="3" cellspacing="0">
    <tr style="background-color:#000000; color:#FFD700;">
        <th width="8%"><b>Rank</b></th>
        <th width="35%"><b>Student Name</b></th>
        <th width="22%"><b>Class</b></th>
        <th width="15%"><b>Completed</b></th>
        <th width="20%"><b>Avg Score</b></th>
    </tr>';

foreach ($topStudents as $idx => $student) {
    // Color code top 3
    $bgColor = '';
    if ($idx == 0) $bgColor = ' style="background-color:#FFD700;"'; // Gold
    elseif ($idx == 1) $bgColor = ' style="background-color:#C0C0C0;"'; // Silver
    elseif ($idx == 2) $bgColor = ' style="background-color:#CD7F32; color:#FFF;"'; // Bronze

    $html .= '<tr' . $bgColor . '>
        <td align="center"><b>' . ($idx + 1) . '</b></td>
        <td>' . htmlspecialchars($student['student_name']) . '</td>
        <td>' . htmlspecialchars($student['class_name']) . '</td>
        <td align="center">' . $student['total_completed'] . '</td>
        <td align="center"><b>' . $student['avg_score'] . '%</b></td>
    </tr>';
}

$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);

// Section 3: Class Performance
$whereClass = ["a.semester_id = ?"];
$paramsClass = [$semester_id];
if ($subject_id) {
    $whereClass[] = "ac.subject_id = ?";
    $paramsClass[] = $subject_id;
}
if ($level) {
    $whereClass[] = "c.level = ?";
    $paramsClass[] = $level;
}
if ($assessment_type_id) {
    $whereClass[] = "a.assessment_type_id = ?";
    $paramsClass[] = $assessment_type_id;
}
$whereClassClause = implode(' AND ', $whereClass);

$stmt = $db->prepare("
    SELECT
        c.class_id,
        p.program_name,
        c.class_name,
        COUNT(DISTINCT s.student_id) as total_students,
        COUNT(r.result_id) as total_submissions,
        COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as completed_submissions,
        ROUND(AVG(CASE WHEN r.status = 'completed' THEN r.score END), 2) as avg_score
    FROM classes c
    JOIN programs p ON c.program_id = p.program_id
    LEFT JOIN students s ON c.class_id = s.class_id
    LEFT JOIN assessmentclasses ac ON c.class_id = ac.class_id AND ac.class_id = c.class_id
    LEFT JOIN assessments a ON ac.assessment_id = a.assessment_id
    LEFT JOIN results r ON s.student_id = r.student_id AND r.assessment_id = a.assessment_id AND r.status = 'completed'
    WHERE $whereClassClause
    GROUP BY c.class_id, p.program_name, c.class_name
    HAVING completed_submissions >= 50
    ORDER BY avg_score DESC
    LIMIT 15
");
$stmt->execute($paramsClass);
$classPerformance = $stmt->fetchAll();

$pdf->AddPage();

// Section 3 Title with colored background
$pdf->SetFillColor(255, 215, 0); // Gold
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, '3. Class Performance Comparison', 0, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->Ln(2);

$html = '<table border="1" cellpadding="3" cellspacing="0">
    <tr style="background-color:#000000; color:#FFD700;">
        <th width="10%"><b>Rank</b></th>
        <th width="30%"><b>Program</b></th>
        <th width="20%"><b>Class</b></th>
        <th width="20%"><b>Submissions</b></th>
        <th width="20%"><b>Avg Score</b></th>
    </tr>';

foreach ($classPerformance as $idx => $class) {
    $html .= '<tr>
        <td align="center">' . ($idx + 1) . '</td>
        <td>' . htmlspecialchars($class['program_name']) . '</td>
        <td>' . htmlspecialchars($class['class_name']) . '</td>
        <td align="center">' . $class['completed_submissions'] . '</td>
        <td align="center"><b>' . $class['avg_score'] . '%</b></td>
    </tr>';
}

$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');

// Output PDF
$filename = 'Performance_Report_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'D'); // D = download
