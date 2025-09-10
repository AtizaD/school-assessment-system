<?php
// verify_payment_callback.php - Handles return from Paystack checkout
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(__FILE__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/payment_check.php';

// Get the reference first to handle session restoration
$reference = $_GET['reference'] ?? $_POST['reference'] ?? '';

if (empty($reference)) {
    // No reference provided, redirect to payment page or login
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
        header('Location: /login.php?error=no_payment_reference');
    } else {
        header('Location: /payment.php?error=no_reference');
    }
    exit;
}

// If user session is lost but we have a payment reference, try to verify and restore session
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    // Try to verify payment and restore user session
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Get Paystack secret key for verification
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $secretKey = $result ? $result['setting_value'] : '';
        
        if (!empty($secretKey)) {
            // Verify payment with Paystack
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer " . $secretKey,
                    "Cache-Control: no-cache",
                ),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode === 200) {
                $paymentData = json_decode($response, true);
                
                if ($paymentData && $paymentData['status'] && $paymentData['data']['status'] === 'success') {
                    // Get student info from metadata or reference pattern
                    $studentId = $paymentData['data']['metadata']['student_id'] ?? null;
                    $userId = $paymentData['data']['metadata']['user_id'] ?? null;
                    
                    if (!$studentId && preg_match('/pay_\d+_(\d+)/', $reference, $matches)) {
                        $studentId = $matches[1];
                    }
                    
                    if ($studentId) {
                        // Get user info and restore session
                        $stmt = $db->prepare("
                            SELECT u.user_id, u.username, u.role, s.student_id 
                            FROM users u 
                            JOIN students s ON u.user_id = s.user_id 
                            WHERE s.student_id = ? AND u.role = 'student'
                        ");
                        $stmt->execute([$studentId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            // Restore user session
                            $_SESSION['user_id'] = $user['user_id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['student_id'] = $user['student_id'];
                            
                            // Log session restoration
                            logSystemActivity(
                                'Authentication',
                                "Session restored after payment for user: {$user['username']}",
                                'INFO',
                                $user['user_id']
                            );
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        logError("Session restoration error: " . $e->getMessage());
    }
    
    // If session restoration failed, redirect to login with payment reference
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
        header('Location: /login.php?payment_reference=' . urlencode($reference) . '&message=login_to_complete_payment');
        exit;
    }
}

// Redirect to our existing verification page
header('Location: /verify_payment.php?reference=' . urlencode($reference));
exit;
?>