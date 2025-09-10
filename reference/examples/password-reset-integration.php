<?php
/**
 * Password Reset Integration Example
 * Shows how to integrate payment option into password reset page
 * 
 * @author School Management System
 * @date July 24, 2025
 */

session_start();
require_once '../includes/functions.php';
require_once '../includes/PaymentHandlers.php';

// This would be integrated into your existing reset-password.php or forgot-password.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - School System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-key mr-2"></i>Reset Password
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <?php
                            $userId = $_SESSION['user_id'];
                            $requiresPayment = PasswordResetPayment::requiresPayment($userId);
                            $pricing = PasswordResetPayment::getPricing();
                            ?>
                            
                            <?php if ($requiresPayment): ?>
                                <!-- Payment Required Section -->
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Payment Required</strong><br>
                                    To prevent abuse, password reset requires a small fee of 
                                    <strong><?= PaymentHandlerUtils::formatCurrency($pricing['amount'], $pricing['currency']) ?></strong>
                                </div>
                                
                                <div class="text-center mb-4">
                                    <button type="button" class="btn btn-primary btn-lg" onclick="initiatePasswordResetPayment()">
                                        <i class="fas fa-credit-card mr-2"></i>
                                        Pay <?= PaymentHandlerUtils::formatCurrency($pricing['amount'], $pricing['currency']) ?> to Reset Password
                                    </button>
                                </div>
                                
                                <div class="text-center">
                                    <small class="text-muted">
                                        Secure payment processed by Paystack<br>
                                        After payment, you can immediately reset your password
                                    </small>
                                </div>
                                
                            <?php else: ?>
                                <!-- Free Password Reset Form -->
                                <form id="passwordResetForm" method="POST">
                                    <div class="form-group">
                                        <label for="newPassword">New Password</label>
                                        <input type="password" class="form-control" id="newPassword" name="new_password" required>
                                        <small class="form-text text-muted">Password must be at least 8 characters long</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="confirmPassword">Confirm Password</label>
                                        <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-success btn-block">
                                        <i class="fas fa-check mr-2"></i>Reset Password
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- Login Required -->
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Please log in to reset your password.
                            </div>
                            <a href="../login.php" class="btn btn-primary btn-block">
                                <i class="fas fa-sign-in-alt mr-2"></i>Login
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Include payment modal -->
    <?php include '../includes/payment-modal.php'; ?>
    
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.min.js"></script>
    
    <script>
    function initiatePasswordResetPayment() {
        showPaymentModal(
            'password_reset',
            'Password Reset Service',
            'Reset your forgotten password instantly with admin assistance',
            <?= $pricing['amount'] ?? 0 ?>,
            '<?= $pricing['currency'] ?? 'GHS' ?>',
            null,
            'One-time payment for immediate password reset access'
        );
    }
    
    // Handle password reset form submission
    $('#passwordResetForm').on('submit', function(e) {
        e.preventDefault();
        
        const newPassword = $('#newPassword').val();
        const confirmPassword = $('#confirmPassword').val();
        
        if (newPassword !== confirmPassword) {
            alert('Passwords do not match');
            return;
        }
        
        if (newPassword.length < 8) {
            alert('Password must be at least 8 characters long');
            return;
        }
        
        // Submit form
        const formData = new FormData();
        formData.append('new_password', newPassword);
        formData.append('action', 'reset_password');
        
        fetch('../api/password-reset-handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Password reset successfully!');
                window.location.href = '../dashboard.php';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while resetting password');
        });
    });
    </script>
</body>
</html>