<?php
// initiate_payment.php - Initialize payment with Paystack
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(__FILE__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/payment_check.php';

// Only students can access this
requireRole('student');

// Get student information
try {
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT s.*, u.email, u.username FROM students s 
                         JOIN users u ON s.user_id = u.user_id 
                         WHERE s.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student record not found');
    }
    
    // Use username-based email for payments (sanitized for valid email format)
    $localPart = preg_replace('/[^A-Za-z0-9._]/', '', $student['username']);
    $student['email'] = $localPart . '@students.vc.bassflix.xyz';
    
    // Get payment settings
    $paymentAmount = getPaymentAmount();
    
    // Get Paystack secret key and webhook URL
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM SystemSettings WHERE setting_key IN ('paystack_secret_key', 'paystack_webhook_url')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $secretKey = $settings['paystack_secret_key'] ?? '';
    $webhookUrl = $settings['paystack_webhook_url'] ?? '';
    
    if (empty($secretKey)) {
        throw new Exception('Payment system not properly configured');
    }
    
    // Generate unique reference
    $reference = 'pay_' . time() . '_' . $student['student_id'];
    
    // Initialize transaction with Paystack
    $curl = curl_init();
    
    $postData = [
        'amount' => $paymentAmount, // Amount in pesewas
        'email' => $student['email'],
        'currency' => 'GHS',
        'reference' => $reference,
        'callback_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/verify_payment.php?reference=' . $reference,
        'metadata' => [
            'student_id' => $student['student_id'],
            'user_id' => $_SESSION['user_id'],
            'student_name' => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
            'purpose' => 'Assessment System Access Fee'
        ]
    ];
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $secretKey,
            "Cache-Control: no-cache",
            "Content-Type: application/json",
        ),
    ));
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode !== 200) {
        throw new Exception('Payment initialization failed. Please try again.');
    }
    
    $paymentData = json_decode($response, true);
    
    if (!$paymentData || !$paymentData['status']) {
        throw new Exception('Payment initialization failed: ' . ($paymentData['message'] ?? 'Unknown error'));
    }
    
    // Get the authorization URL
    $authorizationUrl = $paymentData['data']['authorization_url'];
    
    // Log payment initialization
    logSystemActivity(
        'Payment',
        "Payment initialized for student ID: {$student['student_id']}, Reference: $reference",
        'INFO',
        $_SESSION['user_id']
    );
    
    // Redirect to Paystack checkout
    header('Location: ' . $authorizationUrl);
    exit;
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Payment initialization error: " . $error);
    
    // Redirect back to payment page with error
    header('Location: payment.php?error=' . urlencode($error));
    exit;
}
?>