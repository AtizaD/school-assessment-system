<?php
// payment.php

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__FILE__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/payment_check.php';

// Set CSP headers to allow Paystack
header("Content-Security-Policy: script-src 'self' 'unsafe-inline' https://js.paystack.co https://checkout.paystack.com; frame-src https://checkout.paystack.com; connect-src 'self' https://api.paystack.co https://checkout.paystack.com");

// Only students can access this page
requireRole('student');

// Initialize variables
$student = null;
$error = '';

// Get student information
try {
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT s.*, u.email, u.username FROM students s 
                         JOIN users u ON s.user_id = u.user_id 
                         WHERE s.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        // Debug: Check if user exists and what role they have
        $debugStmt = $db->prepare("SELECT user_id, username, role FROM users WHERE user_id = ?");
        $debugStmt->execute([$_SESSION['user_id']]);
        $userInfo = $debugStmt->fetch(PDO::FETCH_ASSOC);
        
        $debugInfo = $userInfo ? 
            "User exists - ID: {$userInfo['user_id']}, Username: {$userInfo['username']}, Role: {$userInfo['role']}" :
            "User not found in database";
            
        throw new Exception("Student record not found for user ID: {$_SESSION['user_id']}. Debug: $debugInfo");
    }
    
    // If already paid, redirect to dashboard
    if ($student['payment_status'] === 'paid') {
        header('Location: /student/index.php');
        exit;
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Payment page error: " . $error);
    
    // If student not found, show error and stop
    if (!$student) {
        $pageTitle = 'Payment Error';
        require_once INCLUDES_PATH . '/bass/base_header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger"><h4>Error</h4><p>' . htmlspecialchars($error) . '</p></div></div>';
        require_once INCLUDES_PATH . '/bass/base_footer.php';
        exit;
    }
}

// Use username-based email for payments (sanitized for valid email format)
$localPart = preg_replace('/[^A-Za-z0-9._]/', '', $student['username']);
$student['email'] = $localPart . '@students.vc.bassflix.xyz';

$paymentAmount = getPaymentAmount();
$paystackPublicKey = getPaystackPublicKey();
$amountInCedis = $paymentAmount / 100; // Convert pesewas to cedis

$pageTitle = 'Payment Required';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<!-- Add CSP meta tag for Paystack -->
<meta http-equiv="Content-Security-Policy" content="script-src 'self' 'unsafe-inline' https://js.paystack.co https://checkout.paystack.com; frame-src 'self' https://checkout.paystack.com; connect-src 'self' https://api.paystack.co https://checkout.paystack.com;">

<style>
.payment-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 10px;
}

.payment-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    overflow: hidden;
    width: 100%;
    max-width: 400px;
}

.payment-header {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
    padding: 1rem;
    text-align: center;
}

.payment-header h2 {
    font-size: 1.3rem;
    margin: 0.3rem 0 0 0;
}

.payment-header p {
    font-size: 0.9rem;
    margin: 0;
    opacity: 0.9;
}

.payment-body {
    padding: 1rem;
}

.disclaimer {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 0.6rem;
    margin-bottom: 0.8rem;
    font-size: 0.8rem;
    line-height: 1.3;
}

.disclaimer-title {
    font-weight: bold;
    color: #856404;
    margin-bottom: 0.2rem;
    font-size: 0.8rem;
}

.amount-display {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 0.8rem;
    text-align: center;
    margin: 0.8rem 0;
    border: 2px solid #e9ecef;
}

.amount-value {
    font-size: 1.6rem;
    font-weight: bold;
    color: #28a745;
    margin-bottom: 0.2rem;
}

.pay-button {
    background: linear-gradient(135deg, #28a745, #20c997);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    width: 100%;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.pay-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
}

.pay-button:disabled {
    background: #6c757d;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.payment-info {
    margin-top: 0.8rem;
    padding: 0.6rem;
    background: #e8f4fd;
    border-radius: 6px;
    border-left: 3px solid #0066cc;
    font-size: 0.8rem;
}


/* Mobile Responsive */
@media (max-width: 480px) {
    .payment-container {
        padding: 8px;
        align-items: flex-start;
        padding-top: 1rem;
    }
    
    .payment-card {
        max-width: 100%;
    }
    
    .payment-header {
        padding: 0.8rem;
    }
    
    .payment-header h2 {
        font-size: 1.1rem;
    }
    
    .payment-body {
        padding: 0.8rem;
    }
    
    .amount-value {
        font-size: 1.4rem;
    }
    
    .disclaimer {
        font-size: 0.75rem;
        padding: 0.5rem;
    }
    
    
    .pay-button {
        padding: 12px 20px;
        font-size: 0.9rem;
    }
}

@media (max-width: 360px) {
    .payment-header {
        padding: 0.6rem;
    }
    
    .payment-body {
        padding: 0.6rem;
    }
    
    .amount-value {
        font-size: 1.3rem;
    }
    
    .disclaimer {
        font-size: 0.7rem;
    }
}
</style>

<div class="payment-container">
    <div class="payment-card">
        <div class="payment-header">
            <h2><i class="fas fa-credit-card"></i> Payment Required</h2>
            <p class="mb-0">One-time server management fee</p>
        </div>
        
        <div class="payment-body">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>
            
            <div class="disclaimer">
                <div class="disclaimer-title">
                    <i class="fas fa-exclamation-triangle"></i> Private Site Notice
                </div>
                <p class="mb-0">
                    This is a private website not affiliated with any institution. Payment covers hosting costs only.
                </p>
            </div>
            
            <div class="amount-display">
                <div class="amount-value">₵<?php echo number_format($amountInCedis, 2); ?></div>
                <small class="text-muted">One-time payment</small>
            </div>
            
            <div class="payment-info">
                <p class="mb-0"><i class="fas fa-shield-alt"></i> Secure payment powered by Paystack. Instant access after payment.</p>
            </div>
            
            <?php if (!empty($paystackPublicKey)): ?>
                <!-- Server-side payment initialization to bypass CSP -->
                <a href="/initiate_payment.php" class="pay-button" style="display: block; text-decoration: none; text-align: center;">
                    <i class="fas fa-lock"></i> Pay Securely with Paystack
                </a>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-cog"></i> <strong>Payment system not configured.</strong><br>
                    The administrator needs to set up Paystack keys in the system settings.
                </div>
                <div class="alert alert-info">
                    <strong>For Administrator:</strong><br>
                    Go to Admin Settings → Payment System Configuration and enter your Paystack public and secret keys.
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>