<?php
// admin/audit-logs.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

$error = '';
$success = '';

// Handle clear logs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        try {
            $daysToKeep = filter_var($_POST['days_to_keep'], FILTER_VALIDATE_INT);
            $logType = $_POST['log_type'];
            
            if ($daysToKeep !== false) {
                $db = DatabaseConfig::getInstance()->getConnection();
                $db->beginTransaction();
                
                if ($logType === 'system') {
                    $stmt = $db->prepare(
                        "DELETE FROM SystemLogs 
                         WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)"
                    );
                } else {
                    $stmt = $db->prepare(
                        "DELETE FROM AuditTrail 
                         WHERE action_timestamp < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)"
                    );
                }
                
                $stmt->execute([$daysToKeep]);
                $rowsDeleted = $stmt->rowCount();
                
                $db->commit();
                
                // Log the action
                logSystemActivity(
                    'Log Management',
                    "Cleared " . ($logType === 'system' ? 'system' : 'audit') . 
                    " logs older than $daysToKeep days. $rowsDeleted records deleted.",
                    'INFO'
                );
                
                $success = "Successfully cleared $rowsDeleted logs";
                
                // Redirect to prevent form resubmission
                header("Location: audit-logs.php?type=$logType&success=" . urlencode($success));
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to clear logs: ' . $e->getMessage();
            logError("Clear logs failed: " . $e->getMessage());
        }
    }
}

// Get filter parameters
$log_type = $_GET['type'] ?? 'system'; // 'system' or 'audit'
$severity = $_GET['severity'] ?? '';
$component = $_GET['component'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

// Check for success message in URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

$db = DatabaseConfig::getInstance()->getConnection();

// Build query based on log type and filters
if ($log_type === 'system') {
    $query = "SELECT sl.*, u.username 
              FROM SystemLogs sl
              LEFT JOIN Users u ON sl.user_id = u.user_id
              WHERE 1=1";
    
    if ($severity) {
        $query .= " AND sl.severity = :severity";
    }
    if ($component) {
        $query .= " AND sl.component = :component";
    }
    if ($start_date) {
        $query .= " AND DATE(sl.created_at) >= :start_date";
    }
    if ($end_date) {
        $query .= " AND DATE(sl.created_at) <= :end_date";
    }
    if ($search) {
        $query .= " AND (sl.message LIKE :search OR sl.component LIKE :search 
                        OR u.username LIKE :search)";
    }
    
    $query .= " ORDER BY sl.created_at DESC";
} else {
    $query = "SELECT at.*, u.username,
                     JSON_UNQUOTE(at.old_values) as old_values_formatted,
                     JSON_UNQUOTE(at.new_values) as new_values_formatted
              FROM AuditTrail at
              LEFT JOIN Users u ON at.user_id = u.user_id
              WHERE 1=1";
    
    if ($component) {
        $query .= " AND at.table_name = :component";
    }
    if ($start_date) {
        $query .= " AND DATE(at.action_timestamp) >= :start_date";
    }
    if ($end_date) {
        $query .= " AND DATE(at.action_timestamp) <= :end_date";
    }
    if ($search) {
        $query .= " AND (at.table_name LIKE :search OR at.old_values LIKE :search 
                        OR at.new_values LIKE :search OR u.username LIKE :search)";
    }
    
    $query .= " ORDER BY at.action_timestamp DESC";
}

// Count total records for pagination
$count_query = preg_replace('/SELECT.*?FROM/', 'SELECT COUNT(*) FROM', $query);
$count_query = preg_replace('/ORDER BY.*$/', '', $count_query);

try {
    // Prepare and execute count query
    $stmt = $db->prepare($count_query);
    
    // Bind parameters for count query
    if ($severity) {
        $stmt->bindValue(':severity', $severity);
    }
    if ($component) {
        $stmt->bindValue(':component', $component);
    }
    if ($start_date) {
        $stmt->bindValue(':start_date', $start_date);
    }
    if ($end_date) {
        $stmt->bindValue(':end_date', $end_date);
    }
    if ($search) {
        $stmt->bindValue(':search', "%$search%");
    }
    
    $stmt->execute();
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    // Add pagination to main query
    $query .= " LIMIT :offset, :limit";
    
    // Prepare and execute main query
    $stmt = $db->prepare($query);
    
    // Bind parameters for main query
    if ($severity) {
        $stmt->bindValue(':severity', $severity);
    }
    if ($component) {
        $stmt->bindValue(':component', $component);
    }
    if ($start_date) {
        $stmt->bindValue(':start_date', $start_date);
    }
    if ($end_date) {
        $stmt->bindValue(':end_date', $end_date);
    }
    if ($search) {
        $stmt->bindValue(':search', "%$search%");
    }
    
    $stmt->bindValue(':offset', ($page - 1) * $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    // Get unique components for filter
    if ($log_type === 'system') {
        $stmt = $db->query("SELECT DISTINCT component FROM SystemLogs ORDER BY component");
    } else {
        $stmt = $db->query("SELECT DISTINCT table_name as component FROM AuditTrail ORDER BY table_name");
    }
    $components = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    $error = 'Error retrieving logs';
    logError("Audit logs retrieval failed: " . $e->getMessage());
}

$pageTitle = 'Audit Logs';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>
<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Audit Logs</h1>
        <div class="btn-group">
            <a href="?type=system" class="btn btn-outline-primary <?php echo $log_type === 'system' ? 'active' : ''; ?>">
                System Logs
            </a>
            <a href="?type=audit" class="btn btn-outline-primary <?php echo $log_type === 'audit' ? 'active' : ''; ?>">
                Audit Trail
            </a>
        </div>
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

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="type" value="<?php echo $log_type; ?>">
                
                <?php if ($log_type === 'system'): ?>
                <div class="col-md-2">
                    <label class="form-label">Severity</label>
                    <select name="severity" class="form-select">
                        <option value="">All</option>
                        <option value="INFO" <?php echo $severity === 'INFO' ? 'selected' : ''; ?>>Info</option>
                        <option value="WARNING" <?php echo $severity === 'WARNING' ? 'selected' : ''; ?>>Warning</option>
                        <option value="ERROR" <?php echo $severity === 'ERROR' ? 'selected' : ''; ?>>Error</option>
                        <option value="CRITICAL" <?php echo $severity === 'CRITICAL' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <label class="form-label"><?php echo $log_type === 'system' ? 'Component' : 'Table'; ?></label>
                    <select name="component" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($components as $comp): ?>
                        <option value="<?php echo $comp; ?>" <?php echo $component === $comp ? 'selected' : ''; ?>>
                            <?php echo $comp; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search logs...">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <?php if ($log_type === 'system'): ?>
                                    <th>Severity</th>
                                    <th>Component</th>
                                    <th>Message</th>
                                    <th>IP Address</th>
                                <?php else: ?>
                                    <th>Table</th>
                                    <th>Action</th>
                                    <th>Changes</th>
                                    <th>IP Address</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php echo date('Y-m-d H:i:s', strtotime($log_type === 'system' ? 
                                          $log['created_at'] : $log['action_timestamp'])); ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                <?php if ($log_type === 'system'): ?>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($log['severity']) {
                                                'ERROR' => 'danger',
                                                'WARNING' => 'warning',
                                                'INFO' => 'info',
                                                'CRITICAL' => 'dark',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo $log['severity']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['component']); ?></td>
                                    <td><?php echo htmlspecialchars($log['message']); ?></td>
                                    <td><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></td>
                                <?php else: ?>
                                    <td><?php echo htmlspecialchars($log['table_name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($log['action_type']) {
                                                'INSERT' => 'success',
                                                'UPDATE' => 'info',
                                                'DELETE' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo $log['action_type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                onclick="showChanges(<?php echo htmlspecialchars(json_encode([
                                                    'old' => json_decode($log['old_values_formatted'], true),
                                                    'new' => json_decode($log['new_values_formatted'], true)
                                                ])); ?>)">
                                            View Changes
                                        </button>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                Previous
                            </a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Changes Modal -->
<div class="modal fade" id="changesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Changes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody id="changesTable"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Clear Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Warning: This action cannot be undone!
                </div>
                <form method="POST" id="clearLogsForm">
                    <input type="hidden" name="action" value="clear_logs">
                    <input type="hidden" name="log_type" id="clearLogType">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Clear logs older than:</label>
                        <select name="days_to_keep" class="form-select" required>
                            <option value="7">7 days</option>
                            <option value="30">30 days</option>
                            <option value="90">90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">365 days</option>
                            <option value="0">All logs</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="clearLogsForm" class="btn btn-danger">Clear Logs</button>
            </div>
        </div>
    </div>
</div>

<script>
// Declare modal variables at the top level
let changesModal;
let clearLogsModal;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    changesModal = new bootstrap.Modal(document.getElementById('changesModal'));
    clearLogsModal = new bootstrap.Modal(document.getElementById('clearLogsModal'));
    
    // Initialize export and clear buttons
    const headerDiv = document.querySelector('.d-flex.justify-content-between');
    const btnGroup = headerDiv.querySelector('.btn-group');
    
    // Add export button
    const exportButton = document.createElement('button');
    exportButton.className = 'btn btn-success ms-2';
    exportButton.innerHTML = '<i class="fas fa-download me-2"></i>Export';
    exportButton.onclick = exportLogs;
    btnGroup.appendChild(exportButton);
    
    // Add clear logs button
    const clearButton = document.createElement('button');
    clearButton.className = 'btn btn-danger ms-2';
    clearButton.innerHTML = '<i class="fas fa-trash me-2"></i>Clear Logs';
    clearButton.onclick = () => showClearLogsModal('<?php echo $log_type; ?>');
    btnGroup.appendChild(clearButton);

    // Real-time search filtering
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.closest('form').submit();
            }, 500);
        });
    }

    // Date range validation
    const startDate = document.querySelector('input[name="start_date"]');
    const endDate = document.querySelector('input[name="end_date"]');
    
    if (startDate && endDate) {
        startDate.addEventListener('change', function() {
            endDate.min = this.value;
        });
        
        endDate.addEventListener('change', function() {
            startDate.max = this.value;
        });
    }

    // Form submission handling
    document.querySelector('form').addEventListener('submit', function(e) {
        const startDate = this.querySelector('input[name="start_date"]').value;
        const endDate = this.querySelector('input[name="end_date"]').value;
        
        if (startDate && endDate && startDate > endDate) {
            e.preventDefault();
            alert('End date must be after start date');
        }
    });
});

// Show clear logs modal
function showClearLogsModal(logType) {
    document.getElementById('clearLogType').value = logType;
    clearLogsModal.show();
}

// Function to show changes in modal
function showChanges(changes) {
    const tbody = document.getElementById('changesTable');
    tbody.innerHTML = '';
    
    const fields = new Set([
        ...Object.keys(changes.old || {}),
        ...Object.keys(changes.new || {})
    ]);
    
    fields.forEach(field => {
        const row = document.createElement('tr');
        
        // Field name
        const fieldCell = document.createElement('td');
        fieldCell.textContent = field;
        row.appendChild(fieldCell);
        
        // Old value
        const oldCell = document.createElement('td');
        oldCell.textContent = changes.old?.[field] ?? '-';
        oldCell.className = 'text-danger';
        row.appendChild(oldCell);
        
        // New value
        const newCell = document.createElement('td');
        newCell.textContent = changes.new?.[field] ?? '-';
        newCell.className = 'text-success';
        row.appendChild(newCell);
        
        tbody.appendChild(row);
    });
    
    changesModal.show();
}

// Export logs functionality
function exportLogs() {
    const logType = document.querySelector('.btn-group .active').textContent.trim().toLowerCase();
    const table = document.querySelector('.table');
    let csvContent = 'data:text/csv;charset=utf-8,';
    
    // Add headers
    const headers = Array.from(table.querySelectorAll('thead th'))
        .map(th => `"${th.textContent.trim()}"`)
        .join(',');
    csvContent += headers + '\n';
    
    // Add data
    Array.from(table.querySelectorAll('tbody tr')).forEach(row => {
        const rowData = Array.from(row.querySelectorAll('td'))
            .map(cell => {
                // Get text content, removing any button text if present
                let content = cell.querySelector('button') ? 
                    '' : cell.textContent.trim();
                return `"${content}"`;
            })
            .join(',');
        csvContent += rowData + '\n';
    });
    
    // Create download link
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `${logType}_logs_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>