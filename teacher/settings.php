<?php
// teacher/settings.php
define('BASEPATH', dirname(dirname(__FILE__)));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require teacher role
requireRole('teacher');

$error = '';
$success = '';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher details
    $stmt = $db->prepare(
        "SELECT t.*, u.email, u.username 
         FROM Teachers t 
         JOIN Users u ON t.user_id = u.user_id 
         WHERE t.user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch();

    if (!$teacher) {
        throw new Exception('Teacher profile not found');
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $firstName = trim(sanitizeInput($_POST['first_name']));
        $lastName = trim(sanitizeInput($_POST['last_name']));
        $email = trim(sanitizeInput($_POST['email']));

        // Validation
        if (empty($firstName) || empty($lastName) || empty($email)) {
            throw new Exception('All fields are required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Check if email is already used by another user
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM Users 
             WHERE email = ? AND user_id != ?"
        );
        $stmt->execute([$email, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Email is already in use');
        }

        // Start transaction
        $db->beginTransaction();

        try {
            // Update teacher information
            $stmt = $db->prepare(
                "UPDATE Teachers 
                 SET first_name = ?, 
                     last_name = ?, 
                     email = ? 
                 WHERE user_id = ?"
            );
            $stmt->execute([
                ucwords(strtolower($firstName)),
                ucwords(strtolower($lastName)),
                $email,
                $_SESSION['user_id']
            ]);

            // Update user email
            $stmt = $db->prepare(
                "UPDATE Users 
                 SET email = ? 
                 WHERE user_id = ?"
            );
            $stmt->execute([$email, $_SESSION['user_id']]);

            $db->commit();

            logSystemActivity(
                'Profile Update',
                "Teacher profile updated successfully",
                'INFO',
                $_SESSION['user_id']
            );

            $success = 'Profile updated successfully';
            
            // Refresh teacher data
            $stmt = $db->prepare(
                "SELECT t.*, u.email, u.username 
                 FROM Teachers t 
                 JOIN Users u ON t.user_id = u.user_id 
                 WHERE t.user_id = ?"
            );
            $stmt->execute([$_SESSION['user_id']]);
            $teacher = $stmt->fetch();

        } catch (Exception $e) {
            $db->rollBack();
            throw new Exception('Failed to update profile: ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Teacher settings error: " . $e->getMessage());
}

$pageTitle = 'Settings';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-user-cog me-2"></i>Profile Settings
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($teacher['username']); ?>" 
                                       readonly>
                                <div class="form-text text-muted">Username cannot be changed</div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($teacher['first_name']); ?>"
                                       pattern="[A-Za-z\s]+" 
                                       title="Only letters and spaces allowed">
                                <div class="invalid-feedback">
                                    Please enter your first name (letters and spaces only)
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($teacher['last_name']); ?>"
                                       pattern="[A-Za-z\s]+" 
                                       title="Only letters and spaces only">
                                <div class="invalid-feedback">
                                    Please enter your last name (letters and spaces only)
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?php echo htmlspecialchars($teacher['email']); ?>">
                            <div class="invalid-feedback">
                                Please enter a valid email address
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                            <a href="<?php echo BASE_URL; ?>/change-password.php" class="btn btn-outline-secondary">
                                <i class="fas fa-key me-2"></i>Change Password
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.querySelector('form');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        const firstName = form.querySelector('[name="first_name"]').value.trim();
        const lastName = form.querySelector('[name="last_name"]').value.trim();
        
        if (!/^[A-Za-z\s]+$/.test(firstName) || !/^[A-Za-z\s]+$/.test(lastName)) {
            event.preventDefault();
            alert('Names can only contain letters and spaces');
            return;
        }

        form.classList.add('was-validated');
    });

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>