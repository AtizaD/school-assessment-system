<?php
/**
 * Promote Students - School Assessment Management System
 * Promote students from one form level to the next
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();
$promotionResult = null;
$error = null;
$promotedStudents = [];

// Handle promotion request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_level'])) {
    $levelToPromote = $_POST['promote_level'];

    try {
        // Determine source and target levels
        if ($levelToPromote === 'form1') {
            $sourcePrefix = '1';
            $targetPrefix = '2';
            $sourceLevel = 'SHS 1';
            $targetLevel = 'SHS 2';
            $levelName = 'Form 1 to Form 2';
        } elseif ($levelToPromote === 'form2') {
            $sourcePrefix = '2';
            $targetPrefix = '3';
            $sourceLevel = 'SHS 2';
            $targetLevel = 'SHS 3';
            $levelName = 'Form 2 to Form 3';
        } else {
            throw new Exception('Invalid form level specified');
        }

        // CHECK FOR CONFLICTS: Ensure target level is clear
        $conflictCheckSQL = "
            SELECT COUNT(*)
            FROM Students s
            JOIN Users u ON s.user_id = u.user_id
            WHERE u.username LIKE :targetPrefix
            AND u.role = 'student'
        ";

        $conflictStmt = $db->prepare($conflictCheckSQL);
        $conflictStmt->execute([':targetPrefix' => $targetPrefix . '%']);
        $existingStudents = $conflictStmt->fetchColumn();

        if ($existingStudents > 0) {
            if ($levelToPromote === 'form1') {
                throw new Exception("Cannot promote Form 1 students: There are still $existingStudents Form 2 students in the system. Please promote Form 2 students to Form 3 first.");
            } elseif ($levelToPromote === 'form2') {
                throw new Exception("Cannot promote Form 2 students: There are still $existingStudents Form 3 students in the system. Please archive Form 3 students first.");
            }
        }

        $db->beginTransaction();

        // Get all students from source level with their current classes
        $getStudentsSQL = "
            SELECT
                s.student_id,
                s.first_name,
                s.last_name,
                s.user_id,
                s.class_id as current_class_id,
                u.username,
                c.class_name as current_class_name
            FROM Students s
            JOIN Users u ON s.user_id = u.user_id
            LEFT JOIN Classes c ON s.class_id = c.class_id
            WHERE u.username LIKE :sourcePrefix
            AND u.role = 'student'
            ORDER BY c.class_name ASC, u.username ASC
        ";

        $stmt = $db->prepare($getStudentsSQL);
        $stmt->execute([':sourcePrefix' => $sourcePrefix . '%']);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            $db->rollBack();
            $error = "No students found in $levelName";
        } else {
            $promotedCount = 0;
            $classUpdates = [];
            $subjectsCopied = [];

            // Update each student's username and class
            $updateUsernameSQL = "UPDATE Users SET username = :newUsername WHERE user_id = :userId";
            $updateUsernameStmt = $db->prepare($updateUsernameSQL);

            $updateClassSQL = "UPDATE Students SET class_id = :newClassId WHERE student_id = :studentId";
            $updateClassStmt = $db->prepare($updateClassSQL);

            foreach ($students as $student) {
                try {
                    // Replace first character (form level) in username and class name
                    $oldUsername = $student['username'];
                    $newUsername = $targetPrefix . substr($oldUsername, 1);

                    $oldClassName = $student['current_class_name'];
                    $newClassName = $targetPrefix . substr($oldClassName, 1);

                    // Find or create target class by name
                    if (!isset($classUpdates[$oldClassName])) {
                        $findClassSQL = "SELECT class_id FROM Classes WHERE class_name = :className AND level = :level";
                        $findClassStmt = $db->prepare($findClassSQL);
                        $findClassStmt->execute([':className' => $newClassName, ':level' => $targetLevel]);
                        $targetClass = $findClassStmt->fetch();

                        // If target class doesn't exist, create it
                        if (!$targetClass) {
                            // Get program_id from source class
                            $getSourceSQL = "SELECT program_id FROM Classes WHERE class_id = :classId";
                            $getSourceStmt = $db->prepare($getSourceSQL);
                            $getSourceStmt->execute([':classId' => $student['current_class_id']]);
                            $sourceClassData = $getSourceStmt->fetch();

                            if (!$sourceClassData) {
                                throw new Exception("Source class data not found for class_id: {$student['current_class_id']}");
                            }

                            // Create new target class
                            $createClassSQL = "INSERT INTO Classes (program_id, level, class_name) VALUES (:programId, :level, :className)";
                            $createClassStmt = $db->prepare($createClassSQL);
                            $createClassStmt->execute([
                                ':programId' => $sourceClassData['program_id'],
                                ':level' => $targetLevel,
                                ':className' => $newClassName
                            ]);

                            $targetClassId = $db->lastInsertId();

                            // Log class creation
                            logActivity(
                                $_SESSION['user_id'],
                                'class_created',
                                "Auto-created class '$newClassName' during promotion from '$oldClassName'"
                            );
                        } else {
                            $targetClassId = $targetClass['class_id'];
                        }

                        $classUpdates[$oldClassName] = [
                            'source_class_id' => $student['current_class_id'],
                            'target_class_id' => $targetClassId,
                            'source_class_name' => $oldClassName,
                            'target_class_name' => $newClassName,
                            'was_created' => !$targetClass
                        ];

                        // For Form 1 to Form 2: Copy subject assignments
                        if ($levelToPromote === 'form1' && !isset($subjectsCopied[$newClassName])) {
                            // Only delete existing assignments if class already existed
                            if ($targetClass) {
                                $deleteAssignSQL = "DELETE FROM TeacherClassAssignments WHERE class_id = :classId";
                                $deleteAssignStmt = $db->prepare($deleteAssignSQL);
                                $deleteAssignStmt->execute([':classId' => $targetClass['class_id']]);
                            }

                            // Copy assignments from source (Form 1) to target (Form 2)
                            $copyAssignSQL = "
                                INSERT INTO TeacherClassAssignments
                                    (teacher_id, class_id, subject_id, semester_id, is_primary_instructor)
                                SELECT teacher_id, :targetClassId, subject_id, semester_id, is_primary_instructor
                                FROM TeacherClassAssignments
                                WHERE class_id = :sourceClassId
                            ";
                            $copyAssignStmt = $db->prepare($copyAssignSQL);
                            $copyAssignStmt->execute([
                                ':targetClassId' => $targetClassId,
                                ':sourceClassId' => $student['current_class_id']
                            ]);

                            $subjectsCopied[$newClassName] = $copyAssignStmt->rowCount();
                        }
                    }

                    // Update username
                    $updateUsernameStmt->execute([
                        ':newUsername' => $newUsername,
                        ':userId' => $student['user_id']
                    ]);

                    // Update class assignment
                    $updateClassStmt->execute([
                        ':newClassId' => $classUpdates[$oldClassName]['target_class_id'],
                        ':studentId' => $student['student_id']
                    ]);

                    $student['old_username'] = $oldUsername;
                    $student['new_username'] = $newUsername;
                    $student['old_class'] = $oldClassName;
                    $student['new_class'] = $newClassName;
                    $student['status'] = 'success';
                    $promotedStudents[] = $student;
                    $promotedCount++;

                } catch (Exception $e) {
                    $student['status'] = 'error';
                    $student['error_message'] = $e->getMessage();
                    $student['old_username'] = $oldUsername ?? 'N/A';
                    $student['new_username'] = $newUsername ?? 'N/A';
                    $promotedStudents[] = $student;
                }
            }

            $db->commit();

            $promotionResult = [
                'total' => count($students),
                'promoted' => $promotedCount,
                'level' => $levelName,
                'sourcePrefix' => $sourcePrefix,
                'targetPrefix' => $targetPrefix,
                'classUpdates' => $classUpdates,
                'subjectsCopied' => $subjectsCopied
            ];
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Promotion failed: " . $e->getMessage();
        logError("Student Promotion Error: " . $e->getMessage());
    }
}

// Get student counts by form level
try {
    $statsSQL = "
        SELECT
            SUBSTRING(u.username, 1, 1) as form_level,
            COUNT(*) as student_count
        FROM Students s
        JOIN Users u ON s.user_id = u.user_id
        WHERE u.role = 'student'
        AND u.username REGEXP '^[123]'
        GROUP BY SUBSTRING(u.username, 1, 1)
        ORDER BY form_level ASC
    ";

    $statsStmt = $db->query($statsSQL);
    $formStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to associative array
    $stats = [
        '1' => 0,
        '2' => 0,
        '3' => 0
    ];

    foreach ($formStats as $stat) {
        $stats[$stat['form_level']] = $stat['student_count'];
    }

    // Check for conflicts
    $canPromoteForm1 = ($stats['2'] == 0); // Can only promote Form 1 if no Form 2 students exist
    $canPromoteForm2 = ($stats['3'] == 0); // Can only promote Form 2 if no Form 3 students exist

} catch (Exception $e) {
    $stats = ['1' => 0, '2' => 0, '3' => 0];
    $canPromoteForm1 = false;
    $canPromoteForm2 = false;
    logError("Stats fetch error: " . $e->getMessage());
}

$pageTitle = 'Promote Students';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="promote-main">
    <!-- Header Section -->
    <section class="promote-header">
        <div class="header-content">
            <div class="welcome-section">
                <h1 class="promote-title">
                    <i class="fas fa-level-up-alt"></i>
                    Promote Students
                </h1>
                <p class="promote-subtitle">
                    Advance students to the next form level for academic year <?php echo date('Y'); ?>
                </p>
            </div>
            <div class="header-actions">
                <button id="backupBtn" class="btn-backup" onclick="createBackup()">
                    <span class="spinner"></span>
                    <i class="fas fa-database"></i>
                    <span id="backupBtnText">Create Backup</span>
                </button>
                <a href="view_archived_students.php" class="btn-secondary">
                    <i class="fas fa-archive"></i>
                    View Archives
                </a>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section">
        <div class="stats-grid">
            <div class="stat-card form1 <?php echo (!$canPromoteForm1 && $stats['1'] > 0) ? 'blocked' : ''; ?>">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['1']); ?></div>
                    <div class="stat-label">Form 1 Students</div>
                    <div class="stat-action">
                        <?php if (!$canPromoteForm1 && $stats['1'] > 0): ?>
                            <i class="fas fa-lock"></i> Promotion Blocked
                        <?php else: ?>
                            Ready for Form 2
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="stat-card form2 <?php echo (!$canPromoteForm2 && $stats['2'] > 0) ? 'blocked' : ''; ?>">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['2']); ?></div>
                    <div class="stat-label">Form 2 Students</div>
                    <div class="stat-action">
                        <?php if (!$canPromoteForm2 && $stats['2'] > 0): ?>
                            <i class="fas fa-lock"></i> Promotion Blocked
                        <?php else: ?>
                            Ready for Form 3
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="stat-card form3">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['3']); ?></div>
                    <div class="stat-label">Form 3 Students</div>
                    <div class="stat-action">
                        <?php if ($stats['3'] > 0): ?>
                            Ready for Archiving
                        <?php else: ?>
                            No students to archive
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Content Section -->
    <section class="content-section">
        <?php if ($promotionResult): ?>
            <!-- Success Panel -->
            <div class="success-panel">
                <div class="success-header">
                    <i class="fas fa-check-circle"></i>
                    <h2>Promotion Complete!</h2>
                </div>
                <div class="success-body">
                    <p class="success-message">
                        Successfully promoted <strong><?php echo $promotionResult['promoted']; ?></strong>
                        out of <strong><?php echo $promotionResult['total']; ?></strong> students from
                        <strong><?php echo $promotionResult['level']; ?></strong>
                    </p>

                    <div class="info-box">
                        <h3><i class="fas fa-info-circle"></i> What Happened:</h3>
                        <ul>
                            <li>Student usernames updated from <strong><?php echo $promotionResult['sourcePrefix']; ?>XX-XXX</strong> to <strong><?php echo $promotionResult['targetPrefix']; ?>XX-XXX</strong></li>
                            <li>Students moved to matching <?php echo ($promotionResult['sourcePrefix'] == '1' ? 'Form 2' : 'Form 3'); ?> classes</li>
                            <?php if (!empty($promotionResult['classUpdates'])): ?>
                                <?php
                                    $createdClasses = array_filter($promotionResult['classUpdates'], function($update) {
                                        return !empty($update['was_created']);
                                    });
                                    $existingClasses = count($promotionResult['classUpdates']) - count($createdClasses);
                                ?>
                                <li><strong><?php echo count($promotionResult['classUpdates']); ?> classes</strong> processed:
                                    <?php if (count($createdClasses) > 0): ?>
                                        <strong><?php echo count($createdClasses); ?> new classes created</strong>
                                    <?php endif; ?>
                                    <ul style="margin-top: 0.5rem;">
                                        <?php foreach ($promotionResult['classUpdates'] as $update): ?>
                                            <li>
                                                <?php echo htmlspecialchars($update['source_class_name']); ?> →
                                                <?php echo htmlspecialchars($update['target_class_name']); ?>
                                                <?php if (!empty($update['was_created'])): ?>
                                                    <span style="color: #28a745; font-weight: 600;"> ✓ Created</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </li>
                            <?php endif; ?>
                            <?php if (!empty($promotionResult['subjectsCopied'])): ?>
                                <li><strong>Subject assignments copied</strong> from Form 1 to Form 2 classes (NEW curriculum)</li>
                            <?php endif; ?>
                            <li>All student records and assessments preserved</li>
                        </ul>
                    </div>

                    <?php if (!empty($promotedStudents)): ?>
                    <div class="promoted-list">
                        <h3><i class="fas fa-users"></i> Promoted Students (Showing first 50)</h3>
                        <div class="table-responsive">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Old Username</th>
                                        <th>New Username</th>
                                        <th>Name</th>
                                        <th>Old Class</th>
                                        <th>New Class</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $displayCount = min(50, count($promotedStudents));
                                    for ($i = 0; $i < $displayCount; $i++):
                                        $student = $promotedStudents[$i];
                                    ?>
                                    <tr>
                                        <td><?php echo ($i + 1); ?></td>
                                        <td class="old-username"><?php echo htmlspecialchars($student['old_username'] ?? $student['username']); ?></td>
                                        <td class="new-username"><?php echo htmlspecialchars($student['new_username'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td class="old-class"><?php echo htmlspecialchars($student['old_class'] ?? 'N/A'); ?></td>
                                        <td class="new-class"><?php echo htmlspecialchars($student['new_class'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($student['status'] === 'success'): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check"></i> Promoted
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-error">
                                                    <i class="fas fa-times"></i> Error
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($promotedStudents) > 50): ?>
                        <p class="table-note">Showing 50 of <?php echo count($promotedStudents); ?> promoted students</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="action-buttons">
                        <a href="promote_students.php" class="btn-primary">
                            <i class="fas fa-redo"></i>
                            Promote More Students
                        </a>
                        <a href="index.php" class="btn-secondary">
                            <i class="fas fa-tachometer-alt"></i>
                            Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($error): ?>
            <!-- Error Panel -->
            <div class="error-panel">
                <div class="error-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h2>Promotion Failed</h2>
                </div>
                <div class="error-body">
                    <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                    <div class="action-buttons">
                        <a href="promote_students.php" class="btn-primary">
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
            <!-- Promotion Options -->
            <div class="promotion-grid">
                <!-- Form 1 to Form 2 -->
                <div class="promotion-card">
                    <div class="card-header">
                        <div class="card-icon form1">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <h3>Form 1 → Form 2</h3>
                    </div>
                    <div class="card-body">
                        <div class="card-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo number_format($stats['1']); ?></div>
                                <div class="stat-text">Students to Promote</div>
                            </div>
                        </div>

                        <div class="card-info">
                            <h4>This will:</h4>
                            <ul>
                                <li>Update usernames from <code>1XX-XXX</code> to <code>2XX-XXX</code></li>
                                <li>Move students to Form 2 classes (<code>1A1→2A1</code>, <code>1HE1→2HE1</code>)</li>
                                <li><strong>Auto-create</strong> missing Form 2 classes if needed</li>
                                <li>Copy NEW curriculum subjects to Form 2 classes</li>
                                <li>Maintain all student records and grades</li>
                            </ul>
                        </div>

                        <?php if ($stats['1'] > 0): ?>
                            <?php if (!$canPromoteForm1): ?>
                            <div class="conflict-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Blocked:</strong> You must promote or archive the <?php echo $stats['2']; ?> Form 2 students first to avoid username conflicts.
                            </div>
                            <button type="button" class="btn-promote disabled" disabled>
                                <i class="fas fa-lock"></i>
                                Promotion Blocked
                            </button>
                            <?php else: ?>
                            <form method="POST" onsubmit="return confirmPromotion('Form 1', 'Form 2', <?php echo $stats['1']; ?>);">
                                <input type="hidden" name="promote_level" value="form1">
                                <button type="submit" class="btn-promote">
                                    <i class="fas fa-level-up-alt"></i>
                                    Promote <?php echo $stats['1']; ?> Students to Form 2
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                        <div class="no-students">
                            <i class="fas fa-info-circle"></i>
                            No Form 1 students to promote
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Form 2 to Form 3 -->
                <div class="promotion-card">
                    <div class="card-header">
                        <div class="card-icon form2">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <h3>Form 2 → Form 3</h3>
                    </div>
                    <div class="card-body">
                        <div class="card-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo number_format($stats['2']); ?></div>
                                <div class="stat-text">Students to Promote</div>
                            </div>
                        </div>

                        <div class="card-info">
                            <h4>This will:</h4>
                            <ul>
                                <li>Update usernames from <code>2XX-XXX</code> to <code>3XX-XXX</code></li>
                                <li>Move students to Form 3 classes (<code>2A1→3A1</code>, <code>2HE1→3HE1</code>)</li>
                                <li><strong>Auto-create</strong> missing Form 3 classes if needed</li>
                                <li>Keep OLD curriculum subjects (Form 2 & 3 share subjects)</li>
                                <li>Maintain all student records and grades</li>
                            </ul>
                        </div>

                        <?php if ($stats['2'] > 0): ?>
                            <?php if (!$canPromoteForm2): ?>
                            <div class="conflict-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Blocked:</strong> You must <a href="archive_form3_students.php">archive the <?php echo $stats['3']; ?> Form 3 students</a> first to avoid username conflicts.
                            </div>
                            <button type="button" class="btn-promote disabled" disabled>
                                <i class="fas fa-lock"></i>
                                Promotion Blocked
                            </button>
                            <?php else: ?>
                            <form method="POST" onsubmit="return confirmPromotion('Form 2', 'Form 3', <?php echo $stats['2']; ?>);">
                                <input type="hidden" name="promote_level" value="form2">
                                <button type="submit" class="btn-promote">
                                    <i class="fas fa-level-up-alt"></i>
                                    Promote <?php echo $stats['2']; ?> Students to Form 3
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                        <div class="no-students">
                            <i class="fas fa-info-circle"></i>
                            No Form 2 students to promote
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Important Notes -->
            <div class="notes-panel">
                <div class="panel-header">
                    <h2>
                        <i class="fas fa-exclamation-triangle"></i>
                        Important Notes Before Promoting
                    </h2>
                </div>
                <div class="panel-body">
                    <div class="notes-grid">
                        <div class="note-item warning">
                            <i class="fas fa-database"></i>
                            <div>
                                <h4>⚠️ Create Backup First!</h4>
                                <p><strong>IMPORTANT:</strong> Always click "Create Backup" button before promotion.<br>
                                Backups are saved to <code>/database/backups/</code> folder and allow database restoration if errors occur.<br>
                                Backup size: ~10-50 MB depending on data.</p>
                            </div>
                        </div>
                        <div class="note-item warning">
                            <i class="fas fa-sort-numeric-down"></i>
                            <div>
                                <h4>Correct Promotion Order</h4>
                                <p><strong>Step 1:</strong> <a href="archive_form3_students.php">Archive Form 3 students</a> (graduates)<br>
                                <strong>Step 2:</strong> Promote Form 2 → Form 3 (students move to 3A1, 3HE1, etc.)<br>
                                <strong>Step 3:</strong> Promote Form 1 → Form 2 (students move to 2A1, 2HE1, subjects copied)<br>
                                This order prevents conflicts.</p>
                            </div>
                        </div>
                        <div class="note-item info">
                            <i class="fas fa-exchange-alt"></i>
                            <div>
                                <h4>What Gets Updated</h4>
                                <p><strong>Usernames:</strong> 1XX-XXX → 2XX-XXX (or 2XX → 3XX)<br>
                                <strong>Classes:</strong> 1A1 → 2A1, 1HE1 → 2HE1 (students moved to matching classes)<br>
                                <strong>Auto-Creation:</strong> Missing target classes are created automatically<br>
                                <strong>Subjects (Form 1→2 only):</strong> NEW curriculum subjects copied from Form 1 to Form 2 classes<br>
                                <strong>Subjects (Form 2→3):</strong> No change (OLD curriculum continues)</p>
                            </div>
                        </div>
                        <div class="note-item success">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <h4>Data Safety</h4>
                                <p>All student records, assessment results, and grades are preserved. Class assignments and subject assignments are updated automatically based on curriculum requirements.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

<style>
/* Variables */
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
    --info: #17a2b8;
    --form1-color: #17a2b8;
    --form2-color: #28a745;
    --form3-color: #ffd700;
    --shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
    --shadow-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.1);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.promote-main {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    min-height: 100vh;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header */
.promote-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.promote-header::before {
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

.promote-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.promote-title i {
    color: var(--gold);
}

.promote-subtitle {
    font-size: 1.1rem;
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

/* Stats Section */
.stats-section {
    padding: 0 2rem;
    margin-bottom: 2rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.stat-card {
    background: var(--white);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: var(--transition);
    animation: slideInUp 0.6s ease-out;
}

.stat-card.form1 { border-left: 4px solid var(--info); }
.stat-card.form2 { border-left: 4px solid var(--success); }
.stat-card.form3 { border-left: 4px solid var(--gold); }

.stat-card.blocked {
    border-left-color: var(--danger);
    background: rgba(220, 53, 69, 0.03);
}

.stat-card.blocked .stat-action {
    color: var(--danger);
    font-weight: 600;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.stat-card.blocked:hover {
    border-left-color: var(--danger);
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--black), var(--gold-dark));
    color: var(--white);
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.stat-label {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-600);
    margin: 0.25rem 0;
}

.stat-action {
    font-size: 0.875rem;
    color: var(--gold-dark);
    font-weight: 500;
}

/* Content Section */
.content-section {
    padding: 0 2rem 2rem;
}

/* Promotion Grid */
.promotion-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 2rem;
    margin-bottom: 2rem;
}

.promotion-card {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    transition: var(--transition);
    animation: slideInUp 0.6s ease-out;
}

.promotion-card:hover {
    box-shadow: var(--shadow-hover);
    transform: translateY(-4px);
}

.card-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    color: var(--white);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-bottom: 2px solid var(--gold);
}

.card-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--white);
}

.card-icon.form1 { background: var(--info); }
.card-icon.form2 { background: var(--success); }

.card-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
}

.card-body {
    padding: 2rem;
}

.card-stats {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    text-align: center;
}

.stat-value {
    font-size: 3rem;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.stat-text {
    font-size: 1rem;
    color: var(--gray-600);
    margin-top: 0.5rem;
    font-weight: 500;
}

.card-info {
    margin-bottom: 2rem;
}

.card-info h4 {
    margin: 0 0 1rem 0;
    color: var(--black);
    font-size: 1.1rem;
}

.card-info ul {
    margin: 0;
    padding-left: 1.5rem;
}

.card-info li {
    margin: 0.5rem 0;
    color: var(--gray-800);
    line-height: 1.5;
}

.card-info code {
    background: var(--gold-light);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    color: var(--gold-dark);
    font-family: 'Courier New', monospace;
    border: 1px solid var(--gold);
}

.btn-promote {
    width: 100%;
    padding: 1rem 1.5rem;
    background: var(--gold);
    color: var(--black);
    border: 2px solid var(--gold);
    border-radius: var(--border-radius);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-promote:hover {
    background: var(--gold-dark);
    border-color: var(--gold-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.btn-promote.disabled {
    background: var(--gray-200);
    color: var(--gray-600);
    border-color: var(--gray-400);
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-promote.disabled:hover {
    transform: none;
    box-shadow: none;
    background: var(--gray-200);
    border-color: var(--gray-400);
}

.conflict-warning {
    background: rgba(220, 53, 69, 0.1);
    border: 2px solid var(--danger);
    border-radius: var(--border-radius);
    padding: 1rem 1.5rem;
    margin-bottom: 1rem;
    color: var(--danger);
    font-size: 0.95rem;
    line-height: 1.5;
    display: flex;
    align-items: start;
    gap: 0.75rem;
}

.conflict-warning i {
    font-size: 1.25rem;
    margin-top: 0.125rem;
}

.conflict-warning a {
    color: var(--danger);
    text-decoration: underline;
    font-weight: 600;
}

.conflict-warning a:hover {
    color: #bd2130;
}

.no-students {
    background: var(--gray-100);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    text-align: center;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

/* Notes Panel */
.notes-panel {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
}

.panel-header {
    background: linear-gradient(135deg, var(--warning) 0%, #e0a800 100%);
    color: var(--black);
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid var(--gold-dark);
}

.panel-header h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.panel-body {
    padding: 2rem;
}

.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
}

.note-item {
    display: flex;
    gap: 1rem;
    padding: 1.5rem;
    border-radius: var(--border-radius);
    border-left: 4px solid;
}

.note-item.warning {
    background: rgba(255, 193, 7, 0.1);
    border-left-color: var(--warning);
}

.note-item.info {
    background: rgba(23, 162, 184, 0.1);
    border-left-color: var(--info);
}

.note-item.success {
    background: rgba(40, 167, 69, 0.1);
    border-left-color: var(--success);
}

.note-item i {
    font-size: 1.5rem;
    margin-top: 0.25rem;
}

.note-item.warning i { color: var(--warning); }
.note-item.info i { color: var(--info); }
.note-item.success i { color: var(--success); }

.note-item h4 {
    margin: 0 0 0.5rem 0;
    color: var(--black);
    font-size: 1rem;
}

.note-item p {
    margin: 0;
    color: var(--gray-800);
    font-size: 0.95rem;
    line-height: 1.5;
}

.note-item a {
    color: var(--gold-dark);
    font-weight: 600;
    text-decoration: underline;
}

/* Success/Error Panels */
.success-panel,
.error-panel {
    background: var(--white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
    overflow: hidden;
    animation: slideInUp 0.6s ease-out;
    margin-bottom: 2rem;
}

.success-header,
.error-header {
    padding: 1.5rem 2rem;
    color: var(--white);
    border-bottom: 3px solid;
}

.success-header {
    background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
    border-bottom-color: var(--success);
}

.error-header {
    background: linear-gradient(135deg, var(--danger) 0%, #bd2130 100%);
    border-bottom-color: var(--danger);
}

.success-header h2,
.error-header h2 {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.success-body,
.error-body {
    padding: 2rem;
}

.success-message {
    font-size: 1.25rem;
    color: var(--gray-800);
    margin-bottom: 2rem;
}

.success-message strong {
    color: var(--success);
    font-size: 1.5rem;
}

.error-message {
    font-size: 1.1rem;
    color: var(--danger);
    padding: 1rem;
    background: rgba(220, 53, 69, 0.1);
    border-radius: var(--border-radius);
    border-left: 4px solid var(--danger);
    margin-bottom: 2rem;
}

.info-box {
    background: var(--gray-50);
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 2rem;
    border-left: 4px solid var(--gold);
}

.info-box h3 {
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

/* Table */
.promoted-list {
    margin-bottom: 2rem;
}

.promoted-list h3 {
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

.students-table th {
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.students-table td {
    font-size: 0.95rem;
    border-bottom: 1px solid var(--gray-200);
}

.students-table tbody tr {
    transition: var(--transition);
}

.students-table tbody tr:hover {
    background: var(--gray-50);
}

.students-table tbody tr:last-child td {
    border-bottom: none;
}

.old-username,
.old-class {
    color: var(--danger);
    font-family: 'Courier New', monospace;
    font-weight: 600;
    text-decoration: line-through;
}

.new-username,
.new-class {
    color: var(--success);
    font-family: 'Courier New', monospace;
    font-weight: 600;
}

.table-note {
    padding: 1rem;
    background: var(--gold-light);
    text-align: center;
    color: var(--gray-800);
    font-size: 0.95rem;
    margin-top: 1rem;
    border-radius: var(--border-radius);
}

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
.btn-backup {
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

.btn-backup {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: var(--white);
    border-color: #17a2b8;
}

.btn-backup:hover:not(:disabled) {
    background: linear-gradient(135deg, #138496, #117a8b);
    border-color: #117a8b;
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.btn-backup:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-backup .spinner {
    display: none;
    width: 1rem;
    height: 1rem;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

.btn-backup.loading .spinner {
    display: inline-block;
}

.btn-backup.loading .fa-database {
    display: none;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

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
    .promote-header {
        padding: 1.5rem;
    }

    .header-content {
        flex-direction: column;
        gap: 1rem;
    }

    .promote-title {
        font-size: 2rem;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .header-actions a {
        justify-content: center;
    }

    .content-section,
    .stats-section {
        padding: 0 1rem 2rem;
    }

    .stats-grid,
    .promotion-grid {
        grid-template-columns: 1fr;
    }

    .notes-grid {
        grid-template-columns: 1fr;
    }

    .action-buttons {
        flex-direction: column;
    }

    .btn-primary,
    .btn-secondary {
        justify-content: center;
    }
}
</style>

<script>
let backupCreated = false;

async function createBackup() {
    const btn = document.getElementById('backupBtn');
    const btnText = document.getElementById('backupBtnText');

    // Disable button and show loading state
    btn.disabled = true;
    btn.classList.add('loading');
    btnText.textContent = 'Creating Backup...';

    try {
        const response = await fetch('backup_database.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        const result = await response.json();

        if (result.success) {
            backupCreated = true;

            // Remove loading state immediately
            btn.classList.remove('loading');

            // Update button to success state
            btnText.textContent = 'Backup Created ✓';
            btn.style.background = 'linear-gradient(135deg, #28a745, #218838)';
            btn.style.borderColor = '#28a745';

            alert(`✓ Database backup created successfully!\n\nFilename: ${result.filename}\nSize: ${result.size}\nLocation: /database/backups/\n\nYou can now safely proceed with promotion.`);
        } else {
            throw new Error(result.error || 'Backup failed');
        }
    } catch (error) {
        console.error('Backup error:', error);
        alert(`✗ Backup failed: ${error.message}\n\nPlease try again or contact administrator.`);

        // Reset button on error
        btn.disabled = false;
        btn.classList.remove('loading');
        btnText.textContent = 'Create Backup';
    }
}

function confirmPromotion(fromLevel, toLevel, count) {
    // Warn if backup not created
    if (!backupCreated) {
        const proceedWithoutBackup = confirm(
            `⚠️ WARNING: No backup created!\n\n` +
            `It is STRONGLY RECOMMENDED to create a database backup before promotion.\n\n` +
            `Click "Create Backup" button first, then try again.\n\n` +
            `Do you want to proceed WITHOUT a backup? (Not recommended)`
        );

        if (!proceedWithoutBackup) {
            return false;
        }
    }

    const message =
        `Are you sure you want to promote ${count} students from ${fromLevel} to ${toLevel}?\n\n` +
        `This will:\n` +
        `- Update all student usernames (${fromLevel[5]}XX → ${toLevel[5]}XX)\n` +
        `- Move students to matching ${toLevel} classes\n` +
        (fromLevel === 'Form 1' ? `- Copy NEW curriculum subjects to Form 2 classes\n` : '') +
        `- Preserve all records and assessment data\n\n` +
        `${backupCreated ? '✓ Database backup is ready for rollback if needed.\n\n' : ''}` +
        `Continue with promotion?`;

    return confirm(message);
}
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>
