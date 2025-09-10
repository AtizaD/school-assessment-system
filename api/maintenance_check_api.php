<?php
/**
 * API Maintenance Mode Check
 * Include this file in API endpoints to check for maintenance mode
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
    require_once BASEPATH . '/config/config.php';
}

require_once INCLUDES_PATH . '/maintenance_check.php';

/**
 * Secure API maintenance mode enforcement
 * This cannot be bypassed by client-side manipulation
 */
function enforceApiMaintenanceMode() {
    // Allow secure admin access
    if (isSecureAdmin()) {
        return; // Admin bypass with secure validation
    }
    
    // Allow access to maintenance check API itself
    $currentScript = basename($_SERVER['PHP_SELF']);
    if ($currentScript === 'check_maintenance.php') {
        return; // Allow maintenance status checks
    }
    
    // Check if maintenance mode is active
    if (isMaintenanceModeActive()) {
        // Log unauthorized API access attempt
        $userInfo = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'Unknown';
        
        error_log("API Maintenance Mode Violation: User '$userInfo' from IP '$ip' attempted $method '$uri'");
        
        // Return secure JSON error response
        http_response_code(503);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $response = [
            'success' => false,
            'error' => 'System is under maintenance. API access denied.',
            'maintenance_mode' => true,
            'code' => 503,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
}

// Auto-enforce maintenance mode for all API calls
enforceApiMaintenanceMode();
?>