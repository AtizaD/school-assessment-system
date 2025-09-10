<?php
/**
 * Secure Payment Configuration Management
 * Handles encrypted storage and retrieval of payment gateway credentials
 * 
 * @author School Management System
 * @date July 24, 2025
 */

require_once 'functions.php';

class SecurePaymentConfig {
    private static $encryptionKey = null;
    private static $cache = [];
    
    /**
     * Get encryption key from environment or secure file
     */
    private static function getEncryptionKey() {
        if (self::$encryptionKey === null) {
            // Try environment variable first
            $envKey = $_ENV['PAYMENT_ENCRYPTION_KEY'] ?? null;
            
            if ($envKey) {
                self::$encryptionKey = base64_decode($envKey);
            } else {
                // Generate a secure key if none exists and store it securely
                $keyFile = dirname(__DIR__) . '/cache/payment.key';
                
                if (!file_exists($keyFile)) {
                    $key = random_bytes(32);
                    file_put_contents($keyFile, base64_encode($key));
                    chmod($keyFile, 0600); // Restrict access
                    self::$encryptionKey = $key;
                } else {
                    self::$encryptionKey = base64_decode(file_get_contents($keyFile));
                }
            }
        }
        return self::$encryptionKey;
    }
    
    /**
     * Get payment configuration value
     * 
     * @param string $key Configuration key
     * @param int|null $userId User ID for audit logging
     * @return string|null Configuration value
     */
    public static function get($key, $userId = null) {
        try {
            // Check cache first
            if (isset(self::$cache[$key])) {
                return self::$cache[$key];
            }
            
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Get configuration with access logging
            $stmt = $db->prepare(
                "SELECT config_value, is_encrypted 
                 FROM PaymentConfig 
                 WHERE config_key = ?"
            );
            $stmt->execute([$key]);
            $config = $stmt->fetch();
            
            if (!$config) {
                logError("Payment configuration '$key' not found");
                return null;
            }
            
            // Log access for security audit
            self::logConfigAccess($key, $userId);
            
            // Decrypt if necessary
            $value = $config['is_encrypted'] ? 
                self::decrypt($config['config_value']) : 
                $config['config_value'];
                
            // Cache the decrypted value
            self::$cache[$key] = $value;
            
            return $value;
            
        } catch (Exception $e) {
            logError("Payment config access error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set payment configuration value
     * 
     * @param string $key Configuration key
     * @param string $value Configuration value
     * @param bool $encrypt Whether to encrypt the value
     * @param int|null $userId User ID for audit logging
     * @return bool Success status
     */
    public static function set($key, $value, $encrypt = false, $userId = null) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $finalValue = $encrypt ? self::encrypt($value) : $value;
            
            $stmt = $db->prepare(
                "INSERT INTO PaymentConfig (config_key, config_value, is_encrypted, updated_by) 
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                 config_value = VALUES(config_value),
                 is_encrypted = VALUES(is_encrypted),
                 updated_by = VALUES(updated_by),
                 updated_at = CURRENT_TIMESTAMP"
            );
            
            $result = $stmt->execute([$key, $finalValue, $encrypt ? 1 : 0, $userId]);
            
            if ($result) {
                // Update cache
                self::$cache[$key] = $value;
                
                // Log configuration change
                logSystemActivity('PaymentConfig', "Updated config: $key", 'INFO', $userId);
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError("Payment config update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Encrypt sensitive data
     * 
     * @param string $data Data to encrypt
     * @return string Encrypted and base64 encoded data
     */
    private static function encrypt($data) {
        try {
            $key = self::getEncryptionKey();
            
            // Use random_bytes() instead of openssl_random_pseudo_bytes()
            // random_bytes() is cryptographically secure and guaranteed to return exact length
            $iv = random_bytes(16);
            
            $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            return base64_encode($iv . $encrypted);
        } catch (Exception $e) {
            logError("Payment config encryption error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Decrypt sensitive data
     * 
     * @param string $data Encrypted and base64 encoded data
     * @return string Decrypted data
     */
    private static function decrypt($data) {
        try {
            $key = self::getEncryptionKey();
            $decodedData = base64_decode($data);
            
            if ($decodedData === false) {
                throw new Exception('Invalid base64 data');
            }
            
            // Ensure we have enough data for IV + encrypted content
            if (strlen($decodedData) < 16) {
                throw new Exception('Invalid encrypted data: too short');
            }
            
            $iv = substr($decodedData, 0, 16);
            $encrypted = substr($decodedData, 16);
            
            // Verify IV length
            if (strlen($iv) !== 16) {
                throw new Exception('Invalid IV length: ' . strlen($iv) . ' bytes');
            }
            
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }
            
            return $decrypted;
        } catch (Exception $e) {
            logError("Payment config decryption error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Helper method to re-encrypt data with proper IV
     * Call this once to fix any existing data with invalid IVs
     * 
     * @param int|null $userId User ID for audit logging
     * @return bool Success status
     */
    public static function fixEncryptedData($userId = null) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Get all encrypted configurations
            $stmt = $db->prepare(
                "SELECT config_key, config_value 
                 FROM PaymentConfig 
                 WHERE is_encrypted = 1"
            );
            $stmt->execute();
            $configs = $stmt->fetchAll();
            
            $fixed = 0;
            foreach ($configs as $config) {
                try {
                    // Try to decrypt with current method
                    $decrypted = self::decrypt($config['config_value']);
                    
                    // If successful, re-encrypt with proper IV
                    $reencrypted = self::encrypt($decrypted);
                    
                    // Update the database
                    $updateStmt = $db->prepare(
                        "UPDATE PaymentConfig 
                         SET config_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP 
                         WHERE config_key = ?"
                    );
                    $updateStmt->execute([$reencrypted, $userId, $config['config_key']]);
                    
                    $fixed++;
                    
                } catch (Exception $e) {
                    logError("Failed to fix encrypted data for key '{$config['config_key']}': " . $e->getMessage());
                }
            }
            
            logSystemActivity('PaymentConfig', "Fixed $fixed encrypted configuration entries", 'INFO', $userId);
            
            return true;
            
        } catch (Exception $e) {
            logError("Error fixing encrypted data: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log configuration access for security audit
     * 
     * @param string $key Configuration key accessed
     * @param int|null $userId User ID
     */
    private static function logConfigAccess($key, $userId) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Update access tracking
            $stmt = $db->prepare(
                "UPDATE PaymentConfig 
                 SET access_count = access_count + 1, 
                     last_accessed = CURRENT_TIMESTAMP 
                 WHERE config_key = ?"
            );
            $stmt->execute([$key]);
            
            // Log system activity (only for sensitive keys)
            $sensitiveKeys = ['secret_key', 'api_key', 'private_key'];
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($key, $sensitive) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                logSystemActivity('PaymentConfig', "Accessed sensitive config: $key", 'INFO', $userId);
            }
            
        } catch (Exception $e) {
            logError("Config access logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Clear configuration cache and encryption key from memory
     */
    public static function clearCache() {
        self::$cache = [];
        self::$encryptionKey = null;
    }
    
    /**
     * Validate required payment configuration
     * 
     * @throws Exception If required configuration is missing
     */
    public static function validateConfig() {
        $required = ['payment_enabled', 'payment_currency'];
        
        // Add gateway-specific requirements
        $paymentEnabled = self::get('payment_enabled');
        if ($paymentEnabled === '1') {
            $gateway = self::get('payment_gateway');
            if ($gateway === 'paystack') {
                $required = array_merge($required, ['paystack_public_key', 'paystack_secret_key']);
            }
        }
        
        foreach ($required as $key) {
            $value = self::get($key);
            if (empty($value)) {
                throw new Exception("Missing required payment config: $key");
            }
        }
        
        return true;
    }
    
    /**
     * Get all non-sensitive configuration values for admin display
     * 
     * @return array Configuration values with sensitive data masked
     */
    public static function getAllForDisplay() {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT config_key, config_value, is_encrypted, category, 
                        created_at, updated_at, access_count
                 FROM PaymentConfig 
                 ORDER BY category, config_key"
            );
            $stmt->execute();
            $configs = $stmt->fetchAll();
            
            // Mask sensitive values
            foreach ($configs as &$config) {
                if ($config['is_encrypted']) {
                    $config['config_value'] = self::maskSensitiveValue($config['config_key'], $config['config_value']);
                }
            }
            
            return $configs;
            
        } catch (Exception $e) {
            logError("Error retrieving payment configs for display: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mask sensitive configuration values for display
     * 
     * @param string $key Configuration key
     * @param string $value Configuration value
     * @return string Masked value
     */
    private static function maskSensitiveValue($key, $value) {
        $sensitiveKeys = ['secret_key', 'api_key', 'private_key'];
        foreach ($sensitiveKeys as $sensitive) {
            if (strpos($key, $sensitive) !== false) {
                return '***ENCRYPTED***';
            }
        }
        return $value;
    }
}

// Clear sensitive data from memory on script shutdown
register_shutdown_function(function() {
    SecurePaymentConfig::clearCache();
});
?>