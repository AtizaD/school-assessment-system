<?php
// api/get_class_students.php - Get all students in a specific class
header('Content-Type: application/json');

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure user is authenticated and has admin role
requireRole('admin');

$response = ['success' => false, 'message' => '', 'students' => []];

try {
    if (!isset($_GET['class_id']) || empty($_GET['class_id'])) {
        throw new Exception('Class ID is required');
    }

    $classId = (int)$_GET['class_id'];
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get all students in the specified class
    $stmt = $db->prepare("
        SELECT 
            s.student_id,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.student_id as student_id_number
        FROM students s 
        WHERE s.class_id = ?
        ORDER BY s.first_name, s.last_name
    ");
    
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll();
    
    $response['success'] = true;
    $response['students'] = $students;
    $response['message'] = count($students) . ' students found';
    
} catch (Exception $e) {
    logError("Get class students API error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>