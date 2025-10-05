<?php
/**
 * Export Archived Students to CSV
 * Exports ALL archived students matching current filters
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();

// Get filter parameters from POST or GET
$searchTerm = $_POST['search'] ?? $_GET['search'] ?? '';
$yearFilter = $_POST['year'] ?? $_GET['year'] ?? '';

// Build query with filters (NO PAGINATION - get all results)
$sql = "
SELECT
    archive_id,
    username,
    first_name,
    last_name,
    class_name,
    graduation_year,
    archived_date
FROM archived_students
WHERE 1=1
";

$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (username LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
    $params[':search'] = '%' . $searchTerm . '%';
}

if (!empty($yearFilter)) {
    $sql .= " AND graduation_year = :year";
    $params[':year'] = $yearFilter;
}

$sql .= " ORDER BY archived_date DESC, username ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$archivedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate filename with date and filters
$filename = 'archived_students_' . date('Y-m-d');
if (!empty($yearFilter)) {
    $filename .= '_year' . $yearFilter;
}
if (!empty($searchTerm)) {
    $filename .= '_filtered';
}
$filename .= '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, [
    '#',
    'Username',
    'First Name',
    'Last Name',
    'Class',
    'Graduation Year',
    'Archived Date'
]);

// Write data rows
foreach ($archivedStudents as $index => $student) {
    fputcsv($output, [
        $index + 1,
        $student['username'],
        $student['first_name'],
        $student['last_name'],
        $student['class_name'] ?? 'N/A',
        $student['graduation_year'],
        date('M j, Y h:i A', strtotime($student['archived_date']))
    ]);
}

fclose($output);
exit;
?>
