<?php
/**
* Main Configuration
*/

if (!defined('BASEPATH')) {
   define('BASEPATH', $_SERVER['DOCUMENT_ROOT']);
}

/**
 * Enhanced HTTPS detection for ngrok and reverse proxies
 */
function isHTTPS() {
   // Check standard HTTPS server variable
   if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
       return true;
   }
   
   // Check for forwarded protocol headers (common with reverse proxies)
   if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
       return true;
   }
   
   if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
       return true;
   }
   
   // Check for CloudFlare
   if (isset($_SERVER['HTTP_CF_VISITOR'])) {
       $cf_visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
       if ($cf_visitor && isset($cf_visitor['scheme']) && $cf_visitor['scheme'] === 'https') {
           return true;
       }
   }
   
   // Check for standard HTTPS port
   if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
       return true;
   }
   
   // Check if the request scheme is https
   if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
       return true;
   }
   
   // Check for ngrok specifically
   $host = $_SERVER['HTTP_HOST'] ?? '';
   if (strpos($host, 'ngrok') !== false || strpos($host, 'ngrok-free.app') !== false) {
       return true;
   }
   
   // Check for other common tunnel services
   if (strpos($host, 'herokuapp.com') !== false || 
       strpos($host, 'vercel.app') !== false ||
       strpos($host, 'netlify.app') !== false) {
       return true;
   }
   
   return false;
}

function requireHTTPS() {
   if (!isHTTPS()) {
       if (!headers_sent()) {
           $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
           header('HTTP/1.1 301 Moved Permanently');
           header('Location: ' . $redirect);
           exit();
       }
   }
}

function getBaseURL() {
   $protocol = isHTTPS() ? 'https://' : 'http://';
   $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
   $baseDir = '';
   return $protocol . $host . $baseDir;
}

/**
 * Helper function to get asset URLs with correct protocol
 */
function getAssetURL($path = '') {
   return ASSETS_URL . '/' . ltrim($path, '/');
}

/**
 * Helper function to get full URLs with correct protocol
 */
function getURL($path = '') {
   return BASE_URL . '/' . ltrim($path, '/');
}

// System paths
define('CONFIG_PATH', BASEPATH . '/config');
define('INCLUDES_PATH', BASEPATH . '/includes');
define('MODELS_PATH', BASEPATH . '/models');
define('ASSETS_PATH', BASEPATH . '/assets');
define('LOGS_PATH', BASEPATH . '/logs');
define('CACHE_PATH', BASEPATH . '/cache');

// URL paths
define('BASE_URL', getBaseURL());
define('ASSETS_URL', BASE_URL . '/assets');

// System settings
define('SYSTEM_NAME', 'School Management System');
define('TIMEZONE', 'UTC');
define('DEBUG_MODE', true);

// Environment detection
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('IS_DEVELOPMENT', (strpos($host, 'localhost') !== false || 
                         strpos($host, '127.0.0.1') !== false || 
                         strpos($host, 'ngrok') !== false));
define('IS_PRODUCTION', !IS_DEVELOPMENT);

// Session configuration with dynamic secure setting
define('SESSION_LIFETIME', 7200);
define('SESSION_NAME', 'SCHOOL_SESSID');
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', isHTTPS()); // Dynamic based on actual HTTPS status
define('COOKIE_HTTPONLY', true);

// Security settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_LOGIN_ATTEMPTS', 3);
define('LOGIN_TIMEOUT', 300);

// Password security settings (moved from auth.php)
define('MAX_FAILED_LOGIN_ATTEMPTS', 5);
define('ACCOUNT_LOCK_DURATION', 900); // 15 minutes in seconds
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// Database settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'school_db');
define('DB_USER', 'school_user');
define('DB_PASS', 'your_secure_password');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Email settings
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@example.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM', 'noreply@example.com');
define('SMTP_FROM_NAME', 'School Management System');

// File upload settings
define('MAX_UPLOAD_SIZE', 5242880);
define('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,pdf,doc,docx');
define('UPLOAD_PATH', BASEPATH . '/uploads');

// Initialize system
function initializeSystem() {
   date_default_timezone_set(TIMEZONE);
   
   // Create necessary directories
   $directories = [LOGS_PATH, UPLOAD_PATH, CACHE_PATH];
   foreach ($directories as $dir) {
       if (!is_dir($dir)) {
           mkdir($dir, 0755, true);
       }
   }
   
   if (DEBUG_MODE) {
       error_reporting(E_ALL);
       ini_set('display_errors', 1);
       ini_set('log_errors', 1);
       ini_set('error_log', LOGS_PATH . '/php_errors.log');
   } else {
       error_reporting(0);
       ini_set('display_errors', 0);
       ini_set('log_errors', 1);
       ini_set('error_log', LOGS_PATH . '/php_errors.log');
   }
   
   // Configure session settings only if session is not already active and we're not in CLI mode
   if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
       ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
       ini_set('session.cookie_lifetime', SESSION_LIFETIME);
       ini_set('session.use_strict_mode', 1);
       ini_set('session.use_only_cookies', 1);
       ini_set('session.cookie_httponly', 1);
       ini_set('session.cookie_samesite', 'Strict');
       
       session_set_cookie_params([
           'lifetime' => SESSION_LIFETIME,
           'path' => COOKIE_PATH,
           'domain' => COOKIE_DOMAIN,
           'secure' => COOKIE_SECURE,
           'httponly' => COOKIE_HTTPONLY,
           'samesite' => 'Strict'
       ]);
       
       session_name(SESSION_NAME);
       session_start();
   }

   // Security headers - only if not in CLI mode and headers haven't been sent
   if (php_sapi_name() !== 'cli' && !headers_sent()) {
       header('X-Frame-Options: SAMEORIGIN');
       header('X-XSS-Protection: 1; mode=block');
       header('X-Content-Type-Options: nosniff');
       header('Referrer-Policy: strict-origin-when-cross-origin');
       
       // Only add HSTS header if we're actually using HTTPS
       if (COOKIE_SECURE && isHTTPS()) {
           header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
       }
   }
}

function enforceHTTPS() {
   // Only enforce HTTPS in production or when explicitly required
   if (COOKIE_SECURE && !isHTTPS() && IS_PRODUCTION) {
       if (!headers_sent()) {
           $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
           header('HTTP/1.1 301 Moved Permanently');
           header('Location: ' . $redirect);
           exit();
       }
   }
}

/**
 * Function to log system activities
 */
function logActivity($level, $message, $context = []) {
   $timestamp = date('Y-m-d H:i:s');
   $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
   $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
   
   $logEntry = [
       'timestamp' => $timestamp,
       'level' => $level,
       'message' => $message,
       'ip' => $ip,
       'user_agent' => $userAgent,
       'context' => $context
   ];
   
   $logLine = $timestamp . " [{$level}] " . $message . 
              " | IP: {$ip} | Context: " . json_encode($context) . PHP_EOL;
   
   file_put_contents(LOGS_PATH . '/system.log', $logLine, FILE_APPEND | LOCK_EX);
}

// Enhanced error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
   if (!(error_reporting() & $errno)) {
       return false;
   }
   
   $error_message = date('Y-m-d H:i:s') . " - Error: [$errno] $errstr - $errfile:$errline\n";
   error_log($error_message, 3, LOGS_PATH . '/custom_errors.log');
   
   // Log to our system log as well
   logActivity('ERROR', "PHP Error: $errstr", [
       'errno' => $errno,
       'file' => $errfile,
       'line' => $errline
   ]);
   
   if (DEBUG_MODE) {
       throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
   }
   
   return true;
});

// Enhanced exception handler
set_exception_handler(function($exception) {
   $error_message = date('Y-m-d H:i:s') . " - Exception: " . $exception->getMessage() . 
                   " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n";
   error_log($error_message, 3, LOGS_PATH . '/exceptions.log');
   
   // Log to our system log as well
   logActivity('EXCEPTION', $exception->getMessage(), [
       'file' => $exception->getFile(),
       'line' => $exception->getLine(),
       'trace' => $exception->getTraceAsString()
   ]);
   
   if (DEBUG_MODE) {
       echo "<h1>System Exception</h1>";
       echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
       echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
       echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
       echo "<details><summary>Stack Trace</summary><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></details>";
   } else {
       echo "<h1>System Error</h1>";
       echo "<p>An error occurred. Please try again later.</p>";
   }
});

// Add a function to check system health
function checkSystemHealth() {
   $health = [
       'php_version' => PHP_VERSION,
       'https_enabled' => isHTTPS(),
       'session_status' => session_status() === PHP_SESSION_ACTIVE,
       'logs_writable' => is_writable(LOGS_PATH),
       'uploads_writable' => is_writable(UPLOAD_PATH),
       'base_url' => BASE_URL,
       'assets_url' => ASSETS_URL
   ];
   
   if (DEBUG_MODE) {
       logActivity('INFO', 'System health check', $health);
   }
   
   return $health;
}

// Include database configuration
require_once CONFIG_PATH . '/database.php';

// Initialize the system
initializeSystem();

// Enforce HTTPS if required (but be smart about development environments)
if (COOKIE_SECURE && IS_PRODUCTION) {
   enforceHTTPS();
}

// Include core system files in proper order
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Optional: Include other core files
// require_once INCLUDES_PATH . '/database.php';
// require_once INCLUDES_PATH . '/email.php';
// require_once INCLUDES_PATH . '/validation.php';
?>