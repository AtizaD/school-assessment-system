<?php
/**
 * Confirm Account API
 * Sets confirmation flag for forgot password account verification
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
    
    if ($action !== 'confirm_account') {
        throw new Exception('Invalid action');
    }
    
    // Check if user has reset session data
    if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_username'])) {
        throw new Exception('No pending account verification');
    }
    
    // Set account confirmation flag
    $_SESSION['account_confirmed'] = true;
    $_SESSION['confirmed_at'] = time();
    
    logSystemActivity('AccountConfirmation', 
        'User confirmed account for password reset: ' . $_SESSION['reset_username'], 
        'INFO', $_SESSION['reset_user_id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Account confirmed successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>