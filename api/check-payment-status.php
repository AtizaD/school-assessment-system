<?php
/**
 * Improved Payment Status Checker
 * Better handling of various ExpressPay statuses and cleanup
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once BASEPATH . '/includes/functions.php';
require_once BASEPATH . '/includes/PaymentService.php';
require_once BASEPATH . '/includes/ExpressPayGateway.php';

header('Content-Type: application/json');

try {
    // Check authentication - must be for forgot password flow
    if (!isset($_SESSION['reset_user_id'])) {
        throw new Exception('No active password reset session');
    }
    
    $userId = $_SESSION['reset_user_id'];
    $serviceType = 'password_reset';
    
    // First check if user already has access (payment completed)
    if (PaymentService::canUserAccessService($userId, $serviceType)) {
        echo json_encode([
            'success' => true,
            'payment_completed' => true,
            'message' => 'Payment verified! You can now reset your password.',
            'redirect_url' => 'forgot-password.php?payment_complete=1'
        ]);
        return;
    }
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Clean up old pending transactions first (older than 30 minutes)
    $cleanupStmt = $db->prepare(
        "UPDATE PaymentTransactions 
         SET status = 'cancelled' 
         WHERE user_id = ? AND service_type = ? AND status = 'pending'
         AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    );
    $cleanupStmt->execute([$userId, $serviceType]);
    
    // Check for recent pending transactions (within last 30 minutes)
    $stmt = $db->prepare(
        "SELECT reference_id, created_at FROM PaymentTransactions 
         WHERE user_id = ? AND service_type = ? AND status = 'pending'
         AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$userId, $serviceType]);
    $pendingTransaction = $stmt->fetch();
    
    if (!$pendingTransaction) {
        echo json_encode([
            'success' => true,
            'payment_completed' => false,
            'message' => 'No recent pending payments found. Please initiate payment first.'
        ]);
        return;
    }
    
    // Check how old the transaction is
    $transactionAge = time() - strtotime($pendingTransaction['created_at']);
    $ageMinutes = round($transactionAge / 60);
    
    // Use ExpressPay's verification API
    try {
        $gateway = new ExpressPayGateway();
        $verification = $gateway->verifyPayment($pendingTransaction['reference_id']);
        
        if ($verification['success']) {
            $expressPayStatus = $verification['status'];
            
            switch ($expressPayStatus) {
                case 'success':
                    // Payment completed - process it
                    $gatewayData = [
                        'gateway_reference' => $verification['raw_data']['id'],
                        'payment_method' => $verification['payment_method'],
                        'paid_at' => $verification['paid_at'],
                        'amount' => $verification['amount'],
                        'currency' => $verification['currency'],
                        'gateway_response' => $verification['gateway_response']
                    ];
                    
                    $result = PaymentService::processPaymentCallback(
                        $pendingTransaction['reference_id'], 
                        'completed', 
                        $gatewayData
                    );
                    
                    if ($result) {
                        echo json_encode([
                            'success' => true,
                            'payment_completed' => true,
                            'message' => 'Payment verified with ExpressPay! You can now reset your password.'
                            'redirect_url' => 'forgot-password.php?payment_complete=1'
                        ]);
                    } else {
                        throw new Exception('Failed to process completed payment');
                    }
                    break;
                    
                case 'failed':
                    // Mark as failed and cleanup
                    $updateStmt = $db->prepare(
                        "UPDATE PaymentTransactions 
                         SET status = 'failed' 
                         WHERE reference_id = ?"
                    );
                    $updateStmt->execute([$pendingTransaction['reference_id']]);
                    
                    echo json_encode([
                        'success' => true,
                        'payment_completed' => false,
                        'message' => 'Payment failed. Please try again with a new payment.',
                        'status' => 'failed'
                    ]);
                    break;
                    
                case 'abandoned':
                    // Payment was abandoned - mark as cancelled if old enough
                    if ($ageMinutes > 15) {
                        $updateStmt = $db->prepare(
                            "UPDATE PaymentTransactions 
                             SET status = 'cancelled' 
                             WHERE reference_id = ?"
                        );
                        $updateStmt->execute([$pendingTransaction['reference_id']]);
                        
                        echo json_encode([
                            'success' => true,
                            'payment_completed' => false,
                            'message' => 'Previous payment was abandoned. Please start a new payment.',
                            'status' => 'abandoned'
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'payment_completed' => false,
                            'message' => 'Payment was not completed. Please start a new payment.',
                            'status' => 'abandoned'
                        ]);
                    }
                    break;
                    
                case 'pending':
                    // Still actually pending on ExpressPay
                    if ($ageMinutes < 10) {
                        echo json_encode([
                            'success' => true,
                            'payment_completed' => false,
                            'message' => 'Payment is still processing. Please check your phone for authorization.',
                            'status' => 'pending',
                            'age_minutes' => $ageMinutes
                        ]);
                    } else {
                        // Too old, likely abandoned
                        echo json_encode([
                            'success' => true,
                            'payment_completed' => false,
                            'message' => 'Payment has timed out. Please start a new payment.',
                            'status' => 'timeout'
                        ]);
                    }
                    break;
                    
                default:
                    // Unknown status
                    echo json_encode([
                        'success' => true,
                        'payment_completed' => false,
                        'message' => "Payment status: $expressPayStatus. Please try starting a new payment.",
                        'status' => $expressPayStatus
                    ]);
                    break;
            }
        } else {
            // ExpressPay verification failed
            throw new Exception($verification['message'] ?? 'Unable to verify payment with ExpressPay');
        }
        
    } catch (Exception $e) {
        logSystemActivity('PaymentStatusCheck', 
            "ExpressPay verification error: " . $e->getMessage(), 
            'WARNING', $userId);
        
        // If verification fails and transaction is old, suggest new payment
        if ($ageMinutes > 15) {
            echo json_encode([
                'success' => true,
                'payment_completed' => false,
                'message' => 'Unable to verify payment status. Please try starting a new payment.'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'payment_completed' => false,
                'message' => 'Unable to verify payment with ExpressPay. Please try again in a moment.'
            ]);
        }
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>