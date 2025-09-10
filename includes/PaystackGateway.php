<?php
/**
 * Paystack Payment Gateway Integration
 * Handles Paystack API communication for payment processing
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once 'SecurePaymentConfig.php';
require_once 'PaymentService.php';

class PaystackGateway {
    
    private $publicKey;
    private $secretKey;
    private $baseUrl = 'https://api.paystack.co';
    
    public function __construct() {
        $this->publicKey = SecurePaymentConfig::get('paystack_public_key');
        $this->secretKey = SecurePaymentConfig::get('paystack_secret_key');
        
        if (empty($this->publicKey) || empty($this->secretKey)) {
            throw new Exception('Paystack configuration not properly set');
        }
    }
    
    /**
     * Initialize payment transaction
     * 
     * @param array $paymentData Payment details
     * @return array Payment initialization response
     */
    public function initializePayment($paymentData) {
        try {
            $url = $this->baseUrl . '/transaction/initialize';
            
            $data = [
                'email' => $paymentData['email'],
                'amount' => $paymentData['amount'] * 100, // Convert to pesewas/kobo
                'currency' => $paymentData['currency'] ?? 'GHS',
                'reference' => $paymentData['reference'],
                'callback_url' => $paymentData['callback_url'] ?? '',
                'metadata' => [
                    'service_type' => $paymentData['service_type'],
                    'user_id' => $paymentData['user_id'],
                    'assessment_id' => $paymentData['assessment_id'] ?? null,
                    'custom_fields' => [
                        [
                            'display_name' => 'Service',
                            'variable_name' => 'service_type',
                            'value' => $paymentData['service_display_name'] ?? $paymentData['service_type']
                        ]
                    ]
                ]
            ];
            
            // Paystack handles webhooks automatically - no need to specify URL
            
            $response = $this->makeRequest('POST', $url, $data);
            
            if ($response['status'] === true) {
                // Log successful initialization
                logSystemActivity('PaystackGateway', 
                    "Payment initialized: {$paymentData['reference']}", 'INFO', $paymentData['user_id']);
                
                return [
                    'success' => true,
                    'authorization_url' => $response['data']['authorization_url'],
                    'access_code' => $response['data']['access_code'],
                    'reference' => $response['data']['reference']
                ];
            } else {
                logError("Paystack initialization failed: " . $response['message']);
                return [
                    'success' => false,
                    'message' => $response['message']
                ];
            }
            
        } catch (Exception $e) {
            logError("Paystack initialization error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment initialization failed'
            ];
        }
    }
    
    /**
     * Verify payment transaction
     * 
     * @param string $reference Transaction reference
     * @return array Verification response
     */
    public function verifyPayment($reference) {
        try {
            $url = $this->baseUrl . '/transaction/verify/' . urlencode($reference);
            
            $response = $this->makeRequest('GET', $url);
            
            if ($response['status'] === true) {
                $data = $response['data'];
                
                return [
                    'success' => true,
                    'status' => $data['status'],
                    'reference' => $data['reference'],
                    'amount' => $data['amount'] / 100, // Convert back from pesewas/kobo
                    'currency' => $data['currency'],
                    'paid_at' => $data['paid_at'],
                    'gateway_response' => $data['gateway_response'],
                    'payment_method' => $this->extractPaymentMethod($data),
                    'raw_data' => $data
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $response['message']
                ];
            }
            
        } catch (Exception $e) {
            logError("Paystack verification error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment verification failed'
            ];
        }
    }
    
    /**
     * Process webhook notification
     * 
     * @param array $payload Webhook payload
     * @param string $signature Webhook signature
     * @return bool Processing success
     */
    public function processWebhook($payload, $signature) {
        try {
            // Verify webhook signature
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                logError("Invalid webhook signature from Paystack");
                return false;
            }
            
            $event = json_decode($payload, true);
            if (!$event) {
                logError("Invalid webhook payload from Paystack");
                return false;
            }
            
            // Log webhook receipt
            $this->logWebhook($event, true);
            
            // Process based on event type
            switch ($event['event']) {
                case 'charge.success':
                    return $this->handleChargeSuccess($event['data']);
                    
                case 'charge.failed':
                    return $this->handleChargeFailed($event['data']);
                    
                default:
                    logSystemActivity('PaystackWebhook', 
                        "Unhandled webhook event: {$event['event']}", 'INFO');
                    return true;
            }
            
        } catch (Exception $e) {
            logError("Webhook processing error: " . $e->getMessage());
            $this->logWebhook(['error' => $e->getMessage()], false);
            return false;
        }
    }
    
    /**
     * Handle successful charge webhook
     * 
     * @param array $data Charge data
     * @return bool Processing success
     */
    private function handleChargeSuccess($data) {
        try {
            $reference = $data['reference'];
            $status = 'completed';
            
            $gatewayData = [
                'gateway_reference' => $data['id'],
                'payment_method' => $this->extractPaymentMethod($data),
                'paid_at' => $data['paid_at'],
                'amount' => $data['amount'] / 100,
                'currency' => $data['currency'],
                'gateway_response' => $data['gateway_response']
            ];
            
            // Process payment callback
            $result = PaymentService::processPaymentCallback($reference, $status, $gatewayData);
            
            if ($result) {
                logSystemActivity('PaystackWebhook', 
                    "Successfully processed charge success for: $reference", 'INFO');
            } else {
                logError("Failed to process charge success webhook for: $reference");
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Error handling charge success webhook: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle failed charge webhook
     * 
     * @param array $data Charge data
     * @return bool Processing success
     */
    private function handleChargeFailed($data) {
        try {
            $reference = $data['reference'];
            $status = 'failed';
            
            $gatewayData = [
                'gateway_reference' => $data['id'],
                'payment_method' => $this->extractPaymentMethod($data),
                'failure_reason' => $data['gateway_response'],
                'amount' => $data['amount'] / 100,
                'currency' => $data['currency']
            ];
            
            // Process payment callback
            $result = PaymentService::processPaymentCallback($reference, $status, $gatewayData);
            
            if ($result) {
                logSystemActivity('PaystackWebhook', 
                    "Successfully processed charge failure for: $reference", 'INFO');
            } else {
                logError("Failed to process charge failure webhook for: $reference");
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Error handling charge failure webhook: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extract payment method from Paystack data
     * 
     * @param array $data Payment data
     * @return string Payment method
     */
    private function extractPaymentMethod($data) {
        $channel = $data['channel'] ?? 'unknown';
        
        // Map Paystack channels to our payment methods
        $methodMap = [
            'card' => 'paystack_card',
            'bank' => 'paystack_bank',
            'ussd' => 'paystack_ussd',
            'qr' => 'paystack_qr',
            'mobile_money' => 'paystack_mobile_money',
            'bank_transfer' => 'paystack_transfer'
        ];
        
        return $methodMap[$channel] ?? "paystack_$channel";
    }
    
    /**
     * Verify webhook signature
     * 
     * @param string $payload Webhook payload
     * @param string $signature Provided signature
     * @return bool Signature is valid
     */
    private function verifyWebhookSignature($payload, $signature) {
        $computedSignature = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($signature, $computedSignature);
    }
    
    /**
     * Log webhook for debugging and audit
     * 
     * @param array $webhookData Webhook data
     * @param bool $processed Whether webhook was processed successfully
     */
    private function logWebhook($webhookData, $processed) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "INSERT INTO PaymentWebhooks 
                 (gateway, event_type, reference_id, raw_payload, processed, processing_error) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            
            $eventType = $webhookData['event'] ?? 'unknown';
            $referenceId = $webhookData['data']['reference'] ?? null;
            $rawPayload = json_encode($webhookData);
            $processingError = $processed ? null : ($webhookData['error'] ?? 'Processing failed');
            
            $stmt->execute([
                'paystack',
                $eventType,
                $referenceId,
                $rawPayload,
                $processed,
                $processingError
            ]);
            
        } catch (Exception $e) {
            logError("Error logging webhook: " . $e->getMessage());
        }
    }
    
    /**
     * Make HTTP request to Paystack API
     * 
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array $data Request data
     * @return array Response data
     */
    private function makeRequest($method, $url, $data = null) {
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];
        
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);
        
        if ($error) {
            throw new Exception("CURL Error: $error");
        }
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode - $response");
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Paystack");
        }
        
        return $decodedResponse;
    }
    
    /**
     * Get public key for frontend integration
     * 
     * @return string Public key
     */
    public function getPublicKey() {
        return $this->publicKey;
    }
    
    /**
     * Get secret key (static method for webhook verification)
     * 
     * @return string Secret key
     */
    public static function getSecretKey() {
        return SecurePaymentConfig::get('paystack_secret_key');
    }
    
    
    /**
     * Generate payment form HTML for frontend
     * 
     * @param array $paymentData Payment data
     * @return string HTML form
     */
    public function generatePaymentForm($paymentData) {
        $publicKey = $this->getPublicKey();
        
        return "
        <form id='paystackPaymentForm' action='{$paymentData['callback_url']}' method='POST'>
            <input type='hidden' name='reference' value='{$paymentData['reference']}' />
            <input type='hidden' name='service_type' value='{$paymentData['service_type']}' />
            <script src='https://js.paystack.co/v1/inline.js'></script>
            <button type='button' id='paystackPayBtn' class='btn btn-primary btn-lg'>
                Pay {$paymentData['currency']} " . number_format($paymentData['amount'], 2) . "
            </button>
        </form>
        
        <script>
        document.getElementById('paystackPayBtn').onclick = function(e) {
            e.preventDefault();
            
            var handler = PaystackPop.setup({
                key: '$publicKey',
                email: '{$paymentData['email']}',
                amount: " . ($paymentData['amount'] * 100) . ",
                currency: '{$paymentData['currency']}',
                ref: '{$paymentData['reference']}',
                metadata: {
                   custom_fields: [
                      {
                          display_name: 'Service',
                          variable_name: 'service_type',
                          value: '{$paymentData['service_display_name']}'
                      }
                   ]
                },
                callback: function(response) {
                    // Payment successful
                    window.location = '{$paymentData['callback_url']}?reference=' + response.reference;
                },
                onClose: function() {
                    // Payment cancelled
                    console.log('Payment cancelled');
                }
            });
            
            handler.openIframe();
        };
        </script>";
    }
    
    /**
     * Refund a transaction
     * 
     * @param string $transactionId Paystack transaction ID
     * @param float|null $amount Amount to refund (null for full refund)
     * @return array Refund response
     */
    public function refundTransaction($transactionId, $amount = null) {
        try {
            $url = $this->baseUrl . '/refund';
            
            $data = [
                'transaction' => $transactionId
            ];
            
            if ($amount !== null) {
                $data['amount'] = $amount * 100; // Convert to pesewas/kobo
            }
            
            $response = $this->makeRequest('POST', $url, $data);
            
            if ($response['status'] === true) {
                return [
                    'success' => true,
                    'refund_id' => $response['data']['id'],
                    'amount' => $response['data']['amount'] / 100,
                    'status' => $response['data']['status']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $response['message']
                ];
            }
            
        } catch (Exception $e) {
            logError("Paystack refund error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Refund processing failed'
            ];
        }
    }
}
?>