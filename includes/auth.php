<?php
// includes/auth.php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Authentication and authorization functions with account locking security
 */

function authenticateUser($identifier, $password) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // First, get user information including lock status
        $stmt = $db->prepare(
            "SELECT user_id, username, password_hash, role, first_login, 
                    password_change_required, account_locked, locked_until,
                    failed_login_attempts, last_failed_attempt
             FROM Users 
             WHERE username = ? OR email = ?"
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            // User not found - log failed attempt
            logFailedLoginAttempt(null, $identifier);
            logSystemActivity(
                'Authentication',
                "Failed login attempt for unknown identifier: $identifier",
                'WARNING'
            );
            return false;
        }

        // Check if account is currently locked
        if ($user['account_locked'] == 1) {
            // Check if lock period has expired
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                // Account is still locked
                logSystemActivity(
                    'Authentication',
                    "Login attempt on locked account: {$user['username']} (locked until: {$user['locked_until']})",
                    'WARNING',
                    $user['user_id']
                );
                return false;
            } else {
                // Lock period has expired, unlock the account
                unlockAccount($user['user_id']);
                logSystemActivity(
                    'Authentication',
                    "Account automatically unlocked (lock period expired): {$user['username']}",
                    'INFO',
                    $user['user_id']
                );
            }
        }

        // Verify password
        if (password_verify($password, $user['password_hash'])) {
            // Password is correct - reset failed attempts and proceed
            resetFailedLoginAttempts($user['user_id']);
            
            // Log successful login
            $stmt = $db->prepare(
                "INSERT INTO AuthLogs (
                    user_id, 
                    attempt_type, 
                    status, 
                    ip_address, 
                    user_agent
                ) VALUES (?, 'LOGIN', 'SUCCESS', ?, ?)"
            );
            $stmt->execute([
                $user['user_id'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['first_login'] = $user['first_login'];
            $_SESSION['password_change_required'] = $user['password_change_required'];

            // If it's first login or password change required, set a flag
            if ($user['first_login'] || $user['password_change_required']) {
                $_SESSION['force_password_change'] = true;
            }

            logSystemActivity(
                'Authentication',
                "Successful login for user: {$user['username']}",
                'INFO',
                $user['user_id']
            );

            return true;
        } else {
            // Password is incorrect - handle failed attempt
            handleFailedLoginAttempt($user['user_id'], $user['username']);
            return false;
        }

    } catch (Exception $e) {
        logError("Authentication error: " . $e->getMessage());
        return false;
    }
}

function handleFailedLoginAttempt($userId, $username) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Increment failed login attempts
        $stmt = $db->prepare(
            "UPDATE Users 
             SET failed_login_attempts = failed_login_attempts + 1,
                 last_failed_attempt = CURRENT_TIMESTAMP
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
        // Get current failed attempts count
        $stmt = $db->prepare(
            "SELECT failed_login_attempts FROM Users WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        $failedAttempts = $result['failed_login_attempts'];
        
        // Check if account should be locked (e.g., after 5 failed attempts)
        $maxAttempts = defined('MAX_FAILED_LOGIN_ATTEMPTS') ? MAX_FAILED_LOGIN_ATTEMPTS : 5;
        $lockDuration = defined('ACCOUNT_LOCK_DURATION') ? ACCOUNT_LOCK_DURATION : 900; // 15 minutes
        
        if ($failedAttempts >= $maxAttempts) {
            lockAccount($userId, $lockDuration);
            
            logSystemActivity(
                'Authentication',
                "Account locked due to excessive failed login attempts: $username (Total attempts: $failedAttempts)",
                'CRITICAL',
                $userId
            );
        }
        
        // Log failed login attempt
        logFailedLoginAttempt($userId, $username);
        
        logSystemActivity(
            'Authentication',
            "Failed login attempt for user: $username (Attempt #$failedAttempts)",
            'WARNING',
            $userId
        );
        
    } catch (Exception $e) {
        logError("Error handling failed login attempt: " . $e->getMessage());
    }
}

function lockAccount($userId, $lockDurationSeconds = 900) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $lockedUntil = date('Y-m-d H:i:s', time() + $lockDurationSeconds);
        
        $stmt = $db->prepare(
            "UPDATE Users 
             SET account_locked = 1,
                 locked_until = ?
             WHERE user_id = ?"
        );
        $stmt->execute([$lockedUntil, $userId]);
        
    } catch (Exception $e) {
        logError("Error locking account: " . $e->getMessage());
    }
}

function unlockAccount($userId) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "UPDATE Users 
             SET account_locked = 0,
                 locked_until = NULL,
                 failed_login_attempts = 0
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
    } catch (Exception $e) {
        logError("Error unlocking account: " . $e->getMessage());
    }
}

function resetFailedLoginAttempts($userId) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "UPDATE Users 
             SET failed_login_attempts = 0,
                 last_failed_attempt = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
    } catch (Exception $e) {
        logError("Error resetting failed login attempts: " . $e->getMessage());
    }
}

function logFailedLoginAttempt($userId, $identifier) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "INSERT INTO AuthLogs (
                user_id,
                attempt_type,
                status,
                ip_address,
                user_agent
            ) VALUES (?, 'FAILED_LOGIN', 'FAILURE', ?, ?)"
        );
        $stmt->execute([
            $userId, // Will be NULL if user not found
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
    } catch (Exception $e) {
        logError("Error logging failed login attempt: " . $e->getMessage());
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getRole() {
    return $_SESSION['user_role'] ?? null;
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUsername() {
    return $_SESSION['username'] ?? null;
}

function requirePasswordChange() {
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change']) {
        redirectTo('/change-password.php');
    }
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirectTo('/login.php');
    }
}

function requireRole($allowedRoles) {
    requireLogin();
    $userRole = getRole();
    if (!in_array($userRole, (array)$allowedRoles)) {
        redirectTo('/unauthorized.php');
    }
}

function hasRole($role) {
    return getRole() === $role;
}

function logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $userId = getUserId();
    
    if ($userId) {
        try {
            // Mark user session as inactive in database
            $db = DatabaseConfig::getInstance()->getConnection();
            $stmt = $db->prepare(
                "UPDATE UserSessions 
                 SET is_active = FALSE 
                 WHERE user_id = ? AND session_id = ?"
            );
            $stmt->execute([$userId, session_id()]);
            
            // Log logout
            $stmt = $db->prepare(
                "INSERT INTO AuthLogs (
                    user_id,
                    attempt_type,
                    status,
                    ip_address,
                    user_agent
                ) VALUES (?, 'LOGOUT', 'SUCCESS', ?, ?)"
            );
            $stmt->execute([
                $userId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            logSystemActivity(
                'Authentication',
                "User logged out: " . getUsername(),
                'INFO',
                $userId
            );
            
        } catch (Exception $e) {
            logError("Logout error: " . $e->getMessage());
        }
    }
    
    // Clear session
    $_SESSION = array();
    
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
}

function changePassword($userId, $newPassword, $requireCurrentPassword = true, $currentPassword = null) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // If current password verification is required
        if ($requireCurrentPassword) {
            if (empty($currentPassword)) {
                return ['success' => false, 'message' => 'Current password is required'];
            }
            
            $stmt = $db->prepare("SELECT password_hash FROM Users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
        }
        
        // Validate new password strength
        if (!validatePasswordStrength($newPassword)) {
            return ['success' => false, 'message' => 'Password does not meet security requirements'];
        }
        
        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and related fields
        $stmt = $db->prepare(
            "UPDATE Users 
             SET password_hash = ?,
                 last_password_change = CURRENT_TIMESTAMP,
                 first_login = 0,
                 password_change_required = 0,
                 failed_login_attempts = 0,
                 last_failed_attempt = NULL,
                 account_locked = 0,
                 locked_until = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$passwordHash, $userId]);
        
        // Log password change
        $stmt = $db->prepare(
            "INSERT INTO AuthLogs (
                user_id,
                attempt_type,
                status,
                ip_address,
                user_agent
            ) VALUES (?, 'PASSWORD_CHANGE', 'SUCCESS', ?, ?)"
        );
        $stmt->execute([
            $userId,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        logSystemActivity(
            'Authentication',
            "Password changed for user ID: $userId",
            'INFO',
            $userId
        );
        
        // Clear password change flag from session
        if (isset($_SESSION['force_password_change'])) {
            unset($_SESSION['force_password_change']);
        }
        
        return ['success' => true, 'message' => 'Password changed successfully'];
        
    } catch (Exception $e) {
        logError("Password change error: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while changing password'];
    }
}

function validatePasswordStrength($password) {
    // Define password requirements
    $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
    $requireUppercase = defined('PASSWORD_REQUIRE_UPPERCASE') ? PASSWORD_REQUIRE_UPPERCASE : true;
    $requireLowercase = defined('PASSWORD_REQUIRE_LOWERCASE') ? PASSWORD_REQUIRE_LOWERCASE : true;
    $requireNumber = defined('PASSWORD_REQUIRE_NUMBER') ? PASSWORD_REQUIRE_NUMBER : true;
    $requireSpecial = defined('PASSWORD_REQUIRE_SPECIAL') ? PASSWORD_REQUIRE_SPECIAL : true;
    
    // Check length
    if (strlen($password) < $minLength) {
        return false;
    }
    
    // Check uppercase
    if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
        return false;
    }
    
    // Check lowercase
    if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
        return false;
    }
    
    // Check number
    if ($requireNumber && !preg_match('/[0-9]/', $password)) {
        return false;
    }
    
    // Check special character
    if ($requireSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) {
        return false;
    }
    
    return true;
}

function getPasswordRequirements() {
    $requirements = [];
    
    $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
    $requirements[] = "At least $minLength characters long";
    
    if (defined('PASSWORD_REQUIRE_UPPERCASE') && PASSWORD_REQUIRE_UPPERCASE) {
        $requirements[] = "At least one uppercase letter";
    }
    
    if (defined('PASSWORD_REQUIRE_LOWERCASE') && PASSWORD_REQUIRE_LOWERCASE) {
        $requirements[] = "At least one lowercase letter";
    }
    
    if (defined('PASSWORD_REQUIRE_NUMBER') && PASSWORD_REQUIRE_NUMBER) {
        $requirements[] = "At least one number";
    }
    
    if (defined('PASSWORD_REQUIRE_SPECIAL') && PASSWORD_REQUIRE_SPECIAL) {
        $requirements[] = "At least one special character";
    }
    
    return $requirements;
}

function cleanupExpiredSessions() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Mark expired sessions as inactive
        $stmt = $db->prepare(
            "UPDATE UserSessions 
             SET is_active = FALSE 
             WHERE expire_timestamp < CURRENT_TIMESTAMP 
             AND is_active = TRUE"
        );
        $stmt->execute();
        
        // Optionally delete old inactive sessions (older than 30 days)
        $stmt = $db->prepare(
            "DELETE FROM UserSessions 
             WHERE is_active = FALSE 
             AND login_timestamp < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)"
        );
        $stmt->execute();
        
    } catch (Exception $e) {
        logError("Session cleanup error: " . $e->getMessage());
    }
}

function isSessionValid($userId, $sessionId) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as valid_session 
             FROM UserSessions 
             WHERE user_id = ? 
             AND session_id = ? 
             AND is_active = TRUE 
             AND expire_timestamp > CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $sessionId]);
        $result = $stmt->fetch();
        
        return $result['valid_session'] > 0;
        
    } catch (Exception $e) {
        logError("Session validation error: " . $e->getMessage());
        return false;
    }
}

// Administrative functions for account management
function adminUnlockAccount($userId) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "UPDATE Users 
             SET account_locked = 0,
                 locked_until = NULL,
                 failed_login_attempts = 0,
                 last_failed_attempt = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
        // Get username for logging
        $stmt = $db->prepare("SELECT username FROM Users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        logSystemActivity(
            'Administration',
            "Account manually unlocked by admin: " . ($user['username'] ?? "User ID $userId"),
            'INFO',
            getUserId() // Current admin user
        );
        
        return true;
        
    } catch (Exception $e) {
        logError("Admin unlock account error: " . $e->getMessage());
        return false;
    }
}

function adminLockAccount($userId, $reason = 'Administrative action') {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        $stmt = $db->prepare(
            "UPDATE Users 
             SET account_locked = 1,
                 locked_until = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
        // Get username for logging
        $stmt = $db->prepare("SELECT username FROM Users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        logSystemActivity(
            'Administration',
            "Account manually locked by admin: " . ($user['username'] ?? "User ID $userId") . ". Reason: $reason",
            'WARNING',
            getUserId() // Current admin user
        );
        
        return true;
        
    } catch (Exception $e) {
        logError("Admin lock account error: " . $e->getMessage());
        return false;
    }
}

function getAccountStatus($userId) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT account_locked, locked_until, failed_login_attempts, 
                    last_failed_attempt, last_password_change
             FROM Users 
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch();
        
    } catch (Exception $e) {
        logError("Get account status error: " . $e->getMessage());
        return false;
    }
}

?>