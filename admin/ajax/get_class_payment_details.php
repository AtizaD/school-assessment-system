<?php
// admin/ajax/get_class_payment_details.php - Get detailed payment info for a specific class
header('Content-Type: application/json');

if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(dirname(__FILE__))));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
try {
    requireRole('admin');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $className = $input['class_name'] ?? '';
    
    if (empty($className)) {
        throw new Exception('Class name is required');
    }
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get class statistics
    $stmt = $db->prepare("
        SELECT 
            c.class_name,
            COALESCE(CONCAT(t.first_name, ' ', t.last_name), 'N/A') as teacher_name,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT CASE WHEN s.payment_status = 'paid' THEN s.student_id END) as paid_students,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.02 END), 0) as paystack_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.15 END), 0) as hosting_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.17 END), 0) as total_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.83 END), 0) as net_revenue
        FROM classes c
        LEFT JOIN students s ON c.class_id = s.class_id
        LEFT JOIN teacherclassassignments tca ON c.class_id = tca.class_id AND tca.is_primary_instructor = 1
        LEFT JOIN teachers t ON tca.teacher_id = t.teacher_id
        WHERE c.class_name = ?
        GROUP BY c.class_id, c.class_name, t.first_name, t.last_name
    ");
    $stmt->execute([$className]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        throw new Exception('Class not found');
    }
    
    // Get list of paid students
    $stmt = $db->prepare("
        SELECT 
            u.username,
            CONCAT(s.first_name, ' ', s.last_name) as full_name,
            s.payment_date,
            s.payment_amount,
            s.payment_reference
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        JOIN classes c ON s.class_id = c.class_id
        WHERE c.class_name = ? AND s.payment_status = 'paid'
        ORDER BY s.payment_date DESC
    ");
    $stmt->execute([$className]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'students' => $students
    ]);
    
} catch (Exception $e) {
    logError("Class payment details error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>