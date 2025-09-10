<?php
// api/index.php

if (!defined('BASEPATH')) {
    require_once dirname(__DIR__) . '/config/config.php';

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once MODELS_PATH . '/User.php';
require_once MODELS_PATH . '/Student.php';
require_once MODELS_PATH . '/Teacher.php';
require_once MODELS_PATH . '/Assessment.php';
}
// Set headers for JSON API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class API {
    private $db;
    private $method;
    private $endpoint;
    private $params;
    private $requestBody;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->parseRequest();
        $this->requestBody = json_decode(file_get_contents('php://input'), true);
    }

    private function parseRequest() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = str_replace('/api/', '', $path);
        $pathParts = explode('/', $path);
        
        $this->endpoint = $pathParts[0] ?? '';
        $this->params = array_slice($pathParts, 1);
    }

    private function authenticate() {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$token) {
            throw new Exception('No authentication token provided', 401);
        }

        // Verify JWT token or session
        if (!isLoggedIn()) {
            throw new Exception('Invalid or expired token', 401);
        }
    }

    private function requireRole($roles) {
        $userRole = $_SESSION['user_role'] ?? null;
        if (!in_array($userRole, (array)$roles)) {
            throw new Exception('Unauthorized access', 403);
        }
    }

    public function handleRequest() {
        try {
            // Authenticate all API requests except login
            if ($this->endpoint !== 'login') {
                $this->authenticate();
            }

            switch ($this->endpoint) {
                case 'login':
                    return $this->handleLogin();
                case 'users':
                    return $this->handleUsers();
                case 'students':
                    return $this->handleStudents();
                case 'teachers':
                    return $this->handleTeachers();
                case 'assessments':
                    return $this->handleAssessments();
                case 'results':
                    return $this->handleResults();
                default:
                    throw new Exception('Endpoint not found', 404);
            }
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    private function handleLogin() {
        if ($this->method !== 'POST') {
            throw new Exception('Method not allowed', 405);
        }

        $username = $this->requestBody['username'] ?? '';
        $password = $this->requestBody['password'] ?? '';

        if (authenticateUser($username, $password)) {
            return $this->sendResponse([
                'userId' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role' => $_SESSION['user_role']
            ]);
        }

        throw new Exception('Invalid credentials', 401);
    }

    private function handleUsers() {
        $this->requireRole('admin');

        switch ($this->method) {
            case 'GET':
                if (!empty($this->params[0])) {
                    // Get specific user
                    $user = new User();
                    $userData = $user->findById($this->params[0]);
                    if (!$userData) {
                        throw new Exception('User not found', 404);
                    }
                    return $this->sendResponse($userData);
                }
                // List users
                $stmt = $this->db->query("SELECT user_id, username, email, role FROM Users");
                return $this->sendResponse($stmt->fetchAll());

            case 'POST':
                $user = new User();
                if ($user->create(
                    $this->requestBody['username'],
                    $this->requestBody['email'],
                    $this->requestBody['password'],
                    $this->requestBody['role']
                )) {
                    return $this->sendResponse(['message' => 'User created successfully'], 201);
                }
                throw new Exception('Failed to create user', 500);

            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleAssessments() {
        switch ($this->method) {
            case 'GET':
                if (!empty($this->params[0])) {
                    // Get specific assessment
                    $stmt = $this->db->prepare(
                        "SELECT a.*, c.class_name 
                         FROM Assessments a 
                         JOIN Classes c ON a.class_id = c.class_id 
                         WHERE a.assessment_id = ?"
                    );
                    $stmt->execute([$this->params[0]]);
                    $assessment = $stmt->fetch();
                    if (!$assessment) {
                        throw new Exception('Assessment not found', 404);
                    }
                    return $this->sendResponse($assessment);
                }
                // List assessments based on user role
                $role = $_SESSION['user_role'];
                if ($role === 'teacher') {
                    $teacherId = $this->getTeacherId();
                    $stmt = $this->db->prepare(
                        "SELECT a.* FROM Assessments a 
                         JOIN Classes c ON a.class_id = c.class_id 
                         WHERE c.teacher_id = ?"
                    );
                    $stmt->execute([$teacherId]);
                } elseif ($role === 'student') {
                    $studentId = $this->getStudentId();
                    $stmt = $this->db->prepare(
                        "SELECT a.* FROM Assessments a 
                         JOIN Classes c ON a.class_id = c.class_id 
                         JOIN Students s ON c.class_id = s.class_id 
                         WHERE s.student_id = ?"
                    );
                    $stmt->execute([$studentId]);
                } else {
                    throw new Exception('Unauthorized access', 403);
                }
                return $this->sendResponse($stmt->fetchAll());

            case 'POST':
                $this->requireRole('teacher');
                $assessment = new Assessment();
                if ($assessment->create(
                    $this->requestBody['class_id'],
                    $this->requestBody['semester_id'],
                    $this->requestBody['title'],
                    $this->requestBody['description'],
                    $this->requestBody['date']
                )) {
                    return $this->sendResponse(['message' => 'Assessment created successfully'], 201);
                }
                throw new Exception('Failed to create assessment', 500);

            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleResults() {
        switch ($this->method) {
            case 'GET':
                if (empty($this->params[0])) {
                    throw new Exception('Assessment ID required', 400);
                }
                
                $role = $_SESSION['user_role'];
                if ($role === 'teacher') {
                    $stmt = $this->db->prepare(
                        "SELECT r.*, s.first_name, s.last_name 
                         FROM Results r 
                         JOIN Students s ON r.student_id = s.student_id 
                         WHERE r.assessment_id = ?"
                    );
                } elseif ($role === 'student') {
                    $studentId = $this->getStudentId();
                    $stmt = $this->db->prepare(
                        "SELECT r.* FROM Results r 
                         WHERE r.assessment_id = ? AND r.student_id = ?"
                    );
                    $stmt->execute([$this->params[0], $studentId]);
                } else {
                    throw new Exception('Unauthorized access', 403);
                }
                return $this->sendResponse($stmt->fetchAll());

            case 'POST':
                $this->requireRole('teacher');
                $assessment = new Assessment();
                if ($assessment->submitResult(
                    $this->requestBody['assessment_id'],
                    $this->requestBody['student_id'],
                    $this->requestBody['score'],
                    $this->requestBody['feedback']
                )) {
                    return $this->sendResponse(['message' => 'Result submitted successfully'], 201);
                }
                throw new Exception('Failed to submit result', 500);

            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function getTeacherId() {
        $stmt = $this->db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        return $result['teacher_id'] ?? null;
    }

    private function getStudentId() {
        $stmt = $this->db->prepare("SELECT student_id FROM Students WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        return $result['student_id'] ?? null;
    }

    private function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        return [
            'status' => 'success',
            'data' => $data
        ];
    }

    private function sendError($message, $statusCode = 400) {
        http_response_code($statusCode);
        return [
            'status' => 'error',
            'message' => $message
        ];
    }
}

// Initialize and handle API request
$api = new API();
$response = $api->handleRequest();
echo json_encode($response);