<?php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('admin');

class UserManagementService {
    private $db;
    private $cache = [];
    private $perPage = 25;
    
    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }
    
    public function getUsers($filters = [], $page = 1) {
        $cacheKey = md5(serialize($filters) . $page);
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $offset = ($page - 1) * $this->perPage;
        $params = [];
        $whereConditions = $this->buildWhereConditions($filters, $params);
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        $countQuery = "SELECT COUNT(DISTINCT u.user_id) as total FROM users u 
                      LEFT JOIN students s ON u.user_id = s.user_id
                      LEFT JOIN teachers t ON u.user_id = t.user_id
                      $whereClause";
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->execute($params);
        $totalUsers = $countStmt->fetchColumn();
        
        $query = $this->buildMainQuery($whereClause);
        $stmt = $this->db->prepare($query);
        $stmt->execute(array_merge($params, [$this->perPage, $offset]));
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($users)) {
            $users = $this->attachSessionCounts($users);
        }
        
        $result = [
            'users' => $users,
            'total' => $totalUsers,
            'pages' => ceil($totalUsers / $this->perPage),
            'current_page' => $page
        ];
        
        $this->cache[$cacheKey] = $result;
        return $result;
    }
    
    private function buildWhereConditions($filters, &$params) {
        $conditions = [];
        
        if (!empty($filters['search'])) {
            $conditions[] = "(u.username LIKE ? OR u.email LIKE ? OR 
                            CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) LIKE ? OR
                            CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, '')) LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        if (!empty($filters['role'])) {
            $conditions[] = "u.role = ?";
            $params[] = $filters['role'];
        }
        
        return $conditions;
    }
    
    private function buildMainQuery($whereClause) {
        $selectFields = [
            'u.user_id', 'u.username', 'u.email as user_email', 'u.role',
            'u.created_at', 'u.account_locked', 'u.first_login', 
            'u.password_change_required', 'u.failed_login_attempts',
            's.first_name as student_first_name', 's.last_name as student_last_name',
            'c.class_id', 'c.class_name', 'c.level', 'p.program_name',
            't.first_name as teacher_first_name', 't.last_name as teacher_last_name',
            't.email as teacher_email'
        ];
        
        return "SELECT " . implode(', ', $selectFields) . "
                FROM users u
                LEFT JOIN students s ON u.user_id = s.user_id
                LEFT JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN programs p ON c.program_id = p.program_id
                LEFT JOIN teachers t ON u.user_id = t.user_id
                $whereClause
                ORDER BY u.role, u.username
                LIMIT ? OFFSET ?";
    }
    
    private function attachSessionCounts($users) {
        $userIds = array_column($users, 'user_id');
        if (empty($userIds)) return $users;
        
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
        
        $sessionQuery = "SELECT user_id, COUNT(*) as active_sessions 
                        FROM usersessions 
                        WHERE user_id IN ($placeholders) AND is_active = 1 
                        GROUP BY user_id";
        
        $sessionStmt = $this->db->prepare($sessionQuery);
        $sessionStmt->execute($userIds);
        $sessionCounts = $sessionStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($users as &$user) {
            $user['active_sessions'] = $sessionCounts[$user['user_id']] ?? 0;
        }
        
        return $users;
    }
    
    public function getRoleCounts() {
        if (isset($this->cache['role_counts'])) {
            return $this->cache['role_counts'];
        }
        
        $stmt = $this->db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        $roleCounts = [];
        
        while ($row = $stmt->fetch()) {
            $roleCounts[$row['role']] = (int)$row['count'];
        }
        
        $roleCounts['all'] = array_sum($roleCounts);
        $this->cache['role_counts'] = $roleCounts;
        
        return $roleCounts;
    }
    
    public function getClasses() {
        if (isset($this->cache['classes'])) {
            return $this->cache['classes'];
        }
        
        $stmt = $this->db->query(
            "SELECT c.class_id, c.class_name, c.level, p.program_name
             FROM classes c
             INNER JOIN programs p ON c.program_id = p.program_id
             ORDER BY p.program_name, c.level, c.class_name"
        );
        
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->cache['classes'] = $classes;
        
        return $classes;
    }
    
    public function performUserAction($action, $userId, $data = []) {
        $userId = filter_var($userId, FILTER_VALIDATE_INT);
        if (!$userId) {
            throw new InvalidArgumentException('Invalid user ID');
        }
        
        $stmt = $this->db->prepare(
            "SELECT user_id, username, role, account_locked FROM users WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        if ($userId == $_SESSION['user_id'] && in_array($action, ['delete', 'deactivate'])) {
            throw new Exception('Cannot perform this action on your own account');
        }
        
        return $this->executeUserAction($action, $userId, $user, $data);
    }
    
    private function executeUserAction($action, $userId, $user, $data) {
        $this->db->beginTransaction();
        
        try {
            switch ($action) {
                case 'delete':
                    $this->deleteUser($userId, $user);
                    break;
                    
                case 'deactivate':
                    $this->deactivateUser($userId, $user);
                    break;
                    
                case 'activate':
                    $this->activateUser($userId, $user);
                    break;
                    
                case 'reset_password':
                    $newPassword = $this->resetPassword($userId, $user);
                    break;
                    
                case 'force_logout':
                    $this->forceLogout($userId, $user);
                    break;
                    
                case 'update':
                    $this->updateUser($userId, $user, $data);
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            
            $this->db->commit();
            $this->clearCache();
            
            // Return the new password for reset_password action, otherwise return success message
            if ($action === 'reset_password' && isset($newPassword)) {
                return $newPassword;
            }
            
            return "Action '$action' completed successfully for user '{$user['username']}'";
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    private function deleteUser($userId, $user) {
        $dependencyQueries = [
            "SELECT COUNT(*) FROM studentanswers sa JOIN students s ON sa.student_id = s.student_id WHERE s.user_id = ?",
            "SELECT COUNT(*) FROM results r JOIN students s ON r.student_id = s.student_id WHERE s.user_id = ?",
            "SELECT COUNT(*) FROM assessmentattempts aa JOIN students s ON aa.student_id = s.student_id WHERE s.user_id = ?"
        ];
        
        foreach ($dependencyQueries as $query) {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Cannot delete user: Has associated data in the system');
            }
        }
        
        $deleteQueries = [
            "DELETE FROM usersessions WHERE user_id = ?",
            "DELETE FROM students WHERE user_id = ?",
            "DELETE FROM teachers WHERE user_id = ?",
            "DELETE FROM users WHERE user_id = ?"
        ];
        
        foreach ($deleteQueries as $query) {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$userId]);
        }
        
        logSystemActivity('User Management', "Deleted user: {$user['username']}", 'WARNING');
    }
    
    private function deactivateUser($userId, $user) {
        $stmt = $this->db->prepare(
            "UPDATE users SET account_locked = 1, locked_until = DATE_ADD(NOW(), INTERVAL 10 YEAR) WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
        $this->terminateUserSessions($userId);
        logSystemActivity('User Management', "Deactivated user: {$user['username']}", 'WARNING');
    }
    
    private function activateUser($userId, $user) {
        $stmt = $this->db->prepare(
            "UPDATE users SET account_locked = 0, locked_until = NULL, failed_login_attempts = 0 WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        
        logSystemActivity('User Management', "Activated user: {$user['username']}", 'INFO');
    }
    
    private function resetPassword($userId, $user) {
        $newPassword = bin2hex(random_bytes(6));
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare(
            "UPDATE users SET 
             password_hash = ?, 
             password_change_required = 1,
             failed_login_attempts = 0,
             account_locked = 0,
             locked_until = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$passwordHash, $userId]);
        
        $this->terminateUserSessions($userId);
        logSystemActivity('User Management', "Reset password for user: {$user['username']}", 'WARNING');
        
        return $newPassword;
    }
    
    private function forceLogout($userId, $user) {
        $this->terminateUserSessions($userId);
        logSystemActivity('User Management', "Force logged out user: {$user['username']}", 'INFO');
    }
    
    private function updateUser($userId, $user, $data) {
        $username = sanitizeInput($data['username'] ?? '');
        $email = sanitizeInput($data['email'] ?? '');
        $userRole = sanitizeInput($data['role'] ?? '');
        
        if (!$username || !$userRole) {
            throw new Exception('Username and role are required');
        }
        
        $this->checkUsernameUniqueness($username, $userId);
        if ($email) {
            $this->checkEmailUniqueness($email, $userId);
        }
        
        if ($userRole === 'student') {
            $stmt = $this->db->prepare("UPDATE users SET username = ?, email = NULL WHERE user_id = ?");
            $stmt->execute([$username, $userId]);
            $this->updateStudentProfile($userId, $data);
        } else {
            $stmt = $this->db->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?");
            $stmt->execute([$username, $email, $userId]);
            
            if ($userRole === 'teacher') {
                $this->updateTeacherProfile($userId, $data, $email);
            }
        }
        
        logSystemActivity('User Management', "Updated user: $username", 'INFO');
    }
    
    private function terminateUserSessions($userId) {
        $stmt = $this->db->prepare("UPDATE usersessions SET is_active = 0 WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    
    private function clearCache() {
        $this->cache = [];
    }
    
    private function checkUsernameUniqueness($username, $excludeUserId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$username, $excludeUserId]);
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Username already exists');
        }
    }
    
    private function checkEmailUniqueness($email, $excludeUserId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $excludeUserId]);
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Email already exists');
        }
    }
    
    private function updateStudentProfile($userId, $data) {
        $firstName = sanitizeInput($data['first_name'] ?? '');
        $lastName = sanitizeInput($data['last_name'] ?? '');
        $classId = filter_var($data['class_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if (!$firstName || !$lastName || !$classId) {
            throw new Exception('Missing required student information');
        }
        
        $stmt = $this->db->prepare(
            "INSERT INTO students (first_name, last_name, class_id, user_id) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             first_name = VALUES(first_name), 
             last_name = VALUES(last_name), 
             class_id = VALUES(class_id)"
        );
        $stmt->execute([$firstName, $lastName, $classId, $userId]);
    }
    
    private function updateTeacherProfile($userId, $data, $email) {
        $firstName = sanitizeInput($data['teacher_first_name'] ?? '');
        $lastName = sanitizeInput($data['teacher_last_name'] ?? '');
        
        if (!$firstName || !$lastName) {
            throw new Exception('Missing required teacher information');
        }
        
        $stmt = $this->db->prepare(
            "INSERT INTO teachers (first_name, last_name, email, user_id) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             first_name = VALUES(first_name), 
             last_name = VALUES(last_name), 
             email = VALUES(email)"
        );
        $stmt->execute([$firstName, $lastName, $email, $userId]);
    }
}

$userService = new UserManagementService();

$error = '';
$success = '';
$activeTab = 'all';

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_GET['tab']) && in_array($_GET['tab'], ['all', 'admin', 'teacher', 'student'])) {
    $activeTab = $_GET['tab'];
}

$filters = [
    'search' => sanitizeInput($_GET['search'] ?? ''),
    'role' => sanitizeInput($_GET['role'] ?? ($activeTab !== 'all' ? $activeTab : ''))
];

$page = max(1, (int)($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        $action = sanitizeInput($_POST['action'] ?? '');
        $userId = filter_var($_POST['user_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if ($action === 'reset_password') {
            $newPassword = $userService->performUserAction($action, $userId);
            $success = "Password reset successfully. New password: $newPassword";
        } else {
            $success = $userService->performUserAction($action, $userId, $_POST);
        }
        
        $redirectUrl = "users.php?tab=" . urlencode($activeTab) . "&success=" . urlencode($success);
        if (!empty($filters['search'])) {
            $redirectUrl .= "&search=" . urlencode($filters['search']);
        }
        if (!empty($filters['role']) && $activeTab === 'all') {
            $redirectUrl .= "&role=" . urlencode($filters['role']);
        }
        
        header("Location: $redirectUrl");
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        logError("User management error: " . $e->getMessage());
    }
}

try {
    $userData = $userService->getUsers($filters, $page);
    $users = $userData['users'];
    $totalUsers = $userData['total'];
    $totalPages = $userData['pages'];
    
    $roleCounts = $userService->getRoleCounts();
    $classList = $userService->getClasses();
    
} catch (Exception $e) {
    $error = "Error loading data: " . $e->getMessage();
    logError("User data loading error: " . $e->getMessage());
    $users = [];
    $totalUsers = 0;
    $totalPages = 1;
    $roleCounts = ['all' => 0, 'admin' => 0, 'teacher' => 0, 'student' => 0];
    $classList = [];
}

$pageTitle = 'User Management';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>

<div class="container-fluid py-4">
    <!-- Mobile-optimized Header -->
    <div class="row align-items-center mb-4">
        <div class="col-12 col-lg-8 mb-3 mb-lg-0">
            <h1 class="h3 mb-1 text-warning fw-bold d-flex align-items-center">
                <i class="fas fa-users-cog me-2"></i>
                <span class="d-none d-md-inline">User Management Dashboard</span>
                <span class="d-md-none">Users</span>
            </h1>
            <p class="text-muted small mb-0 d-none d-sm-block">Manage administrative, teacher, and student accounts</p>
        </div>
        <div class="col-12 col-lg-4">
            <div class="d-flex gap-2 justify-content-lg-end flex-wrap">
                <button type="button" class="btn btn-outline-warning btn-sm flex-fill flex-lg-grow-0" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i>
                    <span class="d-none d-sm-inline">Refresh</span>
                </button>
                <button type="button" class="btn btn-warning flex-fill flex-lg-grow-0" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-user-plus me-1"></i>
                    <span class="d-none d-sm-inline">Add New User</span>
                    <span class="d-sm-none">Add</span>
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Enhanced Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['label' => 'Total Users', 'count' => $roleCounts['all'], 'icon' => 'users', 'color' => 'primary'],
            ['label' => 'Administrators', 'count' => $roleCounts['admin'], 'icon' => 'user-shield', 'color' => 'info'],
            ['label' => 'Teachers', 'count' => $roleCounts['teacher'], 'icon' => 'chalkboard-teacher', 'color' => 'success'],
            ['label' => 'Students', 'count' => $roleCounts['student'], 'icon' => 'graduation-cap', 'color' => 'warning']
        ];
        
        foreach ($stats as $stat):
        ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stats-card stats-card-<?php echo $stat['color']; ?>">
                <div class="stats-card-content">
                    <div class="stats-info">
                        <div class="stats-label"><?php echo $stat['label']; ?></div>
                        <div class="stats-number"><?php echo number_format($stat['count']); ?></div>
                        <div class="stats-description">
                            <i class="fas fa-<?php echo $stat['icon']; ?> me-1"></i>
                            System users
                        </div>
                    </div>
                    <div class="stats-icon">
                        <i class="fas fa-<?php echo $stat['icon']; ?>"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Mobile-Optimized Search and Filter -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="users.php" id="searchForm">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="page" value="1">
                
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <div class="input-group">
                            <span class="input-group-text bg-dark text-warning">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search users..." 
                                   value="<?php echo htmlspecialchars($filters['search']); ?>"
                                   autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="col-6 col-lg-2">
                        <select name="role" class="form-select">
                            <option value="">All Roles</option>
                            <?php foreach (['admin', 'teacher', 'student'] as $role): ?>
                                <option value="<?php echo $role; ?>" <?php echo $filters['role'] === $role ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($role); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-6 col-lg-2">
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="fas fa-filter d-lg-none"></i>
                            <span class="d-none d-lg-inline">Filter</span>
                        </button>
                    </div>
                    
                    <div class="col-12 col-lg-2">
                        <?php if (!empty($filters['search']) || !empty($filters['role'])): ?>
                            <a href="users.php?tab=<?php echo htmlspecialchars($activeTab); ?>" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-times me-1"></i>Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div class="small text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Showing <?php echo count($users); ?> of <?php echo $totalUsers; ?> users
                        <?php if ($totalPages > 1): ?>
                            | Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="card shadow-sm mb-3">
        <div class="card-body p-2">
            <div class="nav nav-pills nav-fill mobile-tabs">
                <?php
                $tabs = [
                    'all' => ['icon' => 'users', 'label' => 'All', 'count' => $roleCounts['all']],
                    'admin' => ['icon' => 'user-shield', 'label' => 'Admins', 'count' => $roleCounts['admin']],
                    'teacher' => ['icon' => 'chalkboard-teacher', 'label' => 'Teachers', 'count' => $roleCounts['teacher']],
                    'student' => ['icon' => 'graduation-cap', 'label' => 'Students', 'count' => $roleCounts['student']]
                ];
                
                foreach ($tabs as $tabKey => $tabData):
                    $isActive = $activeTab === $tabKey;
                    $urlParams = [];
                    $urlParams['tab'] = $tabKey;
                    if ($tabKey !== 'all') {
                        $urlParams['role'] = $tabKey;
                    }
                    if (!empty($filters['search'])) {
                        $urlParams['search'] = $filters['search'];
                    }
                    
                    $url = "users.php?" . http_build_query($urlParams);
                ?>
                    <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo $url; ?>">
                        <i class="fas fa-<?php echo $tabData['icon']; ?> d-block d-sm-inline me-sm-1"></i>
                        <span class="d-none d-sm-inline"><?php echo $tabData['label']; ?></span>
                        <span class="badge bg-light text-dark ms-1"><?php echo $tabData['count']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="card shadow-sm">
        <!-- Mobile Card View -->
        <div class="d-block d-lg-none">
            <div class="card-header bg-dark text-warning">
                <h6 class="mb-0"><i class="fas fa-users me-2"></i>Users List</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No users found</h5>
                    </div>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $displayName = '';
                        $email = '';
                        
                        if ($user['role'] === 'student') {
                            $displayName = trim($user['student_first_name'] . ' ' . $user['student_last_name']);
                        } elseif ($user['role'] === 'teacher') {
                            $displayName = trim($user['teacher_first_name'] . ' ' . $user['teacher_last_name']);
                            $email = $user['teacher_email'];
                        } else {
                            $displayName = 'Administrator';
                            $email = $user['user_email'];
                        }
                        
                        $isLocked = $user['account_locked'] == 1;
                        $needsPasswordChange = $user['password_change_required'] == 1;
                        $isFirstLogin = $user['first_login'] == 1;
                        $activeSessions = (int)$user['active_sessions'];
                        ?>
                        <div class="border-bottom user-mobile-card">
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($user['username']); ?></h6>
                                        <div class="text-muted small"><?php echo htmlspecialchars($displayName ?: 'N/A'); ?></div>
                                        <?php if ($user['role'] === 'student' && !empty($user['class_name'])): ?>
                                            <div class="text-muted small">
                                                <i class="fas fa-graduation-cap me-1"></i>
                                                <?php echo htmlspecialchars($user['class_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <!-- Role Badge -->
                                        <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'info' : ($user['role'] === 'teacher' ? 'success' : 'warning'); ?> mb-1">
                                            <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'user-shield' : ($user['role'] === 'teacher' ? 'chalkboard-teacher' : 'graduation-cap'); ?> me-1"></i>
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                        
                                        <!-- Action Dropdown -->
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-dark dropdown-toggle" type="button" 
                                                    data-bs-toggle="dropdown">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button type="button" class="dropdown-item" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editUserModal"
                                                            data-user='<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES); ?>'>
                                                        <i class="fas fa-edit me-2"></i>Edit User
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php if (!$isLocked): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#confirmActionModal"
                                                                data-action="deactivate"
                                                                data-user-id="<?php echo $user['user_id']; ?>"
                                                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                            <i class="fas fa-user-lock me-2"></i>Deactivate
                                                        </button>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-success" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#confirmActionModal"
                                                                data-action="activate"
                                                                data-user-id="<?php echo $user['user_id']; ?>"
                                                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                            <i class="fas fa-user-check me-2"></i>Activate
                                                        </button>
                                                    </li>
                                                <?php endif; ?>
                                                <li>
                                                    <button type="button" class="dropdown-item" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#confirmActionModal"
                                                            data-action="reset_password"
                                                            data-user-id="<?php echo $user['user_id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                        <i class="fas fa-key me-2"></i>Reset Password
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-2 small">
                                    <div class="col-6">
                                        <div class="text-muted">Status:</div>
                                        <?php if ($isLocked): ?>
                                            <span class="badge bg-danger">Deactivated</span>
                                        <?php elseif ($needsPasswordChange): ?>
                                            <span class="badge bg-warning text-dark">Password Reset</span>
                                        <?php elseif ($isFirstLogin): ?>
                                            <span class="badge bg-info">First Login</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted">Sessions:</div>
                                        <span class="badge bg-<?php echo $activeSessions > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo $activeSessions > 0 ? "$activeSessions active" : 'Offline'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($email): ?>
                                    <div class="mt-2 small text-muted text-truncate">
                                        <i class="fas fa-envelope me-1"></i>
                                        <?php echo htmlspecialchars($email); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="d-none d-lg-block">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="50" class="text-warning">#</th>
                                <th class="text-warning">Username</th>
                                <th class="text-warning">Name</th>
                                <th class="text-warning">Email</th>
                                <th class="text-warning">Role</th>
                                <th class="text-warning">Status</th>
                                <th class="text-warning">Sessions</th>
                                <th class="text-warning">Created</th>
                                <th width="200" class="text-warning">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-user-slash me-2 text-muted"></i>
                                        <span class="text-muted">No users found</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $index => $user): ?>
                                    <?php
                                    $displayName = '';
                                    $email = '';
                                    
                                    if ($user['role'] === 'student') {
                                        $displayName = trim($user['student_first_name'] . ' ' . $user['student_last_name']);
                                    } elseif ($user['role'] === 'teacher') {
                                        $displayName = trim($user['teacher_first_name'] . ' ' . $user['teacher_last_name']);
                                        $email = $user['teacher_email'];
                                    } else {
                                        $displayName = 'Administrator';
                                        $email = $user['user_email'];
                                    }
                                    
                                    $isLocked = $user['account_locked'] == 1;
                                    $needsPasswordChange = $user['password_change_required'] == 1;
                                    $isFirstLogin = $user['first_login'] == 1;
                                    $activeSessions = (int)$user['active_sessions'];
                                    $globalIndex = ($page - 1) * 25 + $index + 1;
                                    ?>
                                    <tr>
                                        <td class="align-middle"><?php echo $globalIndex; ?></td>
                                        <td class="align-middle">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($user['username']); ?></div>
                                            <?php if ($user['failed_login_attempts'] > 0): ?>
                                                <small class="text-danger">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <?php echo $user['failed_login_attempts']; ?> failed attempts
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle">
                                            <div><?php echo htmlspecialchars($displayName ?: 'N/A'); ?></div>
                                            <?php if ($user['role'] === 'student' && !empty($user['class_name'])): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-graduation-cap me-1"></i>
                                                    <?php echo htmlspecialchars($user['class_name']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle"><?php echo htmlspecialchars($email ?: 'N/A'); ?></td>
                                        <td class="align-middle">
                                            <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'info' : ($user['role'] === 'teacher' ? 'success' : 'warning'); ?>">
                                                <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'user-shield' : ($user['role'] === 'teacher' ? 'chalkboard-teacher' : 'graduation-cap'); ?> me-1"></i>
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <?php if ($isLocked): ?>
                                                <span class="badge bg-danger"><i class="fas fa-lock me-1"></i>Deactivated</span>
                                            <?php elseif ($needsPasswordChange): ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-key me-1"></i>Password Reset Required</span>
                                            <?php elseif ($isFirstLogin): ?>
                                                <span class="badge bg-info"><i class="fas fa-user-clock me-1"></i>First Login Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle">
                                            <span class="badge bg-<?php echo $activeSessions > 0 ? 'success' : 'secondary'; ?>">
                                                <i class="fas fa-circle me-1" style="font-size: 0.6em;"></i>
                                                <?php echo $activeSessions > 0 ? "$activeSessions active" : 'Offline'; ?>
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <span class="small text-muted">
                                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-warning" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editUserModal"
                                                        data-user='<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES); ?>'
                                                        title="Edit User">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-dark dropdown-toggle" type="button" 
                                                            data-bs-toggle="dropdown">
                                                        <i class="fas fa-cog"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if (!$isLocked): ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item text-danger" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#confirmActionModal"
                                                                        data-action="deactivate"
                                                                        data-user-id="<?php echo $user['user_id']; ?>"
                                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                                    <i class="fas fa-user-lock me-2"></i>Deactivate Account
                                                                </button>
                                                            </li>
                                                        <?php else: ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item text-success" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#confirmActionModal"
                                                                        data-action="activate"
                                                                        data-user-id="<?php echo $user['user_id']; ?>"
                                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                                    <i class="fas fa-user-check me-2"></i>Activate Account
                                                                </button>
                                                            </li>
                                                        <?php endif; ?>
                                                        
                                                        <li>
                                                            <button type="button" class="dropdown-item" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#confirmActionModal"
                                                                    data-action="reset_password"
                                                                    data-user-id="<?php echo $user['user_id']; ?>"
                                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                                <i class="fas fa-key me-2"></i>Reset Password
                                                            </button>
                                                        </li>
                                                        
                                                        <?php if ($activeSessions > 0): ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item text-warning" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#confirmActionModal"
                                                                        data-action="force_logout"
                                                                        data-user-id="<?php echo $user['user_id']; ?>"
                                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                                    <i class="fas fa-sign-out-alt me-2"></i>Force Logout
                                                                </button>
                                                            </li>
                                                        <?php endif; ?>
                                                        
                                                        <li><hr class="dropdown-divider"></li>
                                                        
                                                        <li>
                                                            <button type="button" class="dropdown-item text-danger" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#confirmActionModal"
                                                                    data-action="delete"
                                                                    data-user-id="<?php echo $user['user_id']; ?>"
                                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                                <i class="fas fa-trash me-2"></i>Delete User
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-light">
            <div class="row align-items-center">
                <div class="col-12 col-md-6 mb-2 mb-md-0">
                    <div class="small text-muted text-center text-md-start">
                        Showing <?php echo (($page - 1) * 25) + 1; ?> to <?php echo min($page * 25, $totalUsers); ?> of <?php echo $totalUsers; ?> entries
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center justify-content-md-end mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-warning">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="get_user.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required id="addUserRole">
                                <option value="">Select Role</option>
                                <option value="admin">Administrator</option>
                                <option value="teacher">Teacher</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" id="addPassword" class="form-control" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="generatePassword('addPassword')">
                                    <i class="fas fa-dice"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email <span class="text-danger email-required">*</span></label>
                            <input type="email" name="email" id="addUserEmail" class="form-control" required>
                        </div>
                    </div>

                    <!-- Role-specific fields -->
                    <div id="studentFields" class="d-none">
                        <div class="row mb-3">
                            <div class="col-12 col-md-6 mb-3 mb-md-0">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Class <span class="text-danger">*</span></label>
                            <select name="class_id" class="form-select">
                                <option value="">Select Class</option>
                                <?php foreach ($classList as $class): ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="teacherFields" class="d-none">
                        <div class="row mb-3">
                            <div class="col-12 col-md-6 mb-3 mb-md-0">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-user-plus me-1"></i>Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-warning">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="users.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="user_id" id="editUserId">
                    <input type="hidden" name="role" id="editUserRole">

                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="editUsername" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="editEmail" class="form-control">
                        </div>
                    </div>

                    <div id="editStudentFields">
                        <div class="row mb-3">
                            <div class="col-12 col-md-6 mb-3 mb-md-0">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" id="editStudentFirstName" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" id="editStudentLastName" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" id="editClassId" class="form-select">
                                <option value="">Select Class</option>
                                <?php foreach ($classList as $class): ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['program_name'] . ' - ' . $class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="editTeacherFields">
                        <div class="row mb-3">
                            <div class="col-12 col-md-6 mb-3 mb-md-0">
                                <label class="form-label">First Name</label>
                                <input type="text" name="teacher_first_name" id="editTeacherFirstName" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="teacher_last_name" id="editTeacherLastName" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirm Action Modal -->
<div class="modal fade" id="confirmActionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="confirmModalHeader">
                <h5 class="modal-title" id="confirmModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="users.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" id="confirmAction">
                    <input type="hidden" name="user_id" id="confirmUserId">
                    
                    <div class="alert" id="confirmAlert">
                        <div id="confirmMessage"></div>
                    </div>
                    
                    <div id="deleteConfirmation" class="d-none">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmDelete">
                            <label class="form-check-label text-danger" for="confirmDelete">
                                I understand this action is permanent and cannot be undone
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="confirmSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Optimized CSS - Mobile First Design */
:root {
    --gold-primary: #ffd700;
    --gold-dark: #b8860b;
    --black-primary: #000000;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.15);
    --border-radius: 8px;
}

/* Enhanced Statistics Cards */
.stats-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
    height: 100%;
    overflow: hidden;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stats-card-content {
    padding: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stats-info {
    flex: 1;
}

.stats-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--black-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stats-description {
    font-size: 0.75rem;
    color: #6c757d;
    font-weight: 500;
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-left: 1rem;
}

.stats-card-primary .stats-icon {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
}

.stats-card-info .stats-icon {
    background: linear-gradient(135deg, #17a2b8, #117a8b);
    color: white;
}

.stats-card-success .stats-icon {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    color: white;
}

.stats-card-warning .stats-icon {
    background: linear-gradient(135deg, var(--gold-primary), var(--gold-dark));
    color: var(--black-primary);
}

/* Mobile-Optimized Tab Navigation */
.mobile-tabs .nav-link {
    border-radius: var(--border-radius);
    padding: 0.75rem 0.5rem;
    font-weight: 600;
    font-size: 0.875rem;
    transition: var(--transition);
    text-align: center;
    border: 2px solid transparent;
}

.mobile-tabs .nav-link:not(.active) {
    background: #f8f9fa;
    color: #6c757d;
}

.mobile-tabs .nav-link.active {
    background: linear-gradient(135deg, var(--black-primary), var(--gold-primary));
    color: white;
    border-color: var(--gold-primary);
}

.mobile-tabs .nav-link:hover:not(.active) {
    background: #e9ecef;
    color: var(--gold-dark);
    transform: translateY(-1px);
}

/* Mobile User Cards */
.user-mobile-card {
    transition: var(--transition);
}

.user-mobile-card:hover {
    background: rgba(255, 215, 0, 0.05);
}

/* Enhanced Form Styling */
.form-control, .form-select {
    border: 2px solid #e9ecef;
    border-radius: var(--border-radius);
    transition: var(--transition);
    font-size: 0.875rem;
    padding: 0.75rem;
}

.form-control:focus, .form-select:focus {
    border-color: var(--gold-primary);
    box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25);
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

/* Enhanced Button Styling */
.btn {
    border-radius: var(--border-radius);
    font-weight: 600;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.btn-warning {
    background: linear-gradient(135deg, var(--black-primary), var(--gold-primary));
    border: 2px solid var(--gold-primary);
    color: white;
}

.btn-warning:hover {
    background: linear-gradient(135deg, var(--gold-primary), var(--black-primary));
    border-color: var(--gold-dark);
    color: white;
    transform: translateY(-1px);
}

.btn-outline-warning {
    border: 2px solid var(--gold-primary);
    color: var(--gold-dark);
    background: transparent;
}

.btn-outline-warning:hover {
    background: var(--gold-primary);
    border-color: var(--gold-primary);
    color: var(--black-primary);
}

/* Enhanced Table Styling */
.table-dark {
    background: linear-gradient(135deg, var(--black-primary), #2c3e50);
}

.table-dark th {
    background: transparent;
    border: none;
    color: var(--gold-primary);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.875rem;
    letter-spacing: 0.5px;
    padding: 1rem 0.75rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(255, 215, 0, 0.05);
    transition: background-color 0.2s ease;
}

/* Enhanced Modal Styling */
.modal-content {
    border-radius: var(--border-radius);
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.modal-header {
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    border-bottom: none;
    padding: 1.5rem;
}

/* Enhanced Badge Styling */
.badge {
    font-weight: 600;
    border-radius: 6px;
    padding: 0.5em 0.75em;
    font-size: 0.875em;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

/* Enhanced Dropdown Styling */
.dropdown-menu {
    border: none;
    border-radius: 10px;
    box-shadow: var(--shadow-md);
    padding: 0.5rem 0;
    min-width: 220px;
}

.dropdown-item {
    padding: 0.75rem 1.25rem;
    transition: var(--transition);
    font-weight: 500;
    display: flex;
    align-items: center;
}

.dropdown-item:hover {
    background: rgba(255, 215, 0, 0.1);
    color: var(--gold-dark);
}

/* Enhanced Pagination */
.pagination .page-link {
    border: 2px solid #e9ecef;
    color: var(--black-primary);
    font-weight: 600;
    padding: 0.5rem 0.75rem;
    margin: 0 0.125rem;
    border-radius: 6px;
    transition: var(--transition);
}

.pagination .page-link:hover {
    background: var(--gold-primary);
    border-color: var(--gold-primary);
    color: var(--black-primary);
    transform: translateY(-1px);
}

.pagination .page-item.active .page-link {
    background: var(--black-primary);
    border-color: var(--black-primary);
    color: white;
}

/* Mobile Responsive Breakpoints */
@media (max-width: 576px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stats-card-content {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .stats-icon {
        margin-left: 0;
        order: -1;
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .stats-number {
        font-size: 1.75rem;
    }
    
    .mobile-tabs .nav-link {
        padding: 0.5rem 0.25rem;
        font-size: 0.75rem;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .dropdown-menu {
        position: fixed !important;
        bottom: 0 !important;
        left: 0.5rem !important;
        right: 0.5rem !important;
        top: auto !important;
        transform: none !important;
        border-radius: 1rem 1rem 0 0;
        max-height: 75vh;
        overflow-y: auto;
    }
}

@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .modal-lg {
        max-width: 95%;
    }
}

/* Print Styles */
@media print {
    .btn, .dropdown, .modal {
        display: none !important;
    }
    
    .card {
        page-break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ddd;
    }
}

/* Accessibility */
.btn:focus, .form-control:focus, .form-select:focus {
    outline: 2px solid var(--gold-primary);
    outline-offset: 2px;
}

/* Animation for better UX */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stats-card, .card {
    animation: fadeInUp 0.5s ease-out;
}
</style>

<script>
// Optimized JavaScript
document.addEventListener('DOMContentLoaded', function() {
    initializeComponents();
    
    function initializeComponents() {
        initializePasswordGeneration();
        initializeRoleFields();
        initializeModalHandlers();
        initializeFormValidation();
        initializeSearchFunctionality();
        initializeMobileOptimizations();
        initializeConfirmActionModal();
    }
    
    function initializePasswordGeneration() {
        window.generatePassword = function(inputId) {
            const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*";
            let password = "";
            const length = Math.floor(Math.random() * 3) + 12;
            
            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            const input = document.getElementById(inputId);
            input.value = password;
            
            // Visual feedback
            input.style.background = 'linear-gradient(135deg, #d4edda, #c3e6cb)';
            setTimeout(() => {
                input.style.background = '';
            }, 1000);
        };
    }
    
    function initializeRoleFields() {
        const addUserRole = document.getElementById('addUserRole');
        const studentFields = document.getElementById('studentFields');
        const teacherFields = document.getElementById('teacherFields');
        const addUserEmail = document.getElementById('addUserEmail');
        
        if (addUserRole) {
            addUserRole.addEventListener('change', function() {
                const selectedRole = this.value;
                
                // Hide all role-specific fields
                [studentFields, teacherFields].forEach(field => {
                    if (field) {
                        field.classList.add('d-none');
                        toggleRequiredAttributes(field, false);
                    }
                });
                
                // Show appropriate fields
                if (selectedRole === 'student') {
                    studentFields.classList.remove('d-none');
                    toggleRequiredAttributes(studentFields, true);
                    addUserEmail.removeAttribute('required');
                    document.querySelectorAll('.email-required').forEach(el => el.classList.add('d-none'));
                } else {
                    addUserEmail.setAttribute('required', 'required');
                    document.querySelectorAll('.email-required').forEach(el => el.classList.remove('d-none'));
                    
                    if (selectedRole === 'teacher') {
                        teacherFields.classList.remove('d-none');
                        toggleRequiredAttributes(teacherFields, true);
                    }
                }
            });
        }
    }
    
    function initializeModalHandlers() {
        // Edit User Modal
        const editUserModal = document.getElementById('editUserModal');
        if (editUserModal) {
            editUserModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userData = JSON.parse(button.getAttribute('data-user'));
                
                // Set basic fields
                document.getElementById('editUserId').value = userData.user_id;
                document.getElementById('editUsername').value = userData.username;
                document.getElementById('editUserRole').value = userData.role;
                
                // Clear all fields first
                ['editEmail', 'editStudentFirstName', 'editStudentLastName', 
                 'editTeacherFirstName', 'editTeacherLastName', 'editClassId'].forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.value = '';
                });
                
                const editStudentFields = document.getElementById('editStudentFields');
                const editTeacherFields = document.getElementById('editTeacherFields');
                
                // Reset visibility and required attributes
                toggleRequiredAttributes(editStudentFields, false);
                toggleRequiredAttributes(editTeacherFields, false);
                editStudentFields.classList.add('d-none');
                editTeacherFields.classList.add('d-none');
                
                // Show appropriate fields based on role
                if (userData.role === 'student') {
                    editStudentFields.classList.remove('d-none');
                    toggleRequiredAttributes(editStudentFields, true);
                    document.getElementById('editStudentFirstName').value = userData.student_first_name || '';
                    document.getElementById('editStudentLastName').value = userData.student_last_name || '';
                    document.getElementById('editClassId').value = userData.class_id || '';
                    document.getElementById('editEmail').removeAttribute('required');
                } else {
                    document.getElementById('editEmail').setAttribute('required', 'required');
                    
                    if (userData.role === 'teacher') {
                        editTeacherFields.classList.remove('d-none');
                        toggleRequiredAttributes(editTeacherFields, true);
                        document.getElementById('editTeacherFirstName').value = userData.teacher_first_name || '';
                        document.getElementById('editTeacherLastName').value = userData.teacher_last_name || '';
                        document.getElementById('editEmail').value = userData.teacher_email || '';
                    } else {
                        document.getElementById('editEmail').value = userData.user_email || '';
                    }
                }
            });
        }
    }
    
    function initializeConfirmActionModal() {
        const modal = document.getElementById('confirmActionModal');
        if (!modal) return;
        
        const actionConfigs = {
            deactivate: {
                title: 'Deactivate User Account',
                headerClass: 'bg-danger text-white',
                alertClass: 'alert-danger',
                message: 'You are about to deactivate this user account. They will not be able to log in.',
                btnClass: 'btn-danger',
                btnText: 'Deactivate Account'
            },
            activate: {
                title: 'Activate User Account',
                headerClass: 'bg-success text-white',
                alertClass: 'alert-success',
                message: 'You are about to activate this user account. They will be able to log in again.',
                btnClass: 'btn-success',
                btnText: 'Activate Account'
            },
            reset_password: {
                title: 'Reset User Password',
                headerClass: 'bg-warning text-dark',
                alertClass: 'alert-warning',
                message: 'You are about to reset this user\'s password. A new random password will be generated.',
                btnClass: 'btn-warning',
                btnText: 'Reset Password'
            },
            force_logout: {
                title: 'Force Logout User',
                headerClass: 'bg-info text-white',
                alertClass: 'alert-info',
                message: 'You are about to force this user to log out of all active sessions.',
                btnClass: 'btn-info',
                btnText: 'Force Logout'
            },
            delete: {
                title: 'Delete User',
                headerClass: 'bg-danger text-white',
                alertClass: 'alert-danger',
                message: 'You are about to permanently delete this user from the system. This action cannot be undone.',
                btnClass: 'btn-danger',
                btnText: 'Delete User',
                requireConfirmation: true
            }
        };
        
        modal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const action = button.getAttribute('data-action');
            const userId = button.getAttribute('data-user-id');
            const username = button.getAttribute('data-username');
            
            const config = actionConfigs[action];
            if (!config) return;
            
            // Set modal content
            document.getElementById('confirmModalTitle').textContent = config.title;
            document.getElementById('confirmModalHeader').className = `modal-header ${config.headerClass}`;
            document.getElementById('confirmAlert').className = `alert ${config.alertClass}`;
            document.getElementById('confirmMessage').innerHTML = `<strong>${username}:</strong> ${config.message}`;
            document.getElementById('confirmAction').value = action;
            document.getElementById('confirmUserId').value = userId;
            document.getElementById('confirmSubmitBtn').className = `btn ${config.btnClass}`;
            document.getElementById('confirmSubmitBtn').textContent = config.btnText;
            
            // Handle delete confirmation
            const deleteConfirmation = document.getElementById('deleteConfirmation');
            const confirmDelete = document.getElementById('confirmDelete');
            const submitBtn = document.getElementById('confirmSubmitBtn');
            
            if (config.requireConfirmation) {
                deleteConfirmation.classList.remove('d-none');
                submitBtn.disabled = true;
                
                confirmDelete.addEventListener('change', function() {
                    submitBtn.disabled = !this.checked;
                });
            } else {
                deleteConfirmation.classList.add('d-none');
                submitBtn.disabled = false;
            }
        });
        
        // Reset confirmation when modal closes
        modal.addEventListener('hidden.bs.modal', function() {
            const confirmDelete = document.getElementById('confirmDelete');
            if (confirmDelete) {
                confirmDelete.checked = false;
            }
        });
    }
    
    function initializeFormValidation() {
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Remove required attribute from hidden sections
                document.querySelectorAll('.d-none').forEach(hiddenSection => {
                    toggleRequiredAttributes(hiddenSection, false);
                });
            });
        });
    }
    
    function initializeSearchFunctionality() {
        // Auto-search removed - users must click Filter button to search
    }
    
    function initializeMobileOptimizations() {
        // Touch interactions for mobile
        if ('ontouchstart' in window) {
            const cards = document.querySelectorAll('.stats-card, .user-mobile-card');
            cards.forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                
                card.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });
        }
        
        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach((alert, index) => {
            setTimeout(() => {
                const closeBtn = alert.querySelector('.btn-close');
                if (closeBtn) {
                    closeBtn.click();
                }
            }, 5000 + (index * 500));
        });
    }
    
    function toggleRequiredAttributes(container, isRequired) {
        if (!container) return;
        
        const fields = container.querySelectorAll('input, select');
        fields.forEach(field => {
            if (isRequired) {
                field.setAttribute('required', 'required');
            } else {
                field.removeAttribute('required');
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (window.innerWidth <= 768) return; // Skip on mobile
        
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            const addUserBtn = document.querySelector('[data-bs-target="#addUserModal"]');
            if (addUserBtn) addUserBtn.click();
        }
        
        if (e.key === 'Escape') {
            const openModals = document.querySelectorAll('.modal.show');
            openModals.forEach(modal => {
                const closeBtn = modal.querySelector('.btn-close');
                if (closeBtn) closeBtn.click();
            });
        }
    });
    
    console.log('Optimized user management system initialized');
});
</script>

<?php require_once INCLUDES_PATH . '/bass/base_footer.php'; ?>