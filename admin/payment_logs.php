<?php
// admin/payment_logs.php - Detailed payment logs with Paystack status checking
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(__FILE__)));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

// Handle AJAX requests for payment details FIRST (before any HTML output)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['ref'])) {
    header('Content-Type: application/json');
    
    // Function to check Paystack status (moved here for AJAX)
    function getPaystackStatusAjax($reference) {
        try {
            $db = DatabaseConfig::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $secretKey = $result ? $result['setting_value'] : '';
            
            if (empty($secretKey)) {
                return ['error' => 'No API key'];
            }
            
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => array("Authorization: Bearer " . $secretKey),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode !== 200) {
                return ['error' => "HTTP $httpCode"];
            }
            
            $data = json_decode($response, true);
            return $data['status'] ? $data['data'] : ['error' => $data['message'] ?? 'API error'];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    $reference = $_GET['ref'];
    $status = getPaystackStatusAjax($reference);
    
    if (isset($status['error'])) {
        echo json_encode(['error' => $status['error']]);
    } else {
        echo json_encode($status);
    }
    exit;
}

$db = DatabaseConfig::getInstance()->getConnection();
$error = null;
$success = $_GET['success'] ?? null;

// Get filter parameters - auto-apply from URL
$status_filter = $_GET['status'] ?? 'all';
$date_range = $_GET['date_range'] ?? '7'; // Default to last 7 days
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Function to check Paystack status
function getPaystackStatus($reference) {
    static $secretKey = null;
    static $db = null;
    
    if ($secretKey === null) {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'paystack_secret_key' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $secretKey = $result ? $result['setting_value'] : '';
    }
    
    if (empty($secretKey)) {
        return ['error' => 'No API key'];
    }
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array("Authorization: Bearer " . $secretKey),
    ));
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode !== 200) {
        return ['error' => "HTTP $httpCode"];
    }
    
    $data = json_decode($response, true);
    return $data['status'] ? $data['data'] : ['error' => $data['message'] ?? 'API error'];
}

try {
    // Build query conditions
    $conditions = [];
    $params = [];
    
    // Date range filter
    if ($date_range !== 'all') {
        $days = intval($date_range);
        $conditions[] = "sl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = $days;
    }
    
    // Search filter
    if (!empty($search)) {
        $conditions[] = "(sl.message LIKE ? OR u.username LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Build the complete WHERE clause
    $allConditions = ["sl.component = 'Payment'", "sl.message LIKE '%initialized%'"];
    $allConditions = array_merge($allConditions, $conditions);
    $whereClause = "WHERE " . implode(" AND ", $allConditions);
    
    // Get payment logs with student information
    $stmt = $db->prepare("
        SELECT 
            sl.log_id,
            sl.message,
            sl.severity,
            sl.created_at,
            sl.user_id,
            u.username,
            s.student_id,
            s.first_name,
            s.last_name,
            s.payment_status,
            s.payment_date,
            s.payment_reference
        FROM SystemLogs sl
        LEFT JOIN users u ON sl.user_id = u.user_id
        LEFT JOIN students s ON u.user_id = s.user_id
        $whereClause
        ORDER BY sl.created_at DESC 
        LIMIT " . (($page - 1) * $per_page) . ", $per_page
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM SystemLogs sl
        LEFT JOIN users u ON sl.user_id = u.user_id
        LEFT JOIN students s ON u.user_id = s.user_id
        $whereClause
    ");
    $stmt->execute($params);
    $total_logs = $stmt->fetchColumn();
    $total_pages = ceil($total_logs / $per_page);
    
    // Extract references and check Paystack status for visible logs
    $filtered_logs = [];
    foreach ($logs as &$log) {
        if (preg_match('/Reference: (pay_\w+)/', $log['message'], $matches)) {
            $log['reference'] = $matches[1];
            $log['paystack_status'] = getPaystackStatus($matches[1]);
            
            // Auto-filter by Paystack status if specified
            if ($status_filter !== 'all') {
                $paystack_status = isset($log['paystack_status']['status']) ? $log['paystack_status']['status'] : 'error';
                if ($status_filter !== $paystack_status) {
                    continue; // Skip this log if it doesn't match the filter
                }
            }
            
            $filtered_logs[] = $log;
        } else {
            $log['reference'] = null;
            $log['paystack_status'] = ['error' => 'No reference found'];
            
            // Include error logs if showing all or error status
            if ($status_filter === 'all' || $status_filter === 'error') {
                $filtered_logs[] = $log;
            }
        }
    }
    
    $logs = $filtered_logs;
    
} catch (Exception $e) {
    $error = "Error loading payment logs: " . $e->getMessage();
    $logs = [];
    $total_pages = 0;
}

$pageTitle = "Payment Logs";
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<style>
    .status-success { color: #198754; font-weight: bold; }
    .status-failed { color: #dc3545; font-weight: bold; }
    .status-abandoned { color: #fd7e14; font-weight: bold; }
    .status-pending { color: #0dcaf0; font-weight: bold; }
    .status-error { color: #6c757d; font-style: italic; }
    .reference-badge { font-family: monospace; font-size: 0.85em; }
    .gateway-response { font-size: 0.85em; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
    .log-details { background-color: #f8f9fa; border-radius: 0.375rem; padding: 0.75rem; margin-top: 0.5rem; }
</style>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-file-invoice-dollar me-2"></i>Payment Logs</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <a href="payment_tracking.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-chart-line me-1"></i>Back to Tracking
                </a>
                <button class="btn btn-sm btn-outline-success" onclick="refreshStatuses()">
                    <i class="fas fa-sync me-1"></i>Refresh Status
                </button>
            </div>
        </div>
    </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Auto Filter Status -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h6 class="mb-1">
                                    <i class="fas fa-filter me-2"></i>Active Filters
                                </h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($status_filter !== 'all'): ?>
                                        <span class="badge bg-primary">
                                            Status: <?php echo ucfirst($status_filter); ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all'])); ?>" class="text-white ms-1">×</a>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($date_range !== '7'): ?>
                                        <span class="badge bg-info">
                                            <?php 
                                            $dateLabels = ['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All time'];
                                            echo $dateLabels[$date_range] ?? 'Custom range';
                                            ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['date_range' => '7'])); ?>" class="text-white ms-1">×</a>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($search)): ?>
                                        <span class="badge bg-warning">
                                            Search: "<?php echo htmlspecialchars(substr($search, 0, 20) . (strlen($search) > 20 ? '...' : '')); ?>"
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['search' => ''])); ?>" class="text-white ms-1">×</a>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($status_filter === 'all' && $date_range === '7' && empty($search)): ?>
                                        <span class="text-muted">No filters active (showing last 7 days)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="mb-2">
                                    <form method="GET" class="d-inline-block me-3" style="width: 200px;">
                                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                                        <input type="hidden" name="date_range" value="<?php echo htmlspecialchars($date_range); ?>">
                                        <input type="search" name="search" class="form-control form-control-sm" placeholder="Search references, students..." 
                                               value="<?php echo htmlspecialchars($search); ?>" 
                                               onkeypress="if(event.key==='Enter') this.form.submit();">
                                    </form>
                                </div>
                                <div class="btn-group">
                                    <a href="?status=all&date_range=1" class="btn btn-sm btn-outline-secondary <?php echo ($date_range === '1') ? 'active' : ''; ?>">24h</a>
                                    <a href="?status=all&date_range=7" class="btn btn-sm btn-outline-secondary <?php echo ($date_range === '7') ? 'active' : ''; ?>">7d</a>
                                    <a href="?status=all&date_range=30" class="btn btn-sm btn-outline-secondary <?php echo ($date_range === '30') ? 'active' : ''; ?>">30d</a>
                                    <a href="?status=all&date_range=all" class="btn btn-sm btn-outline-secondary <?php echo ($date_range === 'all') ? 'active' : ''; ?>">All</a>
                                </div>
                                <div class="btn-group ms-2">
                                    <a href="?status=success" class="btn btn-sm btn-outline-success <?php echo ($status_filter === 'success') ? 'active' : ''; ?>">Success</a>
                                    <a href="?status=failed" class="btn btn-sm btn-outline-danger <?php echo ($status_filter === 'failed') ? 'active' : ''; ?>">Failed</a>
                                    <a href="?status=abandoned" class="btn btn-sm btn-outline-warning <?php echo ($status_filter === 'abandoned') ? 'active' : ''; ?>">Abandoned</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Logs -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Initialization Logs (<?php echo number_format($total_logs); ?> total)</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($logs)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5>No payment logs found</h5>
                                <p class="text-muted">No payment initializations match your current filters.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Student</th>
                                            <th>Reference</th>
                                            <th>Paystack Status</th>
                                            <th>Gateway Response</th>
                                            <th>Local Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <div><?php echo date('M j, Y', strtotime($log['created_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('g:i A', strtotime($log['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['first_name']): ?>
                                                        <div><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($log['username']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unknown Student</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['reference']): ?>
                                                        <code class="reference-badge"><?php echo htmlspecialchars($log['reference']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">No reference</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $status = $log['paystack_status'];
                                                    if (isset($status['error'])): ?>
                                                        <span class="status-error"><?php echo htmlspecialchars($status['error']); ?></span>
                                                    <?php else:
                                                        $paystackStatus = $status['status'] ?? 'unknown';
                                                        $statusClass = 'status-' . $paystackStatus;
                                                        ?>
                                                        <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($paystackStatus)); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="gateway-response">
                                                    <?php 
                                                    if (isset($status['gateway_response'])): 
                                                        echo htmlspecialchars($status['gateway_response']);
                                                    else:
                                                        echo '<span class="text-muted">N/A</span>';
                                                    endif;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['payment_status'] === 'paid'): ?>
                                                        <span class="badge bg-success">Paid</span>
                                                        <?php if ($log['payment_date']): ?>
                                                            <div><small class="text-muted"><?php echo date('M j, Y', strtotime($log['payment_date'])); ?></small></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Unpaid</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($log['reference']): ?>
                                                            <button class="btn btn-outline-primary btn-sm" onclick="showDetails('<?php echo htmlspecialchars($log['reference']); ?>')">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&', PHP_QUERY_RFC3986); ?>">Previous</a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&', PHP_QUERY_RFC3986); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&', PHP_QUERY_RFC3986); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="closeModal()"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showDetails(reference) {
            // Ensure Bootstrap is loaded, fallback to basic modal
            if (typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(document.getElementById('detailModal'));
                const content = document.getElementById('detailContent');
                modal.show();
            } else {
                // Fallback: show modal manually if Bootstrap isn't available
                const modal = document.getElementById('detailModal');
                const content = document.getElementById('detailContent');
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
                
                // Add backdrop
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.onclick = () => closeModal();
                document.body.appendChild(backdrop);
            }
            
            const content = document.getElementById('detailContent');
            content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            
            // Fetch detailed information via AJAX
            fetch('payment_logs.php?ajax=1&ref=' + encodeURIComponent(reference))
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        content.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                    } else {
                        content.innerHTML = formatPaymentDetails(data);
                    }
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading details: ' + error.message + '</div>';
                });
        }
        
        function closeModal() {
            const modal = document.getElementById('detailModal');
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
            
            // Remove backdrop
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        }
        
        function formatPaymentDetails(data) {
            let html = '<div class="row">';
            html += '<div class="col-md-6"><strong>Reference:</strong><br><code>' + data.reference + '</code></div>';
            html += '<div class="col-md-6"><strong>Status:</strong><br><span class="status-' + data.status + '">' + data.status.charAt(0).toUpperCase() + data.status.slice(1) + '</span></div>';
            html += '</div><hr>';
            
            html += '<div class="row">';
            html += '<div class="col-md-6"><strong>Amount:</strong><br>' + (data.amount / 100) + ' ' + data.currency + '</div>';
            html += '<div class="col-md-6"><strong>Channel:</strong><br>' + (data.authorization?.channel || 'N/A') + '</div>';
            html += '</div><hr>';
            
            html += '<div class="row">';
            html += '<div class="col-md-6"><strong>Created:</strong><br>' + new Date(data.created_at).toLocaleString() + '</div>';
            html += '<div class="col-md-6"><strong>Paid At:</strong><br>' + (data.paid_at ? new Date(data.paid_at).toLocaleString() : 'Not paid') + '</div>';
            html += '</div><hr>';
            
            html += '<div><strong>Gateway Response:</strong><br>' + (data.gateway_response || 'N/A') + '</div>';
            
            if (data.message) {
                html += '<div class="mt-2"><strong>Message:</strong><br>' + data.message + '</div>';
            }
            
            return html;
        }
        
        function refreshStatuses() {
            location.reload();
        }
    </script>

<?php
// Handle AJAX requests for payment details
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['ref'])) {
    header('Content-Type: application/json');
    
    $reference = $_GET['ref'];
    $status = getPaystackStatus($reference);
    
    if (isset($status['error'])) {
        echo json_encode(['error' => $status['error']]);
    } else {
        echo json_encode($status);
    }
    exit;
}
?>