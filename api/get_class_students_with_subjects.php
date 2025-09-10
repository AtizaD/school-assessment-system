<?php
/**
 * Get Class Students with Special Subject Information
 * Returns all students in a class with their current special subject enrollments
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require admin role
try {
    requireRole('admin');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$class_id = sanitizeInput($_GET['class_id'] ?? '');

if (empty($class_id)) {
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit;
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Validate that class exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_id = ?");
    $stmt->execute([$class_id]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid class ID']);
        exit;
    }
    
    // Get all students in the class with their special subject enrollments
    $stmt = $db->prepare("
        SELECT 
            s.student_id,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            GROUP_CONCAT(
                CONCAT(sub.subject_name, ' (', sc.status, ')') 
                ORDER BY sub.subject_name 
                SEPARATOR ', '
            ) as current_subjects,
            COUNT(sc.sp_id) as special_subject_count
        FROM students s 
        LEFT JOIN special_class sc ON s.student_id = sc.student_id 
            AND sc.class_id = ? AND sc.status = 'active'
        LEFT JOIN subjects sub ON sc.subject_id = sub.subject_id
        WHERE s.class_id = ?
        GROUP BY s.student_id, s.first_name, s.last_name
        ORDER BY s.first_name, s.last_name
    ");
    $stmt->execute([$class_id, $class_id]);
    $students = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);
    
} catch (PDOException $e) {
    logError("Get Class Students with Subjects API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    logError("Get Class Students with Subjects API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
}
?>