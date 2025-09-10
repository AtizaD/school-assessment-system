<?php
/**
 * URL Helper Functions for Clean URLs
 */



/**
 * Generate clean URLs
 */
function url($path = '') {
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    // Get the base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . $host;
    
    // Return clean URL
    return $baseUrl . '/' . $path;
}

/**
 * Generate admin URLs
 */
function adminUrl($path = '') {
    return url('admin/' . ltrim($path, '/'));
}

/**
 * Generate teacher URLs
 */
function teacherUrl($path = '') {
    return url('teacher/' . ltrim($path, '/'));
}

/**
 * Generate student URLs
 */
function studentUrl($path = '') {
    return url('student/' . ltrim($path, '/'));
}

/**
 * Generate API URLs
 */
function apiUrl($path = '') {
    return url('api/' . ltrim($path, '/'));
}

/**
 * Redirect to clean URL
 */
if (!function_exists('redirectTo')) {
    function redirectTo($path) {
        header('Location: ' . url($path));
        exit;
    }
}

/**
 * Check if current page matches a path
 */
function isCurrentPage($path) {
    $currentPath = $_SERVER['REQUEST_URI'] ?? '/';
    $currentPath = parse_url($currentPath, PHP_URL_PATH);
    $currentPath = rtrim($currentPath, '/') ?: '/';
    
    $checkPath = '/' . ltrim($path, '/');
    
    return $currentPath === $checkPath;
}

/**
 * Get current path
 */
function getCurrentPath() {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($path, PHP_URL_PATH);
    return rtrim($path, '/') ?: '/';
}

/**
 * Generate pagination URLs
 */
function paginationUrl($page, $params = []) {
    $currentPath = getCurrentPath();
    $params['page'] = $page;
    
    return $currentPath . '?' . http_build_query($params);
}

/**
 * Asset URL helper
 */
function asset($path) {
    return url('assets/' . ltrim($path, '/'));
}

/**
 * Clean URL breadcrumbs
 */
function getBreadcrumbs() {
    $path = getCurrentPath();
    $segments = array_filter(explode('/', $path));
    $breadcrumbs = [];
    
    $breadcrumbs[] = ['Home', url()];
    
    $currentPath = '';
    foreach ($segments as $segment) {
        $currentPath .= '/' . $segment;
        $title = ucfirst(str_replace(['-', '_'], ' ', $segment));
        $breadcrumbs[] = [$title, url($currentPath)];
    }
    
    return $breadcrumbs;
}

/**
 * Role-based URL generation
 */
function roleUrl($role, $path = '') {
    switch ($role) {
        case 'admin':
            return adminUrl($path);
        case 'teacher':
            return teacherUrl($path);
        case 'student':
            return studentUrl($path);
        default:
            return url($path);
    }
}

/**
 * Active navigation class helper
 */
function activeNav($path, $class = 'active') {
    return isCurrentPage($path) ? $class : '';
}
?>