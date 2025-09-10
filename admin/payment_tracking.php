<?php
// admin/payment_tracking.php - Payment tracking dashboard
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(__FILE__)));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();
$error = null;
$success = $_GET['success'] ?? null;

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$dateRange = $_GET['date_range'] ?? '7';
$search = $_GET['search'] ?? '';

try {
    // Payment statistics
    $stats = [];
    
    // Total students and payment breakdown
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_students,
            SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_students,
            SUM(CASE WHEN payment_status = 'paid' THEN payment_amount ELSE 0 END) as total_revenue
        FROM students
    ");
    $stmt->execute();
    $stats['overview'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Payment activity by date (last 30 days)
    $stmt = $db->prepare("
        SELECT 
            DATE(payment_date) as payment_day,
            COUNT(*) as payment_count,
            SUM(payment_amount) as daily_revenue
        FROM students 
        WHERE payment_status = 'paid' 
        AND payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(payment_date)
        ORDER BY payment_day DESC
    ");
    $stmt->execute();
    $stats['daily_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent payment attempts vs completions
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as attempt_day,
            COUNT(*) as attempts
        FROM systemlogs 
        WHERE component = 'Payment' 
        AND message LIKE '%initialized%'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY attempt_day DESC
    ");
    $stmt->execute();
    $stats['payment_attempts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build payment list query
    $whereConditions = [];
    $params = [];
    
    if ($filter === 'paid') {
        $whereConditions[] = "s.payment_status = 'paid'";
    } elseif ($filter === 'unpaid') {
        $whereConditions[] = "s.payment_status = 'unpaid'";
    }
    
    if ($dateRange !== 'all') {
        $whereConditions[] = "s.payment_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = (int)$dateRange;
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(u.username LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get payment list
    $stmt = $db->prepare("
        SELECT 
            u.username,
            CONCAT(s.first_name, ' ', s.last_name) as full_name,
            c.class_name,
            p.program_name,
            s.payment_status,
            s.payment_date,
            s.payment_amount,
            s.payment_reference,
            s.student_id,
            u.user_id
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN programs p ON c.program_id = p.program_id
        $whereClause
        ORDER BY 
            CASE WHEN s.payment_status = 'paid' THEN s.payment_date ELSE u.created_at END DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent webhook activity
    $stmt = $db->prepare("
        SELECT 
            created_at,
            message,
            ip_address
        FROM systemlogs 
        WHERE component = 'Webhook' 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $webhook_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment summary by class (paid students only)
    $stmt = $db->prepare("
        SELECT 
            c.class_name,
            COALESCE(CONCAT(t.first_name, ' ', t.last_name), 'N/A') as teacher_name,
            COUNT(s.student_id) as paid_students,
            SUM(s.payment_amount) as total_amount,
            SUM(s.payment_amount * 0.02) as paystack_deduction,
            SUM(s.payment_amount * 0.15) as hosting_deduction,
            SUM(s.payment_amount * 0.17) as total_deduction,
            SUM(s.payment_amount * 0.83) as net_revenue
        FROM students s 
        JOIN classes c ON s.class_id = c.class_id 
        LEFT JOIN teacherclassassignments tca ON c.class_id = tca.class_id AND tca.is_primary_instructor = 1
        LEFT JOIN teachers t ON tca.teacher_id = t.teacher_id
        WHERE s.payment_status = 'paid' 
        GROUP BY c.class_id, c.class_name, t.first_name, t.last_name
        ORDER BY paid_students DESC, total_amount DESC
    ");
    $stmt->execute();
    $stats['class_summary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Payment tracking error: " . $error);
}

$pageTitle = 'Payment Tracking';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-credit-card"></i> Payment Tracking</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <a href="/admin/direct_payment.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-money-bill-wave"></i> Direct Payment Entry
                </a>
                <a href="/admin/verify_manual_payment.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-search"></i> Manual Verify
                </a>
                <a href="/admin/payment_logs.php" class="btn btn-sm btn-outline-info">
                    <i class="fas fa-file-invoice-dollar"></i> Detailed Logs
                </a>
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/admin/export_payments.php?format=excel&filter=<?php echo $filter; ?>&date_range=<?php echo $dateRange; ?>">
                            <i class="fas fa-file-excel"></i> Export as Excel
                        </a></li>
                        <li><a class="dropdown-item" href="/admin/export_payments.php?format=pdf&filter=<?php echo $filter; ?>&date_range=<?php echo $dateRange; ?>">
                            <i class="fas fa-file-pdf"></i> Export as PDF
                        </a></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    <!-- Overview Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card bg-primary text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Total Students</div>
                            <div class="text-lg fw-bold"><?php echo number_format($stats['overview']['total_students'] ?? 0); ?></div>
                        </div>
                        <div><i class="fas fa-users fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card bg-success text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Paid Students</div>
                            <div class="text-lg fw-bold"><?php echo number_format($stats['overview']['paid_students'] ?? 0); ?></div>
                        </div>
                        <div><i class="fas fa-check-circle fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card bg-warning text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Unpaid Students</div>
                            <div class="text-lg fw-bold"><?php echo number_format($stats['overview']['unpaid_students'] ?? 0); ?></div>
                        </div>
                        <div><i class="fas fa-exclamation-triangle fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card bg-info text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Total Revenue</div>
                            <div class="text-lg fw-bold">₵<?php echo number_format($stats['overview']['total_revenue'] ?? 0, 2); ?></div>
                        </div>
                        <div><i class="fas fa-dollar-sign fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Summary by Class -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-graduation-cap"></i> Payment Summary by Class (Paid Students Only)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($stats['class_summary'])): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Class Teacher</th>
                                        <th>Paid Students</th>
                                        <th>Total Revenue</th>
                                        <th>Deductions (17%)</th>
                                        <th>Net Revenue</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['class_summary'] as $class): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold"><?php echo htmlspecialchars($class['class_name']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($class['teacher_name'] !== 'N/A'): ?>
                                                <span class="text-primary"><?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo number_format($class['paid_students']); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">₵<?php echo number_format($class['total_amount'], 2); ?></span>
                                        </td>
                                        <td>
                                            <small class="text-danger">-₵<?php echo number_format($class['total_deduction'], 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-info">₵<?php echo number_format($class['net_revenue'], 2); ?></span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewClassDetails('<?php echo htmlspecialchars($class['class_name']); ?>')"
                                                    title="View class payment details">
                                                <i class="fas fa-eye"></i> Details
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-info">
                                        <th colspan="2">Total</th>
                                        <th>
                                            <span class="badge bg-primary"><?php echo number_format(array_sum(array_column($stats['class_summary'], 'paid_students'))); ?></span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-primary">₵<?php echo number_format(array_sum(array_column($stats['class_summary'], 'total_amount')), 2); ?></span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-danger">-₵<?php echo number_format(array_sum(array_column($stats['class_summary'], 'total_deduction')), 2); ?></span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-info">₵<?php echo number_format(array_sum(array_column($stats['class_summary'], 'net_revenue')), 2); ?></span>
                                        </th>
                                        <th>-</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>No paid students found in any class.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    

    <div class="row">
        <!-- Payment List -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> Payment Records</h5>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label for="filter" class="form-label">Status</label>
                            <select class="form-select" id="filter" name="filter">
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Students</option>
                                <option value="paid" <?php echo $filter === 'paid' ? 'selected' : ''; ?>>Paid Only</option>
                                <option value="unpaid" <?php echo $filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid Only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="date_range" class="form-label">Date Range</label>
                            <select class="form-select" id="date_range" name="date_range">
                                <option value="all" <?php echo $dateRange === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="1" <?php echo $dateRange === '1' ? 'selected' : ''; ?>>Last 24 Hours</option>
                                <option value="7" <?php echo $dateRange === '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="30" <?php echo $dateRange === '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Username or name">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </div>
                    </form>

                    <!-- Payment Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Payment Date</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($payment['username']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($payment['full_name']); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($payment['class_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($payment['program_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($payment['payment_status'] === 'paid'): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $payment['payment_date'] ? date('M j, Y g:i A', strtotime($payment['payment_date'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $payment['payment_amount'] ? '₵' . number_format($payment['payment_amount'], 2) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php if ($payment['payment_reference']): ?>
                                            <small class="font-monospace"><?php echo htmlspecialchars($payment['payment_reference']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-search fa-2x mb-2"></i>
                                <p>No payment records found matching your criteria.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Analytics -->
        <div class="col-lg-4">
            <!-- Daily Revenue Chart -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6><i class="fas fa-chart-line"></i> Daily Payments (Last 7 Days)</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($stats['daily_activity'])): ?>
                        <?php foreach (array_slice($stats['daily_activity'], 0, 7) as $day): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small><?php echo date('M j', strtotime($day['payment_day'])); ?></small>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo $day['payment_count']; ?> payments</div>
                                <small class="text-muted">₵<?php echo number_format($day['daily_revenue'], 2); ?></small>
                            </div>
                        </div>
                        <div class="progress mb-2" style="height: 4px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?php echo min(100, ($day['payment_count'] / max(1, max(array_column($stats['daily_activity'], 'payment_count')))) * 100); ?>%"></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No recent payment activity</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Webhook Activity -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6><i class="fas fa-exchange-alt"></i> Recent Webhook Activity</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($webhook_activity)): ?>
                        <?php foreach (array_slice($webhook_activity, 0, 5) as $webhook): ?>
                        <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                            <div class="flex-grow-1">
                                <small class="text-muted"><?php echo date('M j, g:i A', strtotime($webhook['created_at'])); ?></small>
                                <div class="small"><?php echo htmlspecialchars($webhook['message']); ?></div>
                            </div>
                            <span class="badge bg-success badge-sm">✓</span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No recent webhook activity</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-info-circle"></i> Payment Stats</h6>
                </div>
                <div class="card-body">
                    <?php 
                    $paid = $stats['overview']['paid_students'] ?? 0;
                    $total = $stats['overview']['total_students'] ?? 1;
                    $completion_rate = round(($paid / $total) * 100, 1);
                    ?>
                    <div class="row text-center">
                        <div class="col-6 border-end">
                            <div class="h4 text-success"><?php echo $completion_rate; ?>%</div>
                            <small class="text-muted">Payment Rate</small>
                        </div>
                        <div class="col-6">
                            <div class="h4 text-info">₵<?php echo number_format(($stats['overview']['total_revenue'] ?? 0) / max(1, $paid), 2); ?></div>
                            <small class="text-muted">Avg. Payment</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Class Details Modal -->
<div class="modal fade" id="classDetailsModal" tabindex="-1" aria-labelledby="classDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="classDetailsModalLabel">
                    <i class="fas fa-graduation-cap"></i> <span id="modalClassName">Class Details</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading class details...</p>
                </div>
                <div id="modalContent" style="display: none;">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="exportClassBtn">
                    <i class="fas fa-download"></i> Export Details
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function viewClassDetails(className) {
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('classDetailsModal'));
    modal.show();
    
    // Update modal title
    document.getElementById('modalClassName').textContent = className + ' Payment Details';
    
    // Show loading state
    document.getElementById('modalLoading').style.display = 'block';
    document.getElementById('modalContent').style.display = 'none';
    
    // Fetch class details
    fetch('/admin/ajax/get_class_payment_details.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            class_name: className
        })
    })
    .then(response => response.json())
    .then(data => {
        // Hide loading state
        document.getElementById('modalLoading').style.display = 'none';
        document.getElementById('modalContent').style.display = 'block';
        
        if (data.success) {
            // Build modal content
            let content = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="fas fa-users"></i> Total Students</h6>
                                <h4 class="text-primary">${data.stats.total_students}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="fas fa-check-circle"></i> Paid Students</h6>
                                <h4 class="text-success">${data.stats.paid_students}</h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="fas fa-money-bill-wave"></i> Total Revenue</h6>
                                <h5 class="text-success">₵${parseFloat(data.stats.total_revenue).toFixed(2)}</h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="fas fa-minus-circle"></i> Deductions</h6>
                                <div class="small text-danger">Paystack: -₵${parseFloat(data.stats.paystack_deduction).toFixed(2)}</div>
                                <div class="small text-danger">Hosting: -₵${parseFloat(data.stats.hosting_deduction).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="fas fa-chart-line"></i> Net Revenue</h6>
                                <h5 class="text-info">₵${parseFloat(data.stats.net_revenue).toFixed(2)}</h5>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h6><i class="fas fa-list"></i> Paid Students List</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Payment Date</th>
                                <th>Amount</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.students.forEach(student => {
                content += `
                    <tr>
                        <td>
                            <div class="fw-bold">${student.username}</div>
                            <small class="text-muted">${student.full_name}</small>
                        </td>
                        <td>${student.payment_date ? new Date(student.payment_date).toLocaleDateString() : '-'}</td>
                        <td>₵${parseFloat(student.payment_amount).toFixed(2)}</td>
                        <td><small class="font-monospace">${student.payment_reference || '-'}</small></td>
                    </tr>
                `;
            });
            
            content += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('modalContent').innerHTML = content;
            
            // Set up export button
            document.getElementById('exportClassBtn').onclick = function() {
                window.open(`/admin/export_payments.php?format=pdf&class_name=${encodeURIComponent(className)}`, '_blank');
            };
            
        } else {
            document.getElementById('modalContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> ${data.message || 'Failed to load class details'}
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('modalLoading').style.display = 'none';
        document.getElementById('modalContent').style.display = 'block';
        document.getElementById('modalContent').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> Error loading class details. Please try again.
            </div>
        `;
    });
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>