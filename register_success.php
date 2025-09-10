<?php
// register_success.php
define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Verify registration data exists
if (!isset($_SESSION['registration_success'])) {
    redirectTo('register.php');
}

$registrationData = $_SESSION['registration_success'];
unset($_SESSION['registration_success']); // Clear the data
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - BASS</title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/styles.css">
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #FFF6D5 0%, #FFE5A8 100%);
            font-family: system-ui, -apple-system, sans-serif;
            padding: 20px;
        }

        .success-container {
            background: white;
            padding: 30px 25px;
            border-radius: 20px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #FFE5A8, #FFF6D5, #FFE5A8);
        }

        .success-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(145deg, #dcfce7, #bbf7d0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: #16a34a;
            font-size: 32px;
            box-shadow: 0 6px 12px rgba(22, 163, 74, 0.2);
            animation: successPulse 2s ease-in-out;
        }

        @keyframes successPulse {
            0% { transform: scale(0.8); opacity: 0; }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
            font-weight: 600;
        }

        .welcome-text {
            color: #666;
            font-size: 16px;
            margin-bottom: 25px;
        }

        .credentials {
            background: linear-gradient(145deg, #f8f9fa, #ffffff);
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: left;
            border: 1px solid #e9ecef;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .credential-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 12px 0;
            padding: 10px 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
        }

        .credential-item:hover {
            border-color: #FFE5A8;
            box-shadow: 0 2px 8px rgba(255, 229, 168, 0.3);
        }

        .credential-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .credential-value {
            font-family: 'Courier New', monospace;
            font-size: 25px;
            color: #ffef03;
            background: #000;
            padding: 8px 12px;
            border-radius: 15px;
            border: 1px solid #e9ecef;
            flex: 1;
            margin-right: 8px;
            word-break: break-all;
            font-weight: 500;
        }

        .password-container {
            position: relative;
            display: flex;
            align-items: center;
            flex: 1;
            margin-right: 8px;
        }

        .password-field {
            margin-right: 0;
            padding-right: 45px;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            cursor: pointer;
            color: #ffef03;
            font-size: 16px;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .password-toggle-btn:hover {
            background: rgba(255, 239, 3, 0.1);
            color: #fff;
        }

        

        .copy-btn {
            background: linear-gradient(145deg, #FFE5A8, #FFF6D5);
            border: none;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #333;
            font-size: 12px;
            min-width: 35px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .copy-btn:hover {
            background: linear-gradient(145deg, #FFD700, #FFE5A8);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 229, 168, 0.4);
        }

        .copy-btn:active {
            transform: translateY(0);
        }

        .copy-btn.copied {
            background: linear-gradient(145deg, #dcfce7, #bbf7d0);
            color: #16a34a;
        }

        .btn-login {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(145deg, #333, #000);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            margin-top: 8px;
        }

        .btn-login:hover {
            background: linear-gradient(145deg, #000, #333);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            color: white;
        }

        .important-note {
            margin-top: 20px;
            padding: 15px;
            background: linear-gradient(145deg, #fff3cd, #ffeeba);
            border: 1px solid #ffeeba;
            border-radius: 10px;
            color: #856404;
            font-size: 13px;
            text-align: left;
            box-shadow: 0 2px 6px rgba(255, 193, 7, 0.15);
        }

        .important-note i {
            color: #f0ad4e;
            margin-right: 8px;
        }

        .success-message {
            margin-top: 20px;
            padding: 15px;
            background: linear-gradient(145deg, #d1ecf1, #bee5eb);
            border: 1px solid #bee5eb;
            border-radius: 10px;
            color: #0c5460;
            font-size: 14px;
            text-align: left;
            box-shadow: 0 2px 6px rgba(23, 162, 184, 0.15);
        }

        .success-message i {
            color: #17a2b8;
            margin-right: 8px;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #16a34a;
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.3);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1000;
            font-weight: 500;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast i {
            margin-right: 8px;
        }

        @media (max-width: 768px) {
            .success-container {
                padding: 25px 20px;
                margin: 10px;
            }

            .credential-item {
                flex-direction: column;
                align-items: stretch;
            }

            .credential-value {
                margin-right: 0;
                margin-bottom: 8px;
            }

            .password-container {
                margin-right: 0;
                margin-bottom: 8px;
            }

            .password-field {
                margin-bottom: 0;
            }

            .copy-btn {
                width: 100%;
                justify-content: center;
            }

            h1 {
                font-size: 22px;
            }

            .welcome-text {
                font-size: 15px;
            }
        }

        /* Loading animation for copy button */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .copy-btn.loading i {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <h1>Registration Successful!</h1>
        <p class="welcome-text">Welcome <strong><?php echo htmlspecialchars($registrationData['name']); ?></strong>!</p>
        
        <div class="credentials">
            <div class="credential-item">
                <div style="flex: 1;">
                    <div class="credential-label">Username</div>
                    <div class="credential-value" id="username"><?php echo htmlspecialchars($registrationData['username']); ?></div>
                </div>
                <button class="copy-btn" onclick="copyToClipboard('username', this)" title="Copy username">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
            
            <div class="credential-item">
                <div style="flex: 1;">
                    <div class="credential-label">
                        <?php echo (isset($registrationData['password_set']) && $registrationData['password_set']) ? 'Your Password' : 'Initial Password'; ?>
                    </div>
                    <div class="password-container">
                        <div class="credential-value password-field" id="password">
                            <?php 
                            if (isset($registrationData['password_set']) && $registrationData['password_set']) {
                                echo str_repeat('•', strlen($registrationData['password']));
                            } else {
                                echo htmlspecialchars($registrationData['username']);
                            }
                            ?>
                        </div>
                        <div class="credential-value password-field" id="password-visible" style="display: none;">
                            <?php 
                            if (isset($registrationData['password_set']) && $registrationData['password_set']) {
                                echo htmlspecialchars($registrationData['password']);
                            } else {
                                echo htmlspecialchars($registrationData['username']);
                            }
                            ?>
                        </div>
                        <button class="password-toggle-btn" onclick="togglePasswordDisplay()" title="Show/Hide password">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </button>
                    </div>
                </div>
                <button class="copy-btn" onclick="copyToClipboard('password-visible', this)" title="Copy password">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>
        
        <?php if (isset($registrationData['password_set']) && $registrationData['password_set']): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <strong>Your account is ready!</strong> You can now login with your chosen username and password.
            </div>
        <?php else: ?>
            <div class="important-note">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Please save these credentials securely. 
                You will need to change your password on your first login for security purposes.
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 25px;">
            <a href="login.php" class="btn-login">
                <i class="fas fa-sign-in-alt" style="margin-right: 6px;"></i>Proceed to Login
            </a>
        </div>
    </div>

    <!-- Toast notification -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toast-message">Copied to clipboard!</span>
    </div>

    <script>
        // Prevent going back to registration form
        history.pushState(null, '', document.URL);
        window.addEventListener('popstate', function () {
            history.pushState(null, '', document.URL);
        });

        // Toggle password display
        function togglePasswordDisplay() {
            const passwordHidden = document.getElementById('password');
            const passwordVisible = document.getElementById('password-visible');
            const toggleIcon = document.getElementById('password-toggle-icon');
            
            if (passwordHidden.style.display === 'none') {
                // Show dots, hide password
                passwordHidden.style.display = 'block';
                passwordVisible.style.display = 'none';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                // Show password, hide dots
                passwordHidden.style.display = 'none';
                passwordVisible.style.display = 'block';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        }

        // Copy to clipboard function
        async function copyToClipboard(elementId, button) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            // Add loading state
            button.classList.add('loading');
            const icon = button.querySelector('i');
            const originalIcon = icon.className;
            icon.className = 'fas fa-spinner';
            
            try {
                // Try modern clipboard API first
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    textArea.style.position = 'fixed';
                    textArea.style.left = '-999999px';
                    textArea.style.top = '-999999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    document.execCommand('copy');
                    textArea.remove();
                }
                
                // Show success state
                setTimeout(() => {
                    button.classList.remove('loading');
                    button.classList.add('copied');
                    icon.className = 'fas fa-check';
                    
                    // Show toast
                    const toast = document.getElementById('toast');
                    const toastMessage = document.getElementById('toast-message');
                    const label = elementId === 'username' ? 'Username' : 'Password';
                    toastMessage.textContent = `${label} copied to clipboard!`;
                    toast.classList.add('show');
                    
                    // Hide toast after 3 seconds
                    setTimeout(() => {
                        toast.classList.remove('show');
                    }, 3000);
                    
                    // Reset button after 2 seconds
                    setTimeout(() => {
                        button.classList.remove('copied');
                        icon.className = originalIcon;
                    }, 2000);
                }, 500);
                
            } catch (err) {
                // Handle error
                setTimeout(() => {
                    button.classList.remove('loading');
                    icon.className = 'fas fa-exclamation-triangle';
                    
                    const toast = document.getElementById('toast');
                    const toastMessage = document.getElementById('toast-message');
                    toast.style.background = '#dc3545';
                    toastMessage.textContent = 'Failed to copy. Please select and copy manually.';
                    toast.classList.add('show');
                    
                    setTimeout(() => {
                        toast.classList.remove('show');
                        toast.style.background = '#16a34a';
                    }, 3000);
                    
                    // Reset button
                    setTimeout(() => {
                        icon.className = originalIcon;
                    }, 2000);
                }, 500);
            }
        }

        // Add some entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.success-container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                container.style.transition = 'all 0.6s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>