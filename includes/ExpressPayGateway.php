<?php
/**
 * ExpressPay Payment Gateway Integration
 * Handles ExpressPay API communication for payment processing
 * 
 * @author School Management System
 * @date July 27, 2025
 */

require_once 'SecurePaymentConfig.php';
require_once 'PaymentService.php';

class ExpressPayGateway {
    
    private $merchantId;
    private $apiKey;
    private $environment;
    private $baseUrl;
    
    public function __construct() {
        $this->merchantId = SecurePaymentConfig::get('expresspay_merchant_id');
        $this->apiKey = SecurePaymentConfig::get('expresspay_api_key');
        $this->environment = SecurePaymentConfig::get('expresspay_environment', 'sandbox');
        
        // Set base URL based on environment
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://checkout.expresspaygh.com/api' 
            : 'https://sandbox.expresspaygh.com/api';
        
        if (empty($this->merchantId) || empty($this->apiKey)) {
            throw new Exception('ExpressPay configuration not properly set');
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
            $url = $this->baseUrl . '/submit.php';
            
            $data = [
                'merchant-id' => $this->merchantId,
                'api-key' => $this->apiKey,
                'currency' => $paymentData['currency'] ?? 'GHS',
                'amount' => number_format($paymentData['amount'], 2, '.', ''), // ExpressPay uses decimal amounts
                'order-id' => $paymentData['reference'],
                'order-desc' => $this->getServiceDescription($paymentData['service_type']),
                'email' => $paymentData['email'],
                'first-name' => $paymentData['first_name'] ?? '',
                'last-name' => $paymentData['last_name'] ?? '',
                'redirect-url' => $paymentData['callback_url'] ?? '',
                'webhook-url' => $paymentData['webhook_url'] ?? '',
                'order-img-url' => $paymentData['order_img_url'] ?? ''
            ];
            
            $response = $this->makeRequest($url, $data);
            
            if (!$response) {
                throw new Exception('Failed to communicate with ExpressPay API');
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new Exception('Invalid response from ExpressPay API');
            }
            
            // ExpressPay returns status 1 for success
            if (isset($result['status']) && $result['status'] == 1) {
                return [
                    'status' => true,
                    'message' => 'Payment initialized successfully',
                    'data' => [
                        'authorization_url' => $result['order-id'] ?? '',
                        'access_code' => $result['token'] ?? '',
                        'reference' => $paymentData['reference'],
                        'checkout_url' => $this->buildCheckoutUrl($result['token'] ?? '', $paymentData['reference'])
                    ]
                ];
            } else {
                return [
                    'status' => false,
                    'message' => $result['result-text'] ?? 'Payment initialization failed',
                    'data' => null
                ];
            }
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Payment initialization error: ' . $e->getMessage(),
                'data' => null
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
            $url = $this->baseUrl . '/query.php';
            
            $data = [
                'merchant-id' => $this->merchantId,
                'api-key' => $this->apiKey,
                'order-id' => $reference
            ];
            
            $response = $this->makeRequest($url, $data);
            
            if (!$response) {
                throw new Exception('Failed to communicate with ExpressPay API');
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new Exception('Invalid response from ExpressPay API');
            }
            
            // Map ExpressPay status codes to our internal status
            $status = 'failed';
            $message = $result['result-text'] ?? 'Unknown status';
            
            switch ($result['status']) {
                case 1:
                    $status = 'success';
                    $message = 'Payment completed successfully';
                    break;
                case 2:
                    $status = 'failed';
                    $message = 'Payment failed';
                    break;
                case 3:
                    $status = 'pending';
                    $message = 'Payment pending';
                    break;
                default:
                    $status = 'failed';
                    $message = 'Unknown payment status';
            }
            
            return [
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'amount' => $result['amount'] ?? 0,
                    'currency' => $result['currency'] ?? 'GHS',
                    'status' => $status,
                    'gateway_response' => $message,
                    'paid_at' => $result['date-processed'] ?? null,
                    'channel' => $result['payment-method'] ?? 'unknown',
                    'customer' => [
                        'email' => $result['email'] ?? ''
                    ],
                    'metadata' => [
                        'expresspay_transaction_id' => $result['transaction-id'] ?? '',
                        'order_description' => $result['order-desc'] ?? ''
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Payment verification error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Process webhook from ExpressPay
     * 
     * @param array $payload Webhook payload
     * @param string $signature Webhook signature
     * @return array Processing result
     */
    public function processWebhook($payload, $signature = null) {
        try {
            // ExpressPay webhook verification (if signature provided)
            if ($signature && !$this->verifyWebhookSignature($payload, $signature)) {
                throw new Exception('Invalid webhook signature');
            }
            
            $reference = $payload['order-id'] ?? null;
            
            if (!$reference) {
                throw new Exception('No order ID found in webhook payload');
            }
            
            // Verify the payment status with ExpressPay
            $verification = $this->verifyPayment($reference);
            
            if (!$verification['status']) {
                throw new Exception('Failed to verify payment: ' . $verification['message']);
            }
            
            return [
                'status' => true,
                'data' => $verification['data'],
                'message' => 'Webhook processed successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Webhook processing error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Make HTTP request to ExpressPay API
     */
    private function makeRequest($url, $data) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: School-Management-System/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('HTTP error: ' . $httpCode);
        }
        
        return $response;
    }
    
    /**
     * Build checkout URL for ExpressPay
     */
    private function buildCheckoutUrl($token, $reference) {
        $baseCheckoutUrl = $this->environment === 'production' 
            ? 'https://checkout.expresspaygh.com' 
            : 'https://sandbox.expresspaygh.com';
            
        return $baseCheckoutUrl . '/checkout.php?token=' . urlencode($token) . '&order-id=' . urlencode($reference);
    }
    
    /**
     * Get service description for payment
     */
    private function getServiceDescription($serviceType) {
        $descriptions = [
            'password_reset' => 'Password Reset Service',
            'assessment_review' => 'Assessment Review Access',
            'assessment_retake' => 'Assessment Retake Service'
        ];
        
        return $descriptions[$serviceType] ?? 'School Management Service';
    }
    
    /**
     * Verify webhook signature (if ExpressPay supports it)
     */
    private function verifyWebhookSignature($payload, $signature) {
        // ExpressPay webhook signature verification
        // This depends on ExpressPay's specific implementation
        // For now, we'll return true as ExpressPay may not have signature verification
        return true;
    }
    
    /**
     * Get supported payment methods
     */
    public function getPaymentMethods() {
        return [
            'card' => 'Credit/Debit Card',
            'mtn_momo' => 'MTN Mobile Money',
            'airteltigo_money' => 'AirtelTigo Money',
            'vodafone_cash' => 'Vodafone Cash',
            'bank_transfer' => 'Bank Transfer'
        ];
    }
    
    /**
     * Get gateway information
     */
    public function getGatewayInfo() {
        return [
            'name' => 'ExpressPay',
            'version' => '1.0',
            'environment' => $this->environment,
            'supported_currencies' => ['GHS', 'USD'],
            'supported_countries' => ['GH']
        ];
    }
}
?>