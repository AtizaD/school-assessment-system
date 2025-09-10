<?php
// student/settings.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/Student.php';

requireRole('student');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student info - Modified query to match the database schema
    $stmt = $db->prepare(
        "SELECT s.*, u.email, u.last_password_change, u.created_at AS account_created, 
                c.class_name, p.program_name 
         FROM students s 
         JOIN users u ON s.user_id = u.user_id
         JOIN classes c ON s.class_id = c.class_id
         JOIN programs p ON c.program_id = p.program_id
         WHERE s.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();

    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        switch ($_POST['action']) {
            case 'update_profile':
                $firstName = sanitizeInput($_POST['first_name']);
                $lastName = sanitizeInput($_POST['last_name']);
                
                $stmt = $db->prepare(
                    "UPDATE students 
                     SET first_name = ?, last_name = ? 
                     WHERE user_id = ?"
                );
                if ($stmt->execute([$firstName, $lastName, $_SESSION['user_id']])) {
                    $success = 'Profile updated successfully';
                    logSystemActivity('Profile Update', "Student profile updated", 'INFO');
                }
                break;

            case 'change_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];

                if ($newPassword !== $confirmPassword) {
                    throw new Exception('New passwords do not match');
                }

                if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
                    throw new Exception('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
                }

                // Verify current password
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();

                if (!password_verify($currentPassword, $user['password_hash'])) {
                    throw new Exception('Current password is incorrect');
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    "UPDATE users 
                     SET password_hash = ?, last_password_change = CURRENT_TIMESTAMP 
                     WHERE user_id = ?"
                );
                if ($stmt->execute([$newHash, $_SESSION['user_id']])) {
                    $success = 'Password changed successfully';
                    // Log the password change in systelogs
                    $stmt = $db->prepare(
                        "INSERT INTO systemlogs 
                         (severity, component, message, user_id, ip_address)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute(['INFO', 'Password Change', 'Student password changed', 
                                   $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
                    
                    // Also log in authlogs
                    $stmt = $db->prepare(
                        "INSERT INTO authlogs 
                         (user_id, attempt_type, status, ip_address, user_agent)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$_SESSION['user_id'], 'PASSWORD_RESET', 'SUCCESS', 
                                   $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
                }
                break;
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    // Log error to systemlogs
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare(
        "INSERT INTO systemlogs 
         (severity, component, message, stack_trace, user_id, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(['ERROR', 'Settings', "Settings error: " . $e->getMessage(), 
                   $e->getTraceAsString(), $_SESSION['user_id'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]);
}

$pageTitle = 'Settings';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Account Settings</h1>
        <div class="text-muted">
            <?php echo htmlspecialchars($studentInfo['program_name']); ?> | 
            <?php echo htmlspecialchars($studentInfo['class_name']); ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Profile Settings -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Profile Settings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" 
                                   value="<?php echo htmlspecialchars($studentInfo['first_name']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" 
                                   value="<?php echo htmlspecialchars($studentInfo['last_name']); ?>" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password Change -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="current_password" id="currentPassword" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('currentPassword')">
                                    <i class="fas fa-eye" id="currentPassword-icon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="newPassword" 
                                       minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword')">
                                    <i class="fas fa-eye" id="newPassword-icon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="confirmPassword" 
                                       minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword')">
                                    <i class="fas fa-eye" id="confirmPassword-icon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>

                    <div class="mt-3">
                        <small class="text-muted">
                            Last password change: 
                            <?php echo date('F d, Y', strtotime($studentInfo['last_password_change'])); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Information -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Account Information</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Student ID</dt>
                        <dd class="col-sm-9"><?php echo htmlspecialchars($studentInfo['student_id']); ?></dd>

                        <dt class="col-sm-3">Email</dt>
                        <dd class="col-sm-9">
                            <?php echo $studentInfo['email'] ? htmlspecialchars($studentInfo['email']) : '<em>Not provided</em>'; ?>
                        </dd>

                        <dt class="col-sm-3">Program</dt>
                        <dd class="col-sm-9"><?php echo htmlspecialchars($studentInfo['program_name']); ?></dd>

                        <dt class="col-sm-3">Class</dt>
                        <dd class="col-sm-9"><?php echo htmlspecialchars($studentInfo['class_name']); ?></dd>

                        <dt class="col-sm-3">Account Created</dt>
                        <dd class="col-sm-9">
                            <?php echo date('F d, Y', strtotime($studentInfo['account_created'])); ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Password validation
document.addEventListener('DOMContentLoaded', function() {
    const passwordForms = document.querySelectorAll('form');
    passwordForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (this.querySelector('input[name="action"]').value === 'change_password') {
                const newPass = this.querySelector('input[name="new_password"]').value;
                const confirmPass = this.querySelector('input[name="confirm_password"]').value;
                
                if (newPass !== confirmPass) {
                    e.preventDefault();
                    alert('New passwords do not match');
                }
            }
        });
    });

    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert:not(.alert-danger)');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>