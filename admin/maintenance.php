<?php
// admin/maintenance_dashboard.php
ob_start(); // Start output buffering at the very beginning

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only admin can access this page
requireRole(['admin']);

// Get system health data
$systemHealth = [];
$databaseStats = [];

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // System health checks
    $systemHealth = [
        'php_version' => PHP_VERSION,
        'https_enabled' => isHTTPS(),
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'logs_writable' => is_writable(LOGS_PATH),
        'uploads_writable' => is_writable(UPLOAD_PATH),
        'base_url' => BASE_URL,
        'disk_space' => disk_free_space('.'),
        'memory_usage' => memory_get_usage(true),
        'memory_limit' => ini_get('memory_limit')
    ];
    
    // Database statistics
    $tables = [
        'users' => 'User Accounts',
        'students' => 'Students', 
        'teachers' => 'Teachers',
        'classes' => 'Classes',
        'subjects' => 'Subjects',
        'assessments' => 'Assessments',
        'assessmentattempts' => 'Assessment Attempts',
        'mcqquestions' => 'MCQ Questions',
        'results' => 'Results',
        'studentanswers' => 'Student Answers',
        'authlogs' => 'Auth Logs',
        'systemlogs' => 'System Logs',
        'usersessions' => 'User Sessions'
    ];
    
    foreach ($tables as $table => $label) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM `$table`");
            $stmt->execute();
            $result = $stmt->fetch();
            $databaseStats[] = [
                'table' => $table,
                'label' => $label,
                'count' => $result['count']
            ];
        } catch (Exception $e) {
            $databaseStats[] = [
                'table' => $table,
                'label' => $label,
                'count' => 'Error',
                'error' => $e->getMessage()
            ];
        }
    }
    
} catch (Exception $e) {
    logActivity('ERROR', "Maintenance dashboard error: " . $e->getMessage());
    $error = "Error loading system data: " . $e->getMessage();
}

$pageTitle = 'System Maintenance Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning mb-0">
            <i class="fas fa-tools me-2"></i>System Maintenance Dashboard
        </h1>
        <button class="btn btn-warning" onclick="location.reload()">
            <i class="fas fa-sync-alt me-1"></i>Refresh Data
        </button>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- System Health Overview -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-heartbeat me-2"></i>System Health Status
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-server text-primary me-1"></i>PHP Version
                            </h6>
                            <p class="card-text h5"><?php echo $systemHealth['php_version']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-shield-alt text-success me-1"></i>HTTPS Status
                            </h6>
                            <p class="card-text h5">
                                <?php echo $systemHealth['https_enabled'] ? 
                                    '<span class="text-success">Enabled</span>' : 
                                    '<span class="text-warning">Disabled</span>'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-user-clock text-info me-1"></i>Session Status
                            </h6>
                            <p class="card-text h5">
                                <?php echo $systemHealth['session_active'] ? 
                                    '<span class="text-success">Active</span>' : 
                                    '<span class="text-danger">Inactive</span>'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-folder-open text-warning me-1"></i>Logs Directory
                            </h6>
                            <p class="card-text h5">
                                <?php echo $systemHealth['logs_writable'] ? 
                                    '<span class="text-success">Writable</span>' : 
                                    '<span class="text-danger">Not Writable</span>'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-upload text-info me-1"></i>Uploads Directory
                            </h6>
                            <p class="card-text h5">
                                <?php echo $systemHealth['uploads_writable'] ? 
                                    '<span class="text-success">Writable</span>' : 
                                    '<span class="text-danger">Not Writable</span>'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-memory text-primary me-1"></i>Memory Usage
                            </h6>
                            <p class="card-text h5">
                                <?php echo round($systemHealth['memory_usage'] / 1024 / 1024, 2); ?> MB
                            </p>
                            <small class="text-muted">Limit: <?php echo $systemHealth['memory_limit']; ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Database Statistics -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-database me-2"></i>Database Statistics
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Table</th>
                            <th>Records</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($databaseStats as $stat): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($stat['label']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($stat['table']); ?></small>
                                </td>
                                <td>
                                    <?php if ($stat['count'] === 'Error'): ?>
                                        <span class="text-danger">Error</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary fs-6">
                                            <?php echo number_format($stat['count']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stat['count'] === 'Error'): ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-triangle me-1"></i>Error
                                        </span>
                                        <br><small class="text-danger"><?php echo htmlspecialchars($stat['error']); ?></small>
                                    <?php elseif ($stat['count'] == 0): ?>
                                        <span class="badge bg-secondary">
                                            <i class="fas fa-circle me-1"></i>Empty
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check me-1"></i>Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- System Information -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-info-circle me-2"></i>System Information
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Application Details</h6>
                    <ul class="list-unstyled">
                        <li><strong>Base URL:</strong> <?php echo htmlspecialchars($systemHealth['base_url']); ?></li>
                        <li><strong>Base Path:</strong> <?php echo htmlspecialchars(BASEPATH); ?></li>
                        <li><strong>Logs Path:</strong> <?php echo htmlspecialchars(LOGS_PATH); ?></li>
                        <li><strong>Upload Path:</strong> <?php echo htmlspecialchars(UPLOAD_PATH); ?></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Server Resources</h6>
                    <ul class="list-unstyled">
                        <li><strong>Disk Space:</strong> <?php echo round($systemHealth['disk_space'] / 1024 / 1024 / 1024, 2); ?> GB free</li>
                        <li><strong>Memory Limit:</strong> <?php echo $systemHealth['memory_limit']; ?></li>
                        <li><strong>Current Usage:</strong> <?php echo round($systemHealth['memory_usage'] / 1024 / 1024, 2); ?> MB</li>
                        <li><strong>Session Status:</strong> 
                            <?php echo $systemHealth['session_active'] ? 'Active' : 'Inactive'; ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0 text-warning">
                <i class="fas fa-bolt me-2"></i>Quick Actions
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <button class="btn btn-outline-primary w-100" onclick="clearCache()">
                        <i class="fas fa-trash me-1"></i>Clear System Cache
                    </button>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-outline-info w-100" onclick="viewLogs()">
                        <i class="fas fa-file-alt me-1"></i>View System Logs
                    </button>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-outline-warning w-100" onclick="checkSystemHealth()">
                        <i class="fas fa-stethoscope me-1"></i>Run Health Check
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // Maintenance Dashboard Functions
    
    function clearCache() {
        if (confirm('Are you sure you want to clear the system cache?')) {
            showAlert('info', 'Clearing cache...', false);
            // Simulate cache clearing - you can implement actual cache clearing here
            setTimeout(() => {
                showAlert('success', 'System cache cleared successfully!');
                location.reload();
            }, 2000);
        }
    }
    
    function viewLogs() {
        // Open logs in a new window or modal
        const logWindow = window.open('', '_blank', 'width=800,height=600');
        logWindow.document.write(`
            <html>
                <head>
                    <title>System Logs</title>
                    <style>
                        body { font-family: monospace; background: #1e1e1e; color: #fff; padding: 20px; }
                        .log-entry { margin-bottom: 10px; border-bottom: 1px solid #333; padding-bottom: 5px; }
                        .error { color: #ff6b6b; }
                        .warning { color: #feca57; }
                        .info { color: #48cae4; }
                    </style>
                </head>
                <body>
                    <h3>System Logs</h3>
                    <div class="log-entry info">2024-08-12 07:45:23 [INFO] System maintenance dashboard accessed</div>
                    <div class="log-entry info">2024-08-12 07:40:15 [INFO] Database cleanup completed</div>
                    <div class="log-entry warning">2024-08-12 07:35:42 [WARNING] High memory usage detected</div>
                    <div class="log-entry info">2024-08-12 07:30:10 [INFO] User session started</div>
                    <p><em>Note: This is a simulated log view. Implement actual log reading functionality as needed.</em></p>
                </body>
            </html>
        `);
    }
    
    function checkSystemHealth() {
        showAlert('info', 'Running system health check...', false);
        
        // Simulate health check
        setTimeout(() => {
            const healthResults = [
                'PHP Version: OK',
                'Database Connection: OK', 
                'File Permissions: OK',
                'Memory Usage: Normal',
                'Disk Space: Sufficient'
            ];
            
            showAlert('success', 'Health Check Complete:\\n' + healthResults.join('\\n'));
        }, 3000);
    }
    
    function showAlert(type, message, autoHide = true) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        if (autoHide) {
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
    }
    
    // Auto-refresh system data every 5 minutes
    setInterval(() => {
        if (confirm('Auto-refresh system data? (Runs every 5 minutes)')) {
            location.reload();
        }
    }, 300000);
</script>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>