<?php
//includes/functions.php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Generate CSRF token for form security
 */
function generateCSRFToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    return !empty($_SESSION[CSRF_TOKEN_NAME]) && 
           hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Redirect to specified path
 */
function redirectTo($path) {
    // Use the helper function from config if available, otherwise fallback to BASE_URL
    if (function_exists('getURL')) {
        header("Location: " . getURL($path));
    } else {
        header("Location: " . BASE_URL . '/' . ltrim($path, '/'));
    }
    exit;
}

/**
 * Log error messages to file
 */
function logError($message, $severity = 'ERROR') {
    $logFile = LOGS_PATH . '/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$severity] $message" . PHP_EOL;
    error_log($logMessage, 3, $logFile);
}

/**
 * Log system activities to database
 */
function logSystemActivity($component, $message, $severity = 'INFO', $userId = null) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO SystemLogs (
                severity,
                component,
                message,
                user_id,
                ip_address,
                created_at
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $severity,
            $component,
            $message,
            $userId ?? ($_SESSION['user_id'] ?? null),
            $_SERVER['REMOTE_ADDR']
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log system activity: " . $e->getMessage());
    }
}

/**
 * Enhanced redirect function with flash messages
 */
function redirectWithMessage($path, $message, $type = 'success') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    
    redirectTo($path);
}

/**
 * Display flash messages
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        
        $alertClass = match($type) {
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            default => 'alert-info'
        };
        
        echo "<div class='alert {$alertClass} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
    }
}

/**
 * Format file size for display
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowedTypes = null) {
    $allowedTypes = $allowedTypes ?? explode(',', ALLOWED_FILE_TYPES);
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "Upload failed with error code: " . $file['error'];
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return "File size exceeds maximum allowed size of " . formatFileSize(MAX_UPLOAD_SIZE);
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        return "File type not allowed. Allowed types: " . implode(', ', $allowedTypes);
    }
    
    return true;
}

/**
 * Check if user has specific permission
 * Note: This works with roles defined in auth.php
 */
function hasPermission($permission) {
    // Must be logged in to have any permissions
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    
    // Get user role (function from auth.php)
    $userRole = function_exists('getRole') ? getRole() : null;
    if (!$userRole) {
        return false;
    }
    
    // Admin has all permissions
    if ($userRole === 'admin') {
        return true;
    }
    
    // Define role-based permissions
    $permissions = [
        'teacher' => [
            'view_students',
            'create_exeat',
            'approve_exeat',
            'view_reports',
            'manage_courses',
            'grade_students'
        ],
        'student' => [
            'view_own_profile',
            'request_exeat',
            'view_own_exeats',
            'view_courses',
            'view_grades',
            'update_profile'
        ],
        'parent' => [
            'view_child_profile',
            'view_child_exeats',
            'approve_child_exeat'
        ]
    ];
    
    return isset($permissions[$userRole]) && 
           in_array($permission, $permissions[$userRole]);
}

/**
 * Require specific permission or redirect
 */
function requirePermission($permission, $redirectPath = '/login') {
    if (!hasPermission($permission)) {
        redirectWithMessage($redirectPath, 'Access denied. Insufficient permissions.', 'error');
    }
}

/**
 * Generate secure random string
 */
function generateSecureString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic validation)
 */
function isValidPhone($phone) {
    // Basic phone validation - adjust regex as needed for your region
    return preg_match('/^[\+]?[0-9\s\-\(\)]{10,15}$/', $phone);
}

/**
 * Clean and format phone number
 */
function formatPhone($phone) {
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9\+]/', '', $phone);
    return $phone;
}

/**
 * Time ago function
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

/**
 * Escape output for HTML display
 */
function escapeHtml($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'Y-m-d H:i:s') {
    if (!$date) return 'N/A';
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

/**
 * Format date as user-friendly string
 */
function formatDateUser($date) {
    if (!$date) return 'N/A';
    
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format('M j, Y g:i A');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

/**
 * Generate pagination HTML
 */
function generatePagination($currentPage, $totalPages, $baseUrl, $queryParams = []) {
    if ($totalPages <= 1) return '';
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $prevParams = array_merge($queryParams, ['page' => $currentPage - 1]);
        $prevUrl = $baseUrl . '?' . http_build_query($prevParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $prevUrl . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $firstParams = array_merge($queryParams, ['page' => 1]);
        $firstUrl = $baseUrl . '?' . http_build_query($firstParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $firstUrl . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $pageParams = array_merge($queryParams, ['page' => $i]);
        $pageUrl = $baseUrl . '?' . http_build_query($pageParams);
        
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $pageUrl . '">' . $i . '</a></li>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $lastParams = array_merge($queryParams, ['page' => $totalPages]);
        $lastUrl = $baseUrl . '?' . http_build_query($lastParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $lastUrl . '">' . $totalPages . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $nextParams = array_merge($queryParams, ['page' => $currentPage + 1]);
        $nextUrl = $baseUrl . '?' . http_build_query($nextParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $nextUrl . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul></nav>';
    
    return $html;
}

/**
 * Get user's IP address (handles proxies)
 */
function getUserIP() {
    // Check for various proxy headers
    $ipKeys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

/**
 * Generate a random color in hex format
 */
function generateRandomColor() {
    return sprintf('#%06X', mt_rand(0, 0xFFFFFF));
}

/**
 * Convert string to URL-friendly slug
 */
function createSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    return trim($string, '-');
}

/**
 * Truncate text to specified length
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
}

/**
 * Check if current request is AJAX
 */
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get current URL
 */
function getCurrentUrl() {
    $protocol = isHTTPS() ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Check if string starts with another string
 */
function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

/**
 * Check if string ends with another string
 */
function endsWith($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Check if maintenance mode is enabled
 */
function isMaintenanceMode() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'maintenance_mode'");
        $stmt->execute();
        $result = $stmt->fetch();
        return ($result && $result['setting_value'] == '1');
    } catch (Exception $e) {
        logError("Error checking maintenance mode: " . $e->getMessage());
        return false; // Fail safe - assume not in maintenance mode
    }
}

/**
 * Check if user is admin (used for maintenance mode exemption)
 */
function isAdmin() {
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    
    $userRole = function_exists('getRole') ? getRole() : null;
    return $userRole === 'admin';
}

/**
 * Enforce maintenance mode restrictions
 * Blocks non-admin users when maintenance mode is enabled
 */
function enforceMaintenanceMode() {
    if (isMaintenanceMode() && !isAdmin()) {
        // Redirect to maintenance page
        redirectTo('/maintenance.php');
    }
}
?>