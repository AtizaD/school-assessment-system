<?php
// change-password.php
define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require login but allow any role
requireLogin();

// Check if this is a forced password change
$forcedChange = isset($_SESSION['password_change_required']) && $_SESSION['password_change_required'] || 
                isset($_SESSION['first_login']) && $_SESSION['first_login'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            
            // Validate current password
            $stmt = $db->prepare("SELECT password_hash FROM Users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                throw new Exception('User not found');
            }

            // Simplified validation checks
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = 'All fields are required';
            } elseif (!password_verify($currentPassword, $user['password_hash'])) {
                $error = 'Current password is incorrect';
                
                // Log failed password change attempt
                logSystemActivity(
                    'Security',
                    'Failed password change attempt - incorrect current password',
                    'WARNING',
                    $_SESSION['user_id']
                );
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match';
            } elseif (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
                $error = 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long';
            } elseif ($newPassword === $currentPassword) {
                $error = 'New password must be different from current password';
            } elseif ($newPassword === $_SESSION['username']) {
                $error = 'Password cannot be the same as your username';
            } else {
                // Start transaction
                $db->beginTransaction();

                try {
                    // Update password
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare(
                        "UPDATE Users 
                         SET password_hash = ?, 
                             first_login = FALSE,
                             password_change_required = FALSE,
                             last_password_change = CURRENT_TIMESTAMP
                         WHERE user_id = ?"
                    );
                    $stmt->execute([$newHash, $_SESSION['user_id']]);

                    // Log the password change
                    logSystemActivity(
                        'Security',
                        'Password changed successfully',
                        'INFO',
                        $_SESSION['user_id']
                    );

                    // Log to AuthLogs
                    $stmt = $db->prepare(
                        "INSERT INTO AuthLogs (
                            user_id,
                            attempt_type,
                            status,
                            ip_address,
                            user_agent
                        ) VALUES (?, 'PASSWORD_RESET', 'SUCCESS', ?, ?)"
                    );
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);

                    // Invalidate all other sessions for this user
                    $stmt = $db->prepare(
                        "UPDATE UserSessions 
                         SET is_active = FALSE 
                         WHERE user_id = ? 
                         AND session_id != ?"
                    );
                    $stmt->execute([$_SESSION['user_id'], session_id()]);

                    $db->commit();
                    
                    // Update session flags
                    $_SESSION['first_login'] = false;
                    $_SESSION['password_change_required'] = false;
                    unset($_SESSION['force_password_change']);

                    $success = 'Password changed successfully';

                    // If this was a forced change, redirect to appropriate dashboard
                    if ($forcedChange) {
                        logSystemActivity(
                            'Security',
                            'Required password change completed successfully',
                            'INFO',
                            $_SESSION['user_id']
                        );

                        // Redirect after 2 seconds
                        header('refresh:2;url=' . BASE_URL . '/' . strtolower($_SESSION['user_role']) . '/index.php');
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    throw new Exception('Failed to update password: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            logError("Password change error: " . $e->getMessage());
        }
    }
}

$pageTitle = 'Change Password';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card border-0 shadow-lg">
                <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                    <h5 class="card-title mb-0 text-warning">
                        <i class="fas fa-key me-2"></i>Change Password
                    </h5>
                </div>
                <div class="card-body" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                    <?php if ($forcedChange): ?>
                        <div class="alert custom-alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Required Action:</strong> You must change your password before continuing.
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert custom-alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert custom-alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="passwordChangeForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="mb-3">
                            <label class="form-label text-warning">Current Password</label>
                            <div class="input-group custom-input-group">
                                <input type="password" name="current_password" id="current_password" 
                                       class="form-control custom-input" required>
                                <button class="btn btn-outline-warning toggle-password" 
                                        type="button" data-target="current_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-warning">New Password</label>
                            <div class="input-group custom-input-group">
                                <input type="password" name="new_password" id="new_password" 
                                       class="form-control custom-input" required 
                                       minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                                <button class="btn btn-outline-warning toggle-password" 
                                        type="button" data-target="new_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-warning">Confirm New Password</label>
                            <div class="input-group custom-input-group">
                                <input type="password" name="confirm_password" id="confirm_password" 
                                       class="form-control custom-input" required>
                                <button class="btn btn-outline-warning toggle-password" 
                                        type="button" data-target="confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Main background */
body {
    background: linear-gradient(145deg, #000 0%, #2c3e50 100%);
    min-height: 100vh;
}

/* Card styling */
.card {
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
}

/* Custom alerts */
.custom-alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border: 1px solid #ffc107;
    color: #ffc107;
}

.custom-alert-danger {
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid #dc3545;
    color: #dc3545;
}

.custom-alert-success {
    background: rgba(40, 167, 69, 0.1);
    border: 1px solid #28a745;
    color: #28a745;
}

/* Input styling */
.custom-input-group {
    border: 1px solid rgba(255, 193, 7, 0.2);
    border-radius: 0.375rem;
    overflow: hidden;
}

.custom-input {
    background-color: rgba(255, 255, 255, 0.05) !important;
    border: none !important;
    color: #fff !important;
}

.custom-input:focus {
    background-color: rgba(255, 255, 255, 0.1) !important;
    box-shadow: none !important;
}

.custom-input::placeholder {
    color: rgba(255, 255, 255, 0.5);
}

/* Button styling */
.btn-warning {
    background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);
    border: none;
    color: #000;
}

.btn-warning:hover {
    background: linear-gradient(145deg, #ff6f00 0%, #ffc107 100%);
    color: #000;
}

.btn-outline-warning {
    border-color: rgba(255, 193, 7, 0.2);
    color: #ffc107;
    background: transparent;
}

.btn-outline-warning:hover {
    background: rgba(255, 193, 7, 0.1);
    border-color: #ffc107;
    color: #ffc107;
}

/* Close button in alerts */
.btn-close-white {
    filter: invert(1) grayscale(100%) brightness(200%);
}

.text-warning {
    color: #ffc107 !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle password visibility toggle
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });

    // Form validation
    const form = document.getElementById('passwordChangeForm');
    form.addEventListener('submit', function(e) {
        const newPassword = form.querySelector('[name="new_password"]').value;
        const confirmPassword = form.querySelector('[name="confirm_password"]').value;
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New passwords do not match');
            return;
        }

        // Check password length
        if (newPassword.length < <?php echo PASSWORD_MIN_LENGTH; ?>) {
            e.preventDefault();
            alert('Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters long');
            return;
        }
    });

    // Auto-dismiss success alerts after 5 seconds
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(successAlert);
            bsAlert.close();
        }, 5000);
    }

    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>