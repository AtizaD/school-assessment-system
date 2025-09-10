<?php
/**
 * Get Available Students API
 * Returns students available for special enrollment based on class and subject
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
$subject_id = sanitizeInput($_GET['subject_id'] ?? '');
$exclude_sp_id = sanitizeInput($_GET['exclude_sp_id'] ?? ''); // For edit mode

// Validate required parameters
if (empty($class_id) || empty($subject_id)) {
    echo json_encode(['success' => false, 'message' => 'Class ID and Subject ID are required']);
    exit;
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Build query to get students from the selected class who are NOT already enrolled 
    // in this specific class-subject combination
    $query = "
        SELECT 
            s.student_id, 
            CONCAT(s.first_name, ' ', s.last_name, ' (ID: ', s.student_id, ')') as student_display
        FROM students s
        WHERE s.class_id = ?
        AND s.student_id NOT IN (
            SELECT sc.student_id 
            FROM special_class sc 
            WHERE sc.class_id = ? 
            AND sc.subject_id = ? 
            AND sc.status = 'active'";
    
    $params = [$class_id, $class_id, $subject_id];
    
    // If we're editing an existing enrollment, exclude the current one from the check
    if (!empty($exclude_sp_id)) {
        $query .= " AND sc.sp_id != ?";
        $params[] = $exclude_sp_id;
    }
    
    $query .= "
        )
        ORDER BY s.first_name, s.last_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true, 
        'students' => $students
    ]);
    
} catch (PDOException $e) {
    logError("Get Available Students API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    logError("Get Available Students API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred'
    ]);
}
?>