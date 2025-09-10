<?php
/**
 * Payment Verification Check
 * Include this file to check if student has paid required fees
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

// Include maintenance check functions for isAjaxRequest() and isApiRequest()
require_once __DIR__ . '/maintenance_check.php';

/**
 * Check if payment is required for students
 */
function isPaymentRequired() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'payment_required_for_students' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && (bool)$result['setting_value'];
    } catch (Exception $e) {
        error_log("Payment requirement check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if student has paid required fees
 */
function hasStudentPaid($studentId = null) {
    // If no student ID provided, get from session
    if ($studentId === null) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                return false;
            }
            
            $studentId = $student['student_id'];
        } catch (Exception $e) {
            error_log("Student lookup failed: " . $e->getMessage());
            return false;
        }
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT payment_status FROM students WHERE student_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && $result['payment_status'] === 'paid';
    } catch (Exception $e) {
        error_log("Payment status check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get payment amount from settings
 */
function getPaymentAmount() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'payment_amount' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['setting_value'] : 2000; // Default 2000 kobo (NGN 20)
    } catch (Exception $e) {
        error_log("Payment amount check failed: " . $e->getMessage());
        return 2000;
    }
}

/**
 * Get Paystack public key
 */
function getPaystackPublicKey() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_public_key' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['setting_value'] : '';
    } catch (Exception $e) {
        error_log("Paystack key check failed: " . $e->getMessage());
        return '';
    }
}

/**
 * Enforce payment requirement for students
 */
function enforcePaymentRequirement() {
    // Only apply to students
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
        return true; // Allow non-student access
    }
    
    // Skip if payment is not required
    if (!isPaymentRequired()) {
        return true; // Payment not required
    }
    
    // Skip for payment-related pages and authentication pages
    $allowedScripts = ['payment.php', 'verify_payment.php', 'verify_payment_callback.php', 'payment_callback.php', 'initiate_payment.php', 'logout.php', 'change-password.php'];
    $currentScript = basename($_SERVER['PHP_SELF']);
    
    if (in_array($currentScript, $allowedScripts)) {
        return true; // Allow access to payment and authentication pages
    }
    
    // Allow access if user requires password change (first login or forced change)
    if ((isset($_SESSION['first_login']) && $_SESSION['first_login']) || 
        (isset($_SESSION['password_change_required']) && $_SESSION['password_change_required'])) {
        return true; // Allow access when password change is required
    }
    
    // Check if student has paid
    if (!hasStudentPaid()) {
        // For API requests, return JSON error
        if (isAjaxRequest() || isApiRequest()) {
            http_response_code(402);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Payment required to access this feature.',
                'payment_required' => true,
                'payment_url' => '/payment.php',
                'code' => 402
            ]);
            exit;
        }
        
        // For regular page requests, redirect to payment page
        if (!headers_sent()) {
            header('Location: payment.php');
            exit;
        }
        
        // If headers already sent, use JavaScript redirect
        echo "<script>window.location.href = 'payment.php';</script>";
        exit;
    }
    
    return true; // Allow access if paid
}

// Note: isAjaxRequest() and isApiRequest() functions are loaded from maintenance_check.php
?>