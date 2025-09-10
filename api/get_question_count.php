<?php
// api/get_question_count.php - Completely fixed version
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clean any existing output
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh output buffering
ob_start();

// Set JSON headers early
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    // Define BASEPATH if not already defined
    if (!defined('BASEPATH')) {
        define('BASEPATH', dirname(__DIR__));
    }
    
    // Include required files
    require_once BASEPATH . '/config/config.php';
    require_once INCLUDES_PATH . '/functions.php';
    require_once INCLUDES_PATH . '/auth.php';
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check authentication - only allow logged in teachers
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
        throw new Exception('Authentication required - teacher access only');
    }
    
    // Validate input parameters
    $levelId = filter_input(INPUT_GET, 'level_id', FILTER_VALIDATE_INT);
    $subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
    
    if (!$levelId || $levelId <= 0) {
        throw new Exception('Valid level_id parameter is required');
    }
    
    if (!$subjectId || $subjectId <= 0) {
        throw new Exception('Valid subject_id parameter is required');
    }
    
    // Get database connection
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Verify teacher has access to this level/subject combination
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherInfo['teacher_id'];
    
    // Check if teacher has classes in this level/subject
    $stmt = $db->prepare(
        "SELECT COUNT(*) as access_count
         FROM TeacherClassAssignments tca
         JOIN Classes c ON tca.class_id = c.class_id
         WHERE tca.teacher_id = ? AND c.level_id = ? AND tca.subject_id = ?"
    );
    $stmt->execute([$teacherId, $levelId, $subjectId]);
    $accessCheck = $stmt->fetch();
    
    if (!$accessCheck || $accessCheck['access_count'] == 0) {
        throw new Exception('Not authorized for this level/subject combination');
    }
    
    // Query for question count in the question bank
    $stmt = $db->prepare(
        "SELECT COUNT(*) as total_count 
         FROM questionbank 
         WHERE level_id = ? AND subject_id = ? AND is_active = 1"
    );
    $stmt->execute([$levelId, $subjectId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $count = $result ? intval($result['total_count']) : 0;
    
    // Also get some additional metadata that might be useful
    $stmt = $db->prepare(
        "SELECT 
            l.level_name,
            s.subject_name
         FROM levels l, subjects s 
         WHERE l.level_id = ? AND s.subject_id = ?"
    );
    $stmt->execute([$levelId, $subjectId]);
    $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Clear any buffered output
    ob_clean();
    
    // Prepare successful response
    $response = [
        'success' => true,
        'count' => $count,
        'level_id' => $levelId,
        'subject_id' => $subjectId,
        'level_name' => $metadata['level_name'] ?? null,
        'subject_name' => $metadata['subject_name'] ?? null,
        'teacher_id' => $teacherId,
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'ok'
    ];
    
    // Send response
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Clear any buffered output
    ob_clean();
    
    // Log the error for debugging
    error_log("Question count API error: " . $e->getMessage() . " | Level: " . ($levelId ?? 'null') . " | Subject: " . ($subjectId ?? 'null'));
    
    // Set appropriate HTTP status code
    http_response_code(400);
    
    // Prepare error response
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'level_id' => $levelId ?? null,
        'subject_id' => $subjectId ?? null,
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'error'
    ];
    
    // Send error response
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    // Catch any other errors (PHP 7+ syntax)
    ob_clean();
    
    error_log("Fatal error in question count API: " . $e->getMessage());
    
    http_response_code(500);
    
    $response = [
        'success' => false,
        'error' => 'Internal server error occurred',
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'fatal_error'
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// Ensure output is flushed and script terminates
ob_end_flush();
exit;
?>