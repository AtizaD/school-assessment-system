<?php
/**
 * API endpoint to check maintenance mode status
 * Returns JSON response indicating if maintenance mode is active
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/maintenance_check.php';

// Set JSON header
header('Content-Type: application/json');

try {
    $maintenanceActive = isMaintenanceModeActive();
    
    $response = [
        'success' => true,
        'maintenance_active' => $maintenanceActive,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // For admins, also include management info
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        $response['admin_access'] = true;
        $response['settings_url'] = BASE_URL . '/admin/settings.php';
    }
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'success' => false,
        'error' => 'Failed to check maintenance status',
        'maintenance_active' => false // Fail safe
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>