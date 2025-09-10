<?php
/**
 * API Payment Verification Check
 * Include this file in student API endpoints to check for payment
 */

if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(__FILE__));
}

require_once BASEPATH . '/config/config.php';

require_once INCLUDES_PATH . '/payment_check.php';

/**
 * Secure API payment enforcement
 * This prevents unpaid students from accessing student-specific APIs
 */
function enforceApiPaymentRequirement() {
    // Only apply to students
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
        return; // Allow non-student access
    }
    
    // Skip if payment is not required
    if (!isPaymentRequired()) {
        return; // Payment not required
    }
    
    // Allow access to payment verification API
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript === 'verify_payment.php') {
        return; // Allow payment verification
    }
    
    // Check if student has paid
    if (!hasStudentPaid()) {
        // Log unauthorized API access attempt
        $userInfo = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'Unknown';
        
        error_log("API Payment Required: Student '$userInfo' from IP '$ip' attempted $method '$uri' without payment");
        
        // Return secure JSON error response
        http_response_code(402); // Payment Required
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $response = [
            'success' => false,
            'error' => 'Payment required to access this feature.',
            'payment_required' => true,
            'payment_url' => BASE_URL . '/payment.php',
            'code' => 402,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
}

// Auto-enforce payment requirement for all student API calls
enforceApiPaymentRequirement();
?>