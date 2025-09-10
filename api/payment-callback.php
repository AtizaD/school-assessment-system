<?php
/**
 * Payment Callback Handler
 * Handles payment gateway callbacks and redirects
 * 
 * @author School Management System
 * @date July 24, 2025
 */

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/PaymentService.php';
require_once '../includes/ExpressPayGateway.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$reference = $_GET['reference'] ?? '';
$error = null;
$success = null;
$paymentDetails = null;

if (empty($reference)) {
    $error = "No payment reference provided";
} else {
    try {
        // Get transaction details
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM PaymentTransactions 
             WHERE reference_id = ? AND user_id = ?"
        );
        $stmt->execute([$reference, $_SESSION['user_id']]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Verify payment with gateway if not already completed
        if ($transaction['status'] === 'pending') {
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
                
                $result = PaymentService::processPaymentCallback($reference, 'completed', $gatewayData);
                
                if ($result) {
                    $success = "Payment completed successfully!";
                    $paymentDetails = [
                        'service_type' => $transaction['service_type'],
                        'amount' => $verification['amount'],
                        'currency' => $verification['currency'],
                        'assessment_id' => $transaction['assessment_id']
                    ];
                } else {
                    throw new Exception('Payment processing failed');
                }
            } else {
                throw new Exception('Payment verification failed');
            }
        } else {
            // Transaction already processed
            $success = "Payment already processed";
            $paymentDetails = [
                'service_type' => $transaction['service_type'],
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'],
                'assessment_id' => $transaction['assessment_id']
            ];
        }
        
    } catch (Exception $e) {
        logError("Payment callback error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Determine redirect URL based on service type
$redirectUrl = BASE_URL . '/student/index.php';
if ($paymentDetails) {
    switch ($paymentDetails['service_type']) {
        case 'password_reset':
            $redirectUrl = BASE_URL . '/student/reset-password.php';
            break;
        case 'review_assessment':
            if ($paymentDetails['assessment_id']) {
                $redirectUrl = BASE_URL . '/student/assessment-results.php?id=' . $paymentDetails['assessment_id'];
            }
            break;
        case 'retake_assessment':
            if ($paymentDetails['assessment_id']) {
                $redirectUrl = BASE_URL . '/student/take-assessment.php?id=' . $paymentDetails['assessment_id'];
            }
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - School System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/all.min.css" rel="stylesheet">
    <style>
        .status-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .status-card {
            max-width: 500px;
            width: 90%;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .status-icon {
            font-size: 4rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="status-container">
        <div class="card status-card">
            <div class="card-body text-center p-5">
                <?php if ($success): ?>
                    <!-- Success State -->
                    <div class="text-success mb-4">
                        <i class="fas fa-check-circle status-icon"></i>
                    </div>
                    <h2 class="text-success mb-3">Payment Successful!</h2>
                    <p class="text-muted mb-4"><?= htmlspecialchars($success) ?></p>
                    
                    <?php if ($paymentDetails): ?>
                        <div class="alert alert-info">
                            <h6>Service Activated:</h6>
                            <strong><?= ucwords(str_replace('_', ' ', $paymentDetails['service_type'])) ?></strong><br>
                            <small>
                                Amount: <?= htmlspecialchars($paymentDetails['currency']) ?> 
                                <?= number_format($paymentDetails['amount'], 2) ?>
                            </small>
                        </div>
                    <?php endif; ?>
                    
                    <button class="btn btn-success btn-lg" onclick="proceedToService()">
                        <i class="fas fa-arrow-right mr-2"></i>Continue
                    </button>
                    
                <?php else: ?>
                    <!-- Error State -->
                    <div class="text-danger mb-4">
                        <i class="fas fa-times-circle status-icon"></i>
                    </div>
                    <h2 class="text-danger mb-3">Payment Failed</h2>
                    <p class="text-muted mb-4">
                        <?= htmlspecialchars($error ?: 'An error occurred while processing your payment') ?>
                    </p>
                    
                    <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-primary" onclick="retryPayment()">
                            <i class="fas fa-redo mr-2"></i>Try Again
                        </button>
                        <button class="btn btn-secondary" onclick="goToDashboard()">
                            <i class="fas fa-home mr-2"></i>Dashboard
                        </button>
                    </div>
                <?php endif; ?>
                
                <div class="mt-4 pt-3 border-top">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Your payment is secure and encrypted
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script>
    function proceedToService() {
        window.location.href = '<?= $redirectUrl ?>';
    }
    
    function retryPayment() {
        window.history.back();
    }
    
    function goToDashboard() {
        window.location.href = '<?= BASE_URL ?>/student/index.php';
    }
    
    // Auto-redirect after 5 seconds if successful
    <?php if ($success): ?>
    setTimeout(() => {
        proceedToService();
    }, 5000);
    <?php endif; ?>
    </script>
</body>
</html>