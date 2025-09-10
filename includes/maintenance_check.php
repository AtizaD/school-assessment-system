<?php
/**
 * Maintenance Mode Check
 * Include this file in entry points to check for maintenance mode
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

function isMaintenanceModeActive() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Check if SystemSettings table exists and get maintenance mode status
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'maintenance_mode' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && (bool)$result['setting_value'];
    } catch (Exception $e) {
        // If we can't check the database, assume maintenance mode is off
        error_log("Maintenance mode check failed: " . $e->getMessage());
        return false;
    }
}

function checkMaintenanceMode() {
    // Skip maintenance check for admin users
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return;
    }
    
    // Skip maintenance check for maintenance.php itself
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript === 'maintenance.php') {
        return;
    }
    
    // Check if maintenance mode is active
    if (isMaintenanceModeActive()) {
        // Redirect to maintenance page
        $maintenanceUrl = '/maintenance.php';
        
        // Use absolute URL if needed
        if (!headers_sent()) {
            header("Location: $maintenanceUrl");
            exit;
        } else {
            // If headers already sent, use JavaScript redirect
            echo "<script>window.location.href = '$maintenanceUrl';</script>";
            exit;
        }
    }
}

/**
 * Secure admin validation with multiple checks
 * Cannot be spoofed by session manipulation alone
 */
function isSecureAdmin() {
    // Basic session check
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        return false;
    }
    
    // Additional session validation
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        return false;
    }
    
    try {
        // Database verification of admin role
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT u.user_id, u.role 
            FROM users u 
            WHERE u.user_id = ? AND u.role = 'admin'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Session claims admin but database says otherwise - security violation
            error_log("Security Alert: Invalid admin session detected for user_id: " . $_SESSION['user_id']);
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        // Database error - fail secure (deny access)
        error_log("Admin validation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Secure server-side enforcement for maintenance mode
 * Blocks all functionality except for admins
 */
function enforceMaintenanceMode() {
    // Secure admin access validation
    if (isSecureAdmin()) {
        return true; // Allow access
    }
    
    // Allow access to login page and maintenance check API
    $allowedScripts = ['login.php', 'check_maintenance.php', 'maintenance.php'];
    $currentScript = basename($_SERVER['PHP_SELF']);
    
    if (in_array($currentScript, $allowedScripts)) {
        return true; // Allow access to these specific pages
    }
    
    // Check if maintenance mode is active
    if (isMaintenanceModeActive()) {
        // Log unauthorized access attempt
        $userInfo = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        error_log("Maintenance Mode Violation: User '$userInfo' from IP '$ip' attempted to access '$uri'");
        
        // For AJAX/API requests, return JSON error
        if (isAjaxRequest() || isApiRequest()) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'System is under maintenance. Access denied.',
                'maintenance_mode' => true,
                'code' => 503
            ]);
            exit;
        }
        
        // For regular page requests during maintenance mode:
        // DO NOT redirect - allow page to load so overlay can be shown
        // The overlay in base_header.php will handle the user experience
        // We only block actual form submissions and critical actions
        
        // Only block if this is a form submission or critical action
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Block POST requests (form submissions, actions)
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'System is under maintenance. Action blocked.',
                    'maintenance_mode' => true
                ]);
                exit;
            }
        }
    }
    
    return true; // Allow access if maintenance is not active
}

/**
 * Check if current request is AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Check if current request is API call
 */
function isApiRequest() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($uri, '/api/') !== false;
}

/**
 * Display maintenance message when headers are already sent
 */
function displayMaintenanceMessage() {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>System Under Maintenance</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { 
                font-family: Arial, sans-serif; 
                text-align: center; 
                padding: 50px; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .container {
                background: rgba(255,255,255,0.1);
                padding: 3rem;
                border-radius: 20px;
                backdrop-filter: blur(10px);
            }
            .icon { font-size: 4rem; margin-bottom: 1rem; }
            h1 { margin-bottom: 1rem; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="icon">🔧</div>
            <h1>System Under Maintenance</h1>
            <p>Access denied. The system is currently under maintenance.</p>
            <p><a href="/login.php" style="color: #ffc107;">Return to Login</a></p>
        </div>
    </body>
    </html>';
}

// Note: Auto-check disabled - now handled via overlay in base_header.php
// This file only provides the utility functions
?>