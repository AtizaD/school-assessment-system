<?php
// admin/reset_passwords.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

$db = DatabaseConfig::getInstance()->getConnection();

$error = null;
$success = null;
$programs = [];
$levels = [];
$classes = [];
$students = [];

try {
    // Get all programs for the dropdown
    $stmt = $db->query("SELECT program_id, program_name FROM Programs ORDER BY program_name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique levels for the dropdown
    $stmt = $db->query("SELECT DISTINCT level FROM Classes ORDER BY level");
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Process password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token");
        }
        
        if ($_POST['action'] === 'reset_password' && isset($_POST['user_id'], $_POST['new_password'])) {
            $userId = (int)$_POST['user_id'];
            $newPassword = $_POST['new_password'];
            
            // Validate password
            if (strlen($newPassword) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }
            
            // Hash the new password
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Begin transaction
            $db->beginTransaction();
            
            try {
                // Update the password
                $stmt = $db->prepare("
                    UPDATE Users 
                    SET password_hash = :password_hash,
                        last_password_change = CURRENT_TIMESTAMP,
                        first_login = 0,
                        password_change_required = 0, /* Explicitly set password_change_required to 0 */
                        failed_login_attempts = 0,
                        account_locked = 0,
                        locked_until = NULL
                    WHERE user_id = :user_id AND role = 'student'
                ");
                
                $stmt->execute([
                    ':password_hash' => $passwordHash,
                    ':user_id' => $userId
                ]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Failed to reset password. User not found or not a student.");
                }
                
                // Clear any existing sessions for this user
                $stmt = $db->prepare("
                    DELETE FROM UserSessions 
                    WHERE user_id = :user_id
                ");
                $stmt->execute([':user_id' => $userId]);
                
                $sessionCleared = $stmt->rowCount() > 0;
                
                // Log the password reset
                $stmt = $db->prepare("
                    INSERT INTO SystemLogs (severity, component, message, user_id, ip_address)
                    VALUES ('INFO', 'User Management', :message, :admin_user_id, :ip_address)
                ");
                
                $logMessage = "Password reset for user ID: $userId by admin (password_change_required set to 0)";
                if ($sessionCleared) {
                    $logMessage .= ". All user sessions cleared.";
                }
                
                $stmt->execute([
                    ':message' => $logMessage,
                    ':admin_user_id' => $_SESSION['user_id'],
                    ':ip_address' => $_SERVER['REMOTE_ADDR']
                ]);
                
                // Commit transaction
                $db->commit();
                
                $successMessage = "Password has been reset successfully and password change requirement removed";
                if ($sessionCleared) {
                    $successMessage .= ". All user sessions have been cleared.";
                }
                $success = $successMessage;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollBack();
                throw $e;
            }
        } elseif ($_POST['action'] === 'get_classes_json') {
            // This is an AJAX request for classes based on program/level
            header('Content-Type: application/json');
            
            $programId = isset($_POST['program_id']) && !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
            $level = isset($_POST['level']) && !empty($_POST['level']) ? $_POST['level'] : null;
            
            $classQuery = "SELECT class_id, class_name FROM Classes WHERE 1=1";
            $classParams = [];
            
            if ($programId) {
                $classQuery .= " AND program_id = :program_id";
                $classParams[':program_id'] = $programId;
            }
            
            if ($level) {
                $classQuery .= " AND level = :level";
                $classParams[':level'] = $level;
            }
            
            $classQuery .= " ORDER BY class_name";
            $stmt = $db->prepare($classQuery);
            $stmt->execute($classParams);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($classes);
            exit;
        }
    }
    
    // Process filters for the initial page load
    $programId = isset($_GET['program_id']) && !empty($_GET['program_id']) ? (int)$_GET['program_id'] : null;
    $level = isset($_GET['level']) && !empty($_GET['level']) ? $_GET['level'] : null;
    $classId = isset($_GET['class_id']) && !empty($_GET['class_id']) ? (int)$_GET['class_id'] : null;
    $searchTerm = isset($_GET['search']) && !empty($_GET['search']) ? trim($_GET['search']) : null;
    
    // Get classes based on program and level selection for initial load
    $classQuery = "SELECT class_id, class_name FROM Classes WHERE 1=1";
    $classParams = [];
    
    if ($programId) {
        $classQuery .= " AND program_id = :program_id";
        $classParams[':program_id'] = $programId;
    }
    
    if ($level) {
        $classQuery .= " AND level = :level";
        $classParams[':level'] = $level;
    }
    
    $classQuery .= " ORDER BY class_name";
    $stmt = $db->prepare($classQuery);
    $stmt->execute($classParams);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all students for client-side filtering
    $query = "
        SELECT s.student_id, s.first_name, s.last_name, u.username, u.email, 
               u.user_id, u.first_login, u.password_change_required, 
               u.failed_login_attempts, u.account_locked,
               c.class_id, c.class_name, c.level, p.program_id, p.program_name
        FROM Students s
        JOIN Users u ON s.user_id = u.user_id
        JOIN Classes c ON s.class_id = c.class_id
        JOIN Programs p ON c.program_id = p.program_id
        ORDER BY s.last_name, s.first_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Apply server-side filters for initial page load
    $students = array_filter($allStudents, function($student) use ($programId, $level, $classId, $searchTerm) {
        $matchesProgram = $programId ? $student['program_id'] == $programId : true;
        $matchesLevel = $level ? $student['level'] == $level : true;
        $matchesClass = $classId ? $student['class_id'] == $classId : true;
        
        $matchesSearch = true;
        if ($searchTerm) {
            $searchTerm = strtolower($searchTerm);
            $matchesSearch = 
                stripos($student['first_name'], $searchTerm) !== false ||
                stripos($student['last_name'], $searchTerm) !== false ||
                stripos($student['username'], $searchTerm) !== false ||
                ($student['email'] && stripos($student['email'], $searchTerm) !== false);
        }
        
        return $matchesProgram && $matchesLevel && $matchesClass && $matchesSearch;
    });
    
} catch (PDOException $e) {
    logError("Database error in reset_passwords.php: " . $e->getMessage());
    $error = "Database error: " . $e->getMessage();
} catch (Exception $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Reset Student Passwords';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<main class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Reset Student Passwords</h1>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-0">
            <h5 class="card-title mb-0">Filter Students</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="program_id" class="form-label">Program</label>
                    <select class="form-select" id="program_id" name="program_id">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['program_id']; ?>" <?php echo ($programId == $program['program_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="level" class="form-label">Level</label>
                    <select class="form-select" id="level" name="level">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $lvl): ?>
                            <option value="<?php echo $lvl; ?>" <?php echo ($level == $lvl) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lvl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="class_id" class="form-label">Class</label>
                    <select class="form-select" id="class_id" name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>" <?php echo ($classId == $class['class_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="search" name="search" placeholder="Name, Username, Email" value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>">
                        <button class="btn btn-outline-secondary" type="button" id="clearFilters">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Student List</h5>
            <span class="badge bg-primary" id="studentCount"><?php echo count($students); ?> students found</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="studentsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Program</th>
                            <th>Level</th>
                            <th>Class</th>
                            <th>Account Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr id="noStudentsRow">
                                <td colspan="8" class="text-center py-4">No students found matching the criteria</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr class="student-row" 
                                    data-program-id="<?php echo $student['program_id']; ?>"
                                    data-level="<?php echo htmlspecialchars($student['level']); ?>"
                                    data-class-id="<?php echo $student['class_id']; ?>"
                                    data-search="<?php echo htmlspecialchars(strtolower($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['username'] . ' ' . ($student['email'] ?? ''))); ?>">
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['username']); ?></td>
                                    <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['level']); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                    <td>
                                        <?php if ($student['account_locked']): ?>
                                            <span class="badge bg-danger">Locked</span>
                                        <?php elseif ($student['password_change_required']): ?>
                                            <span class="badge bg-warning text-dark">Password Change Required</span>
                                        <?php elseif ($student['first_login']): ?>
                                            <span class="badge bg-info">Never Logged In</span>
                                        <?php elseif ($student['failed_login_attempts'] > 0): ?>
                                            <span class="badge bg-warning text-dark">Failed Attempts: <?php echo $student['failed_login_attempts']; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary reset-password-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#resetPasswordModal" 
                                                data-user-id="<?php echo $student['user_id']; ?>"
                                                data-username="<?php echo htmlspecialchars($student['username']); ?>"
                                                data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>">
                                            <i class="fas fa-key"></i> Set Password
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="reset_passwords.php" id="resetPasswordForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="modal_user_id">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="resetPasswordModalLabel">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <p>Reset password for <strong id="modal_student_name"></strong> (<span id="modal_username"></span>)</p>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" id="generatePassword">
                                Generate
                            </button>
                        </div>
                        <div class="form-text">Password must be at least 8 characters long</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cache DOM elements
    const programSelect = document.getElementById('program_id');
    const levelSelect = document.getElementById('level');
    const classSelect = document.getElementById('class_id');
    const searchInput = document.getElementById('search');
    const clearFiltersBtn = document.getElementById('clearFilters');
    const studentRows = document.querySelectorAll('.student-row');
    const studentCountBadge = document.getElementById('studentCount');
    const noStudentsRow = document.getElementById('noStudentsRow') || createNoStudentsRow();
    
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            // Use Bootstrap's dismiss method if available
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            } else {
                // Fallback: manually remove the alert
                alert.classList.remove('show');
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 150); // small delay for fade-out animation
            }
        }, 5000); // 5 seconds
    });
    
    // Function to create the "no students found" row if it doesn't exist
    function createNoStudentsRow() {
        const row = document.createElement('tr');
        row.id = 'noStudentsRow';
        row.style.display = 'none';
        
        const cell = document.createElement('td');
        cell.colSpan = 8;
        cell.className = 'text-center py-4';
        cell.textContent = 'No students found matching the criteria';
        
        row.appendChild(cell);
        document.querySelector('#studentsTable tbody').appendChild(row);
        
        return row;
    }
    
    // Update classes dropdown via AJAX when program or level changes
    function updateClasses() {
        const programId = programSelect.value;
        const level = levelSelect.value;
        
        // Create form data for the AJAX request
        const formData = new FormData();
        formData.append('action', 'get_classes_json');
        formData.append('program_id', programId);
        formData.append('level', level);
        formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
        
        // Send AJAX request to get updated classes
        fetch('reset_passwords.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(classes => {
            // Save current selection
            const currentClassId = classSelect.value;
            
            // Clear existing options
            classSelect.innerHTML = '<option value="">All Classes</option>';
            
            // Add new options
            classes.forEach(classObj => {
                const option = document.createElement('option');
                option.value = classObj.class_id;
                option.textContent = classObj.class_name;
                
                // Restore selection if possible
                if (classObj.class_id == currentClassId) {
                    option.selected = true;
                }
                
                classSelect.appendChild(option);
            });
            
            // Trigger filtering
            filterStudents();
        })
        .catch(error => {
            console.error('Error fetching classes:', error);
        });
    }
    
    // Function to filter students based on all criteria
    function filterStudents() {
        const programId = programSelect.value;
        const level = levelSelect.value;
        const classId = classSelect.value;
        const searchQuery = searchInput.value.toLowerCase().trim();
        
        let visibleCount = 0;
        
        // Apply filters to each student row
        studentRows.forEach(row => {
            const rowProgramId = row.getAttribute('data-program-id');
            const rowLevel = row.getAttribute('data-level');
            const rowClassId = row.getAttribute('data-class-id');
            const rowSearchText = row.getAttribute('data-search');
            
            // Check if the row matches all filters
            const matchesProgram = !programId || rowProgramId === programId;
            const matchesLevel = !level || rowLevel === level;
            const matchesClass = !classId || rowClassId === classId;
            const matchesSearch = !searchQuery || rowSearchText.includes(searchQuery);
            
            // Show/hide the row based on filter results
            if (matchesProgram && matchesLevel && matchesClass && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update student count
        studentCountBadge.textContent = `${visibleCount} students found`;
        
        // Show/hide the "no students found" message
        if (visibleCount === 0) {
            noStudentsRow.style.display = '';
        } else {
            noStudentsRow.style.display = 'none';
        }
    }
    
    // Update URL parameters for sharing/bookmarking
    function updateUrlParams() {
        const programId = programSelect.value;
        const level = levelSelect.value;
        const classId = classSelect.value;
        const searchQuery = searchInput.value.trim();
        
        const url = new URL(window.location.href);
        
        // Update or remove parameters based on filter values
        if (programId) {
            url.searchParams.set('program_id', programId);
        } else {
            url.searchParams.delete('program_id');
        }
        
        if (level) {
            url.searchParams.set('level', level);
        } else {
            url.searchParams.delete('level');
        }
        
        if (classId) {
            url.searchParams.set('class_id', classId);
        } else {
            url.searchParams.delete('class_id');
        }
        
        if (searchQuery) {
            url.searchParams.set('search', searchQuery);
        } else {
            url.searchParams.delete('search');
        }
        
        // Update browser URL without reloading the page
        window.history.replaceState({}, '', url);
    }
    
    // Event listeners for filtering
    programSelect.addEventListener('change', function() {
        updateClasses();
        updateUrlParams();
    });
    
    levelSelect.addEventListener('change', function() {
        updateClasses();
        updateUrlParams();
    });
    
    classSelect.addEventListener('change', function() {
        filterStudents();
        updateUrlParams();
    });
    
    // Live search with debounce
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            filterStudents();
            updateUrlParams();
        }, 300); // 300ms debounce delay
    });
    
    // Clear filters button
    clearFiltersBtn.addEventListener('click', function() {
        programSelect.value = '';
        levelSelect.value = '';
        classSelect.value = '';
        searchInput.value = '';
        
        // Reset the filter
        filterStudents();
        updateUrlParams();
    });
    
    // Set user data in modal when button is clicked
    const resetButtons = document.querySelectorAll('.reset-password-btn');
    resetButtons.forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const username = this.getAttribute('data-username');
            const name = this.getAttribute('data-name');
            
            document.getElementById('modal_user_id').value = userId;
            document.getElementById('modal_username').textContent = username;
            document.getElementById('modal_student_name').textContent = name;
        });
    });
    
    // Handle form submission via AJAX
    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Get form data
        const formData = new FormData(this);
        
        // Send AJAX request
        fetch('reset_passwords.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            // Create a temporary div to parse the response
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            
            // Create a Bootstrap modal object and hide it
            const resetModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('resetPasswordModal'));
            resetModal.hide();
            
            // Show success message without page reload
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success alert-dismissible fade show';
            successAlert.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                Password has been reset successfully and password change requirement removed. All user sessions have been cleared.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Insert at the top of the main container
            const mainContainer = document.querySelector('main.container-fluid');
            mainContainer.insertBefore(successAlert, mainContainer.firstChild);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(successAlert);
                bsAlert.close();
            }, 5000);
            
            // Reset the form
            this.reset();
        })
        .catch(error => {
            console.error('Error resetting password:', error);
            alert('An error occurred while resetting the password. Please try again.');
        });
    });
    
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('new_password');
        const icon = this.querySelector('i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
    
    // Generate random password
    document.getElementById('generatePassword').addEventListener('click', function() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('new_password').value = password;
        document.getElementById('new_password').type = 'text';
        const icon = document.getElementById('togglePassword').querySelector('i');
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    });
    
    // Apply initial filtering
    filterStudents();
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>