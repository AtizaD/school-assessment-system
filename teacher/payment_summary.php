<?php
// teacher/payment_summary.php - Teacher's payment summary page
if (!defined("BASEPATH")) {
    define("BASEPATH", dirname(dirname(__FILE__)));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require teacher role
requireRole('teacher');

$teacher_id = $_SESSION['teacher_id'] ?? null;

// If teacher_id not in session, try to get it from database
if (!$teacher_id && isset($_SESSION['user_id'])) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        if ($result) {
            $teacher_id = $result['teacher_id'];
            $_SESSION['teacher_id'] = $teacher_id; // Cache it for future requests
        }
    } catch (Exception $e) {
        logError("Failed to get teacher_id: " . $e->getMessage());
    }
}

$error = null;
$selected_class = $_GET['class'] ?? 'all';

if (!$teacher_id) {
    $error = "Teacher account not properly configured. Please contact administrator.";
}

$db = DatabaseConfig::getInstance()->getConnection();

try {
    if ($teacher_id) {
        // Get teacher's classes for filter dropdown
        $stmt = $db->prepare("
            SELECT DISTINCT c.class_name
            FROM teacherclassassignments tca
            JOIN classes c ON tca.class_id = c.class_id
            WHERE tca.teacher_id = ? AND tca.is_primary_instructor = 1
            ORDER BY c.class_name
        ");
        $stmt->execute([$teacher_id]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build WHERE clause for class filter
        $class_filter = "";
        $params = [$teacher_id];
        if ($selected_class !== 'all') {
            $class_filter = " AND c.class_name = ?";
            $params[] = $selected_class;
        }
        
        // Get teacher's payment summary
        $stmt = $db->prepare("
        SELECT 
            c.class_name,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT CASE WHEN s.payment_status = 'paid' THEN s.student_id END) as paid_students,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.02 END), 0) as paystack_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.15 END), 0) as hosting_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.17 END), 0) as total_deduction,
            COALESCE(SUM(CASE WHEN s.payment_status = 'paid' THEN s.payment_amount * 0.83 END), 0) as net_revenue
        FROM teacherclassassignments tca
        JOIN classes c ON tca.class_id = c.class_id
        LEFT JOIN students s ON c.class_id = s.class_id
        WHERE tca.teacher_id = ? AND tca.is_primary_instructor = 1" . $class_filter . "
        GROUP BY c.class_id, c.class_name
        ORDER BY paid_students DESC, total_revenue DESC
    ");
    $stmt->execute($params);
    $payment_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Get teacher name
        $stmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) as teacher_name FROM teachers WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get paid students list with class filter
        $stmt = $db->prepare("
            SELECT 
                u.username,
                CONCAT(s.first_name, ' ', s.last_name) as full_name,
                c.class_name,
                s.payment_date,
                s.payment_amount
            FROM teacherclassassignments tca
            JOIN classes c ON tca.class_id = c.class_id
            JOIN students s ON c.class_id = s.class_id
            JOIN users u ON s.user_id = u.user_id
            WHERE tca.teacher_id = ? 
            AND tca.is_primary_instructor = 1 
            AND s.payment_status = 'paid'" . $class_filter . "
            ORDER BY c.class_name, CONCAT(s.first_name, ' ', s.last_name)
        ");
        $stmt->execute($params);
        $paid_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $payment_summary = [];
        $teacher = ['teacher_name' => 'Unknown Teacher'];
        $paid_students = [];
        $teacher_classes = [];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Teacher payment summary error: " . $error);
}

$pageTitle = 'Payment Summary';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-money-bill-wave"></i> Payment Summary</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <a href="/teacher/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="printSummary()">
                    <i class="fas fa-print"></i> Print Summary
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Teacher Info -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <h5><i class="fas fa-user"></i> <?php echo htmlspecialchars($teacher['teacher_name'] ?? 'Unknown Teacher'); ?></h5>
                    <p class="text-muted mb-0">Payment summary for all assigned classes</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Class Filter -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="class" class="form-label">Filter by Class</label>
                            <select class="form-select" id="class" name="class" onchange="this.form.submit()">
                                <option value="all" <?php echo $selected_class === 'all' ? 'selected' : ''; ?>>All Classes</option>
                                <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class_name']); ?>" 
                                        <?php echo $selected_class === $class['class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                        <?php if ($selected_class !== 'all'): ?>
                        <div class="col-md-2">
                            <a href="?class=all" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Summary Table -->
    <div class="row mb-4" id="printable-summary">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-graduation-cap"></i> Classes Payment Summary 
                        <?php if ($selected_class !== 'all'): ?>
                            <span class="badge bg-primary"><?php echo htmlspecialchars($selected_class); ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($payment_summary)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Total Students</th>
                                        <th>Paid Students</th>
                                        <th>Payment Rate</th>
                                        <th>Total Revenue</th>
                                        <th>Deductions (17%)</th>
                                        <th>Net Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_summary as $class): ?>
                                    <?php 
                                    $payment_rate = $class['total_students'] > 0 ? 
                                        round(($class['paid_students'] / $class['total_students']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold"><?php echo htmlspecialchars($class['class_name']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo number_format($class['total_students']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo number_format($class['paid_students']); ?></span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-<?php echo $payment_rate >= 75 ? 'success' : ($payment_rate >= 50 ? 'warning' : 'danger'); ?>" 
                                                     role="progressbar" style="width: <?php echo $payment_rate; ?>%">
                                                    <?php echo $payment_rate; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">₵<?php echo number_format($class['total_revenue'], 2); ?></span>
                                        </td>
                                        <td>
                                            <small class="text-danger">-₵<?php echo number_format($class['total_deduction'], 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-info">₵<?php echo number_format($class['net_revenue'], 2); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-info">
                                        <th>Total</th>
                                        <th>
                                            <span class="badge bg-secondary"><?php echo number_format(array_sum(array_column($payment_summary, 'total_students'))); ?></span>
                                        </th>
                                        <th>
                                            <span class="badge bg-success"><?php echo number_format(array_sum(array_column($payment_summary, 'paid_students'))); ?></span>
                                        </th>
                                        <th>
                                            <?php 
                                            $total_students = array_sum(array_column($payment_summary, 'total_students'));
                                            $total_paid = array_sum(array_column($payment_summary, 'paid_students'));
                                            $overall_rate = $total_students > 0 ? round(($total_paid / $total_students) * 100, 1) : 0;
                                            ?>
                                            <span class="fw-bold"><?php echo $overall_rate; ?>%</span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-success">₵<?php echo number_format(array_sum(array_column($payment_summary, 'total_revenue')), 2); ?></span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-danger">-₵<?php echo number_format(array_sum(array_column($payment_summary, 'total_deduction')), 2); ?></span>
                                        </th>
                                        <th>
                                            <span class="fw-bold text-info">₵<?php echo number_format(array_sum(array_column($payment_summary, 'net_revenue')), 2); ?></span>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>No payment data available for your classes.</p>
                            <p>You may not be assigned as a primary instructor to any classes yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <?php if (!empty($payment_summary)): ?>
    <div class="row">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Total Classes</div>
                            <div class="text-lg fw-bold"><?php echo count($payment_summary); ?></div>
                        </div>
                        <div><i class="fas fa-graduation-cap fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Total Paid Students</div>
                            <div class="text-lg fw-bold"><?php echo number_format(array_sum(array_column($payment_summary, 'paid_students'))); ?></div>
                        </div>
                        <div><i class="fas fa-user-check fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Total Revenue</div>
                            <div class="text-lg fw-bold">₵<?php echo number_format(array_sum(array_column($payment_summary, 'total_revenue')), 2); ?></div>
                        </div>
                        <div><i class="fas fa-money-bill-wave fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-white-75 small">Net Revenue</div>
                            <div class="text-lg fw-bold">₵<?php echo number_format(array_sum(array_column($payment_summary, 'net_revenue')), 2); ?></div>
                        </div>
                        <div><i class="fas fa-chart-line fa-2x text-white-25"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Paid Students List -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-user-check"></i> My Paid Students</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($paid_students)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Payment Date</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paid_students as $student): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($student['username']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['full_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($student['class_name']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo $student['payment_date'] ? date('M j, Y', strtotime($student['payment_date'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">₵<?php echo number_format($student['payment_amount'], 2); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="2">Total Paid Students</th>
                                        <th><?php echo count($paid_students); ?> students</th>
                                        <th>
                                            <span class="fw-bold text-success">₵<?php echo number_format(array_sum(array_column($paid_students, 'payment_amount')), 2); ?></span>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>No paid students found in your classes.</p>
                            <p>Students will appear here once they complete their payments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
@media print {
    .btn-toolbar, .card:not(#printable-summary .card), nav, .sidebar, .navbar {
        display: none !important;
    }
    
    #printable-summary {
        margin: 0 !important;
    }
    
    #printable-summary .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    body {
        background: white !important;
    }
    
    .table {
        border-collapse: collapse !important;
    }
    
    .table th, .table td {
        border: 1px solid #000 !important;
        padding: 8px !important;
    }
    
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
        background: transparent !important;
    }
}
</style>

<script>
function printSummary() {
    // Hide everything except the summary table
    const elements = document.querySelectorAll('body > *:not(#printable-summary)');
    elements.forEach(el => {
        if (!el.contains(document.getElementById('printable-summary'))) {
            el.style.display = 'none';
        }
    });
    
    // Print
    window.print();
    
    // Restore elements
    elements.forEach(el => {
        el.style.display = '';
    });
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>