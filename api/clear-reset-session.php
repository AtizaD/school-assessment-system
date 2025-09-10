<?php
/**
 * Clear Reset Session API
 * Clears forgot password session data to allow username re-entry
 * 
 * @author School Management System
 * @date July 25, 2025
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';

header('Content-Type: application/json');

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed');
    }
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action !== 'clear_reset_data') {
        throw new Exception('Invalid action');
    }
    
    // Clear forgot password session data
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_username']);
    unset($_SESSION['reset_user_role']);
    unset($_SESSION['reset_user_fullname']);
    unset($_SESSION['account_confirmed']);
    unset($_SESSION['confirmed_at']);
    unset($_SESSION['show_verification_modal']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Session data cleared successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>