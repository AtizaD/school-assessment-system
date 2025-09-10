<?php
/**
 * Payment Webhook Handler
 * Handles webhook notifications from payment gateways
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/ExpressPayGateway.php';

// Set proper headers
header('Content-Type: application/json');
http_response_code(200); // Always return 200 initially to acknowledge receipt

try {
    // Get the payload and headers
    $input = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_EXPRESSPAY_SIGNATURE'] ?? '';
    
    if (empty($input)) {
        throw new Exception('Empty webhook payload');
    }
    
    if (empty($signature)) {
        throw new Exception('Missing webhook signature');
    }
    
    // Determine gateway based on signature header
    $gateway = null;
    if (isset($_SERVER['HTTP_X_EXPRESSPAY_SIGNATURE'])) {
        $gateway = 'expresspay';
    }
    
    if (!$gateway) {
        throw new Exception('Unknown payment gateway');
    }
    
    // Process webhook based on gateway
    $result = false;
    
    switch ($gateway) {
        case 'expresspay':
            $result = handleExpressPayWebhook($input, $signature);
            break;
            
        default:
            throw new Exception('Unsupported payment gateway');
    }
    
    if ($result) {
        echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
        logSystemActivity('PaymentWebhook', "Successfully processed $gateway webhook", 'INFO');
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Webhook processing failed']);
        logError("Failed to process $gateway webhook");
    }
    
} catch (Exception $e) {
    logError("Webhook processing error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Handle ExpressPay webhook
 * 
 * @param string $payload Webhook payload
 * @param string $signature Webhook signature
 * @return bool Processing success
 */
function handleExpressPayWebhook($payload, $signature) {
    try {
        $gateway = new ExpressPayGateway();
        return $gateway->processWebhook($payload, $signature);
        
    } catch (Exception $e) {
        logError("ExpressPay webhook error: " . $e->getMessage());
        
        // Log failed webhook for debugging
        logFailedWebhook('expresspay', $payload, $e->getMessage());
        
        return false;
    }
}

/**
 * Log failed webhook for debugging
 * 
 * @param string $gatewayName Gateway name
 * @param string $payload Webhook payload
 * @param string $error Error message
 */
function logFailedWebhook($gatewayName, $payload, $error) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "INSERT INTO PaymentWebhooks 
             (gateway, event_type, raw_payload, processed, processing_error) 
             VALUES (?, ?, ?, ?, ?)"
        );
        
        $eventData = json_decode($payload, true);
        $eventType = $eventData['event'] ?? 'unknown';
        
        $stmt->execute([
            $gatewayName,
            $eventType,
            $payload,
            false,
            $error
        ]);
        
    } catch (Exception $e) {
        logError("Failed to log webhook error: " . $e->getMessage());
    }
}
?>