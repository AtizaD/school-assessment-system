# Payment System Implementation Guide
## School Management System - Payment Gateway Integration

### Overview
This document outlines the implementation of payment options for premium services in the school management system, specifically for:
- Password reset services
- Assessment review access
- Assessment retake opportunities

---

## 1. Business Requirements

### 1.1 Paid Services
| Service | Description | Suggested Price (GHS) | Use Case |
|---------|-------------|---------------------|----------|
| **Password Reset** | Manual admin password reset for forgotten passwords | 5.00 - 10.00 | Prevent abuse of password reset system |
| **Review Assessment** | Access to detailed results, correct answers, and explanations | 2.00 - 5.00 | Premium learning feedback |
| **Retake Assessment** | Unlock opportunity to retake failed assessments | 10.00 - 20.00 | Second chance with financial commitment |

### 1.2 Payment Methods (Ghana Focus)
- **MTN Mobile Money** (Primary - 60%+ market share)
- **Vodafone Cash**
- **AirtelTigo Money**
- **Paystack** (Cards + Mobile Money aggregator)
- **Bank Transfer** (Manual verification)

---

## 2. Database Schema Changes

### 2.1 New Tables

#### PaymentTransactions Table
```sql
CREATE TABLE PaymentTransactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    service_type ENUM('password_reset', 'review_assessment', 'retake_assessment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'GHS',
    status ENUM('pending', 'completed', 'failed', 'refunded', 'cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50), -- 'mtn_momo', 'vodafone_cash', 'paystack', etc.
    
    -- Payment Gateway Fields
    gateway_reference VARCHAR(100), -- Payment gateway transaction ID
    gateway_response TEXT, -- Full gateway response (JSON)
    
    -- Internal Tracking
    reference_id VARCHAR(100) UNIQUE, -- Internal reference for tracking
    assessment_id INT NULL, -- For assessment-related payments
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL, -- Payment link expiry
    
    -- Indexes
    INDEX idx_user_service (user_id, service_type),
    INDEX idx_status (status),
    INDEX idx_reference (reference_id),
    
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES Assessments(assessment_id) ON DELETE SET NULL
);
```

#### ServicePricing Table
```sql
CREATE TABLE ServicePricing (
    pricing_id INT AUTO_INCREMENT PRIMARY KEY,
    service_type VARCHAR(50) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'GHS',
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Admin tracking
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- Default pricing
INSERT INTO ServicePricing (service_type, amount) VALUES 
('password_reset', 5.00),
('review_assessment', 3.00),
('retake_assessment', 15.00);
```

#### PaidServices Table
```sql
CREATE TABLE PaidServices (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    transaction_id INT NOT NULL,
    
    -- Service-specific fields
    assessment_id INT NULL, -- For review/retake services
    reference_data JSON NULL, -- Additional service data
    
    -- Service lifecycle
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL, -- Service expiry (24hrs for review, etc.)
    used_at TIMESTAMP NULL, -- When service was actually used
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Indexes
    INDEX idx_user_service (user_id, service_type, is_active),
    INDEX idx_transaction (transaction_id),
    
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES PaymentTransactions(transaction_id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES Assessments(assessment_id) ON DELETE CASCADE
);
```

#### PaymentWebhooks Table (For logging)
```sql
CREATE TABLE PaymentWebhooks (
    webhook_id INT AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    reference_id VARCHAR(100),
    raw_payload TEXT,
    processed BOOLEAN DEFAULT FALSE,
    processing_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_reference (reference_id),
    INDEX idx_processed (processed)
);
```

### 2.2 Modify Existing Tables

#### StudentAssessments Table
```sql
ALTER TABLE StudentAssessments 
ADD COLUMN review_paid BOOLEAN DEFAULT FALSE,
ADD COLUMN review_expires_at TIMESTAMP NULL,
ADD COLUMN retake_paid BOOLEAN DEFAULT FALSE,
ADD COLUMN retake_count INT DEFAULT 0,
ADD COLUMN max_retakes INT DEFAULT 1;
```

#### SystemSettings Table
```sql
INSERT INTO SystemSettings (setting_key, setting_value) VALUES 
('payment_enabled', '0'),
('payment_gateway', 'paystack'),
('payment_currency', 'GHS'),
('paystack_public_key', ''),
('paystack_secret_key', ''),
('mtn_momo_api_key', ''),
('payment_timeout_minutes', '15'),
('review_access_hours', '24'),
('retake_cooldown_hours', '2');
```

---

## 3. Payment Gateway Integration

### 3.1 Paystack Integration (Recommended)

#### Configuration
```php
// config/payment.php
class PaymentConfig {
    const PAYSTACK_PUBLIC_KEY = 'pk_test_xxxxx'; // From settings
    const PAYSTACK_SECRET_KEY = 'sk_test_xxxxx'; // From settings
    const PAYSTACK_BASE_URL = 'https://api.paystack.co';
    
    const CURRENCIES = ['GHS', 'NGN', 'USD'];
}
```

#### Payment Flow Class
```php
// models/Payment.php
class PaymentHandler {
    private $db;
    
    public function initiatePayment($userId, $serviceType, $assessmentId = null) {
        // 1. Get service pricing
        // 2. Create transaction record
        // 3. Generate payment link
        // 4. Return payment URL
    }
    
    public function verifyPayment($reference) {
        // 1. Call Paystack verification API
        // 2. Update transaction status
        // 3. Grant service access
        // 4. Send notifications
    }
    
    public function handleWebhook($payload) {
        // 1. Verify webhook signature
        // 2. Process payment event
        // 3. Update records
        // 4. Log webhook
    }
}
```

### 3.2 Mobile Money Direct Integration

#### MTN Mobile Money
```php
// Payment request
$mtnPayment = [
    'amount' => '5.00',
    'currency' => 'GHS',
    'externalId' => $referenceId,
    'payer' => [
        'partyIdType' => 'MSISDN',
        'partyId' => $phoneNumber
    ],
    'payerMessage' => 'Password Reset Fee',
    'payeeNote' => 'School System Payment'
];
```

---

## 4. Payment Configuration Security

### 4.1 Configuration Storage Strategy

**Database storage is RECOMMENDED** over file storage for payment configurations due to superior security features.

#### Database Storage (Recommended) ✅

**Security Advantages:**
- **Encrypted storage** - Sensitive keys encrypted in database
- **Access control** - Database-level permissions restrict access
- **Audit trails** - Track who changed payment settings and when
- **Runtime isolation** - Keys aren't exposed in file system
- **Backup security** - Database backups can be encrypted separately

#### Secure Configuration Table
```sql
CREATE TABLE PaymentConfig (
    config_id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT, -- Encrypted for sensitive values
    is_encrypted BOOLEAN DEFAULT FALSE,
    category VARCHAR(50) DEFAULT 'general',
    description TEXT,
    
    -- Audit fields
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Security
    access_count INT DEFAULT 0,
    last_accessed TIMESTAMP NULL,
    
    FOREIGN KEY (created_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- Sample encrypted configuration data
INSERT INTO PaymentConfig (config_key, config_value, is_encrypted, category, created_by) VALUES 
('paystack_public_key', 'pk_live_xxxxx', FALSE, 'paystack', 1),
('paystack_secret_key', 'ENCRYPTED_VALUE_HERE', TRUE, 'paystack', 1),
('mtn_api_key', 'ENCRYPTED_VALUE_HERE', TRUE, 'mobile_money', 1),
('payment_enabled', '1', FALSE, 'general', 1),
('payment_currency', 'GHS', FALSE, 'general', 1),
('payment_timeout_minutes', '15', FALSE, 'general', 1);
```

#### Secure Configuration Handler
```php
// includes/SecurePaymentConfig.php
class SecurePaymentConfig {
    private static $encryptionKey = null;
    private static $cache = [];
    
    private static function getEncryptionKey() {
        if (self::$encryptionKey === null) {
            // Load from environment variable or secure file outside web root
            self::$encryptionKey = $_ENV['PAYMENT_ENCRYPTION_KEY'] ?? 
                                 file_get_contents('/var/www/secure/payment.key');
        }
        return self::$encryptionKey;
    }
    
    public static function get($key, $userId = null) {
        try {
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
                throw new Exception("Payment configuration '$key' not found");
            }
            
            // Log access for security audit
            self::logConfigAccess($key, $userId);
            
            // Decrypt if necessary
            $value = $config['is_encrypted'] ? 
                self::decrypt($config['config_value']) : 
                $config['config_value'];
                
            return $value;
            
        } catch (Exception $e) {
            logError("Payment config access error: " . $e->getMessage());
            return null;
        }
    }
    
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
            
            $result = $stmt->execute([$key, $finalValue, $encrypt, $userId]);
            
            // Log configuration change
            logSystemActivity('PaymentConfig', "Updated config: $key", 'INFO', $userId);
            
            return $result;
            
        } catch (Exception $e) {
            logError("Payment config update error: " . $e->getMessage());
            return false;
        }
    }
    
    private static function encrypt($data) {
        $key = self::getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    private static function decrypt($data) {
        $key = self::getEncryptionKey();
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
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
            
            // Log system activity
            logSystemActivity('PaymentConfig', "Accessed config: $key", 'INFO', $userId);
            
        } catch (Exception $e) {
            logError("Config access logging error: " . $e->getMessage());
        }
    }
    
    public static function clearCache() {
        self::$cache = [];
        self::$encryptionKey = null;
    }
}
```

### 4.2 File Storage (NOT Recommended) ❌

**Security Risks:**
- **File system exposure** - Config files can be accidentally exposed via web
- **Version control leaks** - Keys might be committed to Git repositories
- **Server access** - Anyone with file system access can see keys
- **No audit trail** - Cannot track who changed configurations when
- **Backup exposure** - File backups may not be properly encrypted

**Common Vulnerabilities:**
```php
// DANGEROUS: Config in web-accessible directory
/public_html/config/payment.php

// DANGEROUS: Committed to version control
.env with real keys pushed to Git

// DANGEROUS: Incorrect file permissions
-rw-rw-rw- payment_config.php (world-readable)

// DANGEROUS: Plain text storage
define('PAYSTACK_SECRET', 'sk_live_actual_secret_key');
```

### 4.3 Hybrid Approach (Best Practice) 🔒

**Combine database and environment variables for maximum security:**

```php
// Environment/System Configuration (non-sensitive)
// /etc/environment or systemd service file
PAYMENT_ENCRYPTION_KEY=base64_encoded_32_byte_key
APP_ENVIRONMENT=production

// Database Configuration (sensitive, encrypted)
class PaymentGateway {
    public function __construct() {
        // Get encrypted keys from database
        $this->publicKey = SecurePaymentConfig::get('paystack_public_key');
        $this->secretKey = SecurePaymentConfig::get('paystack_secret_key');
        $this->environment = $_ENV['APP_ENVIRONMENT'] ?? 'sandbox';
    }
}
```

### 4.4 Security Implementation Checklist

#### Database Security
- [ ] **Encrypt sensitive configuration values** using AES-256-CBC
- [ ] **Store encryption key outside web root** or in environment variables
- [ ] **Implement database access logging** for all config operations
- [ ] **Use proper database permissions** (app user can SELECT only)
- [ ] **Regular key rotation** for payment gateway credentials
- [ ] **Encrypted database backups** for production systems

#### Access Control
```sql
-- Restrict database access
CREATE USER 'payment_app'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, UPDATE ON school_system.PaymentConfig TO 'payment_app'@'localhost';
GRANT INSERT ON school_system.PaymentTransactions TO 'payment_app'@'localhost';
REVOKE ALL ON school_system.PaymentConfig FROM 'public';
```

#### Runtime Security
```php
// Clear sensitive data from memory
register_shutdown_function(function() {
    SecurePaymentConfig::clearCache();
});

// Validate configuration integrity
public static function validateConfig() {
    $required = ['paystack_secret_key', 'paystack_public_key'];
    foreach ($required as $key) {
        if (empty(self::get($key))) {
            throw new Exception("Missing required payment config: $key");
        }
    }
}
```

#### Admin Interface Security
```php
// admin/payment-settings.php
if (!hasPermission('manage_payment_settings')) {
    redirectWithMessage('/admin', 'Access denied', 'error');
}

// Mask sensitive values in UI
function maskConfigValue($key, $value) {
    $sensitiveKeys = ['secret_key', 'api_key', 'private_key'];
    foreach ($sensitiveKeys as $sensitive) {
        if (strpos($key, $sensitive) !== false) {
            return substr($value, 0, 6) . '***' . substr($value, -4);
        }
    }
    return $value;
}
```

---

## 5. Implementation Architecture

### 4.1 Core Payment Classes

#### PaymentService Class
```php
// includes/PaymentService.php
class PaymentService {
    public static function getServicePrice($serviceType) {}
    public static function canUserAccessService($userId, $serviceType, $referenceId = null) {}
    public static function createPaymentRequest($userId, $serviceType, $referenceId = null) {}
    public static function processPaymentCallback($reference, $status) {}
    public static function grantServiceAccess($userId, $serviceType, $transactionId, $referenceId = null) {}
}
```

#### Service-Specific Handlers
```php
// includes/PaymentHandlers.php
class PasswordResetPayment {
    public static function requiresPayment($userId) {}
    public static function processReset($userId, $transactionId) {}
}

class AssessmentReviewPayment {
    public static function requiresPayment($userId, $assessmentId) {}
    public static function grantAccess($userId, $assessmentId, $hours = 24) {}
}

class AssessmentRetakePayment {
    public static function requiresPayment($userId, $assessmentId) {}
    public static function grantRetake($userId, $assessmentId) {}
}
```

### 4.2 User Interface Integration

#### Payment Modal Component
```html
<!-- payment-modal.php -->
<div class="payment-modal" id="paymentModal">
    <div class="modal-content">
        <h3>Complete Payment</h3>
        <div class="service-info">
            <span class="service-name">Password Reset</span>
            <span class="amount">GHS 5.00</span>
        </div>
        <div class="payment-methods">
            <button onclick="payWithMobileMoney()">Mobile Money</button>
            <button onclick="payWithCard()">Card Payment</button>
        </div>
    </div>
</div>
```

#### Integration Points
1. **Password Reset Page**: Show payment option when reset is requested
2. **Assessment Results**: Show "Pay to Review" button for detailed feedback
3. **Failed Assessment Page**: Show "Pay to Retake" option
4. **Student Dashboard**: Payment history and active services

### 4.3 Admin Interface

#### Payment Management Dashboard
- View all transactions
- Manual payment verification
- Refund processing
- Service pricing management
- Payment analytics

#### Admin Features
```php
// admin/payments.php
- Transaction listing with filters
- Payment status overview
- Manual service grants (for exceptions)
- Pricing configuration
- Gateway settings
- Revenue reports
```

---

## 5. Security Considerations

### 5.1 Payment Security
- **Webhook signature verification** - Verify all payment callbacks
- **Transaction reconciliation** - Regular checks against gateway records
- **Double payment prevention** - Check for duplicate transactions
- **Amount validation** - Verify payment amounts match service pricing
- **Reference uniqueness** - Ensure transaction references are unique

### 5.2 Service Access Security
- **Time-based expiry** - Services expire after set periods
- **Usage tracking** - Prevent multiple uses of single payment
- **User verification** - Ensure user owns the payment
- **Admin override logging** - Track manual service grants

### 5.3 Data Protection
- **PCI compliance** - Never store card details
- **Encrypted storage** - Encrypt sensitive payment data
- **Access logging** - Log all payment-related access
- **Audit trails** - Complete transaction history

---

## 6. Implementation Phases

### Phase 1: Core Infrastructure (Week 1-2)
- [ ] Database schema implementation
- [ ] Payment service classes
- [ ] Basic Paystack integration
- [ ] Admin payment settings

### Phase 2: Service Integration (Week 3-4)
- [ ] Password reset payment flow
- [ ] Assessment review payment
- [ ] Assessment retake payment
- [ ] User interface components

### Phase 3: Advanced Features (Week 5-6)
- [ ] Mobile money direct integration
- [ ] Payment analytics dashboard
- [ ] Automated reconciliation
- [ ] Email/SMS notifications

### Phase 4: Testing & Deployment (Week 7-8)
- [ ] Payment flow testing
- [ ] Security audit
- [ ] Performance optimization
- [ ] Production deployment

---

## 7. Configuration Management

### 7.1 Environment Variables
```php
// .env or config
PAYMENT_ENABLED=true
PAYMENT_GATEWAY=paystack
PAYSTACK_PUBLIC_KEY=pk_live_xxxxx
PAYSTACK_SECRET_KEY=sk_live_xxxxx
PAYMENT_CURRENCY=GHS
PAYMENT_TIMEOUT=900 // 15 minutes
```

### 7.2 Admin Settings Interface
- Enable/disable payment system
- Configure service pricing
- Set payment gateway credentials
- Manage service expiry times
- Configure notification settings

---

## 8. Testing Strategy

### 8.1 Payment Testing
- **Sandbox testing** - Use gateway test environments
- **Edge cases** - Failed payments, timeouts, network issues
- **Security testing** - Webhook signature validation, CSRF protection
- **User experience** - Complete payment flows for each service

### 8.2 Service Testing
- **Access control** - Verify paid services are properly gated
- **Expiry handling** - Test service expiration logic
- **Usage tracking** - Ensure services can't be used multiple times
- **Admin overrides** - Test manual service grants

---

## 9. Monitoring & Analytics

### 9.1 Key Metrics
- **Revenue tracking** - Daily/monthly payment volumes
- **Service popularity** - Most purchased services
- **Conversion rates** - Payment completion rates
- **User behavior** - Service usage patterns

### 9.2 Alerting
- **Failed payments** - High failure rate alerts
- **Webhook failures** - Payment processing issues
- **Service abuse** - Unusual usage patterns
- **Revenue anomalies** - Unexpected payment volumes

---

## 10. Support & Maintenance

### 10.1 Customer Support
- **Payment help desk** - Dedicated support for payment issues
- **Refund procedures** - Clear refund policy and process
- **Technical support** - Payment gateway troubleshooting
- **Service access issues** - Help with service activation

### 10.2 Maintenance Tasks
- **Daily reconciliation** - Match payments with gateway records
- **Weekly reporting** - Revenue and usage reports
- **Monthly audit** - Complete payment system review
- **Quarterly updates** - Gateway integration updates

---

## 11. Legal & Compliance

### 11.1 Ghana Regulations
- **Payment service licensing** - Ensure compliance with Bank of Ghana regulations
- **Tax obligations** - VAT/GeTFund levy on digital services
- **Consumer protection** - Clear terms and refund policies
- **Data protection** - Comply with Ghana Data Protection Act

### 11.2 Terms & Conditions
- **Service descriptions** - Clear explanation of paid services
- **Refund policy** - Conditions for refunds
- **Usage terms** - Service limitations and expiry
- **Privacy policy** - Payment data handling

---

## 12. Cost Estimates

### 12.1 Development Costs
- **Backend development**: 40-60 hours
- **Frontend integration**: 20-30 hours
- **Testing & QA**: 15-20 hours
- **Documentation**: 10-15 hours
- **Total**: 85-125 hours

### 12.2 Operational Costs
- **Payment gateway fees**: 1.5-3.5% per transaction
- **SMS notifications**: GHS 0.05 per message
- **Server resources**: Minimal additional cost
- **Support overhead**: 2-4 hours/week

### 12.3 Revenue Projections
Assuming 500 students:
- **Password resets**: 20/month × GHS 5 = GHS 100
- **Assessment reviews**: 50/month × GHS 3 = GHS 150  
- **Assessment retakes**: 15/month × GHS 15 = GHS 225
- **Monthly potential**: GHS 475
- **Annual potential**: GHS 5,700

---

## Conclusion

This payment system implementation will add significant value to the school management system by:

1. **Generating revenue** from premium services
2. **Reducing administrative overhead** for password resets
3. **Enhancing learning** through paid review access
4. **Providing second chances** through paid retakes
5. **Improving system sustainability** through diversified income

The modular design allows for gradual implementation and easy expansion to additional paid services in the future.

---

**Document Version**: 1.0  
**Last Updated**: July 24, 2025  
**Author**: System Documentation  
**Status**: Implementation Ready