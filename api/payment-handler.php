<?php
/**
 * Payment API Handler
 * Handles AJAX requests for payment operations
 * 
 * @author School Management System
 * @date July 24, 2025
 */

// Define BASEPATH for included files
define('BASEPATH', dirname(__DIR__));

require_once BASEPATH . '/config/config.php';

// Session is already started by config.php
require_once BASEPATH . '/includes/functions.php';
require_once BASEPATH . '/includes/PaymentService.php';
require_once BASEPATH . '/includes/PaymentHandlers.php';
require_once BASEPATH . '/includes/ExpressPayGateway.php';

header('Content-Type: application/json');

// Removed debug logging - production ready

// Check authentication - session, token-based, or forgot password flow
$userId = null;
$userRole = null;

// Authentication handled below

// Try session-based authentication first
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? '';
} 
// Try token-based authentication as fallback
elseif (isset($_POST['auth_user_id']) && isset($_POST['auth_session_token'])) {
    $authUserId = $_POST['auth_user_id'];
    $authUserRole = $_POST['auth_user_role'] ?? '';
    $authToken = $_POST['auth_session_token'];
    
    // Verify the token (simple verification)
    $expectedToken = md5(session_id() . $authUserId);
    if ($authToken === $expectedToken && !empty($authUserId)) {
        $userId = $authUserId;
        $userRole = $authUserRole;
    }
}
// Handle forgot password flow - user stored in session for reset
elseif (isset($_SESSION['reset_user_id']) && isset($_POST['auth_session_token'])) {
    $resetUserId = $_SESSION['reset_user_id'];
    $resetUserRole = $_SESSION['reset_user_role'] ?? '';
    $authToken = $_POST['auth_session_token'];
    
    // Verify token for reset flow
    $expectedToken = md5(session_id() . $resetUserId);
    if ($authToken === $expectedToken) {
        // Additional security: Check if account has been confirmed
        if (!isset($_SESSION['account_confirmed']) || $_SESSION['account_confirmed'] !== true) {
            http_response_code(403);
            echo json_encode([
                'success' => false, 
                'message' => 'Account verification required before payment'
            ]);
            exit;
        }
        
        $userId = $resetUserId;
        $userRole = $resetUserRole;
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access'
    ]);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create_payment':
            handleCreatePayment($userId);
            break;
            
        case 'verify_payment':
            handleVerifyPayment($userId);
            break;
            
        case 'get_service_pricing':
            handleGetServicePricing();
            break;
            
        case 'check_service_access':
            handleCheckServiceAccess($userId);
            break;
            
        case 'get_payment_history':
            handleGetPaymentHistory($userId);
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    logError("Payment API error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Handle payment creation request
 */
function handleCreatePayment($userId) {
    $serviceType = $_POST['service_type'] ?? '';
    $referenceId = $_POST['reference_id'] ?? null;
    
    if (empty($serviceType)) {
        throw new Exception('Service type is required');
    }
    
    // Validate service type
    $validServices = ['password_reset', 'review_assessment', 'retake_assessment'];
    if (!in_array($serviceType, $validServices)) {
        throw new Exception('Invalid service type');
    }
    
    // Check if user already has access
    if (PaymentService::canUserAccessService($userId, $serviceType, $referenceId)) {
        throw new Exception('You already have access to this service');
    }
    
    // Create payment request
    $paymentRequest = PaymentService::createPaymentRequest($userId, $serviceType, $referenceId);
    
    if (!$paymentRequest) {
        throw new Exception('Failed to create payment request');
    }
    
    // Get user information for payment based on role
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get user role first
    $roleStmt = $db->prepare("SELECT role, email FROM Users WHERE user_id = ?");
    $roleStmt->execute([$userId]);
    $userData = $roleStmt->fetch();
    
    if (!$userData) {
        throw new Exception('User not found');
    }
    
    // Get user information and handle missing emails for students
    $user = [];
    
    // Get name and email based on role
    if ($userData['role'] === 'student') {
        $stmt = $db->prepare("SELECT first_name, last_name FROM Students WHERE user_id = ?");
        $stmt->execute([$userId]);
        $nameData = $stmt->fetch();
        if ($nameData) {
            $user['first_name'] = $nameData['first_name'];
            $user['last_name'] = $nameData['last_name'];
        }
        
        // Students don't have emails, generate system email for payment
        $stmt = $db->prepare("SELECT username FROM Users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $userAccount = $stmt->fetch();
        $user['email'] = $userData['email'] ?: "student{$userAccount['username']}@example.com";
        
    } elseif ($userData['role'] === 'teacher') {
        $stmt = $db->prepare("SELECT first_name, last_name FROM Teachers WHERE user_id = ?");
        $stmt->execute([$userId]);
        $nameData = $stmt->fetch();
        if ($nameData) {
            $user['first_name'] = $nameData['first_name'];
            $user['last_name'] = $nameData['last_name'];
        }
        $user['email'] = $userData['email'];
        
    } else {
        // For admin users, use username as name
        $stmt = $db->prepare("SELECT username FROM Users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $adminData = $stmt->fetch();
        if ($adminData) {
            $user['first_name'] = $adminData['username'];
            $user['last_name'] = '';
        }
        $user['email'] = $userData['email'];
    }
    
    // Ensure we have required user information
    if (empty($user['email'])) {
        throw new Exception('Unable to process payment - user information incomplete');
    }
    
    // Initialize ExpressPay payment
    try {
        $gateway = new ExpressPayGateway();
        
        $paymentData = [
            'email' => $user['email'],
            'amount' => $paymentRequest['amount'],
            'currency' => $paymentRequest['currency'],
            'reference' => $paymentRequest['reference_id'],
            'service_type' => $serviceType,
            'service_display_name' => PaymentHandlerUtils::getServiceDisplayName($serviceType),
            'user_id' => $userId,
            'assessment_id' => $referenceId,
            'callback_url' => getSystemUrl() . '/api/payment-callback.php'
        ];
        
        $initResponse = $gateway->initializePayment($paymentData);
        
        if ($initResponse['success']) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'public_key' => $gateway->getPublicKey(),
                    'reference' => $paymentRequest['reference_id'],
                    'amount' => $paymentRequest['amount'],
                    'currency' => $paymentRequest['currency'],
                    'email' => $user['email'],
                    'authorization_url' => $initResponse['authorization_url'],
                    'access_code' => $initResponse['access_code']
                ]
            ]);
        } else {
            throw new Exception($initResponse['message']);
        }
        
    } catch (Exception $e) {
        logError("Payment gateway error: " . $e->getMessage());
        throw new Exception('Payment gateway initialization failed');
    }
}

/**
 * Handle payment verification request
 */
function handleVerifyPayment($userId) {
    $reference = $_POST['reference'] ?? '';
    
    if (empty($reference)) {
        throw new Exception('Payment reference is required');
    }
    
    // Get transaction details
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare(
        "SELECT * FROM PaymentTransactions 
         WHERE reference_id = ? AND user_id = ?"
    );
    $stmt->execute([$reference, $userId]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        throw new Exception('Transaction not found');
    }
    
    // Verify with ExpressPay
    try {
        $gateway = new ExpressPayGateway();
        $verification = $gateway->verifyPayment($reference);
        
        if ($verification['success'] && $verification['status'] === 'success') {
            // Process payment callback
            $gatewayData = [
                'gateway_reference' => $verification['raw_data']['id'],
                'payment_method' => $verification['payment_method'],
                'paid_at' => $verification['paid_at'],
                'amount' => $verification['amount'],
                'currency' => $verification['currency'],
                'gateway_response' => $verification['gateway_response']
            ];
            
            $callbackResult = PaymentService::processPaymentCallback($reference, 'completed', $gatewayData);
            
            if ($callbackResult) {
                // Generate success response based on service type
                $successData = generateSuccessResponse($transaction['service_type'], $transaction['assessment_id']);
                
                echo json_encode([
                    'success' => true,
                    'data' => $successData
                ]);
            } else {
                throw new Exception('Payment processing failed');
            }
        } else {
            throw new Exception('Payment verification failed: ' . ($verification['message'] ?? 'Unknown error'));
        }
        
    } catch (Exception $e) {
        logError("Payment verification error: " . $e->getMessage());
        throw new Exception('Payment verification failed');
    }
}

/**
 * Generate success response based on service type
 */
function generateSuccessResponse($serviceType, $assessmentId = null) {
    $successData = [
        'message' => 'Payment completed successfully!',
        'activation_details' => '',
        'redirect_url' => ''
    ];
    
    switch ($serviceType) {
        case 'password_reset':
            $successData['message'] = 'Password reset service activated!';
            $successData['activation_details'] = 'You can now reset your password. Please proceed to the password reset form.';
            $successData['redirect_url'] = getSystemUrl() . '/reset-password.php';
            break;
            
        case 'review_assessment':
            $reviewHours = SecurePaymentConfig::get('review_access_hours') ?: 24;
            $successData['message'] = 'Assessment review access granted!';
            $successData['activation_details'] = "You now have $reviewHours hours of access to detailed assessment results and explanations.";
            if ($assessmentId) {
                $successData['redirect_url'] = getSystemUrl() . "/student/assessment-results.php?id=$assessmentId";
            }
            break;
            
        case 'retake_assessment':
            $successData['message'] = 'Assessment retake access granted!';
            $successData['activation_details'] = 'You can now retake the assessment. Good luck!';
            if ($assessmentId) {
                $successData['redirect_url'] = getSystemUrl() . "/student/take-assessment.php?id=$assessmentId";
            }
            break;
    }
    
    return $successData;
}

/**
 * Handle service pricing request
 */
function handleGetServicePricing() {
    $serviceType = $_POST['service_type'] ?? '';
    
    if (empty($serviceType)) {
        throw new Exception('Service type is required');
    }
    
    $pricing = PaymentService::getServicePrice($serviceType);
    
    if (!$pricing) {
        throw new Exception('Service pricing not found');
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'service_type' => $pricing['service_type'],
            'amount' => $pricing['amount'],
            'currency' => $pricing['currency'],
            'display_name' => PaymentHandlerUtils::getServiceDisplayName($pricing['service_type']),
            'description' => PaymentHandlerUtils::getServiceDescription($pricing['service_type']),
            'formatted_amount' => PaymentHandlerUtils::formatCurrency($pricing['amount'], $pricing['currency'])
        ]
    ]);
}

/**
 * Handle service access check
 */
function handleCheckServiceAccess($userId) {
    $serviceType = $_POST['service_type'] ?? '';
    $referenceId = $_POST['reference_id'] ?? null;
    
    if (empty($serviceType)) {
        throw new Exception('Service type is required');
    }
    
    $hasAccess = PaymentService::canUserAccessService($userId, $serviceType, $referenceId);
    
    $response = [
        'success' => true,
        'has_access' => $hasAccess,
        'service_type' => $serviceType
    ];
    
    // Add service-specific details
    if ($serviceType === 'review_assessment' && $referenceId) {
        $accessInfo = AssessmentReviewPayment::checkAccess($userId, $referenceId);
        $response['access_details'] = $accessInfo;
    } elseif ($serviceType === 'retake_assessment' && $referenceId) {
        $retakeInfo = AssessmentRetakePayment::getRetakeInfo($userId, $referenceId);
        $response['retake_info'] = $retakeInfo;
    }
    
    echo json_encode($response);
}

/**
 * Handle payment history request
 */
function handleGetPaymentHistory($userId) {
    $limit = (int)($_POST['limit'] ?? 20);
    $limit = min($limit, 100); // Cap at 100 records
    
    $history = PaymentService::getUserPaymentHistory($userId, $limit);
    
    // Format history for display
    $formattedHistory = array_map(function($transaction) {
        return [
            'transaction_id' => $transaction['transaction_id'],
            'service_type' => $transaction['service_type'],
            'service_name' => PaymentHandlerUtils::getServiceDisplayName($transaction['service_type']),
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency'],
            'formatted_amount' => PaymentHandlerUtils::formatCurrency($transaction['amount'], $transaction['currency']),
            'status' => $transaction['status'],
            'payment_method' => $transaction['payment_method'],
            'created_at' => $transaction['created_at'],
            'completed_at' => $transaction['completed_at'],
            'assessment_title' => $transaction['assessment_title']
        ];
    }, $history);
    
    echo json_encode([
        'success' => true,
        'data' => $formattedHistory
    ]);
}

/**
 * Get system base URL
 */
function getSystemUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname(dirname($_SERVER['REQUEST_URI']));
    return $protocol . '://' . $host . $path;
}
?>