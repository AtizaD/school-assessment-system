<?php
// admin/direct_payment.php - Direct Payment Entry

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Only admin can access this page
requireRole('admin');

$message = '';
$messageType = '';
$students = [];
$recentPayments = [];
$searchResults = [];
$searchTerm = '';

// Get payment settings
function getSystemPaymentAmount() {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM SystemSettings WHERE setting_key = 'payment_amount'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? (float)$result['setting_value'] / 100 : 10.00; // Convert pesewas to cedis
    } catch (Exception $e) {
        return 10.00; // Default amount
    }
}

// Handle search
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchTerm = trim($_GET['search']);
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name, u.username, c.class_name, s.payment_status, s.payment_amount, s.payment_reference, s.payment_date
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            LEFT JOIN classes c ON s.class_id = c.class_id 
            WHERE (s.first_name LIKE ? OR s.last_name LIKE ? OR u.username LIKE ? OR c.class_name LIKE ?)
            ORDER BY s.payment_status DESC, s.first_name, s.last_name
        ");
        $searchParam = "%{$searchTerm}%";
        $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam]);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = 'Error searching students: ' . $e->getMessage();
        $messageType = 'danger';
    }
} else {
    // Get unpaid students (default view)
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name, u.username, c.class_name, s.payment_status
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            LEFT JOIN classes c ON s.class_id = c.class_id 
            WHERE s.payment_status = 'unpaid'
            ORDER BY s.first_name, s.last_name
            LIMIT 20
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = 'Error loading students: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Get recent direct payments
try {
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT s.student_id, s.first_name, s.last_name, u.username, c.class_name, 
               s.payment_amount, s.payment_reference, s.payment_date, s.payment_status
        FROM students s 
        JOIN users u ON s.user_id = u.user_id 
        LEFT JOIN classes c ON s.class_id = c.class_id 
        WHERE s.payment_status = 'paid' AND s.payment_reference LIKE 'direct%'
        ORDER BY s.payment_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silently handle error for recent payments
}

// Handle delete payment
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $studentId = (int)$_GET['delete'];
        $db = DatabaseConfig::getInstance()->getConnection();
        
        // Get student details first
        $stmt = $db->prepare("
            SELECT s.first_name, s.last_name, u.username, s.payment_reference
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            WHERE s.student_id = ? AND s.payment_reference LIKE 'direct%'
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        if ($student) {
            // Reset payment status
            $stmt = $db->prepare("
                UPDATE students 
                SET payment_status = 'unpaid', payment_amount = NULL, payment_reference = NULL, payment_date = NULL
                WHERE student_id = ?
            ");
            $stmt->execute([$studentId]);
            
            // Log the deletion
            logSystemActivity(
                'Payment',
                "Direct payment deleted: {$student['first_name']} {$student['last_name']} ({$student['username']}) - Ref: {$student['payment_reference']}",
                'WARNING',
                $_SESSION['user_id']
            );
            
            $message = "Payment record deleted for {$student['first_name']} {$student['last_name']} ({$student['username']})";
            $messageType = 'success';
        } else {
            $message = 'Payment record not found or not a direct payment';
            $messageType = 'warning';
        }
    } catch (Exception $e) {
        $message = 'Error deleting payment: ' . $e->getMessage();
        $messageType = 'danger';
    }
    
    // Redirect to remove GET parameter
    header('Location: direct_payment.php');
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $studentId = (int)$_POST['student_id'];
        $paymentAmount = (float)$_POST['payment_amount'];
        $paymentReference = trim($_POST['payment_reference']);
        $paymentDate = $_POST['payment_date'] ?: date('Y-m-d H:i:s');
        
        // Validation
        if (empty($studentId) || $paymentAmount <= 0 || empty($paymentReference)) {
            throw new Exception('Please fill in all required fields');
        }
        
        $db = DatabaseConfig::getInstance()->getConnection();
        $db->beginTransaction();
        
        // Update student payment details
        $stmt = $db->prepare("
            UPDATE students 
            SET payment_status = 'paid', 
                payment_amount = ?, 
                payment_reference = ?, 
                payment_date = ?
            WHERE student_id = ?
        ");
        $stmt->execute([$paymentAmount, $paymentReference, $paymentDate, $studentId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Student not found or already processed');
        }
        
        // Get student details for logging
        $stmt = $db->prepare("
            SELECT s.first_name, s.last_name, u.username 
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            WHERE s.student_id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        // Log the manual payment entry
        logSystemActivity(
            'Payment',
            "Direct payment recorded: {$student['first_name']} {$student['last_name']} ({$student['username']}) - ₵{$paymentAmount} - Ref: {$paymentReference}",
            'INFO',
            $_SESSION['user_id']
        );
        
        $db->commit();
        
        $message = "Payment successfully recorded for {$student['first_name']} {$student['last_name']} ({$student['username']})";
        $messageType = 'success';
        
        // Refresh students list
        $stmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name, u.username, c.class_name, s.payment_status
            FROM students s 
            JOIN users u ON s.user_id = u.user_id 
            LEFT JOIN classes c ON s.class_id = c.class_id 
            WHERE s.payment_status = 'unpaid'
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Error processing payment: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$defaultAmount = getSystemPaymentAmount();
$pageTitle = 'Direct Payment Entry';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-money-bill-wave"></i> Direct Payment Entry
        </h1>
        <div class="d-flex gap-2 align-items-center">
            <a href="payment_tracking.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-chart-line"></i> Payment Tracking
            </a>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Direct Payment Entry</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <div class="flex-grow-1">
                            <input type="text" 
                                   class="form-control" 
                                   name="search" 
                                   placeholder="Search students by name, username, or class..." 
                                   value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if ($searchTerm): ?>
                            <a href="direct_payment.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Payment Entry Form -->
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-plus-circle"></i> Record Manual Payment
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="paymentForm">
                        <div class="mb-3">
                            <label for="student_id" class="form-label">Select Student <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" id="student_id" required>
                                <option value="">Choose a student...</option>
                                <?php 
                                $dropdownStudents = $searchTerm ? $searchResults : $students;
                                foreach ($dropdownStudents as $student): 
                                    // Only show unpaid students in dropdown
                                    if ($student['payment_status'] === 'unpaid'):
                                ?>
                                    <option value="<?php echo $student['student_id']; ?>">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        (<?php echo htmlspecialchars($student['username']); ?>)
                                        <?php if ($student['class_name']): ?>
                                            - <?php echo htmlspecialchars($student['class_name']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php 
                                    endif;
                                endforeach; ?>
                            </select>
                            <?php if ($searchTerm): ?>
                                <div class="form-text text-info">
                                    <i class="fas fa-info-circle"></i> Only unpaid students from search results are shown
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="payment_amount" class="form-label">Payment Amount (₵) <span class="text-danger">*</span></label>
                            <input type="number" 
                                   class="form-control" 
                                   name="payment_amount" 
                                   id="payment_amount" 
                                   step="0.01" 
                                   min="0.01" 
                                   value="<?php echo number_format($defaultAmount, 2, '.', ''); ?>" 
                                   required>
                            <div class="form-text">
                                Default system amount: ₵<?php echo number_format($defaultAmount, 2); ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="payment_reference" class="form-label">Payment Reference <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="payment_reference" 
                                   id="payment_reference" 
                                   placeholder="e.g., direct_payment, cash_001, momo_123" 
                                   required>
                            <div class="form-text">
                                Enter a unique reference for this payment
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="payment_date" class="form-label">Payment Date</label>
                            <input type="datetime-local" 
                                   class="form-control" 
                                   name="payment_date" 
                                   id="payment_date" 
                                   value="<?php echo date('Y-m-d\TH:i'); ?>">
                            <div class="form-text">
                                Leave empty to use current date and time
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Record Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Search Results / Unpaid Students List -->
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-users"></i> 
                        <?php if ($searchTerm): ?>
                            Search Results (<?php echo count($searchResults); ?>)
                        <?php else: ?>
                            Recent Unpaid Students (<?php echo count($students); ?>)
                        <?php endif; ?>
                    </h6>
                    <small class="text-muted">Click to select</small>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                    <?php 
                    $displayList = $searchTerm ? $searchResults : $students;
                    if (empty($displayList)): ?>
                        <div class="text-center py-4">
                            <?php if ($searchTerm): ?>
                                <i class="fas fa-search text-muted fa-3x mb-3"></i>
                                <h5>No results found</h5>
                                <p class="text-muted">Try a different search term.</p>
                            <?php else: ?>
                                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                                <h5>All recent students have paid!</h5>
                                <p class="text-muted">Use search to find specific students.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($displayList as $student): ?>
                                <div class="list-group-item border-0 px-0 py-2 student-item" 
                                     data-student-id="<?php echo $student['student_id']; ?>"
                                     data-student-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                                     data-username="<?php echo htmlspecialchars($student['username']); ?>"
                                     data-payment-status="<?php echo $student['payment_status']; ?>"
                                     style="cursor: pointer;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($student['username']); ?>
                                                <?php if ($student['class_name']): ?>
                                                    • <?php echo htmlspecialchars($student['class_name']); ?>
                                                <?php endif; ?>
                                                <?php if ($searchTerm && isset($student['payment_amount']) && $student['payment_amount']): ?>
                                                    • ₵<?php echo number_format($student['payment_amount'], 2); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($student['payment_status'] === 'paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                                <?php if (isset($student['payment_reference']) && strpos($student['payment_reference'], 'direct') === 0): ?>
                                                    <button class="btn btn-outline-danger btn-sm" 
                                                            onclick="deletePayment(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>')"
                                                            title="Delete direct payment">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($searchTerm && isset($student['payment_reference']) && $student['payment_reference']): ?>
                                        <small class="text-muted d-block mt-1">
                                            Ref: <?php echo htmlspecialchars($student['payment_reference']); ?>
                                            <?php if ($student['payment_date']): ?>
                                                • <?php echo date('M j, Y H:i', strtotime($student['payment_date'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Direct Payments -->
    <?php if (!empty($recentPayments)): ?>
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-history"></i> Recent Direct Payments (<?php echo count($recentPayments); ?>)
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Username</th>
                                    <th>Class</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPayments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($payment['username']); ?></code></td>
                                    <td><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></td>
                                    <td><strong>₵<?php echo number_format($payment['payment_amount'], 2); ?></strong></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($payment['payment_reference']); ?></small></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <button class="btn btn-outline-danger btn-sm" 
                                                onclick="deletePayment(<?php echo $payment['student_id']; ?>, '<?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>')"
                                                title="Delete direct payment">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill form when student is clicked
    document.querySelectorAll('.student-item').forEach(item => {
        item.addEventListener('click', function(e) {
            // Don't select if clicking on delete button
            if (e.target.closest('.btn')) return;
            
            const studentId = this.dataset.studentId;
            const username = this.dataset.username;
            const paymentStatus = this.dataset.paymentStatus;
            
            // Only allow selection of unpaid students for payment form
            if (paymentStatus === 'paid') {
                alert('This student has already paid. Use the delete button to remove the payment if needed.');
                return;
            }
            
            // Set the select value
            document.getElementById('student_id').value = studentId;
            
            // Generate a reference based on username
            const reference = 'direct_payment_' + username.toLowerCase();
            document.getElementById('payment_reference').value = reference;
            
            // Highlight the selected item
            document.querySelectorAll('.student-item').forEach(i => i.classList.remove('bg-light'));
            this.classList.add('bg-light');
            
            // Scroll to form on mobile
            if (window.innerWidth < 768) {
                document.getElementById('paymentForm').scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    
    // Form validation
    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('payment_amount').value);
        const reference = document.getElementById('payment_reference').value.trim();
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid payment amount');
            return;
        }
        
        if (reference.length < 3) {
            e.preventDefault();
            alert('Please enter a valid payment reference (minimum 3 characters)');
            return;
        }
        
        // Confirm before submitting
        const studentSelect = document.getElementById('student_id');
        const selectedOption = studentSelect.options[studentSelect.selectedIndex];
        
        if (!confirm(`Confirm payment of ₵${amount.toFixed(2)} for ${selectedOption.text}?`)) {
            e.preventDefault();
        }
    });
});

// Delete payment function
function deletePayment(studentId, studentName) {
    if (confirm(`Are you sure you want to delete the payment record for ${studentName}?\n\nThis will reset their payment status to "unpaid".`)) {
        window.location.href = `direct_payment.php?delete=${studentId}`;
    }
}
</script>

<style>
.student-item:hover {
    background-color: #f8f9fa !important;
}

.student-item.bg-light {
    background-color: #e3f2fd !important;
    border-left: 3px solid #2196f3 !important;
}

.card-body::-webkit-scrollbar {
    width: 6px;
}

.card-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>