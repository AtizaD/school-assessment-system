<?php
// admin/verify_manual_payment.php - Manual payment verification tool
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(__FILE__)));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = sanitizeInput($_POST['reference'] ?? '');
    
    if (empty($reference)) {
        $error = 'Payment reference is required';
    } else {
        try {
            // Get Paystack secret key
            $db = DatabaseConfig::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $secretKey = $result ? $result['setting_value'] : '';
            
            if (empty($secretKey)) {
                throw new Exception('Paystack secret key not configured');
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
                throw new Exception('Payment verification failed. HTTP Code: ' . $httpCode);
            }
            
            $paymentData = json_decode($response, true);
            
            if (!$paymentData || !$paymentData['status']) {
                throw new Exception('Invalid payment verification response');
            }
            
            $transaction = $paymentData['data'];
            
            if ($transaction['status'] === 'success') {
                // Extract student ID from reference or metadata
                $studentId = null;
                if (isset($transaction['metadata']['student_id'])) {
                    $studentId = $transaction['metadata']['student_id'];
                } else {
                    // Try to extract from reference pattern: pay_timestamp_studentid
                    if (preg_match('/pay_\d+_(\d+)/', $reference, $matches)) {
                        $studentId = $matches[1];
                    }
                }
                
                if (!$studentId) {
                    throw new Exception('Could not determine student ID from payment reference');
                }
                
                // Check if payment already processed
                $stmt = $db->prepare("SELECT s.*, u.username FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.student_id = ?");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception('Student not found with ID: ' . $studentId);
                }
                
                if ($student['payment_status'] === 'paid') {
                    $message = "Payment already processed for student {$student['username']} (ID: {$studentId})";
                } else {
                    // Update payment status
                    $amount = $transaction['amount'] / 100; // Convert pesewas to cedis
                    
                    $stmt = $db->prepare("UPDATE students SET 
                        payment_status = 'paid',
                        payment_date = NOW(),
                        payment_reference = ?,
                        payment_amount = ?
                        WHERE student_id = ?");
                    
                    $stmt->execute([$reference, $amount, $studentId]);
                    
                    // Log the manual verification
                    logSystemActivity(
                        'Payment',
                        "Manual payment verification successful for student {$student['username']} (ID: {$studentId}), Reference: $reference, Amount: GHS $amount",
                        'INFO',
                        $_SESSION['user_id']
                    );
                    
                    $message = "Payment successfully verified and processed for student {$student['username']} (ID: {$studentId}). Amount: GHS $amount";
                }
            } else {
                $error = "Payment verification failed. Status: {$transaction['status']}";
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            logError("Manual payment verification error: " . $error);
        }
    }
}

$pageTitle = 'Manual Payment Verification';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-search"></i> Manual Payment Verification</h5>
                    <p class="mb-0 text-muted">Verify and process payments manually using Paystack reference</p>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="row g-3">
                        <div class="col-md-8">
                            <label for="reference" class="form-label">Payment Reference</label>
                            <input type="text" class="form-control" id="reference" name="reference" 
                                   placeholder="pay_1234567890_123" required>
                            <div class="form-text">Enter the Paystack payment reference to verify</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Verify Payment
                            </button>
                        </div>
                    </form>
                    
                    <hr>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> How to use:</h6>
                        <ol class="mb-0">
                            <li>Get the payment reference from system logs or student report</li>
                            <li>Enter the reference above and click "Verify Payment"</li>
                            <li>System will check with Paystack and update student status if payment was successful</li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle"></i> Important:</h6>
                        <ul class="mb-0">
                            <li>Only use this for legitimate payments that failed to process automatically</li>
                            <li>This tool verifies payments directly with Paystack</li>
                            <li>All verifications are logged for audit purposes</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>