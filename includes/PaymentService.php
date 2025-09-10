<?php
/**
 * Payment Service - Core payment handling logic
 * Manages payment requests, verification, and service access
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once 'functions.php';
require_once 'SecurePaymentConfig.php';
require_once 'ExpressPayGateway.php';

class PaymentService {
    
    /**
     * Get service pricing
     * 
     * @param string $serviceType Service type
     * @return array|null Service pricing information
     */
    public static function getServicePrice($serviceType) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT * FROM ServicePricing 
                 WHERE service_type = ? AND is_active = TRUE"
            );
            $stmt->execute([$serviceType]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            logError("Error getting service price: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if user can access a service without payment
     * 
     * Note: Password reset follows one-time use policy - once used, requires new payment
     * 
     * @param int $userId User ID
     * @param string $serviceType Service type
     * @param int|null $referenceId Reference ID (assessment ID for assessments)
     * @return bool True if user has access
     */
    public static function canUserAccessService($userId, $serviceType, $referenceId = null) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $sql = "SELECT COUNT(*) FROM PaidServices 
                    WHERE user_id = ? AND service_type = ? AND is_active = TRUE";
            $params = [$userId, $serviceType];
            
            // For password reset: check usage limits (max 2 uses)
            if ($serviceType === 'password_reset') {
                $sql .= " AND usage_count < max_uses";
            }
            
            // Add reference ID check for assessment services
            if ($referenceId && in_array($serviceType, ['review_assessment', 'retake_assessment'])) {
                $sql .= " AND assessment_id = ?";
                $params[] = $referenceId;
            }
            
            // Check expiry for time-limited services
            if ($serviceType === 'review_assessment') {
                $sql .= " AND (expires_at IS NULL OR expires_at > NOW())";
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            logError("Error checking service access: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a payment request
     * 
     * @param int $userId User ID
     * @param string $serviceType Service type
     * @param int|null $referenceId Reference ID (assessment ID for assessments)
     * @return array|null Payment request data
     */
    public static function createPaymentRequest($userId, $serviceType, $referenceId = null) {
        try {
            // Check if payment is enabled
            $paymentEnabled = SecurePaymentConfig::get('payment_enabled');
            if ($paymentEnabled !== '1') {
                throw new Exception("Payment system is disabled");
            }
            
            // Get service pricing
            $pricing = self::getServicePrice($serviceType);
            if (!$pricing) {
                throw new Exception("Service pricing not found for: $serviceType");
            }
            
            // Check if user already has access
            if (self::canUserAccessService($userId, $serviceType, $referenceId)) {
                throw new Exception("User already has access to this service");
            }
            
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Generate unique reference ID
            $reference = self::generateTransactionReference($serviceType);
            
            // Calculate expiry time
            $timeoutMinutes = SecurePaymentConfig::get('payment_timeout_minutes') ?: 15;
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$timeoutMinutes minutes"));
            
            // Create transaction record
            $stmt = $db->prepare(
                "INSERT INTO PaymentTransactions 
                 (user_id, service_type, amount, currency, reference_id, assessment_id, expires_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            $currency = SecurePaymentConfig::get('payment_currency') ?: 'GHS';
            
            $result = $stmt->execute([
                $userId,
                $serviceType,
                $pricing['amount'],
                $currency,
                $reference,
                $referenceId ?: null, // Convert empty string to NULL
                $expiresAt
            ]);
            
            if ($result) {
                $transactionId = $db->lastInsertId();
                
                // Log payment request
                logSystemActivity('PaymentService', 
                    "Created payment request: $serviceType for user $userId", 'INFO', $userId);
                
                return [
                    'transaction_id' => $transactionId,
                    'reference_id' => $reference,
                    'amount' => $pricing['amount'],
                    'currency' => $currency,
                    'service_type' => $serviceType,
                    'expires_at' => $expiresAt
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            logError("Error creating payment request: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process payment callback from gateway
     * 
     * @param string $reference Transaction reference
     * @param string $status Payment status
     * @param array $gatewayData Gateway response data
     * @return bool Success status
     */
    public static function processPaymentCallback($reference, $status, $gatewayData = []) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Get transaction
            $stmt = $db->prepare(
                "SELECT * FROM PaymentTransactions WHERE reference_id = ?"
            );
            $stmt->execute([$reference]);
            $transaction = $stmt->fetch();
            
            if (!$transaction) {
                throw new Exception("Transaction not found: $reference");
            }
            
            // Check if already processed
            if ($transaction['status'] !== 'pending') {
                logSystemActivity('PaymentService', 
                    "Duplicate callback for transaction: $reference", 'WARNING');
                return true; // Already processed
            }
            
            // Update transaction status
            $stmt = $db->prepare(
                "UPDATE PaymentTransactions 
                 SET status = ?, completed_at = ?, gateway_response = ?, 
                     payment_method = ?, gateway_reference = ?
                 WHERE reference_id = ?"
            );
            
            $gatewayResponse = json_encode($gatewayData);
            $paymentMethod = $gatewayData['payment_method'] ?? 'unknown';
            $gatewayRef = $gatewayData['gateway_reference'] ?? null;
            
            $result = $stmt->execute([
                $status,
                ($status === 'completed') ? date('Y-m-d H:i:s') : null,
                $gatewayResponse,
                $paymentMethod,
                $gatewayRef,
                $reference
            ]);
            
            // Grant service access if payment successful
            if ($status === 'completed' && $result) {
                $accessGranted = self::grantServiceAccess(
                    $transaction['user_id'],
                    $transaction['service_type'],
                    $transaction['transaction_id'],
                    $transaction['assessment_id']
                );
                
                if ($accessGranted) {
                    logSystemActivity('PaymentService', 
                        "Payment completed and service granted: $reference", 'INFO', $transaction['user_id']);
                } else {
                    logError("Failed to grant service access for transaction: $reference");
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Error processing payment callback: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Grant service access to user
     * 
     * @param int $userId User ID
     * @param string $serviceType Service type
     * @param int $transactionId Transaction ID
     * @param int|null $referenceId Reference ID (assessment ID)
     * @return bool Success status
     */
    public static function grantServiceAccess($userId, $serviceType, $transactionId, $referenceId = null) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Calculate expiry time for time-limited services
            $expiresAt = null;
            if ($serviceType === 'review_assessment') {
                $reviewHours = SecurePaymentConfig::get('review_access_hours') ?: 24;
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$reviewHours hours"));
            }
            
            // Set max uses based on service type
            $maxUses = ($serviceType === 'password_reset') ? 2 : 1;
            
            // Create service access record
            $stmt = $db->prepare(
                "INSERT INTO PaidServices 
                 (user_id, service_type, transaction_id, assessment_id, expires_at, max_uses) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            
            $result = $stmt->execute([
                $userId,
                $serviceType,
                $transactionId,
                $referenceId,
                $expiresAt,
                $maxUses
            ]);
            
            // Update related tables for assessment services
            if ($result && $referenceId) {
                if ($serviceType === 'review_assessment') {
                    $stmt = $db->prepare(
                        "UPDATE StudentAssessments 
                         SET review_paid = TRUE, review_expires_at = ? 
                         WHERE user_id = ? AND assessment_id = ?"
                    );
                    $stmt->execute([$expiresAt, $userId, $referenceId]);
                } elseif ($serviceType === 'retake_assessment') {
                    $stmt = $db->prepare(
                        "UPDATE StudentAssessments 
                         SET retake_paid = TRUE 
                         WHERE user_id = ? AND assessment_id = ?"
                    );
                    $stmt->execute([$userId, $referenceId]);
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Error granting service access: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user's payment history
     * 
     * @param int $userId User ID
     * @param int $limit Number of records to fetch
     * @return array Payment history
     */
    public static function getUserPaymentHistory($userId, $limit = 50) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT pt.*, sp.service_type as service_name, a.title as assessment_title
                 FROM PaymentTransactions pt
                 LEFT JOIN ServicePricing sp ON pt.service_type = sp.service_type
                 LEFT JOIN Assessments a ON pt.assessment_id = a.assessment_id
                 WHERE pt.user_id = ?
                 ORDER BY pt.created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$userId, $limit]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logError("Error getting payment history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get active services for user
     * 
     * @param int $userId User ID
     * @return array Active services
     */
    public static function getUserActiveServices($userId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT ps.*, a.title as assessment_title
                 FROM PaidServices ps
                 LEFT JOIN Assessments a ON ps.assessment_id = a.assessment_id
                 WHERE ps.user_id = ? AND ps.is_active = TRUE
                 AND (ps.expires_at IS NULL OR ps.expires_at > NOW())
                 ORDER BY ps.granted_at DESC"
            );
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logError("Error getting active services: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark service as used
     * 
     * @param int $userId User ID
     * @param string $serviceType Service type
     * @param int|null $referenceId Reference ID
     * @return bool Success status
     */
    public static function markServiceAsUsed($userId, $serviceType, $referenceId = null) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // For password reset: increment usage counter and set used_at on first use
            if ($serviceType === 'password_reset') {
                $sql = "UPDATE PaidServices 
                        SET usage_count = usage_count + 1,
                            used_at = CASE WHEN used_at IS NULL THEN NOW() ELSE used_at END
                        WHERE user_id = ? AND service_type = ? AND usage_count < max_uses AND is_active = TRUE";
            } else {
                // For other services: keep original one-time use logic
                $sql = "UPDATE PaidServices 
                        SET used_at = NOW() 
                        WHERE user_id = ? AND service_type = ? AND used_at IS NULL";
            }
            
            $params = [$userId, $serviceType];
            
            if ($referenceId) {
                $sql .= " AND assessment_id = ?";
                $params[] = $referenceId;
            }
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            // Log the usage for password reset services
            if ($result && $serviceType === 'password_reset') {
                // Get current usage count
                $checkStmt = $db->prepare(
                    "SELECT usage_count, max_uses FROM PaidServices 
                     WHERE user_id = ? AND service_type = ? AND is_active = TRUE 
                     ORDER BY service_id DESC LIMIT 1"
                );
                $checkStmt->execute([$userId, $serviceType]);
                $service = $checkStmt->fetch();
                
                if ($service) {
                    logSystemActivity('PasswordResetUsage', 
                        "Password reset used {$service['usage_count']}/{$service['max_uses']} times", 
                        'INFO', $userId);
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Error marking service as used: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate unique transaction reference
     * 
     * @param string $serviceType Service type
     * @return string Transaction reference
     */
    private static function generateTransactionReference($serviceType) {
        $prefix = strtoupper(substr($serviceType, 0, 3));
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return $prefix . '_' . $timestamp . '_' . $random;
    }
    
    /**
     * Clean up expired payment requests
     * 
     * @return int Number of expired requests cleaned
     */
    public static function cleanupExpiredRequests() {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "UPDATE PaymentTransactions 
                 SET status = 'cancelled' 
                 WHERE status = 'pending' AND expires_at < NOW()"
            );
            $stmt->execute();
            
            $cleanedCount = $stmt->rowCount();
            
            if ($cleanedCount > 0) {
                logSystemActivity('PaymentService', 
                    "Cleaned up $cleanedCount expired payment requests", 'INFO');
            }
            
            return $cleanedCount;
            
        } catch (Exception $e) {
            logError("Error cleaning up expired requests: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if user has unused password reset credits
     * 
     * @param int $userId User ID
     * @return int Number of unused password reset credits
     */
    public static function getUnusedPasswordResetCredits($userId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM PaidServices 
                 WHERE user_id = ? AND service_type = 'password_reset' 
                 AND is_active = TRUE AND used_at IS NULL"
            );
            $stmt->execute([$userId]);
            
            return (int) $stmt->fetchColumn();
            
        } catch (Exception $e) {
            logError("Error checking unused password reset credits: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get payment statistics for admin dashboard
     * 
     * @param string $period Period (today, week, month, year)
     * @return array Payment statistics
     */
    public static function getPaymentStats($period = 'month') {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $whereClause = '';
            switch ($period) {
                case 'today':
                    $whereClause = "WHERE DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $whereClause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                    break;
                case 'month':
                    $whereClause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                    break;
                case 'year':
                    $whereClause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                    break;
            }
            
            // Total revenue and transaction count
            $stmt = $db->prepare(
                "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_transactions,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_transactions,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transactions
                 FROM PaymentTransactions $whereClause"
            );
            $stmt->execute();
            $overall = $stmt->fetch();
            
            // Service breakdown
            $stmt = $db->prepare(
                "SELECT 
                    service_type,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue
                 FROM PaymentTransactions $whereClause
                 GROUP BY service_type
                 ORDER BY revenue DESC"
            );
            $stmt->execute();
            $services = $stmt->fetchAll();
            
            return [
                'period' => $period,
                'overall' => $overall,
                'services' => $services
            ];
            
        } catch (Exception $e) {
            logError("Error getting payment stats: " . $e->getMessage());
            return [
                'period' => $period,
                'overall' => [
                    'total_transactions' => 0,
                    'total_revenue' => 0,
                    'successful_transactions' => 0,
                    'failed_transactions' => 0,
                    'pending_transactions' => 0
                ],
                'services' => []
            ];
        }
    }
    
    /**
     * Get remaining password reset attempts for user
     * 
     * @param int $userId User ID
     * @return array Usage information
     */
    public static function getPasswordResetUsage($userId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT usage_count, max_uses, 
                        (max_uses - usage_count) as remaining_uses,
                        granted_at, used_at
                 FROM PaidServices 
                 WHERE user_id = ? AND service_type = 'password_reset' 
                 AND usage_count < max_uses AND is_active = TRUE
                 ORDER BY service_id DESC LIMIT 1"
            );
            $stmt->execute([$userId]);
            
            $result = $stmt->fetch();
            if ($result) {
                return [
                    'has_access' => true,
                    'usage_count' => (int)$result['usage_count'],
                    'max_uses' => (int)$result['max_uses'],
                    'remaining_uses' => (int)$result['remaining_uses'],
                    'granted_at' => $result['granted_at'],
                    'first_used_at' => $result['used_at']
                ];
            }
            
            return [
                'has_access' => false,
                'usage_count' => 0,
                'max_uses' => 0,
                'remaining_uses' => 0,
                'granted_at' => null,
                'first_used_at' => null
            ];
            
        } catch (Exception $e) {
            logError("Error getting password reset usage: " . $e->getMessage());
            return [
                'has_access' => false,
                'usage_count' => 0,
                'max_uses' => 0,
                'remaining_uses' => 0,
                'granted_at' => null,
                'first_used_at' => null
            ];
        }
    }
    
    /**
     * Clean up old pending payment transactions
     * Cancels all pending transactions older than 1 hour
     * 
     * @return int Number of transactions cancelled
     */
    public static function cleanupOldPendingTransactions() {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "UPDATE PaymentTransactions 
                 SET status = 'cancelled' 
                 WHERE status = 'pending' 
                 AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            
            $stmt->execute();
            $cancelledCount = $stmt->rowCount();
            
            if ($cancelledCount > 0) {
                logSystemActivity('PaymentService', 
                    "Cancelled $cancelledCount old pending transactions", 'INFO');
            }
            
            return $cancelledCount;
            
        } catch (Exception $e) {
            logError("Error cleaning up old pending transactions: " . $e->getMessage());
            return 0;
        }
    }
}
?>