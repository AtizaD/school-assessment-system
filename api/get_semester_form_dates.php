<?php
// api/get_semester_form_dates.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

header('Content-Type: application/json');

if (!isset($_GET['semester_id']) || !is_numeric($_GET['semester_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid semester ID']);
    exit;
}

$semesterId = (int)$_GET['semester_id'];

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    $stmt = $db->prepare(
        "SELECT sf.*
         FROM semester_forms sf
         WHERE sf.semester_id = ?
         ORDER BY sf.form_level"
    );
    
    $stmt->execute([$semesterId]);
    $formDates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($formDates)) {
        echo json_encode(['error' => 'No form dates found for this semester']);
    } else {
        echo json_encode($formDates);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    logError("Failed to fetch semester form dates: " . $e->getMessage());
}
?>