<?php
/**
 * Logout Script
 * Handles user logout, session cleanup, and logout event logging
 */

// Include the configuration file using absolute path
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Store the user ID and session ID before we clear the session
$userId = $_SESSION['user_id'] ?? null;
$sessionId = session_id();

// If we had a logged in user, log the logout event
if ($userId) {
    logSystemActivity(
        'Authentication',
        'User logged out',
        'INFO',
        $userId
    );
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Log the logout event
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

        // Update user session status - now includes session_id in the WHERE clause
        $stmt = $db->prepare(
            "UPDATE UserSessions 
             SET is_active = FALSE 
             WHERE user_id = ? 
             AND session_id = ?
             AND is_active = TRUE"
        );
        $stmt->execute([$userId, $sessionId]);

        // Also invalidate any other active sessions for this user (optional, uncomment if needed)
        /*
        $stmt = $db->prepare(
            "UPDATE UserSessions 
             SET is_active = FALSE 
             WHERE user_id = ? 
             AND is_active = TRUE"
        );
        $stmt->execute([$userId]);
        */
    } catch (PDOException $e) {
        logError("Logout error: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Clear any other cookies that might be set
setcookie('PHPSESSID', '', time() - 3600, '/');
setcookie(SESSION_NAME, '', time() - 3600, '/');

// Prevent caching of this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Redirect to the login page with a cache-busting parameter
header('Location: ' . BASE_URL . '/login.php?logout=' . time());
exit;