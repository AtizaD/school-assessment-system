<?php
// verify_payment.php
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(__FILE__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/payment_check.php';

// Only students can access this page
requireRole('student');

$success = false;
$error = '';
$reference = $_GET['reference'] ?? '';

if (empty($reference)) {
    $error = 'Invalid payment reference';
} else {
    try {
        // Get Paystack secret key
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $secretKey = $result ? $result['setting_value'] : '';
        
        if (empty($secretKey)) {
            throw new Exception('Payment verification system not configured');
        }
        
        // Verify payment with Paystack
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $secretKey,
                "Cache-Control: no-cache",
            ),
        ));
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode !== 200) {
            throw new Exception('Payment verification failed. Please try again.');
        }
        
        $paymentData = json_decode($response, true);
        
        if (!$paymentData || !$paymentData['status']) {
            throw new Exception('Invalid payment verification response');
        }
        
        $transaction = $paymentData['data'];
        
        // Check if payment was successful
        if ($transaction['status'] !== 'success') {
            throw new Exception('Payment was not successful. Status: ' . $transaction['status']);
        }
        
        // Get student information
        $stmt = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception('Student record not found');
        }
        
        $studentId = $student['student_id'];
        
        // Check if this payment has already been processed
        $stmt = $db->prepare("SELECT student_id FROM students WHERE payment_reference = ? AND payment_status = 'paid'");
        $stmt->execute([$reference]);
        $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingPayment) {
            // Payment already processed
            $success = true;
        } else {
            // Update student payment status
            $db->beginTransaction();
            
            try {
                $stmt = $db->prepare("UPDATE students SET 
                    payment_status = 'paid',
                    payment_date = NOW(),
                    payment_reference = ?,
                    payment_amount = ?
                    WHERE student_id = ?");
                
                $amount = $transaction['amount'] / 100; // Convert pesewas to cedis for storage
                $stmt->execute([$reference, $amount, $studentId]);
                
                $db->commit();
                $success = true;
                
                // Log successful payment
                logSystemActivity(
                    'Payment',
                    "Payment successful for student ID: $studentId, Reference: $reference, Amount: GHS $amount",
                    'INFO',
                    $_SESSION['user_id']
                );
                
                // Auto-redirect to dashboard after 3 seconds
                header("refresh:3;url=/student/index.php");
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        logError("Payment verification error: " . $error);
        
        // Log failed verification attempt
        if (isset($studentId)) {
            logSystemActivity(
                'Payment',
                "Payment verification failed for student ID: $studentId, Reference: $reference, Error: $error",
                'WARNING',
                $_SESSION['user_id']
            );
        }
    }
}

$pageTitle = $success ? 'Payment Successful' : 'Payment Verification';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
.verification-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
}

.verification-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    overflow: hidden;
    max-width: 500px;
    width: 100%;
    text-align: center;
}

.success-header {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    padding: 2rem;
}

.error-header {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
    padding: 2rem;
}

.verification-body {
    padding: 2rem;
}

.success-icon, .error-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}

.success-icon {
    color: #28a745;
}

.error-icon {
    color: #dc3545;
}

.btn-primary {
    background: linear-gradient(135deg, #007bff, #0056b3);
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.btn-secondary {
    background: linear-gradient(135deg, #6c757d, #545b62);
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: white;
}

.payment-details {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
    text-align: left;
}
</style>

<div class="verification-container">
    <div class="verification-card">
        <?php if ($success): ?>
            <div class="success-header">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2>Payment Successful!</h2>
                <p class="mb-0">Welcome to the Assessment System</p>
            </div>
            
            <div class="verification-body">
                <div class="payment-details">
                    <h6><i class="fas fa-receipt"></i> Payment Details</h6>
                    <p><strong>Reference:</strong> <?php echo htmlspecialchars($reference); ?></p>
                    <p><strong>Status:</strong> <span class="text-success">Paid</span></p>
                    <p><strong>Date:</strong> <?php echo date('F j, Y g:i A'); ?></p>
                </div>
                
                <div class="alert alert-success">
                    <i class="fas fa-info-circle"></i>
                    Your payment has been confirmed. You now have full access to the assessment system.
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-clock"></i>
                    Redirecting to your dashboard in <span id="countdown">3</span> seconds...
                </div>
                
                <a href="/student/index.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-tachometer-alt"></i> Go to Dashboard Now
                </a>
                
                <script>
                let timeLeft = 3;
                const countdownElement = document.getElementById('countdown');
                
                const timer = setInterval(function() {
                    timeLeft--;
                    countdownElement.textContent = timeLeft;
                    
                    if (timeLeft <= 0) {
                        clearInterval(timer);
                        window.location.href = '/student/index.php';
                    }
                }, 1000);
                </script>
            </div>
            
        <?php else: ?>
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h2>Payment Verification Failed</h2>
                <p class="mb-0">Unable to verify your payment</p>
            </div>
            
            <div class="verification-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <div class="payment-details">
                    <h6><i class="fas fa-info-circle"></i> What to do next:</h6>
                    <ul class="text-left">
                        <li>Check your email for payment confirmation</li>
                        <li>Wait a few minutes and try refreshing this page</li>
                        <li>Contact support if the problem persists</li>
                    </ul>
                </div>
                
                <div class="d-flex gap-2 justify-content-center">
                    <a href="/payment.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Try Again
                    </a>
                    <button onclick="location.reload()" class="btn btn-secondary">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>