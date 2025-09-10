<?php
/**
 * Service-Specific Payment Handlers
 * Handles payment logic for specific services (Password Reset, Assessment Review, Retake)
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once 'PaymentService.php';
require_once 'SecurePaymentConfig.php';

/**
 * Password Reset Payment Handler
 */
class PasswordResetPayment {
    
    /**
     * Check if password reset requires payment for user
     * 
     * @param int $userId User ID
     * @return bool True if payment is required
     */
    public static function requiresPayment($userId) {
        try {
            // Check if payment system is enabled
            $paymentEnabled = SecurePaymentConfig::get('payment_enabled');
            if ($paymentEnabled !== '1') {
                return false;
            }
            
            // Check if user has already paid for password reset
            return !PaymentService::canUserAccessService($userId, 'password_reset');
            
        } catch (Exception $e) {
            logError("Error checking password reset payment requirement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process password reset after payment
     * 
     * @param int $userId User ID
     * @param int $transactionId Transaction ID
     * @param string $newPassword New password
     * @return array Result with success status and message
     */
    public static function processReset($userId, $transactionId, $newPassword) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Verify payment was successful
            $stmt = $db->prepare(
                "SELECT * FROM PaymentTransactions 
                 WHERE transaction_id = ? AND user_id = ? AND status = 'completed' 
                 AND service_type = 'password_reset'"
            );
            $stmt->execute([$transactionId, $userId]);
            $transaction = $stmt->fetch();
            
            if (!$transaction) {
                return ['success' => false, 'message' => 'Payment verification failed'];
            }
            
            // Check if service has been used already
            $stmt = $db->prepare(
                "SELECT used_at FROM PaidServices 
                 WHERE transaction_id = ? AND service_type = 'password_reset'"
            );
            $stmt->execute([$transactionId]);
            $service = $stmt->fetch();
            
            if ($service && $service['used_at']) {
                return ['success' => false, 'message' => 'Password reset service already used'];
            }
            
            // Hash the new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update user password
            $stmt = $db->prepare(
                "UPDATE Users SET password = ?, password_changed_at = NOW(), 
                 must_change_password = FALSE WHERE user_id = ?"
            );
            $result = $stmt->execute([$hashedPassword, $userId]);
            
            if ($result) {
                // Mark service as used
                PaymentService::markServiceAsUsed($userId, 'password_reset');
                
                // Log password reset
                logSystemActivity('PasswordReset', 
                    "Password reset completed via payment for user $userId", 'INFO', $userId);
                
                return ['success' => true, 'message' => 'Password reset successful'];
            } else {
                return ['success' => false, 'message' => 'Failed to update password'];
            }
            
        } catch (Exception $e) {
            logError("Error processing paid password reset: " . $e->getMessage());
            return ['success' => false, 'message' => 'System error occurred'];
        }
    }
    
    /**
     * Get password reset pricing
     * 
     * @return array|null Pricing information
     */
    public static function getPricing() {
        return PaymentService::getServicePrice('password_reset');
    }
}

/**
 * Assessment Review Payment Handler
 */
class AssessmentReviewPayment {
    
    /**
     * Check if assessment review requires payment
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return bool True if payment is required
     */
    public static function requiresPayment($userId, $assessmentId) {
        try {
            // Check if payment system is enabled
            $paymentEnabled = SecurePaymentConfig::get('payment_enabled');
            if ($paymentEnabled !== '1') {
                return false;
            }
            
            // Check if user has already paid for review
            return !PaymentService::canUserAccessService($userId, 'review_assessment', $assessmentId);
            
        } catch (Exception $e) {
            logError("Error checking assessment review payment requirement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Grant assessment review access
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @param int $hours Hours of access (default from config)
     * @return array Result with success status and expiry time
     */
    public static function grantAccess($userId, $assessmentId, $hours = null) {
        try {
            if ($hours === null) {
                $hours = SecurePaymentConfig::get('review_access_hours') ?: 24;
            }
            
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$hours hours"));
            
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Update StudentAssessments table
            $stmt = $db->prepare(
                "UPDATE StudentAssessments 
                 SET review_paid = TRUE, review_expires_at = ? 
                 WHERE user_id = ? AND assessment_id = ?"
            );
            $result = $stmt->execute([$expiresAt, $userId, $assessmentId]);
            
            if ($result) {
                // Mark service as used if not already marked
                PaymentService::markServiceAsUsed($userId, 'review_assessment', $assessmentId);
                
                logSystemActivity('AssessmentReview', 
                    "Review access granted for assessment $assessmentId until $expiresAt", 'INFO', $userId);
                
                return [
                    'success' => true, 
                    'expires_at' => $expiresAt,
                    'hours' => $hours
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to grant review access'];
            
        } catch (Exception $e) {
            logError("Error granting assessment review access: " . $e->getMessage());
            return ['success' => false, 'message' => 'System error occurred'];
        }
    }
    
    /**
     * Check if user has active review access
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return array Access status with expiry information
     */
    public static function checkAccess($userId, $assessmentId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT review_paid, review_expires_at 
                 FROM StudentAssessments 
                 WHERE user_id = ? AND assessment_id = ?"
            );
            $stmt->execute([$userId, $assessmentId]);
            $assessment = $stmt->fetch();
            
            if (!$assessment || !$assessment['review_paid']) {
                return ['has_access' => false, 'reason' => 'not_paid'];
            }
            
            $expiresAt = $assessment['review_expires_at'];
            if ($expiresAt && strtotime($expiresAt) < time()) {
                return [
                    'has_access' => false, 
                    'reason' => 'expired',
                    'expired_at' => $expiresAt
                ];
            }
            
            return [
                'has_access' => true,
                'expires_at' => $expiresAt,
                'time_remaining' => $expiresAt ? (strtotime($expiresAt) - time()) : null
            ];
            
        } catch (Exception $e) {
            logError("Error checking assessment review access: " . $e->getMessage());
            return ['has_access' => false, 'reason' => 'error'];
        }
    }
    
    /**
     * Get assessment review pricing
     * 
     * @return array|null Pricing information
     */
    public static function getPricing() {
        return PaymentService::getServicePrice('review_assessment');
    }
}

/**
 * Assessment Retake Payment Handler
 */
class AssessmentRetakePayment {
    
    /**
     * Check if assessment retake requires payment
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return bool True if payment is required
     */
    public static function requiresPayment($userId, $assessmentId) {
        try {
            // Check if payment system is enabled
            $paymentEnabled = SecurePaymentConfig::get('payment_enabled');
            if ($paymentEnabled !== '1') {
                return false;
            }
            
            // Check if user has available retakes
            $retakeInfo = self::getRetakeInfo($userId, $assessmentId);
            
            // Payment required if user has exceeded free retakes
            return $retakeInfo['retakes_used'] >= $retakeInfo['max_retakes'] && 
                   !PaymentService::canUserAccessService($userId, 'retake_assessment', $assessmentId);
            
        } catch (Exception $e) {
            logError("Error checking assessment retake payment requirement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Grant assessment retake access
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return array Result with success status
     */
    public static function grantRetake($userId, $assessmentId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Check cooldown period
            $cooldownHours = SecurePaymentConfig::get('retake_cooldown_hours') ?: 2;
            $stmt = $db->prepare(
                "SELECT MAX(completed_at) as last_attempt 
                 FROM StudentAssessments 
                 WHERE user_id = ? AND assessment_id = ?"
            );
            $stmt->execute([$userId, $assessmentId]);
            $lastAttempt = $stmt->fetchColumn();
            
            if ($lastAttempt) {
                $cooldownEnd = strtotime($lastAttempt . " +$cooldownHours hours");
                if (time() < $cooldownEnd) {
                    $waitTime = $cooldownEnd - time();
                    return [
                        'success' => false, 
                        'message' => 'Cooldown period active',
                        'wait_minutes' => ceil($waitTime / 60)
                    ];
                }
            }
            
            // Update retake status
            $stmt = $db->prepare(
                "UPDATE StudentAssessments 
                 SET retake_paid = TRUE, retake_count = retake_count + 1 
                 WHERE user_id = ? AND assessment_id = ?"
            );
            $result = $stmt->execute([$userId, $assessmentId]);
            
            if ($result) {
                // Mark service as used
                PaymentService::markServiceAsUsed($userId, 'retake_assessment', $assessmentId);
                
                logSystemActivity('AssessmentRetake', 
                    "Retake access granted for assessment $assessmentId", 'INFO', $userId);
                
                return ['success' => true, 'message' => 'Retake access granted'];
            }
            
            return ['success' => false, 'message' => 'Failed to grant retake access'];
            
        } catch (Exception $e) {
            logError("Error granting assessment retake access: " . $e->getMessage());
            return ['success' => false, 'message' => 'System error occurred'];
        }
    }
    
    /**
     * Get retake information for user and assessment
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return array Retake information
     */
    public static function getRetakeInfo($userId, $assessmentId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT retake_count, max_retakes, retake_paid 
                 FROM StudentAssessments 
                 WHERE user_id = ? AND assessment_id = ?"
            );
            $stmt->execute([$userId, $assessmentId]);
            $info = $stmt->fetch();
            
            if (!$info) {
                return [
                    'retakes_used' => 0,
                    'max_retakes' => 1,
                    'retake_paid' => false,
                    'can_retake_free' => true,
                    'needs_payment' => false
                ];
            }
            
            $retakesUsed = (int)$info['retake_count'];
            $maxRetakes = (int)$info['max_retakes'];
            $retakePaid = (bool)$info['retake_paid'];
            
            return [
                'retakes_used' => $retakesUsed,
                'max_retakes' => $maxRetakes,
                'retake_paid' => $retakePaid,
                'can_retake_free' => $retakesUsed < $maxRetakes,
                'needs_payment' => $retakesUsed >= $maxRetakes && !$retakePaid
            ];
            
        } catch (Exception $e) {
            logError("Error getting retake info: " . $e->getMessage());
            return [
                'retakes_used' => 0,
                'max_retakes' => 1,
                'retake_paid' => false,
                'can_retake_free' => true,
                'needs_payment' => false
            ];
        }
    }
    
    /**
     * Check if user can retake assessment
     * 
     * @param int $userId User ID
     * @param int $assessmentId Assessment ID
     * @return array Retake eligibility information
     */
    public static function canUserRetake($userId, $assessmentId) {
        try {
            $retakeInfo = self::getRetakeInfo($userId, $assessmentId);
            
            // Check if user has free retakes available
            if ($retakeInfo['can_retake_free']) {
                return [
                    'can_retake' => true,
                    'requires_payment' => false,
                    'reason' => 'free_retake_available'
                ];
            }
            
            // Check if user has paid for additional retake
            if ($retakeInfo['retake_paid']) {
                return [
                    'can_retake' => true,
                    'requires_payment' => false,
                    'reason' => 'paid_retake_available'
                ];
            }
            
            // Check if payment system is enabled for paid retakes
            $paymentEnabled = SecurePaymentConfig::get('payment_enabled');
            if ($paymentEnabled === '1') {
                return [
                    'can_retake' => true,
                    'requires_payment' => true,
                    'reason' => 'payment_required'
                ];
            }
            
            return [
                'can_retake' => false,
                'requires_payment' => false,
                'reason' => 'no_retakes_available'
            ];
            
        } catch (Exception $e) {
            logError("Error checking retake eligibility: " . $e->getMessage());
            return [
                'can_retake' => false,
                'requires_payment' => false,
                'reason' => 'error'
            ];
        }
    }
    
    /**
     * Get assessment retake pricing
     * 
     * @return array|null Pricing information
     */
    public static function getPricing() {
        return PaymentService::getServicePrice('retake_assessment');
    }
}

/**
 * Utility functions for payment handlers
 */
class PaymentHandlerUtils {
    
    /**
     * Format currency amount for display
     * 
     * @param float $amount Amount
     * @param string $currency Currency code
     * @return string Formatted amount
     */
    public static function formatCurrency($amount, $currency = 'GHS') {
        $symbols = [
            'GHS' => 'GH₵',
            'USD' => '$',
            'EUR' => '€',
            'NGN' => '₦'
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . number_format($amount, 2);
    }
    
    /**
     * Get service display name
     * 
     * @param string $serviceType Service type
     * @return string Display name
     */
    public static function getServiceDisplayName($serviceType) {
        $names = [
            'password_reset' => 'Password Reset',
            'review_assessment' => 'Assessment Review',
            'retake_assessment' => 'Assessment Retake'
        ];
        
        return $names[$serviceType] ?? ucwords(str_replace('_', ' ', $serviceType));
    }
    
    /**
     * Get service description
     * 
     * @param string $serviceType Service type
     * @return string Service description
     */
    public static function getServiceDescription($serviceType) {
        $descriptions = [
            'password_reset' => 'Reset your forgotten password instantly',
            'review_assessment' => 'Access detailed results, correct answers, and explanations',
            'retake_assessment' => 'Get another chance to improve your score'
        ];
        
        return $descriptions[$serviceType] ?? 'Premium service access';
    }
}
?>