<?php
// admin/settings.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

// Initialize variables
$db = DatabaseConfig::getInstance()->getConnection();
$error = null;
$success = null;

try {
    // Check if SystemSettings table exists, if not create it
    $tableExists = false;
    $stmt = $db->query("SHOW TABLES LIKE 'SystemSettings'");
    $tableExists = ($stmt->rowCount() > 0);
    
    if (!$tableExists) {
        // Create the SystemSettings table
        $db->exec("
            CREATE TABLE SystemSettings (
                setting_id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(255) NOT NULL,
                setting_value TEXT,
                updated_by INT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (setting_key),
                FOREIGN KEY (updated_by) REFERENCES Users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");
        
        // Insert default settings
        $stmt = $db->prepare("
            INSERT INTO SystemSettings (setting_key, setting_value, updated_by) VALUES 
            ('site_name', 'School Assessment System', ?),
            ('maintenance_mode', '0', ?),
            ('default_assessment_duration', '60', ?),
            ('allow_late_submission', '0', ?),
            ('max_reset_limit', '2', ?),
            ('partial_reset_time', '5', ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            $_SESSION['user_id'],
            $_SESSION['user_id']
        ]);
        
        $success = "System settings initialized successfully";
    }
    
    // Process settings update if form is submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token");
        }
        
        switch ($_POST['action']) {
            case 'update_system_settings':
                // Update system settings
                $siteName = sanitizeInput($_POST['site_name'] ?? '');
                $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
                
                $sql = "
                    INSERT INTO SystemSettings (setting_key, setting_value, updated_by) 
                    VALUES 
                        ('site_name', ?, ?),
                        ('maintenance_mode', ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        setting_value = VALUES(setting_value),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $siteName, $_SESSION['user_id'],
                    $maintenanceMode, $_SESSION['user_id']
                ]);
                
                $success = "System settings updated successfully";
                break;
                
            case 'update_assessment_settings':
                // Update assessment settings
                $defaultDuration = (int)($_POST['default_duration'] ?? 60);
                $allowLateSubmission = isset($_POST['allow_late_submission']) ? 1 : 0;
                $maxResetLimit = (int)($_POST['max_reset_limit'] ?? 2);
                $partialResetTime = (int)($_POST['partial_reset_time'] ?? 5);
                
                if ($defaultDuration < 1) {
                    throw new Exception("Default duration must be at least 1 minute");
                }
                
                if ($partialResetTime < 1) {
                    throw new Exception("Partial reset time must be at least 1 minute");
                }
                
                $sql = "
                    INSERT INTO SystemSettings (setting_key, setting_value, updated_by) 
                    VALUES 
                        ('default_assessment_duration', ?, ?),
                        ('allow_late_submission', ?, ?),
                        ('max_reset_limit', ?, ?),
                        ('partial_reset_time', ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        setting_value = VALUES(setting_value),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $defaultDuration, $_SESSION['user_id'],
                    $allowLateSubmission, $_SESSION['user_id'],
                    $maxResetLimit, $_SESSION['user_id'],
                    $partialResetTime, $_SESSION['user_id']
                ]);
                
                $success = "Assessment settings updated successfully";
                break;
        }
    }
    
    // Get current settings
    $settings = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM SystemSettings");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        // If table doesn't exist yet, we'll use default values
    }
    
    // Set default values
    $siteName = $settings['site_name'] ?? 'School Assessment System';
    $maintenanceMode = (bool)($settings['maintenance_mode'] ?? 0);
    $defaultDuration = (int)($settings['default_assessment_duration'] ?? 60);
    $allowLateSubmission = (bool)($settings['allow_late_submission'] ?? 0);
    $maxResetLimit = (int)($settings['max_reset_limit'] ?? 2);
    $partialResetTime = (int)($settings['partial_reset_time'] ?? 5);
    
    // Get assessment statistics
    $assessmentStats = [
        'total_assessments' => 0,
        'completed_assessments' => 0,
        'pending_assessments' => 0
    ];
    
    try {
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total_assessments,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_assessments,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_assessments
            FROM Assessments
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $assessmentStats = $result;
        }
    } catch (PDOException $e) {
        // If query fails, we'll use default values
        logError("Error fetching assessment stats: " . $e->getMessage());
    }
    
    // Get reset statistics
    $resetStats = [
        'total_resets' => 0,
        'partial_resets' => 0,
        'full_resets' => 0
    ];
    
    try {
        // Check if AssessmentResets table exists
        $stmt = $db->query("SHOW TABLES LIKE 'AssessmentResets'");
        if ($stmt->rowCount() > 0) {
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total_resets,
                    SUM(CASE WHEN reset_type = 'partial' THEN 1 ELSE 0 END) as partial_resets,
                    SUM(CASE WHEN reset_type = 'full' THEN 1 ELSE 0 END) as full_resets
                FROM AssessmentResets
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $resetStats = $result;
            }
        } else {
            // Create AssessmentResets table if it doesn't exist
            $db->exec("
                CREATE TABLE AssessmentResets (
                    reset_id BIGINT NOT NULL AUTO_INCREMENT,
                    assessment_id INT NOT NULL,
                    student_id INT NOT NULL,
                    admin_id INT NOT NULL,
                    reset_type ENUM('partial','full') NOT NULL,
                    reason TEXT NOT NULL,
                    reset_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    previous_status VARCHAR(50) DEFAULT NULL,
                    previous_answers_count INT DEFAULT 0,
                    PRIMARY KEY (reset_id),
                    KEY assessment_id (assessment_id),
                    KEY student_id (student_id),
                    KEY admin_id (admin_id),
                    CONSTRAINT assessmentresets_ibfk_1 FOREIGN KEY (assessment_id) REFERENCES Assessments (assessment_id) ON DELETE CASCADE,
                    CONSTRAINT assessmentresets_ibfk_2 FOREIGN KEY (student_id) REFERENCES Students (student_id) ON DELETE CASCADE,
                    CONSTRAINT assessmentresets_ibfk_3 FOREIGN KEY (admin_id) REFERENCES Users (user_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            ");
            $success = ($success ? $success . " and " : "") . "Assessment reset tracking initialized";
        }
    } catch (PDOException $e) {
        // If query fails, we'll use default values
        logError("Error with reset stats: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Settings page error: " . $e->getMessage());
}

$pageTitle = 'System Settings';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 text-warning">System Settings</h1>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Settings Tabs -->
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                <i class="fas fa-cog me-2"></i>General
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="assessment-tab" data-bs-toggle="tab" data-bs-target="#assessment" type="button" role="tab">
                <i class="fas fa-clipboard-list me-2"></i>Assessment
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <!-- General Settings Tab -->
        <div class="tab-pane fade show active" id="general" role="tabpanel">
            <div class="card shadow-sm mb-4">
                <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                    <h5 class="card-title mb-0 text-warning">
                        <i class="fas fa-cogs me-2"></i>General Settings
                    </h5>
                </div>
                <div class="card-body">
                    <form method="post" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="update_system_settings">
                        
                        <div class="mb-3">
                            <label for="site_name" class="form-label fw-bold">Site Name</label>
                            <input type="text" class="form-control" id="site_name" name="site_name" 
                                value="<?php echo htmlspecialchars($siteName); ?>" required>
                            <div class="form-text">This name will appear in the browser title and header</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="maintenance_mode" name="maintenance_mode" 
                                <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="maintenance_mode">Enable Maintenance Mode</label>
                            <div class="form-text">When enabled, only administrators can access the system</div>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-1"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Assessment Settings Tab -->
        <div class="tab-pane fade" id="assessment" role="tabpanel">
            <div class="row">
                <!-- Assessment Settings Form -->
                <div class="col-md-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #34495e 100%);">
                            <h5 class="card-title mb-0 text-warning">
                                <i class="fas fa-sliders-h me-2"></i>Assessment Configuration
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="settings.php">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="update_assessment_settings">
                                
                                <div class="mb-3">
                                    <label for="default_duration" class="form-label fw-bold">Default Assessment Duration (minutes)</label>
                                    <input type="number" class="form-control" id="default_duration" name="default_duration" 
                                        value="<?php echo $defaultDuration; ?>" min="1" required>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="allow_late_submission" name="allow_late_submission" 
                                        <?php echo $allowLateSubmission ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="allow_late_submission">Allow Late Submissions by Default</label>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_reset_limit" class="form-label fw-bold">Maximum Assessment Reset Limit</label>
                                    <select class="form-select" id="max_reset_limit" name="max_reset_limit">
                                        <option value="1" <?php echo $maxResetLimit == 1 ? 'selected' : ''; ?>>1 reset</option>
                                        <option value="2" <?php echo $maxResetLimit == 2 ? 'selected' : ''; ?>>2 resets</option>
                                        <option value="3" <?php echo $maxResetLimit == 3 ? 'selected' : ''; ?>>3 resets</option>
                                    </select>
                                    <div class="form-text">Maximum number of times an assessment can be reset for a student</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="partial_reset_time" class="form-label fw-bold">Partial Reset Time (minutes)</label>
                                    <input type="number" class="form-control" id="partial_reset_time" name="partial_reset_time" 
                                        value="<?php echo $partialResetTime; ?>" min="1" required>
                                    <div class="form-text">Time given to students after a partial reset (preserves their answers)</div>
                                </div>
                                
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-save me-1"></i> Save Assessment Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm">
                        <div class="card-header py-3" style="background: linear-gradient(145deg, #2c3e50 0%, #d35400 100%);">
                            <h5 class="card-title mb-0 text-warning">
                                <i class="fas fa-tools me-2"></i>Assessment Management
                            </h5>
                        </div>
                        <div class="card-body">
                            <p>Manage student assessment attempts and resets:</p>
                            <a href="reset_assessment.php" class="btn btn-warning">
                                <i class="fas fa-redo-alt me-1"></i> Manage Assessment Resets
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Assessment Stats -->
                <div class="col-md-4">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header py-3" style="background: linear-gradient(145deg, #000 0%, #617186 100%);">
                            <h5 class="card-title mb-0 text-warning">
                                <i class="fas fa-chart-bar me-2"></i>Assessment Statistics
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-clipboard-list text-warning me-2"></i>Total Assessments</span>
                                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $assessmentStats['total_assessments']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-check-circle text-success me-2"></i>Completed</span>
                                    <span class="badge bg-success rounded-pill"><?php echo $assessmentStats['completed_assessments']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-hourglass-half text-secondary me-2"></i>Pending</span>
                                    <span class="badge bg-secondary text-white rounded-pill"><?php echo $assessmentStats['pending_assessments']; ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm">
                        <div class="card-header py-3" style="background: linear-gradient(145deg, #000 0%, #617186 100%);">
                            <h5 class="card-title mb-0 text-warning">
                                <i class="fas fa-history me-2"></i>Reset Statistics
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-sync text-warning me-2"></i>Total Resets</span>
                                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $resetStats['total_resets']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-clock text-info me-2"></i>Partial Resets</span>
                                    <span class="badge bg-info text-dark rounded-pill"><?php echo $resetStats['partial_resets']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-redo-alt text-danger me-2"></i>Full Resets</span>
                                    <span class="badge bg-danger rounded-pill"><?php echo $resetStats['full_resets']; ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .card {
        border: none;
        transition: transform 0.2s;
        margin-bottom: 1.5rem;
    }

    .card:hover {
        transform: translateY(-2px);
    }

    .list-group-item {
        padding: 1rem 1.25rem;
        border-left: none;
        border-right: none;
    }

    .badge {
        font-weight: 500;
        padding: 0.5em 0.8em;
    }

    .btn-warning {
        color: #000;
        background: linear-gradient(145deg, #ffc107 0%, #ff6f00 100%);
        border: none;
    }

    .btn-warning:hover {
        background: linear-gradient(145deg, #ff6f00 0%, #ffc107 100%);
        color: #000;
    }

    .text-warning {
        color: #ffc107 !important;
    }

    .form-control:focus, .form-select:focus {
        border-color: #ffc107;
        box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25);
    }

    .form-check-input:checked {
        background-color: #ffc107;
        border-color: #ffc107;
    }

    .nav-tabs .nav-link {
        color: #495057;
    }

    .nav-tabs .nav-link.active {
        color: #ffc107;
        border-color: #dee2e6 #dee2e6 #fff;
        font-weight: bold;
    }

    .nav-tabs .nav-link:hover {
        border-color: #e9ecef #e9ecef #dee2e6;
        color: #ff6f00;
    }
    
    .badge.bg-warning {
        background-color: #ffd700 !important;
    }
    
    .badge.bg-info {
        background-color: #17a2b8 !important;
        color: white !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle tab activation
    const triggerTabList = Array.from(document.querySelectorAll('#settingsTabs button'));
    triggerTabList.forEach(function(triggerEl) {
        const tabTrigger = new bootstrap.Tab(triggerEl);
        triggerEl.addEventListener('click', function(event) {
            event.preventDefault();
            tabTrigger.show();
        });
    });
    
    // Check if URL has a hash and select that tab
    if (window.location.hash) {
        const activeTab = document.querySelector(`button[data-bs-target="${window.location.hash}"]`);
        if (activeTab) {
            bootstrap.Tab.getOrCreateInstance(activeTab).show();
        }
    }
    
    // Auto-dismiss alerts
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        }, 5000);
    });
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>