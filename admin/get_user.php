<?php
// admin/get_user.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure user is admin
requireRole('admin');

// Initialize response variables
$response = [
    'status' => 'error',
    'message' => 'Invalid request'
];

try {
    // Check if request is POST and has valid CSRF token
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $action = sanitizeInput($_POST['action'] ?? '');
        
        // Handle user creation
        if ($action === 'add') {
            $username = sanitizeInput($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitizeInput($_POST['role'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            
            // Validate required fields
            if (empty($username) || empty($password) || empty($role)) {
                throw new Exception('Missing required fields');
            }
            
            // Check if username already exists
            $db = DatabaseConfig::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT COUNT(*) FROM Users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Username already exists');
            }
            
            // Check if email already exists (for admin and teacher roles)
            if (in_array($role, ['admin', 'teacher'])) {
                if (empty($email)) {
                    throw new Exception('Email is required for admin and teacher roles');
                }
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM Users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Email already exists');
                }
            }
            
            // Validate password length
            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters long');
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Create user account
                $stmt = $db->prepare(
                    "INSERT INTO Users (
                        username, 
                        password_hash, 
                        role, 
                        email, 
                        first_login, 
                        password_change_required
                    ) VALUES (?, ?, ?, ?, 1, 1)"
                );
                
                // For student role, email is NULL
                if ($role === 'student') {
                    $stmt->execute([$username, $passwordHash, $role, null]);
                } else {
                    $stmt->execute([$username, $passwordHash, $role, $email]);
                }
                
                $userId = $db->lastInsertId();
                
                // Create profile based on role
                if ($role === 'student') {
                    $firstName = sanitizeInput($_POST['first_name'] ?? '');
                    $lastName = sanitizeInput($_POST['last_name'] ?? '');
                    $classId = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
                    
                    if (empty($firstName) || empty($lastName) || empty($classId)) {
                        throw new Exception('Missing required student information');
                    }
                    
                    $stmt = $db->prepare(
                        "INSERT INTO Students (
                            first_name, 
                            last_name, 
                            class_id, 
                            user_id
                        ) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$firstName, $lastName, $classId, $userId]);
                    
                } elseif ($role === 'teacher') {
                    $firstName = sanitizeInput($_POST['first_name'] ?? '');
                    $lastName = sanitizeInput($_POST['last_name'] ?? '');
                    
                    if (empty($firstName) || empty($lastName)) {
                        throw new Exception('Missing required teacher information');
                    }
                    
                    $stmt = $db->prepare(
                        "INSERT INTO Teachers (
                            first_name, 
                            last_name, 
                            email, 
                            user_id
                        ) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$firstName, $lastName, $email, $userId]);
                }
                
                // Log the action
                logSystemActivity(
                    'User Management',
                    "Created new user: $username (ID: $userId, Role: $role)",
                    'INFO'
                );
                
                $db->commit();
                
                $_SESSION['success'] = "User '$username' created successfully with password: $password";
                
                // Redirect back to users page
                header('Location: users.php');
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } elseif ($action === 'get_details') {
            // Handle fetching user details (for edit modal)
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            
            if (!$userId) {
                throw new Exception('Invalid user ID');
            }
            
            $db = DatabaseConfig::getInstance()->getConnection();
            
            $stmt = $db->prepare(
                "SELECT 
                    u.user_id, 
                    u.username, 
                    u.email as user_email, 
                    u.role, 
                    s.student_id,
                    s.first_name as student_first_name,
                    s.last_name as student_last_name,
                    c.class_id,
                    c.class_name,
                    t.teacher_id,
                    t.first_name as teacher_first_name,
                    t.last_name as teacher_last_name,
                    t.email as teacher_email
                FROM Users u
                LEFT JOIN Students s ON u.user_id = s.user_id
                LEFT JOIN Classes c ON s.class_id = c.class_id
                LEFT JOIN Teachers t ON u.user_id = t.user_id
                WHERE u.user_id = ?"
            );
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData) {
                throw new Exception('User not found');
            }
            
            // Return user data as JSON
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'data' => $userData
            ]);
            exit;
        }
    }
    
    // If we get here, something went wrong
    throw new Exception('Invalid action or request');
    
} catch (Exception $e) {
    // Log the error
    logError("User management API error: " . $e->getMessage());
    
    // Store error message in session for redirect
    $_SESSION['error'] = $e->getMessage();
    
    // If it's an AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
    
    // Otherwise redirect back to users page
    header('Location: users.php');
    exit;
}

// Redirect back to users page (fallback)
header('Location: users.php');
exit;