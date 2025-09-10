<?php
// api/get_students.php
ob_start(); // Start output buffering at the very beginning

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only allow authenticated users to access this API
requireRole(['admin', 'teacher']);

// Clean any output buffers before sending JSON headers
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

try {
    // Validate class_id parameter
    if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
        throw new Exception('Invalid class ID');
    }
    
    $classId = (int)$_GET['class_id'];
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get all students for the specified class
    $stmt = $db->prepare(
        "SELECT student_id, first_name, last_name 
         FROM students 
         WHERE class_id = ? 
         ORDER BY last_name, first_name"
    );
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll();
    
    echo json_encode($students);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    logError("API error (get_students): " . $e->getMessage());
}
?>