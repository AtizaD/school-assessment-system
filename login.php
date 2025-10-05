<?php
define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';


if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isHTTPS() ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_lifetime', 0); // Session cookies only
    
    session_start();
}

// Force session regeneration if CSRF token issues persist
if (isset($_GET['refresh_session'])) {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    logSystemActivity('Security', 'Session regenerated due to CSRF issues', 'INFO');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare(
        "UPDATE UserSessions 
         SET is_active = FALSE 
         WHERE expire_timestamp < CURRENT_TIMESTAMP 
         AND is_active = TRUE"
    );
    $stmt->execute();
} catch (PDOException $e) {
    logError("Session cleanup error: " . $e->getMessage());
}

if (isset($_SESSION['user_id'])) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as active_session 
             FROM UserSessions 
             WHERE user_id = ? 
             AND session_id = ? 
             AND is_active = TRUE 
             AND expire_timestamp > CURRENT_TIMESTAMP"
        );
        $stmt->execute([$_SESSION['user_id'], session_id()]);
        $result = $stmt->fetch();
        
        if ($result['active_session'] == 0) {
            $_SESSION = array();
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
    } catch (PDOException $e) {
        $_SESSION = array();
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
        logError("Session verification error: " . $e->getMessage());
    }
}

if (isLoggedIn()) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as active_session 
             FROM UserSessions 
             WHERE user_id = ? 
             AND session_id = ? 
             AND is_active = TRUE 
             AND expire_timestamp > CURRENT_TIMESTAMP"
        );
        $stmt->execute([$_SESSION['user_id'], session_id()]);
        $result = $stmt->fetch();
        
        if ($result['active_session'] > 0) {
            // Check maintenance mode for logged-in users
            if (isMaintenanceMode() && !isAdmin()) {
                redirectTo('/maintenance.php');
            }
            
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
        } else {
            $_SESSION = array();
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
    } catch (PDOException $e) {
        logError("Login session verification error: " . $e->getMessage());
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $identifier = sanitizeInput($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = 'Please enter both identifier and password';
        } else {
            if (authenticateUser($identifier, $password)) {
                $expireTimestamp = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                $stmt = $db->prepare(
                    "INSERT INTO UserSessions (
                        session_id,
                        user_id,
                        login_timestamp,
                        expire_timestamp,
                        ip_address,
                        user_agent,
                        is_active
                    ) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, TRUE)"
                );
                
                $stmt->execute([
                    session_id(),
                    $_SESSION['user_id'],
                    $expireTimestamp,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
            
                if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change']) {
                    redirectTo('/change-password.php');
                }
                
                // Check maintenance mode after successful login
                if (isMaintenanceMode() && !isAdmin()) {
                    redirectTo('/maintenance.php');
                }
            
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
            } else {
                $error = 'Invalid username/email or password';
                
                try {
                    $db = DatabaseConfig::getInstance()->getConnection();
                    $stmt = $db->prepare(
                        "INSERT INTO AuthLogs (
                            user_id,
                            attempt_type,
                            status,
                            ip_address,
                            user_agent
                        ) VALUES (NULL, 'FAILED_LOGIN', 'FAILURE', ?, ?)"
                    );
                    $stmt->execute([
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                } catch (PDOException $e) {
                    logError("Failed to log login attempt: " . $e->getMessage());
                }
            }
        }
    }
}

$pageTitle = 'BASS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <!-- Security Headers -->
    <?php
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';");
    
    // Cache control for login page
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
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
            --student-highlight: #2563eb;
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

        /* Login container */
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            text-align: center;
            position: relative;
            transition: transform 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
        }

        /* School logo styling */
        .school-logo {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin: 0 auto 20px;
            display: block;
            border-radius: 50%;
            padding: 5px;
            background-color: white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Logo and header */
        .login-header {
            margin-bottom: 30px;
        }

        .login-header h1 {
            color: var(--text-dark);
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .login-header p {
            color: #666;
            font-size: 16px;
        }

        /* Form styling with floating labels */
        .form-group {
            margin-bottom: 25px;
            text-align: left;
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            background-color: transparent;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease, color 0.3s ease;
            color: var(--text-dark);
            height: 50px;
        }

        /* Student ID highlighting */
        .form-group input.student-id {
            border-color: var(--student-highlight);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
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
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            background-color: transparent;
            color: #777;
            padding: 0 5px;
            font-size: 16px;
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

        .form-group input.student-id:focus + label,
        .form-group input.student-id:not(:placeholder-shown) + label {
            color: var(--student-highlight);
        }

        /* Student ID indicator */
        .student-id-indicator {
            position: absolute;
            right: 50px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--student-highlight);
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 2;
            background: white;
            padding: 2px 6px;
            border-radius: 10px;
            border: 1px solid var(--student-highlight);
        }

        .student-id-indicator.show {
            opacity: 1;
        }

        /* Password toggle */
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--primary-dark);
        }

        /* Buttons */
        button[type="submit"] {
            width: 100%;
            padding: 15px;
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

        .student-reg-btn {
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

        .student-reg-btn:hover {
            background: #f5f5f5;
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Auth links styling */
        .auth-links {
            text-align: center;
            margin: 20px 0;
        }

        .forgot-password-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
            border-radius: 25px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .forgot-password-link:hover {
            color: var(--primary-dark);
            background-color: var(--primary-light);
            border-color: var(--primary);
            transform: translateY(-1px);
            text-decoration: none;
        }

        .forgot-password-link i {
            font-size: 14px;
        }

        /* Alert styling */
        .alert-error {
            background-color: var(--error-light);
            border-left: 4px solid var(--error);
            color: var(--error);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.1);
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-icon {
            flex-shrink: 0;
            font-size: 16px;
            margin-top: 2px;
        }

        .alert-text {
            flex: 1;
            line-height: 1.4;
        }

        .alert-text strong {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .alert-action {
            margin-top: 8px;
        }

        .alert-link {
            color: var(--error);
            text-decoration: underline;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .alert-link:hover {
            color: #b91c1c;
            text-decoration: none;
        }

        /* Fix placeholder opacity to hide it for floating label effect */
        .form-group input::placeholder {
            opacity: 0;
            color: transparent;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 25px 20px;
            }

            .school-logo {
                width: 80px;
                height: 80px;
            }

            .login-header h1 {
                font-size: 20px;
            }

            .form-group input {
                font-size: 15px;
                padding: 12px;
                height: 45px;
            }

            button[type="submit"],
            .student-reg-btn {
                padding: 10px;
            }

            .student-id-indicator {
                right: 45px;
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="background-container">
        <img src="<?php echo ASSETS_URL; ?>/images/backgrounds/image1.jpg" alt="background" class="background-image">
        <div class="background-overlay"></div>
    </div>

    <div class="login-container">
        <img src="<?php echo ASSETS_URL; ?>/images/logo.png" alt="School Logo" class="school-logo">
        
        <div class="login-header">
            <h1>BREMAN ASIKUMA SHS</h1>
            <p>User Login</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <div class="alert-content">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                    <div class="alert-text">
                        <strong><?php echo htmlspecialchars($error); ?></strong>
                        <?php if ($error === 'Invalid request. Please refresh the page and try again.'): ?>
                            <div class="alert-action">
                                <small>If this continues to happen, you can 
                                    <a href="<?php echo BASE_URL; ?>/login.php?refresh_session=1" class="alert-link">
                                        reset your session here
                                    </a>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-group">
                <input 
                    type="text" 
                    id="identifier" 
                    name="identifier" 
                    required 
                    autocomplete="username"
                    spellcheck="false"
                    data-lpignore="true"
                    placeholder="Username or Email"
                >
                <label for="identifier">Username or Email</label>
                <span class="student-id-indicator" id="studentIndicator">
                    <i class="fas fa-graduation-cap"></i> Student ID
                </span>
            </div>

            <div class="form-group">
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    autocomplete="current-password"
                    spellcheck="false"
                    data-lpignore="true"
                    placeholder="Password"
                >
                <label for="password">Password</label>
                <span class="password-toggle" onclick="togglePassword()">
                    <i class="fas fa-eye"></i>
                </span>
            </div>

            <button type="submit">
                <i class="fas fa-sign-in-alt"></i>
                <span>Login</span>
            </button>
        </form>
        
        <div class="auth-links">
            <a href="forgot-password.php" class="forgot-password-link">
                <i class="fas fa-key"></i>
                <span>Forgot Password?</span>
            </a>
        </div>
        
        <a href="register.php" class="student-reg-btn">
            <i class="fas fa-user-plus"></i>
            <span>Student Registration</span>
        </a>
    </div>

    <script>
    // Smart capitalization for student IDs (start with 1, 2, or 3)
    function smartCapitalize(input) {
        const value = input.value.trim();
        
        // Don't capitalize if it looks like an email
        if (value.includes('@')) {
            removeStudentIdHighlight(input);
            return false;
        }
        
        // Student IDs start with 1, 2, or 3
        if (value.length > 0 && /^[123]/.test(value)) {
            const originalValue = input.value;
            input.value = value.toUpperCase();
            
            // Add student ID visual feedback
            addStudentIdHighlight(input);
            
            // Brief color change if value was capitalized
            if (originalValue !== input.value) {
                input.style.color = '#2563eb';
                setTimeout(() => {
                    input.style.color = '';
                }, 1000);
            }
            
            return true;
        } else {
            removeStudentIdHighlight(input);
            return false;
        }
    }

    // Add student ID visual indicators
    function addStudentIdHighlight(input) {
        input.classList.add('student-id');
        const indicator = document.getElementById('studentIndicator');
        if (indicator) {
            indicator.classList.add('show');
        }
    }

    // Remove student ID visual indicators
    function removeStudentIdHighlight(input) {
        input.classList.remove('student-id');
        const indicator = document.getElementById('studentIndicator');
        if (indicator) {
            indicator.classList.remove('show');
        }
    }

    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.password-toggle i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // Initialize floating labels and smart capitalization
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('.form-group input');
        const identifierInput = document.getElementById('identifier');
        
        // Add smart capitalization to identifier input
        if (identifierInput) {
            // Check on input with debounce
            let timeout;
            identifierInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    smartCapitalize(this);
                }, 200);
            });
            
            // Also check on blur (when user leaves the field)
            identifierInput.addEventListener('blur', function() {
                clearTimeout(timeout);
                smartCapitalize(this);
            });

            // Check on paste
            identifierInput.addEventListener('paste', function() {
                setTimeout(() => {
                    smartCapitalize(this);
                }, 50);
            });
        }
        
        // Handle floating labels
        inputs.forEach(input => {
            // Check if input has value (for browser autofill)
            if (input.value !== '') {
                const label = input.nextElementSibling;
                if (label && label.tagName === 'LABEL') {
                    label.style.top = '0';
                    label.style.fontSize = '12px';
                    label.style.backgroundColor = 'white';
                    label.style.color = '#CCB000';
                }
            }
            
            // Handle focus event
            input.addEventListener('focus', function() {
                const label = this.nextElementSibling;
                if (label && label.tagName === 'LABEL') {
                    label.style.top = '0';
                    label.style.fontSize = '12px';
                    label.style.backgroundColor = 'white';
                    label.style.color = this.classList.contains('student-id') ? 
                                        '#2563eb' : '#CCB000';
                }
            });
            
            // Handle blur event
            input.addEventListener('blur', function() {
                const label = this.nextElementSibling;
                if (label && label.tagName === 'LABEL' && this.value === '') {
                    label.style.top = '50%';
                    label.style.fontSize = '16px';
                    label.style.backgroundColor = 'transparent';
                    label.style.color = '#777';
                }
            });
        });
        
        // Additional check for autofilled inputs (with a slight delay)
        setTimeout(function() {
            inputs.forEach(input => {
                if (input.value !== '') {
                    const label = input.nextElementSibling;
                    if (label && label.tagName === 'LABEL') {
                        label.style.top = '0';
                        label.style.fontSize = '12px';
                        label.style.backgroundColor = 'white';
                        label.style.color = '#CCB000';
                    }
                    
                    // Check if it's a student ID
                    if (input.id === 'identifier') {
                        smartCapitalize(input);
                    }
                }
            });
        }, 100);
    });

    // Prevent form resubmission
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    </script>
</body>
</html>