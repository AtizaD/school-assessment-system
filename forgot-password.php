<?php
/**
 * Forgot Password Page
 * Allows users to reset their forgotten passwords with payment
 * 
 * @author School Management System
 * @date July 25, 2025
 */

define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/PaymentHandlers.php';
require_once INCLUDES_PATH . '/PaymentService.php';

// Redirect if already logged in - use same logic as index.php
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'];
    switch ($role) {
        case 'admin':
            redirectTo('/admin/index.php');
            break;
        case 'teacher':
            redirectTo('/teacher/index.php');
            break;
        case 'student':
            redirectTo('/student/index.php');
            break;
        default:
            redirectTo('/index.php');
    }
}

$pageTitle = 'Forgot Password - BASS';
$error = null;
$success = null;
$step = 'identify'; // identify, verify, payment, reset

// Get payment pricing
$pricing = PaymentService::getServicePrice('password_reset');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($csrfToken) || !validateCSRFToken($csrfToken)) {
        $error = "Invalid security token. Please refresh the page and try again.";
        logSystemActivity('Security', 'CSRF token validation failed for forgot password', 'WARNING');
    } else {
        try {
            if (isset($_POST['action']) && $_POST['action'] === 'identify_user') {
                $username = trim($_POST['username'] ?? '');
                
                if (empty($username)) {
                    throw new Exception("Please enter your username");
                }
                
                // Check if user exists and get full name
                $db = DatabaseConfig::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT user_id, username, role FROM Users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    throw new Exception("Username not found");
                }
                
                // Get full name based on role
                $fullName = '';
                if ($user['role'] === 'student') {
                    $stmt = $db->prepare("SELECT first_name, last_name FROM Students WHERE user_id = ?");
                    $stmt->execute([$user['user_id']]);
                    $details = $stmt->fetch();
                    if ($details) {
                        $fullName = trim($details['first_name'] . ' ' . $details['last_name']);
                    }
                } elseif ($user['role'] === 'teacher') {
                    $stmt = $db->prepare("SELECT first_name, last_name FROM Teachers WHERE user_id = ?");
                    $stmt->execute([$user['user_id']]);
                    $details = $stmt->fetch();
                    if ($details) {
                        $fullName = trim($details['first_name'] . ' ' . $details['last_name']);
                    }
                } elseif ($user['role'] === 'admin') {
                    // For admin, try to get name from Users table or use username
                    $fullName = ucfirst($user['username']) . ' (Administrator)';
                }
                
                // Fallback if no name found
                if (empty($fullName)) {
                    $fullName = 'Name not available';
                }
                
                // Store user info in session for next steps
                $_SESSION['reset_user_id'] = $user['user_id'];
                $_SESSION['reset_username'] = $user['username'];
                $_SESSION['reset_user_role'] = $user['role'];
                $_SESSION['reset_user_fullname'] = $fullName;
                
                // Set flag to show modal instead of changing step
                $_SESSION['show_verification_modal'] = true;
                $success = "Account found! Please verify your information.";
                
            } elseif (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
                // This will be handled after payment completion
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($newPassword) || empty($confirmPassword)) {
                    throw new Exception("Please fill in all fields");
                }
                
                if ($newPassword !== $confirmPassword) {
                    throw new Exception("Passwords do not match");
                }
                
                if (strlen($newPassword) < 8) {
                    throw new Exception("Password must be at least 8 characters long");
                }
                
                // Check if user has paid for reset
                $userId = $_SESSION['reset_user_id'] ?? null;
                if (!$userId || !PaymentService::canUserAccessService($userId, 'password_reset')) {
                    throw new Exception("Payment required to reset password");
                }
                
                // Process password reset
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $db = DatabaseConfig::getInstance()->getConnection();
                $stmt = $db->prepare(
                    "UPDATE Users SET password_hash = ?, password_change_required = FALSE, 
                     last_password_change = NOW() WHERE user_id = ?"
                );
                
                if ($stmt->execute([$hashedPassword, $userId])) {
                    // Mark service as used
                    PaymentService::markServiceAsUsed($userId, 'password_reset');
                    
                    logSystemActivity('PasswordReset', 
                        "Password reset completed via payment for user $userId", 'INFO', $userId);
                    
                    // Clear reset session data
                    unset($_SESSION['reset_user_id'], $_SESSION['reset_username'], $_SESSION['reset_user_role'], 
                          $_SESSION['reset_user_fullname'], $_SESSION['account_confirmed'], $_SESSION['confirmed_at']);
                    
                    $success = "Password reset successfully! You can now login with your new password.";
                    $step = 'complete';
                } else {
                    throw new Exception("Failed to update password");
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Check current step based on session data and URL parameters
if (isset($_GET['step']) && $_GET['step'] === 'payment' && isset($_SESSION['reset_user_id'])) {
    // Only allow payment step if account has been confirmed
    if (isset($_SESSION['account_confirmed']) && $_SESSION['account_confirmed'] === true) {
        $step = 'payment';
    } else {
        // Redirect back to identify step if not confirmed
        $error = "Please verify your account information first.";
        $step = 'identify';
    }
} elseif (isset($_SESSION['reset_user_id'])) {
    // Always require account confirmation before proceeding to any step
    if (isset($_SESSION['account_confirmed']) && $_SESSION['account_confirmed'] === true) {
        // Account confirmed - check if payment is completed and usage limit not exceeded
        $hasPaymentAccess = PaymentService::canUserAccessService($_SESSION['reset_user_id'], 'password_reset');
        
        // If no access through PaidServices, check for recent completed transactions
        // but only if there's no existing service record (to handle timing issues)
        if (!$hasPaymentAccess) {
            try {
                $db = DatabaseConfig::getInstance()->getConnection();
                
                // First, check if user has any PaidServices record for password_reset
                $existingServiceStmt = $db->prepare(
                    "SELECT COUNT(*) FROM PaidServices 
                     WHERE user_id = ? AND service_type = 'password_reset' AND is_active = TRUE"
                );
                $existingServiceStmt->execute([$_SESSION['reset_user_id']]);
                $hasExistingService = $existingServiceStmt->fetchColumn() > 0;
                
                // Only check recent transactions if no existing service record
                if (!$hasExistingService) {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM PaymentTransactions 
                         WHERE user_id = ? AND service_type = 'password_reset' 
                         AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                    );
                    $stmt->execute([$_SESSION['reset_user_id']]);
                    $hasRecentCompletedPayment = $stmt->fetchColumn() > 0;
                    
                    if ($hasRecentCompletedPayment) {
                        $hasPaymentAccess = true;
                    }
                }
            } catch (Exception $e) {
                logError("Error checking recent payments: " . $e->getMessage());
            }
        }
        
        // Debug logging
        logSystemActivity('PasswordResetStep', 
            "Payment access check for user {$_SESSION['reset_user_id']}: " . ($hasPaymentAccess ? 'YES' : 'NO'), 
            'DEBUG', $_SESSION['reset_user_id']);
        
        if ($hasPaymentAccess) {
            $step = 'reset';
        } else {
            $step = 'payment';
        }
    } else {
        // User identified but not confirmed - stay on identify step to show modal
        $step = 'identify';
    }
}

// Handle payment completion redirect
if (isset($_GET['payment_complete']) && isset($_SESSION['reset_user_id'])) {
    // Always require account confirmation even after payment completion
    if (isset($_SESSION['account_confirmed']) && $_SESSION['account_confirmed'] === true) {
        // Re-check if user now has access after payment
        if (PaymentService::canUserAccessService($_SESSION['reset_user_id'], 'password_reset')) {
            $step = 'reset';
        } else {
            $step = 'payment';
        }
    } else {
        // Not confirmed - redirect back to identify step
        $error = "Please verify your account information first.";
        $step = 'identify';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap CSS for modal functionality -->
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Base styling */
        :root {
            --primary: #FFD700;
            --primary-light: #FFE5A8;
            --primary-dark: #CCB000;
            --black: #000000;
            --white: #FFFFFF;
            --text-dark: #333333;
            --text-light: #FFFFFF;
            --error: #dc2626;
            --error-light: #fee2e2;
            --success: #16a34a;
            --success-light: #dcfce7;
        }

        /* Reset and basic styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            padding: 20px;
            position: relative;
            background-color: #222; /* Fallback color */
        }

        /* Background styling */
        .background-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .background-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .background-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.7) 100%);
        }

        /* Reset container */
        .reset-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            text-align: center;
            position: relative;
            transition: transform 0.3s ease;
        }

        .reset-container:hover {
            transform: translateY(-5px);
        }

        /* School logo and header */
        .reset-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .school-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            margin-right: 15px;
            border-radius: 50%;
            padding: 4px;
            background-color: white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .header-text {
            text-align: left;
        }

        .header-text h1 {
            color: var(--text-dark);
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .header-text p {
            color: #666;
            font-size: 15px;
        }

        /* Progress steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .step {
            flex: 1;
            height: 4px;
            background-color: #e5e5e5;
            margin: 0 3px;
            border-radius: 2px;
            transition: background-color 0.3s ease;
        }

        .step.active {
            background-color: var(--primary);
        }

        /* Form styling with floating labels */
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
            position: relative;
            flex: 1;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            font-size: 15px;
            background-color: transparent;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            color: var(--text-dark);
            height: 46px;
        }

        /* Important: Fix for autofill background */
        .form-group input:-webkit-autofill,
        .form-group input:-webkit-autofill:hover,
        .form-group input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            -webkit-text-fill-color: var(--text-dark) !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
        }

        /* Floating label styling */
        .form-group label {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            background-color: transparent;
            color: #777;
            padding: 0 5px;
            font-size: 15px;
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .form-group input:focus + label,
        .form-group input:not(:placeholder-shown) + label {
            top: 0;
            font-size: 12px;
            color: var(--primary-dark);
            background-color: white;
            transform: translateY(-50%);
        }

        /* Fix placeholder opacity to hide it for floating label effect */
        .form-group input::placeholder {
            opacity: 0;
            color: transparent;
        }

        /* Buttons */
        button[type="submit"] {
            width: 100%;
            padding: 14px;
            background: var(--black);
            color: var(--primary);
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        button[type="submit"]:hover {
            background: var(--primary);
            color: var(--black);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-payment {
            background: var(--primary) !important;
            color: var(--black) !important;
        }

        .btn-payment:hover {
            background: var(--primary-dark) !important;
            color: var(--white) !important;
        }

        .login-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            background: transparent;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            color: #666;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .login-link:hover {
            background: #f5f5f5;
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Alert styling */
        .alert-error {
            background-color: var(--error-light);
            border-left: 4px solid var(--error);
            color: var(--error);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
        }

        .alert-success {
            background-color: var(--success-light);
            border-left: 4px solid var(--success);
            color: var(--success);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
        }

        /* User info styling */
        .user-info {
            background: var(--primary-light);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: left;
        }

        .user-info h4 {
            margin-bottom: 5px;
            color: var(--black);
            font-size: 16px;
        }

        .user-info p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }

        /* Payment info styling */
        .payment-info {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }

        .payment-amount {
            font-size: 24px;
            font-weight: bold;
            color: var(--success);
            margin-bottom: 10px;
        }

        .service-info {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Success completion styling */
        .completion-section {
            text-align: center;
            padding: 20px 0;
        }

        .completion-icon {
            font-size: 48px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .completion-title {
            color: var(--success);
            font-size: 20px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .completion-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .btn-login {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--primary);
            color: var(--black);
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            background: var(--primary-dark);
            color: var(--white);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 480px) {
            .reset-container {
                padding: 25px 20px;
            }
            
            .reset-header {
                flex-direction: column;
                text-align: center;
            }
            
            .school-logo {
                margin-right: 0;
                margin-bottom: 15px;
            }
            
            .header-text {
                text-align: center;
            }
        }

        /* Modal Management Styles */
        .modal, .payment-modal, [class*="modal"] {
            z-index: 1050 !important;
        }

        .modal-backdrop, .backdrop {
            z-index: 1040 !important;
        }

        /* Hide any conflicting elements */
        .hidden {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }

        /* Override any modal styles that might conflict */
        body.modal-open {
            overflow: hidden;
        }

        /* Verification Modal Styles */
        .user-verification-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e9ecef;
        }

        .user-verification-info .row {
            margin-bottom: 0;
            padding: 8px 0;
        }

        .user-verification-info hr {
            margin: 12px 0;
            border-color: #dee2e6;
        }

        #verificationModal .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        #verificationModal .modal-header {
            border-radius: 15px 15px 0 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        #verificationModal .fa-user-circle {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="background-container">
        <img src="<?php echo ASSETS_URL; ?>/images/backgrounds/image1.jpg" alt="background" class="background-image">
        <div class="background-overlay"></div>
    </div>

    <div class="reset-container">
        <div class="reset-header">
            <img src="<?php echo ASSETS_URL; ?>/images/logo.png" alt="School Logo" class="school-logo">
            <div class="header-text">
                <h1>BREMAN ASIKUMA SHS</h1>
                <p>Password Recovery</p>
            </div>
        </div>

        <!-- Progress indicator -->
        <div class="progress-steps">
            <div class="step <?php echo ($step !== 'identify') ? 'active' : ''; ?>" id="step1"></div>
            <div class="step <?php echo ($step === 'payment' || $step === 'verify' || $step === 'reset' || $step === 'complete') ? 'active' : ''; ?>" id="step2"></div>
            <div class="step <?php echo ($step === 'reset' || $step === 'complete') ? 'active' : ''; ?>" id="step3"></div>
            <div class="step <?php echo ($step === 'complete') ? 'active' : ''; ?>" id="step4"></div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 'identify'): ?>
            <!-- Step 1: Identify User -->
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="identifyForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="identify_user">
                
                <div class="form-group">
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required 
                        placeholder="Username"
                    >
                    <label for="username">Username</label>
                </div>

                <button type="submit">
                    <i class="fas fa-search"></i>
                    <span>Verify Account</span>
                </button>
            </form>

        <?php elseif ($step === 'payment'): ?>
            <!-- Step 2: Payment Required -->
            <div class="payment-info">
                <div class="payment-amount"><?php echo PaymentHandlerUtils::formatCurrency($pricing['amount'], $pricing['currency']); ?></div>
                <p><strong>Password Reset Service</strong></p>
                <p class="service-info">Secure payment required to prevent unauthorized password resets</p>
            </div>

            <?php if (isset($_SESSION['account_confirmed']) && $_SESSION['account_confirmed'] === true): ?>
            <button type="button" class="btn-payment" onclick="initiatePasswordResetPayment()" style="width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px;">
                <i class="fas fa-credit-card"></i>
                <span>Pay to Reset Password</span>
            </button>
            <?php else: ?>
            <button type="button" class="btn-payment" disabled style="width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: not-allowed; transition: all 0.3s ease; display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px; background: #ccc; color: #666;">
                <i class="fas fa-lock"></i>
                <span>Account Verification Required</span>
            </button>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Account verification required!</strong> Please go back and verify your account information through the username entry form.
            </div>
            <?php endif; ?>

        <?php elseif ($step === 'reset'): ?>
            <!-- Step 3: Set New Password -->
            <?php
            // Get usage information for this user
            $usageInfo = PaymentService::getPasswordResetUsage($_SESSION['reset_user_id']);
            ?>
            
            <?php if ($usageInfo['has_access']): ?>
            <div class="alert alert-info">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Password Reset Usage:</strong> 
                        <?php echo $usageInfo['usage_count']; ?>/<?php echo $usageInfo['max_uses']; ?> attempts used
                    </div>
                    <div class="text-right">
                        <small>
                            <?php if ($usageInfo['remaining_uses'] > 0): ?>
                                <span class="text-success">
                                    <i class="fas fa-check mr-1"></i>
                                    <?php echo $usageInfo['remaining_uses']; ?> remaining
                                </span>
                            <?php else: ?>
                                <span class="text-warning">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    Last attempt
                                </span>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($usageInfo['has_access']): ?>
                <!-- User has valid access - show password reset form -->
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="reset_password">

                    <div class="form-group">
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            required 
                            minlength="8"
                            placeholder="New Password"
                        >
                        <label for="new_password">New Password</label>
                    </div>

                    <div class="form-group">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            required 
                            minlength="8"
                            placeholder="Confirm Password"
                        >
                        <label for="confirm_password">Confirm Password</label>
                    </div>

                    <button type="submit">
                        <i class="fas fa-check"></i>
                        <span>Reset Password</span>
                    </button>
                </form>
                
            <?php else: ?>
                <!-- User has no access - show payment required message -->
                <div class="alert alert-warning">
                    <div class="text-center">
                        <i class="fas fa-credit-card fa-2x mb-3"></i>
                        <h5><strong>Payment Required</strong></h5>
                        <p>You have exhausted your password reset attempts or your payment has expired.</p>
                        <p>A payment of <strong><?php echo PaymentHandlerUtils::formatCurrency($pricing['amount'], $pricing['currency']); ?></strong> is required to reset your password.</p>
                        
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?step=payment" class="btn btn-primary">
                                <i class="fas fa-credit-card mr-2"></i>Make Payment
                            </a>
                            <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary ml-2">
                                <i class="fas fa-arrow-left mr-2"></i>Start Over
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($step === 'complete'): ?>
            <!-- Step 4: Complete -->
            <div class="completion-section">
                <div class="completion-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3 class="completion-title">Password Reset Complete!</h3>
                <p class="completion-message">Your password has been successfully reset. You can now login with your new password.</p>
                
                <a href="login.php" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Go to Login</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($step !== 'complete'): ?>
            <a href="login.php" class="login-link">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Login</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- User Verification Modal -->
    <div class="modal fade" id="verificationModal" tabindex="-1" role="dialog" aria-labelledby="verificationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="verificationModalLabel">
                        <i class="fas fa-user-check mr-2"></i>Verify Account Information
                    </h5>
                    <!-- Removed close button to force explicit choice -->
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-user-circle fa-3x text-primary mb-3"></i>
                        <h5>Is this your account?</h5>
                    </div>
                    
                    <div class="user-verification-info">
                        <div class="row">
                            <div class="col-4 text-muted">
                                <strong>Full Name:</strong>
                            </div>
                            <div class="col-8">
                                <span id="modalFullName"><?php echo htmlspecialchars($_SESSION['reset_user_fullname'] ?? 'Name not available'); ?></span>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-4 text-muted">
                                <strong>Username:</strong>
                            </div>
                            <div class="col-8">
                                <span id="modalUsername"><?php echo htmlspecialchars($_SESSION['reset_username'] ?? ''); ?></span>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-4 text-muted">
                                <strong>Role:</strong>
                            </div>
                            <div class="col-8">
                                <span id="modalRole"><?php echo ucfirst($_SESSION['reset_user_role'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="notMyAccount()">
                        <i class="fas fa-times mr-1"></i>Not My Account
                    </button>
                    <button type="button" class="btn btn-primary" onclick="confirmAccountModal()">
                        <i class="fas fa-check mr-1"></i>Yes, This is My Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment modal not needed - going directly to Paystack -->

    <!-- Bootstrap JavaScript for modal functionality -->
    <script src="<?php echo BASE_URL; ?>/assets/js/jquery.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide all modals on page load
            hideAllModals();
            initializeFloatingLabels();
            
            // Check if we should show verification modal
            <?php if (isset($_SESSION['show_verification_modal']) && $_SESSION['show_verification_modal']): ?>
            // Update modal content with session data
            $('#modalFullName').text('<?php echo addslashes($_SESSION['reset_user_fullname'] ?? 'Name not available'); ?>');
            $('#modalUsername').text('<?php echo addslashes($_SESSION['reset_username'] ?? ''); ?>');
            $('#modalRole').text('<?php echo addslashes(ucfirst($_SESSION['reset_user_role'] ?? '')); ?>');
            
            // Show verification modal after a brief delay
            setTimeout(() => {
                $('#verificationModal').modal({
                    backdrop: 'static',  // Disable backdrop click to close
                    keyboard: false,     // Disable Escape key to close
                    show: true
                });
            }, 500);
            
            // Handle modal close events
            $('#verificationModal').on('hidden.bs.modal', function () {
                // Only clear session if user didn't make an explicit choice
                if (!userMadeChoice) {
                    // Clear session data and go back to username entry
                    fetch('<?php echo BASE_URL; ?>/api/clear-reset-session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'clear_reset_data'
                        })
                    }).then(() => {
                        // Reload page to show username entry form
                        window.location.href = window.location.pathname;
                    }).catch(() => {
                        // Fallback: just reload the page
                        window.location.href = window.location.pathname;
                    });
                }
            });
            <?php 
            // Clear the flag after showing
            unset($_SESSION['show_verification_modal']); 
            ?>
            <?php endif; ?>
            
            // Auto-dismiss success messages after 4 seconds
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                // Make it clickable to dismiss immediately
                successAlert.style.cursor = 'pointer';
                successAlert.title = 'Click to dismiss';
                
                // Add click to dismiss
                successAlert.addEventListener('click', function() {
                    dismissAlert(this);
                });
                
                // Auto-dismiss after 4 seconds
                setTimeout(() => {
                    dismissAlert(successAlert);
                }, 4000);
            }
            
            function dismissAlert(alert) {
                if (alert && alert.style.opacity !== '0') {
                    alert.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }
            }
            
            // Close any open modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-backdrop')) {
                    hideAllModals();
                }
            });
            
            // Handle Escape key to close modals (except verification modal)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    // Don't close verification modal with Escape - force explicit choice
                    if (!$('#verificationModal').hasClass('show')) {
                        hideAllModals();
                    }
                }
            });
            
            // Auto-check payment status when landing on payment page
            <?php if ($step === 'payment'): ?>
            setTimeout(async () => {
                try {
                    const statusCheck = await checkExistingPayment();
                    if (statusCheck.completed) {
                        // Payment already completed - redirect to reset
                        showStatusMessage('Payment already completed! Redirecting to password reset...', 'success');
                        setTimeout(() => {
                            window.location.href = window.location.pathname + '?payment_complete=1';
                        }, 2000);
                    } else if (statusCheck.hasPending) {
                        // Show pending payment options
                        showPendingPaymentOptions();
                    }
                } catch (error) {
                    console.error('Error checking payment status on page load:', error);
                }
            }, 1000);
            <?php endif; ?>
        });

        function initializeFloatingLabels() {
            const inputs = document.querySelectorAll('.form-group input');
            
            inputs.forEach(input => {
                // Check if input has value on page load (for autofill)
                if (input.value !== '') {
                    const label = input.nextElementSibling;
                    if (label) {
                        label.style.top = '0';
                        label.style.fontSize = '12px';
                        label.style.backgroundColor = 'white';
                        label.style.color = '#CCB000';
                    }
                }
                
                input.addEventListener('focus', function() {
                    const label = this.nextElementSibling;
                    label.style.top = '0';
                    label.style.fontSize = '12px';
                    label.style.backgroundColor = 'white';
                    label.style.color = '#CCB000';
                });
                
                input.addEventListener('blur', function() {
                    const label = this.nextElementSibling;
                    if (this.value === '') {
                        label.style.top = '50%';
                        label.style.fontSize = '15px';
                        label.style.backgroundColor = 'transparent';
                        label.style.color = '#777';
                    }
                });
            });
        }

        function hideAllModals() {
            // Only hide custom modals, not Bootstrap modals during their operation
            const customModals = document.querySelectorAll('.payment-modal, [class*="custom-modal"]');
            customModals.forEach(modal => {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                modal.style.opacity = '0';
                modal.classList.remove('show', 'active');
            });
            
            // Only remove custom backdrops
            const customBackdrops = document.querySelectorAll('.custom-backdrop');
            customBackdrops.forEach(backdrop => backdrop.remove());
        }

        async function initiatePasswordResetPayment() {
            // Set authentication data for API calls - using reset user session data
            window.authData = {
                user_id: '<?php echo $_SESSION['reset_user_id'] ?? ''; ?>',
                user_role: '<?php echo $_SESSION['reset_user_role'] ?? ''; ?>',
                session_token: '<?php echo md5(session_id() . ($_SESSION['reset_user_id'] ?? '')); ?>'
            };
            
            // First check if there's already a completed payment
            const statusCheck = await checkExistingPayment();
            if (statusCheck.completed) {
                // Payment already completed - redirect to reset
                window.location.href = window.location.pathname + '?payment_complete=1';
                return;
            }
            
            // If there's a pending payment, give user option to check status or create new payment
            if (statusCheck.hasPending) {
                showPendingPaymentOptions();
            } else {
                // No existing payments - create new payment
                createPaymentAndOpenPaystack();
            }
        }
        
        async function createPaymentAndOpenPaystack() {
            try {
                // Show loading indicator
                const button = document.querySelector('.btn-payment');
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.disabled = true;
                
                // Create payment request on server
                const formData = new FormData();
                formData.append('service_type', 'password_reset');
                formData.append('reference_id', '');
                formData.append('action', 'create_payment');
                
                // Add authentication data for forgot password flow
                formData.append('auth_session_token', '<?php echo md5(session_id() . ($_SESSION['reset_user_id'] ?? '')); ?>');
                
                const response = await fetch('<?php echo BASE_URL; ?>/api/payment-handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Load Paystack script and open payment
                    loadPaystackAndPay(result.data);
                } else {
                    throw new Error(result.message || 'Failed to initialize payment');
                }
            } catch (error) {
                console.error('Payment error:', error);
                alert('Payment initialization failed: ' + error.message);
                
                // Reset button
                const button = document.querySelector('.btn-payment');
                button.innerHTML = '<i class="fas fa-credit-card"></i> <span>Pay to Reset Password</span>';
                button.disabled = false;
            }
        }
        
        function loadPaystackAndPay(paymentData) {
            // Load Paystack script if not already loaded
            if (typeof PaystackPop === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://js.paystack.co/v1/inline.js';
                script.onload = () => {
                    openPaystackPopup(paymentData);
                };
                document.head.appendChild(script);
            } else {
                openPaystackPopup(paymentData);
            }
        }
        
        function openPaystackPopup(paymentData) {
            const paymentConfig = {
                key: paymentData.public_key,
                email: paymentData.email,
                amount: paymentData.amount * 100, // Convert to pesewas
                currency: paymentData.currency,
                ref: paymentData.reference,
                metadata: {
                    service_type: 'password_reset',
                    custom_fields: [
                        {
                            display_name: 'Service',
                            variable_name: 'service_type',
                            value: 'Password Reset Service'
                        }
                    ]
                },
                callback: function(response) {
                    handlePaymentSuccess(response);
                },
                onClose: function() {
                    console.log('Payment cancelled by user');
                    // Reset button
                    const button = document.querySelector('.btn-payment');
                    button.innerHTML = '<i class="fas fa-credit-card"></i> <span>Pay to Reset Password</span>';
                    button.disabled = false;
                }
            };
            
            const handler = PaystackPop.setup(paymentConfig);
            handler.openIframe();
        }
        
        async function handlePaymentSuccess(response) {
            try {
                // Show success loading
                const button = document.querySelector('.btn-payment');
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
                
                // Verify payment on server
                const formData = new FormData();
                formData.append('reference', response.reference);
                formData.append('action', 'verify_payment');
                formData.append('auth_session_token', '<?php echo md5(session_id() . ($_SESSION['reset_user_id'] ?? '')); ?>');
                
                const verifyResponse = await fetch('<?php echo BASE_URL; ?>/api/payment-handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await verifyResponse.json();
                
                if (result.success) {
                    // Payment successful - redirect to password reset step
                    window.location.href = window.location.href.split('?')[0] + '?payment_complete=1';
                } else {
                    throw new Error(result.message || 'Payment verification failed');
                }
            } catch (error) {
                console.error('Payment verification error:', error);
                alert('Payment verification failed: ' + error.message);
                
                // Reset button
                const button = document.querySelector('.btn-payment');
                button.innerHTML = '<i class="fas fa-credit-card"></i> <span>Pay to Reset Password</span>';
                button.disabled = false;
            }
        }

        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }
        });

        // Check for existing payments
        async function checkExistingPayment() {
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/check-payment-status.php', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Check if there's actually a pending payment
                    const hasActualPending = !result.payment_completed && 
                                           result.status === 'pending' && 
                                           !result.message.includes('No recent pending payments');
                    
                    return {
                        completed: result.payment_completed,
                        hasPending: hasActualPending,
                        status: result.status || 'none',
                        message: result.message
                    };
                }
                
                return { completed: false, hasPending: false, message: 'Error checking status' };
            } catch (error) {
                console.error('Error checking existing payment:', error);
                return { completed: false, hasPending: false, message: 'Network error' };
            }
        }
        
        // Show options when there's a pending payment
        function showPendingPaymentOptions() {
            const paymentInfo = document.querySelector('.payment-info');
            
            // Remove existing pending payment UI
            const existingPendingUI = document.querySelector('.pending-payment-ui');
            if (existingPendingUI) {
                existingPendingUI.remove();
            }
            
            // Create pending payment options UI
            const pendingUI = document.createElement('div');
            pendingUI.className = 'pending-payment-ui alert alert-warning';
            pendingUI.innerHTML = `
                <h6><i class="fas fa-clock mr-2"></i>Pending Payment Found</h6>
                <p>You have a pending payment that may have been approved on your phone.</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" onclick="checkPendingPaymentStatus()">
                        <i class="fas fa-sync-alt mr-1"></i>Check Status
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="createNewPayment()">
                        <i class="fas fa-plus mr-1"></i>New Payment
                    </button>
                </div>
            `;
            
            // Insert after payment info
            paymentInfo.parentNode.insertBefore(pendingUI, paymentInfo.nextSibling);
        }
        
        // Check status of pending payment
        async function checkPendingPaymentStatus() {
            const button = document.querySelector('button[onclick="checkPendingPaymentStatus()"]');
            const originalContent = button.innerHTML;
            
            // Show loading state
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
            button.disabled = true;
            
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/check-payment-status.php', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const result = await response.json();
                
                if (result.success && result.payment_completed) {
                    // Payment completed - redirect
                    showStatusMessage('Payment verified with Paystack! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = result.redirect_url || (window.location.pathname + '?payment_complete=1');
                    }, 1500);
                } else {
                    // Still pending or failed
                    showStatusMessage(result.message || 'Payment still pending', 'info');
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
                showStatusMessage('Network error occurred while contacting Paystack', 'error');
            } finally {
                // Reset button
                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.disabled = false;
                }, 2000);
            }
        }
        
        // Create new payment (skip pending check)
        function createNewPayment() {
            // Remove pending payment UI
            const pendingUI = document.querySelector('.pending-payment-ui');
            if (pendingUI) {
                pendingUI.remove();
            }
            
            // Proceed with new payment
            createPaymentAndOpenPaystack();
        }

        // Legacy check payment status function (kept for compatibility)
        async function checkPaymentStatus() {
            const button = document.querySelector('button[onclick="checkPaymentStatus()"]');
            const originalContent = button.innerHTML;
            
            // Show loading state
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Checking...</span>';
            button.disabled = true;
            
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/check-payment-status.php', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    if (result.payment_completed) {
                        // Paystack confirmed payment completed
                        showStatusMessage(result.message, 'success');
                        
                        // Redirect after showing success message
                        setTimeout(() => {
                            window.location.href = result.redirect_url || (window.location.pathname + '?payment_complete=1');
                        }, 2000);
                    } else {
                        // Payment still pending or not found
                        showStatusMessage(result.message, 'info');
                    }
                } else {
                    showStatusMessage(result.message || 'Error checking payment status', 'error');
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
                showStatusMessage('Network error occurred while contacting Paystack', 'error');
            } finally {
                // Reset button after 3 seconds
                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.disabled = false;
                }, 3000);
            }
        }
        
        // Show status message helper
        function showStatusMessage(message, type) {
            // Remove any existing status messages
            const existingMessages = document.querySelectorAll('.payment-status-message');
            existingMessages.forEach(msg => msg.remove());
            
            // Create new message
            const messageDiv = document.createElement('div');
            messageDiv.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} payment-status-message`;
            messageDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} mr-2"></i>${message}`;
            
            // Insert after payment info
            const paymentInfo = document.querySelector('.payment-info');
            if (paymentInfo) {
                paymentInfo.parentNode.insertBefore(messageDiv, paymentInfo.nextSibling);
            }
            
            // Auto-dismiss after 5 seconds unless it's a success message
            if (type !== 'success') {
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.style.transition = 'opacity 0.5s ease-out';
                        messageDiv.style.opacity = '0';
                        setTimeout(() => {
                            if (messageDiv.parentNode) {
                                messageDiv.remove();
                            }
                        }, 500);
                    }
                }, 5000);
            }
        }

        // Legacy payment handlers removed - now using direct Paystack integration

        // Track if user made an explicit choice
        let userMadeChoice = false;
        
        // Modal verification functions
        function notMyAccount() {
            userMadeChoice = true;
            
            // Close modal
            $('#verificationModal').modal('hide');
            
            // Clear session data and go back to username entry
            fetch('<?php echo BASE_URL; ?>/api/clear-reset-session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'clear_reset_data'
                })
            }).then(() => {
                // Reload page to show username entry form
                window.location.href = window.location.pathname;
            }).catch(() => {
                // Fallback: just reload the page
                window.location.href = window.location.pathname;
            });
        }
        
        function confirmAccountModal() {
            userMadeChoice = true;
            
            // Set confirmation flag in session
            fetch('<?php echo BASE_URL; ?>/api/confirm-account.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'confirm_account'
                })
            }).then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal
                    $('#verificationModal').modal('hide');
                    
                    // Let PHP logic determine the correct step on page reload
                    window.location.href = window.location.pathname;
                } else {
                    alert('Unable to confirm account. Please try again.');
                }
            }).catch(() => {
                alert('Connection error. Please try again.');
            });
        }

        // Check payment status and redirect to appropriate page
        async function checkPaymentStatusAndRedirect() {
            try {
                // Show loading indicator
                const loadingDiv = document.createElement('div');
                loadingDiv.className = 'alert alert-info text-center';
                loadingDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Checking payment status...';
                document.querySelector('.container').appendChild(loadingDiv);
                
                // Check existing payment status
                const statusCheck = await checkExistingPayment();
                
                // Remove loading indicator
                loadingDiv.remove();
                
                if (statusCheck.completed) {
                    // Payment already completed - go directly to reset page
                    showStatusMessage('Payment verified! Redirecting to password reset...', 'success');
                    setTimeout(() => {
                        window.location.href = window.location.pathname + '?payment_complete=1';
                    }, 2000);
                } else {
                    // No payment completed - go to payment page
                    window.location.href = window.location.pathname + '?step=payment';
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
                // Fallback to payment page if there's an error
                window.location.href = window.location.pathname + '?step=payment';
            }
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>