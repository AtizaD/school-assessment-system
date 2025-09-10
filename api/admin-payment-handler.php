<?php
/**
 * Admin Payment API Handler
 * Handles AJAX requests for admin payment management operations
 * 
 * @author School Management System
 * @date July 24, 2025
 */

// Define BASEPATH for included files
define('BASEPATH', dirname(__DIR__));

require_once BASEPATH . '/config/config.php';
require_once BASEPATH . '/includes/functions.php';
require_once BASEPATH . '/includes/auth.php';
require_once BASEPATH . '/includes/PaymentService.php';
require_once BASEPATH . '/includes/PaymentHandlers.php';
require_once BASEPATH . '/includes/SecurePaymentConfig.php';

header('Content-Type: application/json');

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_recent_transactions':
            handleGetRecentTransactions();
            break;
            
        case 'get_transaction_details':
            handleGetTransactionDetails();
            break;
            
        case 'cleanup_expired_payments':
            handleCleanupExpiredPayments();
            break;
            
        case 'update_service_pricing':
            handleUpdateServicePricing($userId);
            break;
            
        case 'toggle_payment_system':
            handleTogglePaymentSystem($userId);
            break;
            
        case 'get_payment_config':
            handleGetPaymentConfig();
            break;
            
        case 'update_payment_config':
            handleUpdatePaymentConfig($userId);
            break;
            
        case 'manual_service_grant':
            handleManualServiceGrant($userId);
            break;
            
        case 'refund_transaction':
            handleRefundTransaction($userId);
            break;
            
        case 'get_payment_reports':
            handleGetPaymentReports();
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    logError("Admin Payment API error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Get recent transactions for admin dashboard
 */
function handleGetRecentTransactions() {
    $limit = (int)($_POST['limit'] ?? 10);
    $limit = min($limit, 50); // Cap at 50 records
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "SELECT pt.*, 
                    u.username,
                    u.role as user_role,
                    u.email as user_email,
                    s.first_name as student_first_name,
                    s.last_name as student_last_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    a.title as assessment_title
             FROM PaymentTransactions pt
             LEFT JOIN Users u ON pt.user_id = u.user_id
             LEFT JOIN Students s ON u.user_id = s.user_id AND u.role = 'student'
             LEFT JOIN Teachers t ON u.user_id = t.user_id AND u.role = 'teacher'
             LEFT JOIN Assessments a ON pt.assessment_id = a.assessment_id
             ORDER BY pt.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        $transactions = $stmt->fetchAll();
        
        // Format transactions for display
        $formattedTransactions = array_map(function($tx) {
            // Determine user name based on role
            $userName = '';
            if ($tx['user_role'] === 'student' && $tx['student_first_name']) {
                $userName = $tx['student_first_name'] . ' ' . $tx['student_last_name'];
            } elseif ($tx['user_role'] === 'teacher' && $tx['teacher_first_name']) {
                $userName = $tx['teacher_first_name'] . ' ' . $tx['teacher_last_name'];
            } else {
                $userName = $tx['username']; // Fallback to username for admins or missing data
            }
            
            // Generate email for students if missing
            $userEmail = $tx['user_email'];
            if (!$userEmail && $tx['user_role'] === 'student') {
                $userEmail = 'student' . $tx['username'] . '@example.com';
            }
            
            return [
                'transaction_id' => $tx['transaction_id'],
                'user_id' => $tx['user_id'],
                'user_name' => $userName,
                'user_email' => $userEmail,
                'user_role' => $tx['user_role'],
                'service_type' => $tx['service_type'],
                'service_display_name' => PaymentHandlerUtils::getServiceDisplayName($tx['service_type']),
                'amount' => $tx['amount'],
                'currency' => $tx['currency'],
                'formatted_amount' => PaymentHandlerUtils::formatCurrency($tx['amount'], $tx['currency']),
                'status' => $tx['status'],
                'payment_method' => $tx['payment_method'],
                'reference_id' => $tx['reference_id'],
                'gateway_reference' => $tx['gateway_reference'],
                'assessment_title' => $tx['assessment_title'],
                'created_at' => $tx['created_at'],
                'completed_at' => $tx['completed_at'],
                'expires_at' => $tx['expires_at']
            ];
        }, $transactions);
        
        echo json_encode([
            'success' => true,
            'data' => $formattedTransactions
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch transactions: ' . $e->getMessage());
    }
}

/**
 * Get detailed transaction information
 */
function handleGetTransactionDetails() {
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    
    if (!$transactionId) {
        throw new Exception('Transaction ID is required');
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "SELECT pt.*, 
                    u.username,
                    u.role as user_role,
                    u.email as user_email,
                    s.first_name as student_first_name,
                    s.last_name as student_last_name,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    a.title as assessment_title,
                    ps.service_id, ps.granted_at, ps.expires_at as service_expires_at, ps.used_at
             FROM PaymentTransactions pt
             LEFT JOIN Users u ON pt.user_id = u.user_id
             LEFT JOIN Students s ON u.user_id = s.user_id AND u.role = 'student'
             LEFT JOIN Teachers t ON u.user_id = t.user_id AND u.role = 'teacher'
             LEFT JOIN Assessments a ON pt.assessment_id = a.assessment_id
             LEFT JOIN PaidServices ps ON pt.transaction_id = ps.transaction_id
             WHERE pt.transaction_id = ?"
        );
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Get related webhook data
        $stmt = $db->prepare(
            "SELECT * FROM PaymentWebhooks 
             WHERE reference_id = ? 
             ORDER BY created_at DESC"
        );
        $stmt->execute([$transaction['reference_id']]);
        $webhooks = $stmt->fetchAll();
        
        // Determine user name based on role
        $userName = '';
        if ($transaction['user_role'] === 'student' && $transaction['student_first_name']) {
            $userName = $transaction['student_first_name'] . ' ' . $transaction['student_last_name'];
        } elseif ($transaction['user_role'] === 'teacher' && $transaction['teacher_first_name']) {
            $userName = $transaction['teacher_first_name'] . ' ' . $transaction['teacher_last_name'];
        } else {
            $userName = $transaction['username']; // Fallback to username for admins or missing data
        }
        
        // Generate email for students if missing
        $userEmail = $transaction['user_email'];
        if (!$userEmail && $transaction['user_role'] === 'student') {
            $userEmail = 'student' . $transaction['username'] . '@example.com';
        }
        
        $transactionDetails = [
            'transaction' => [
                'transaction_id' => $transaction['transaction_id'],
                'user_id' => $transaction['user_id'],
                'user_name' => $userName,
                'user_email' => $userEmail,
                'user_role' => $transaction['user_role'],
                'service_type' => $transaction['service_type'],
                'service_display_name' => PaymentHandlerUtils::getServiceDisplayName($transaction['service_type']),
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'],
                'formatted_amount' => PaymentHandlerUtils::formatCurrency($transaction['amount'], $transaction['currency']),
                'status' => $transaction['status'],
                'payment_method' => $transaction['payment_method'],
                'reference_id' => $transaction['reference_id'],
                'gateway_reference' => $transaction['gateway_reference'],
                'gateway_response' => $transaction['gateway_response'] ? json_decode($transaction['gateway_response'], true) : null,
                'assessment_id' => $transaction['assessment_id'],
                'assessment_title' => $transaction['assessment_title'],
                'created_at' => $transaction['created_at'],
                'completed_at' => $transaction['completed_at'],
                'expires_at' => $transaction['expires_at']
            ],
            'service' => $transaction['service_id'] ? [
                'service_id' => $transaction['service_id'],
                'granted_at' => $transaction['granted_at'],
                'expires_at' => $transaction['service_expires_at'],
                'used_at' => $transaction['used_at'],
                'is_active' => $transaction['used_at'] === null && 
                              ($transaction['service_expires_at'] === null || strtotime($transaction['service_expires_at']) > time())
            ] : null,
            'webhooks' => $webhooks
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $transactionDetails
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch transaction details: ' . $e->getMessage());
    }
}

/**
 * Cleanup expired payments
 */
function handleCleanupExpiredPayments() {
    $cleanedCount = PaymentService::cleanupExpiredRequests();
    
    echo json_encode([
        'success' => true,
        'cleaned_count' => $cleanedCount,
        'message' => "Cleaned up $cleanedCount expired payments"
    ]);
}

/**
 * Update service pricing
 */
function handleUpdateServicePricing($userId) {
    $serviceType = $_POST['service_type'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $isActive = (bool)($_POST['is_active'] ?? true);
    
    if (empty($serviceType) || $amount < 0) {
        throw new Exception('Invalid service type or amount');
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "UPDATE ServicePricing 
             SET amount = ?, is_active = ?, updated_by = ?, updated_at = NOW()
             WHERE service_type = ?"
        );
        $result = $stmt->execute([$amount, $isActive, $userId, $serviceType]);
        
        if ($result) {
            logSystemActivity('PaymentAdmin', 
                "Updated pricing for $serviceType: $amount", 'INFO', $userId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Service pricing updated successfully'
            ]);
        } else {
            throw new Exception('Failed to update service pricing');
        }
        
    } catch (Exception $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Toggle payment system on/off
 */
function handleTogglePaymentSystem($userId) {
    $enabled = $_POST['enabled'] === '1' ? '1' : '0';
    
    $result = SecurePaymentConfig::set('payment_enabled', $enabled, false, $userId);
    
    if ($result) {
        $status = $enabled === '1' ? 'enabled' : 'disabled';
        logSystemActivity('PaymentAdmin', "Payment system $status", 'INFO', $userId);
        
        echo json_encode([
            'success' => true,
            'message' => "Payment system has been $status"
        ]);
    } else {
        throw new Exception('Failed to update payment system status');
    }
}

/**
 * Get payment configuration for admin settings
 */
function handleGetPaymentConfig() {
    try {
        $configs = SecurePaymentConfig::getAllForDisplay();
        
        echo json_encode([
            'success' => true,
            'data' => $configs
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch payment configuration: ' . $e->getMessage());
    }
}

/**
 * Update payment configuration
 */
function handleUpdatePaymentConfig($userId) {
    $configKey = $_POST['config_key'] ?? '';
    $configValue = $_POST['config_value'] ?? '';
    $isEncrypted = (bool)($_POST['is_encrypted'] ?? false);
    
    if (empty($configKey)) {
        throw new Exception('Configuration key is required');
    }
    
    $result = SecurePaymentConfig::set($configKey, $configValue, $isEncrypted, $userId);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Configuration updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update configuration');
    }
}

/**
 * Manually grant service access to user
 */
function handleManualServiceGrant($userId) {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $serviceType = $_POST['service_type'] ?? '';
    $assessmentId = (int)($_POST['assessment_id'] ?? 0) ?: null;
    $reason = $_POST['reason'] ?? 'Manual admin grant';
    
    if (!$targetUserId || empty($serviceType)) {
        throw new Exception('User ID and service type are required');
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Create a manual transaction record
        $stmt = $db->prepare(
            "INSERT INTO PaymentTransactions 
             (user_id, service_type, amount, status, payment_method, reference_id, assessment_id, completed_at) 
             VALUES (?, ?, 0.00, 'completed', 'manual_admin_grant', ?, ?, NOW())"
        );
        
        $reference = 'ADMIN_' . time() . '_' . $targetUserId;
        $stmt->execute([$targetUserId, $serviceType, $reference, $assessmentId]);
        $transactionId = $db->lastInsertId();
        
        // Grant service access
        $accessGranted = PaymentService::grantServiceAccess($targetUserId, $serviceType, $transactionId, $assessmentId);
        
        if ($accessGranted) {
            logSystemActivity('PaymentAdmin', 
                "Manually granted $serviceType access to user $targetUserId. Reason: $reason", 'INFO', $userId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Service access granted successfully',
                'transaction_id' => $transactionId
            ]);
        } else {
            throw new Exception('Failed to grant service access');
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to grant manual access: ' . $e->getMessage());
    }
}

/**
 * Process refund for a transaction
 */
function handleRefundTransaction($userId) {
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $refundReason = $_POST['refund_reason'] ?? 'Admin initiated refund';
    
    if (!$transactionId) {
        throw new Exception('Transaction ID is required');
    }
    
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Get transaction details
        $stmt = $db->prepare(
            "SELECT * FROM PaymentTransactions WHERE transaction_id = ? AND status = 'completed'"
        );
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            throw new Exception('Transaction not found or not eligible for refund');
        }
        
        // Update transaction status to refunded
        $stmt = $db->prepare(
            "UPDATE PaymentTransactions 
             SET status = 'refunded', gateway_response = ? 
             WHERE transaction_id = ?"
        );
        
        $refundData = json_encode([
            'refund_reason' => $refundReason,
            'refunded_by' => $userId,
            'refunded_at' => date('Y-m-d H:i:s')
        ]);
        
        $result = $stmt->execute([$refundData, $transactionId]);
        
        if ($result) {
            // Deactivate associated service
            $stmt = $db->prepare(
                "UPDATE PaidServices SET is_active = FALSE WHERE transaction_id = ?"
            );
            $stmt->execute([$transactionId]);
            
            logSystemActivity('PaymentAdmin', 
                "Refunded transaction $transactionId. Reason: $refundReason", 'INFO', $userId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Transaction refunded successfully'
            ]);
        } else {
            throw new Exception('Failed to process refund');
        }
        
    } catch (Exception $e) {
        throw new Exception('Refund processing failed: ' . $e->getMessage());
    }
}

/**
 * Get payment reports data
 */
function handleGetPaymentReports() {
    $period = $_POST['period'] ?? 'month';
    $serviceType = $_POST['service_type'] ?? null;
    
    try {
        $stats = PaymentService::getPaymentStats($period);
        
        // Get additional metrics if needed
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Payment method breakdown
        $stmt = $db->prepare(
            "SELECT payment_method, COUNT(*) as count, SUM(amount) as total
             FROM PaymentTransactions 
             WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
             GROUP BY payment_method"
        );
        $stmt->execute();
        $paymentMethods = $stmt->fetchAll();
        
        // Daily revenue for the last 30 days
        $stmt = $db->prepare(
            "SELECT DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as transactions
             FROM PaymentTransactions 
             WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        );
        $stmt->execute();
        $dailyRevenue = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'payment_methods' => $paymentMethods,
                'daily_revenue' => $dailyRevenue
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to generate reports: ' . $e->getMessage());
    }
}
?>