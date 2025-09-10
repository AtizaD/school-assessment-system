<?php
// webhook/paystack.php - Paystack webhook endpoint for payment notifications
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(dirname(__FILE__)));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Log webhook attempt
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
];

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Get the raw POST data
    $input = file_get_contents('php://input');
    
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data received']);
        exit;
    }

    // Get webhook secret for signature verification
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_webhook_secret' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $webhookSecret = $result ? $result['setting_value'] : '';

    // Verify webhook signature if secret is configured
    if (!empty($webhookSecret)) {
        $paystackSignature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        
        if (empty($paystackSignature)) {
            logError("Webhook signature missing");
            http_response_code(400);
            echo json_encode(['error' => 'Signature missing']);
            exit;
        }

        $computedSignature = hash_hmac('sha512', $input, $webhookSecret);
        
        if (!hash_equals($paystackSignature, $computedSignature)) {
            logError("Webhook signature verification failed");
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }

    // Parse the webhook data
    $event = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("Invalid JSON in webhook: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Log the webhook event
    logSystemActivity(
        'Webhook',
        "Paystack webhook received: " . ($event['event'] ?? 'unknown'),
        'INFO'
    );

    // Handle different event types
    $eventType = $event['event'] ?? '';
    $eventData = $event['data'] ?? [];

    switch ($eventType) {
        case 'charge.success':
            handleSuccessfulPayment($eventData);
            break;
            
        case 'charge.failed':
            handleFailedPayment($eventData);
            break;
            
        case 'charge.pending':
            handlePendingPayment($eventData);
            break;
            
        default:
            // Log unhandled event types but still return success
            logSystemActivity(
                'Webhook',
                "Unhandled webhook event type: $eventType",
                'INFO'
            );
            break;
    }

    // Return success response
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Webhook processing error: " . $error);
    
    // Log the error with context
    logSystemActivity(
        'Webhook',
        "Webhook processing failed: $error",
        'ERROR'
    );

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Handle successful payment notification
 */
function handleSuccessfulPayment($data) {
    try {
        $reference = $data['reference'] ?? '';
        $amount = ($data['amount'] ?? 0) / 100; // Convert pesewas to cedis
        $studentId = $data['metadata']['student_id'] ?? null;
        $userId = $data['metadata']['user_id'] ?? null;
        
        if (empty($reference)) {
            throw new Exception('Payment reference missing from webhook data');
        }

        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Find student by reference or metadata
        if ($studentId) {
            $stmt = $db->prepare("SELECT s.*, u.username FROM students s 
                                 JOIN users u ON s.user_id = u.user_id 
                                 WHERE s.student_id = ?");
            $stmt->execute([$studentId]);
        } else {
            // Fallback: try to find by payment reference pattern
            $stmt = $db->prepare("SELECT s.*, u.username FROM students s 
                                 JOIN users u ON s.user_id = u.user_id 
                                 WHERE ? LIKE CONCAT('%_', s.student_id)");
            $stmt->execute([$reference]);
        }
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception("Student not found for payment reference: $reference");
        }

        // Check if payment is already processed
        $stmt = $db->prepare("SELECT payment_status FROM students WHERE payment_reference = ? AND payment_status = 'paid'");
        $stmt->execute([$reference]);
        $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingPayment) {
            logSystemActivity(
                'Payment',
                "Duplicate webhook notification for already processed payment: $reference",
                'INFO',
                $student['user_id']
            );
            return;
        }

        // Update payment status
        $db->beginTransaction();
        
        $stmt = $db->prepare("UPDATE students SET 
            payment_status = 'paid',
            payment_date = NOW(),
            payment_reference = ?,
            payment_amount = ?
            WHERE student_id = ?");
        
        $stmt->execute([$reference, $amount, $student['student_id']]);
        
        $db->commit();

        // Log successful payment
        logSystemActivity(
            'Payment',
            "Webhook payment successful for student {$student['username']} (ID: {$student['student_id']}), Reference: $reference, Amount: GHS $amount",
            'INFO',
            $student['user_id']
        );

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Handle failed payment notification
 */
function handleFailedPayment($data) {
    $reference = $data['reference'] ?? '';
    $reason = $data['gateway_response'] ?? 'Unknown reason';
    
    logSystemActivity(
        'Payment',
        "Webhook payment failed for reference: $reference, Reason: $reason",
        'WARNING'
    );
}

/**
 * Handle pending payment notification
 */
function handlePendingPayment($data) {
    $reference = $data['reference'] ?? '';
    
    logSystemActivity(
        'Payment',
        "Webhook payment pending for reference: $reference",
        'INFO'
    );
}
?>