# Payment System Setup Guide

## Quick Setup Instructions for School Management System Payment Integration

### Prerequisites
- XAMPP/WAMP with PHP 7.4+
- MySQL database access
- Existing school management system
- Paystack account (for payment processing)

### Step 1: Database Setup

1. **Run the database scripts in order:**
   ```sql
   -- First, create the new payment tables
   SOURCE database/payment_system_tables.sql;
   
   -- Then, modify existing tables
   SOURCE database/modify_existing_tables.sql;
   ```

2. **Verify tables were created:**
   ```sql
   SHOW TABLES LIKE '%Payment%';
   SHOW TABLES LIKE '%Service%';
   ```

### Step 2: File Integration

1. **Copy payment files to your system:**
   - Copy `includes/SecurePaymentConfig.php`
   - Copy `includes/PaymentService.php`
   - Copy `includes/PaymentHandlers.php`
   - Copy `includes/PaystackGateway.php`
   - Copy `includes/payment-modal.php`
   - Copy `api/payment-handler.php`
   - Copy `api/payment-webhook.php`
   - Copy `api/admin-payment-handler.php`

2. **Create cache directory for encryption keys:**
   ```bash
   mkdir cache
   chmod 755 cache
   ```

### Step 3: Paystack Configuration

1. **Get Paystack API keys:**
   - Sign up at https://paystack.com
   - Get your Test/Live Public and Secret keys
   - Set up webhook URL: `your-domain.com/api/payment-webhook.php`

2. **Configure payment settings in database:**
   ```sql
   INSERT INTO PaymentConfig (config_key, config_value, is_encrypted) VALUES 
   ('paystack_public_key', 'pk_test_your_public_key', FALSE),
   ('paystack_secret_key', 'sk_test_your_secret_key', TRUE),
   ('payment_enabled', '1', FALSE);
   ```

### Step 4: Integration Examples

#### Password Reset Integration
Add to your existing password reset page:

```php
<?php
require_once 'includes/PaymentHandlers.php';

if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $requiresPayment = PasswordResetPayment::requiresPayment($userId);
    $pricing = PasswordResetPayment::getPricing();
    
    if ($requiresPayment) {
        // Show payment button
        echo '<button onclick="showPaymentModal(\'password_reset\', \'Password Reset\', \'Reset your password\', ' . $pricing['amount'] . ')">Pay to Reset Password</button>';
    } else {
        // Show regular reset form
    }
}

// Include payment modal
include 'includes/payment-modal.php';
?>
```

#### Assessment Results Integration
Add to your assessment results page:

```php
<?php
require_once 'includes/PaymentHandlers.php';

$assessmentId = $_GET['id'];
$userId = $_SESSION['user_id'];

$reviewRequiresPayment = AssessmentReviewPayment::requiresPayment($userId, $assessmentId);
$retakeRequiresPayment = AssessmentRetakePayment::requiresPayment($userId, $assessmentId);

if ($reviewRequiresPayment) {
    $pricing = AssessmentReviewPayment::getPricing();
    echo '<button onclick="showPaymentModal(\'review_assessment\', \'Assessment Review\', \'Detailed feedback\', ' . $pricing['amount'] . ', \'GHS\', ' . $assessmentId . ')">Pay to Review</button>';
}

if ($retakeRequiresPayment) {
    $pricing = AssessmentRetakePayment::getPricing();
    echo '<button onclick="showPaymentModal(\'retake_assessment\', \'Assessment Retake\', \'Another attempt\', ' . $pricing['amount'] . ', \'GHS\', ' . $assessmentId . ')">Pay to Retake</button>';
}

include 'includes/payment-modal.php';
?>
```

### Step 5: Admin Dashboard Integration

1. **Add to admin navigation:**
   ```php
   <li class="nav-item">
       <a href="admin/payment-dashboard.php" class="nav-link">
           <i class="fas fa-credit-card"></i> Payments
       </a>
   </li>
   ```

2. **Copy admin files:**
   - Copy `admin/payment-dashboard.php`

### Step 6: Security Configuration

1. **Set up encryption key (choose one method):**
   
   **Method A: Environment Variable (Recommended)**
   ```bash
   export PAYMENT_ENCRYPTION_KEY=$(openssl rand -base64 32)
   ```
   
   **Method B: Secure File**
   ```bash
   openssl rand -base64 32 > /var/www/secure/payment.key
   chmod 600 /var/www/secure/payment.key
   ```

2. **Configure webhook security:**
   - Set webhook URL in Paystack dashboard
   - Ensure webhook endpoint is accessible: `your-domain.com/api/payment-webhook.php`

### Step 7: Testing

1. **Enable test mode:**
   ```sql
   UPDATE PaymentConfig SET config_value = 'pk_test_your_test_key' WHERE config_key = 'paystack_public_key';
   UPDATE PaymentConfig SET config_value = 'ENCRYPTED_TEST_SECRET' WHERE config_key = 'paystack_secret_key';
   ```

2. **Test payment flow:**
   - Try password reset payment with test user
   - Use Paystack test card: 4084084084084081
   - Verify payment completion and service activation

3. **Test admin functions:**
   - View payment dashboard
   - Check transaction details
   - Test manual service grants

### Step 8: Production Deployment

1. **Switch to live keys:**
   ```sql
   UPDATE PaymentConfig SET config_value = 'pk_live_your_live_key' WHERE config_key = 'paystack_public_key';
   UPDATE PaymentConfig SET config_value = 'ENCRYPTED_LIVE_SECRET' WHERE config_key = 'paystack_secret_key';
   ```

2. **Update webhook URL to production:**
   - Update in Paystack dashboard
   - Test webhook delivery

3. **Set proper pricing:**
   ```sql
   UPDATE ServicePricing SET amount = 5.00 WHERE service_type = 'password_reset';
   UPDATE ServicePricing SET amount = 3.00 WHERE service_type = 'review_assessment';
   UPDATE ServicePricing SET amount = 15.00 WHERE service_type = 'retake_assessment';
   ```

### Troubleshooting

#### Common Issues:

**1. Payment modal not showing:**
- Check if jQuery and Bootstrap are loaded
- Verify payment-modal.php is included
- Check browser console for JavaScript errors

**2. Payment verification failing:**
- Verify webhook URL is accessible
- Check Paystack webhook logs
- Ensure secret key is properly encrypted

**3. Service access not granted:**
- Check PaymentService::processPaymentCallback logs
- Verify database transactions are completing
- Check PaidServices table for granted access

**4. Admin dashboard errors:**
- Verify user has admin role
- Check database permissions
- Ensure all required files are uploaded

#### Log Files to Check:
- `logs/system.log` - General system logs
- `logs/payment.log` - Payment-specific logs
- `logs/error.log` - PHP errors

### Configuration Options

#### Service Pricing:
```sql
-- Update pricing anytime
UPDATE ServicePricing SET amount = 10.00 WHERE service_type = 'password_reset';
```

#### System Settings:
```sql
-- Enable/disable payment system
UPDATE PaymentConfig SET config_value = '1' WHERE config_key = 'payment_enabled';

-- Change review access duration
UPDATE PaymentConfig SET config_value = '48' WHERE config_key = 'review_access_hours';

-- Change payment timeout
UPDATE PaymentConfig SET config_value = '30' WHERE config_key = 'payment_timeout_minutes';
```

### Support

For technical support or questions:
1. Check the logs for detailed error messages
2. Verify database table structure matches the schema
3. Test with Paystack test environment first
4. Review webhook payload logs in admin dashboard

### Security Best Practices

1. **Always encrypt sensitive configuration:**
   ```php
   SecurePaymentConfig::set('paystack_secret_key', $secretKey, true, $adminUserId);
   ```

2. **Regular key rotation:**
   - Rotate Paystack keys periodically
   - Update encryption keys
   - Audit access logs

3. **Monitor transactions:**
   - Set up alerts for failed payments
   - Review refund requests
   - Monitor unusual payment patterns

4. **Backup considerations:**
   - Encrypt database backups
   - Secure webhook logs
   - Protect payment configuration

---

## Quick Start Checklist

- [ ] Database tables created
- [ ] PHP files uploaded
- [ ] Paystack account configured
- [ ] Webhook URL set
- [ ] Test payment completed
- [ ] Admin dashboard accessible
- [ ] Production keys configured
- [ ] Pricing set appropriately
- [ ] Security measures implemented
- [ ] Backup procedures established

**Estimated Setup Time:** 2-4 hours
**Prerequisites Time:** 1 hour (Paystack signup, API keys)
**Testing Time:** 1 hour
**Total Time:** 4-6 hours

This payment system will generate revenue while providing valuable services to students and reducing administrative overhead.