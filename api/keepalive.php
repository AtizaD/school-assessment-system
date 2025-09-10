<?php
// api/keepalive.php - Session keepalive endpoint
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Set JSON headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Check if user is logged in
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    
    // Update session activity time
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    // Log the keepalive activity
    logSystemActivity(
        'Session',
        'Session extended via keepalive for user ID: ' . $_SESSION['user_id'],
        'INFO',
        $_SESSION['user_id']
    );
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Session extended',
        'new_expiry' => time() + SESSION_LIFETIME
    ]);
    
} catch (Exception $e) {
    logError("Keepalive error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>