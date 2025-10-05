<?php
/**
 * Archive Form 3 Students - School Assessment Management System
 * This script moves Form 3 students to the archived_students table and removes them from active database
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();
$archiveResult = null;
$error = null;
$archivedStudents = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_archive'])) {
    try {
        // Step 1: Create archived_students table if it doesn't exist (before transaction)
        // DDL statements cause implicit commit, so we do this first
        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS archived_students (
            archive_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            class_name VARCHAR(255),
            graduation_year INT NOT NULL,
            archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            student_created_at TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_student_id (student_id),
            INDEX idx_graduation_year (graduation_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $db->exec($createTableSQL);

        // Step 2: Start transaction for data operations
        $db->beginTransaction();

        // Step 3: Get current year
        $currentYear = date('Y');

        // Step 4: Get all Form 3 students (usernames starting with '3')
        // Exclude students who are already archived
        $getForm3SQL = "
        SELECT
            s.student_id,
            s.first_name,
            s.last_name,
            s.class_id,
            s.user_id,
            s.created_at as student_created_at,
            u.username,
            c.class_name
        FROM Students s
        JOIN Users u ON s.user_id = u.user_id
        LEFT JOIN Classes c ON s.class_id = c.class_id
        WHERE u.username LIKE '3%'
        AND u.role = 'student'
        AND s.student_id NOT IN (SELECT student_id FROM archived_students)
        ";

        $stmt = $db->query($getForm3SQL);
        $form3Students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($form3Students)) {
            $db->rollBack();
            $error = "No Form 3 students found to archive.";
        } else {
            $archivedCount = 0;

            // Prepare statements
            $archiveSQL = "
            INSERT INTO archived_students
            (student_id, user_id, username, first_name, last_name, class_name, graduation_year, student_created_at)
            VALUES (:student_id, :user_id, :username, :first_name, :last_name, :class_name, :graduation_year, :student_created_at)
            ";

            $deleteStudentSQL = "DELETE FROM Students WHERE student_id = :student_id";
            $deleteUserSQL = "DELETE FROM Users WHERE user_id = :user_id";

            $archiveStmt = $db->prepare($archiveSQL);
            $deleteStudentStmt = $db->prepare($deleteStudentSQL);
            $deleteUserStmt = $db->prepare($deleteUserSQL);

            foreach ($form3Students as $student) {
                try {
                    // Insert into archived_students with GRAD prefix
                    $archivedUsername = "GRAD{$currentYear}-{$student['username']}";

                    $archiveStmt->execute([
                        ':student_id' => $student['student_id'],
                        ':user_id' => $student['user_id'],
                        ':username' => $archivedUsername,
                        ':first_name' => $student['first_name'],
                        ':last_name' => $student['last_name'],
                        ':class_name' => $student['class_name'] ?? 'Unknown',
                        ':graduation_year' => $currentYear,
                        ':student_created_at' => $student['student_created_at']
                    ]);

                    // Delete from Students table
                    $deleteStudentStmt->execute([':student_id' => $student['student_id']]);

                    // Delete from Users table
                    $deleteUserStmt->execute([':user_id' => $student['user_id']]);

                    $archivedCount++;
                    $student['archive_status'] = 'success';
                    $archivedStudents[] = $student;

                } catch (Exception $e) {
                    $student['archive_status'] = 'error';
                    $student['error_message'] = $e->getMessage();
                    $archivedStudents[] = $student;
                }
            }

            // Commit transaction
            $db->commit();

            $archiveResult = [
                'total' => count($form3Students),
                'archived' => $archivedCount,
                'year' => $currentYear
            ];
        }

    } catch (Exception $e) {
        // Only rollback if transaction is still active
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Archive process failed: " . $e->getMessage();
        logError("Archive Form 3 Students Error: " . $e->getMessage());
    }
}

// Get count of Form 3 students to be archived
try {
    $countStmt = $db->query("
        SELECT COUNT(*) as count
        FROM Students s
        JOIN Users u ON s.user_id = u.user_id
        WHERE u.username LIKE '3%'
        AND u.role = 'student'
        AND s.student_id NOT IN (SELECT student_id FROM archived_students)
    ");
    $form3Count = $countStmt->fetchColumn();
} catch (Exception $e) {
    $form3Count = 0;
}

$pageTitle = 'Archive Form 3 Students';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="archive-main">
    <!-- Header Section -->
    <section class="archive-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="archive-title">
                    <i class="fas fa-archive"></i>
                    Archive Form 3 Students
                </h1>
                <p class="archive-subtitle">
                    Graduate and archive Form 3 students for academic year <?php echo date('Y'); ?>
                </p>
            </div>
            <div class="header-actions">
                <a href="view_archived_students.php" class="btn-secondary">
                    <i class="fas fa-eye"></i>
                    View Archived Students
                </a>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </section>

    <section class="content-section">
        <div class="container">
            <?php if ($archiveResult): ?>
                <!-- Success Message -->
                <div class="success-panel">
                    <div class="success-header">
                        <i class="fas fa-check-circle"></i>
                        <h2>Archive Complete!</h2>
                    </div>
                    <div class="success-body">
                        <p class="success-message">
                            Successfully archived <strong><?php echo $archiveResult['archived']; ?></strong>
                            out of <strong><?php echo $archiveResult['total']; ?></strong> Form 3 students.
                        </p>

                        <div class="info-box">
                            <h3><i class="fas fa-info-circle"></i> What Happened:</h3>
                            <ul>
                                <li>Students moved to <code>archived_students</code> table with <strong>GRAD<?php echo $archiveResult['year']; ?>-</strong> prefix</li>
                                <li><strong>Removed from Students and Users tables</strong></li>
                                <li>All assessment data and grades preserved in related tables</li>
                                <li>Form 3 usernames (3XX-XXX) now available for new students</li>
                                <li>Archived records kept for historical purposes (example: GRAD<?php echo $archiveResult['year']; ?>-3A1-AB001)</li>
                            </ul>
                        </div>

                        <?php if (!empty($archivedStudents)): ?>
                        <div class="archived-list">
                            <h3><i class="fas fa-users"></i> Archived Students</h3>
                            <div class="table-responsive">
                                <table class="students-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Username</th>
                                            <th>Name</th>
                                            <th>Class</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archivedStudents as $index => $student): ?>
                                        <tr>
                                            <td><?php echo ($index + 1); ?></td>
                                            <td><?php echo htmlspecialchars($student['username']); ?></td>
                                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($student['archive_status'] === 'success'): ?>
                                                    <span class="badge badge-success">
                                                        <i class="fas fa-check"></i> Archived
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-error">
                                                        <i class="fas fa-times"></i> Error
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="action-buttons">
                            <a href="view_archived_students.php" class="btn-primary">
                                <i class="fas fa-eye"></i>
                                View All Archived Students
                            </a>
                            <a href="index.php" class="btn-secondary">
                                <i class="fas fa-tachometer-alt"></i>
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>

            <?php elseif ($error): ?>
                <!-- Error Message -->
                <div class="error-panel">
                    <div class="error-header">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h2>Archive Failed</h2>
                    </div>
                    <div class="error-body">
                        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                        <div class="action-buttons">
                            <a href="archive_form3_students.php" class="btn-primary">
                                <i class="fas fa-redo"></i>
                                Try Again
                            </a>
                            <a href="index.php" class="btn-secondary">
                                <i class="fas fa-arrow-left"></i>
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Archive Form -->
                <div class="archive-panel">
                    <div class="panel-header">
                        <h2>
                            <i class="fas fa-graduation-cap"></i>
                            Ready to Archive Form 3 Students
                        </h2>
                    </div>
                    <div class="panel-body">
                        <?php if ($form3Count > 0): ?>
                            <div class="warning-box">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <h3>Warning: This action will archive <strong><?php echo $form3Count; ?></strong> Form 3 students</h3>
                                    <p>Students will be permanently removed from active student lists but preserved in the archives.</p>
                                </div>
                            </div>

                            <div class="process-info">
                                <h3><i class="fas fa-info-circle"></i> Archival Process:</h3>
                                <div class="steps-grid">
                                    <div class="step-card">
                                        <div class="step-number">1</div>
                                        <div class="step-content">
                                            <h4>Copy to Archive</h4>
                                            <p>Student records copied to <code>archived_students</code> table with GRAD<?php echo date('Y'); ?>- prefix</p>
                                        </div>
                                    </div>
                                    <div class="step-card">
                                        <div class="step-number">2</div>
                                        <div class="step-content">
                                            <h4>Remove from Active</h4>
                                            <p>Students removed from <code>Students</code> and <code>Users</code> tables</p>
                                        </div>
                                    </div>
                                    <div class="step-card">
                                        <div class="step-number">3</div>
                                        <div class="step-content">
                                            <h4>Preserve Data</h4>
                                            <p>All assessment data, grades, and historical records preserved</p>
                                        </div>
                                    </div>
                                    <div class="step-card">
                                        <div class="step-number">4</div>
                                        <div class="step-content">
                                            <h4>Free Usernames</h4>
                                            <p>Form 3 usernames (3XX-XXX) become available for next year's students</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" onsubmit="return confirmArchive();">
                                <div class="form-actions">
                                    <button type="submit" name="confirm_archive" class="btn-danger">
                                        <i class="fas fa-archive"></i>
                                        Archive <?php echo $form3Count; ?> Form 3 Students
                                    </button>
                                    <a href="index.php" class="btn-secondary">
                                        <i class="fas fa-times"></i>
                                        Cancel
                                    </a>
                                </div>
                            </form>

                        <?php else: ?>
                            <div class="info-box">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <h3>No Form 3 Students to Archive</h3>
                                    <p>All Form 3 students have already been archived or no Form 3 students exist in the system.</p>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <a href="view_archived_students.php" class="btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Archived Students
                                </a>
                                <a href="index.php" class="btn-secondary">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Dashboard
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<style>
/* Black & Gold Professional Theme */
:root {
    --black: #000000;
    --gold: #ffd700;
    --gold-dark: #b8a000;
    --gold-light: #fff9cc;
    --white: #ffffff;
    --gray-50: #f8f9fa;
    --gray-100: #e9ecef;
    --gray-200: #dee2e6;
    --gray-400: #6c757d;
    --gray-600: #495057;
    --gray-800: #343a40;
    --success: #28a745;
    --danger: #dc3545;
    --warning: #ffc107;
    --shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
    --shadow-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.1);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.archive-main {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    min-height: 100vh;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header */
.archive-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.archive-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 200px;
    background: radial-gradient(circle, var(--gold) 0%, transparent 70%);
    opacity: 0.1;
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    z-index: 1;
}

.archive-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.archive-title i {
    color: var(--gold);
}

.archive-subtitle {
    font-size: 1.1rem;
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

/* Content Section */
.content-section {
    padding: 0 2rem 2rem;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
}

/* Panels */
.archive-panel,
.success-panel,
.error-panel {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    animation: slideInUp 0.6s ease-out;
}

.panel-header,
.success-header,
.error-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.5rem 2rem;
    border-bottom: 3px solid var(--gold);
}

.success-header {
    background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
}

.error-header {
    background: linear-gradient(135deg, var(--danger) 0%, #bd2130 100%);
}

.panel-header h2,
.success-header h2,
.error-header h2 {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.panel-body,
.success-body,
.error-body {
    padding: 2rem;
}

/* Success Message */
.success-message {
    font-size: 1.25rem;
    color: var(--gray-800);
    margin-bottom: 2rem;
}

.success-message strong {
    color: var(--success);
    font-size: 1.5rem;
}

/* Error Message */
.error-message {
    font-size: 1.1rem;
    color: var(--danger);
    padding: 1rem;
    background: rgba(220, 53, 69, 0.1);
    border-radius: var(--border-radius);
    border-left: 4px solid var(--danger);
    margin-bottom: 2rem;
}

/* Info Boxes */
.info-box,
.warning-box {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 2rem;
    border-left: 4px solid var(--gold);
}

.warning-box {
    background: rgba(255, 193, 7, 0.1);
    border-left-color: var(--warning);
    display: flex;
    gap: 1rem;
    align-items: start;
}

.warning-box > i {
    color: var(--warning);
    font-size: 2rem;
    margin-top: 0.25rem;
}

.info-box h3,
.warning-box h3 {
    margin: 0 0 0.5rem 0;
    color: var(--black);
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-box i {
    color: var(--gold);
}

.info-box ul {
    margin: 0.5rem 0 0 1.5rem;
    padding: 0;
}

.info-box li {
    margin: 0.5rem 0;
    color: var(--gray-800);
}

.info-box code {
    background: var(--white);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    color: var(--gold-dark);
    font-family: 'Courier New', monospace;
    border: 1px solid var(--gray-200);
}

/* Process Info */
.process-info {
    margin-bottom: 2rem;
}

.process-info h3 {
    margin: 0 0 1rem 0;
    color: var(--black);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.steps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.step-card {
    background: var(--white);
    border: 2px solid var(--gray-200);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    display: flex;
    gap: 1rem;
    transition: var(--transition);
}

.step-card:hover {
    border-color: var(--gold);
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.step-number {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    color: var(--black);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.step-content h4 {
    margin: 0 0 0.5rem 0;
    color: var(--black);
    font-size: 1rem;
}

.step-content p {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.875rem;
    line-height: 1.4;
}

/* Archived List */
.archived-list {
    margin-bottom: 2rem;
}

.archived-list h3 {
    margin: 0 0 1rem 0;
    color: var(--black);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.table-responsive {
    overflow-x: auto;
    border-radius: var(--border-radius);
    border: 1px solid var(--gray-200);
}

.students-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
}

.students-table thead {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
}

.students-table th,
.students-table td {
    padding: 1rem;
    text-align: left;
}

.students-table tbody tr {
    border-bottom: 1px solid var(--gray-200);
    transition: var(--transition);
}

.students-table tbody tr:hover {
    background: var(--gray-50);
}

.students-table tbody tr:last-child {
    border-bottom: none;
}

/* Badges */
.badge {
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.badge-success {
    background: var(--success);
    color: var(--white);
}

.badge-error {
    background: var(--danger);
    color: var(--white);
}

/* Buttons */
.btn-primary,
.btn-secondary,
.btn-danger {
    padding: 0.75rem 1.5rem;
    border-radius: var(--border-radius);
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition);
    cursor: pointer;
    border: 2px solid;
    font-size: 1rem;
}

.btn-primary {
    background: var(--black);
    color: var(--white);
    border-color: var(--black);
}

.btn-primary:hover {
    background: var(--gold);
    color: var(--black);
    border-color: var(--gold);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.btn-secondary {
    background: var(--white);
    color: var(--black);
    border-color: var(--gray-400);
}

.btn-secondary:hover {
    background: var(--gray-100);
    border-color: var(--black);
    transform: translateY(-2px);
}

.btn-danger {
    background: var(--danger);
    color: var(--white);
    border-color: var(--danger);
}

.btn-danger:hover {
    background: #bd2130;
    border-color: #bd2130;
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.form-actions,
.action-buttons {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

/* Animations */
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive */
@media (max-width: 768px) {
    .archive-header {
        padding: 1.5rem;
    }

    .header-content {
        flex-direction: column;
        gap: 1rem;
    }

    .archive-title {
        font-size: 2rem;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .header-actions a {
        justify-content: center;
    }

    .content-section {
        padding: 0 1rem 2rem;
    }

    .steps-grid {
        grid-template-columns: 1fr;
    }

    .form-actions,
    .action-buttons {
        flex-direction: column;
    }

    .btn-primary,
    .btn-secondary,
    .btn-danger {
        justify-content: center;
    }

    .warning-box {
        flex-direction: column;
    }
}
</style>

<script>
function confirmArchive() {
    return confirm('Are you sure you want to archive all Form 3 students?\n\nThis will:\n- Move them to the archived students table\n- Remove them from active student lists\n- Preserve all their assessment data\n- Free up Form 3 usernames for next year\n\nThis action cannot be undone.');
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>
