<?php
// api/upload_question_image.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('teacher');

// Define allowed image types and max file size
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

function sendResponse($status, $message = '', $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    // Check if file is uploaded
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['image'];
    
    // Validate file type
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed types: JPG, PNG, GIF');
    }
    
    // Validate file size
    if ($file['size'] > $maxFileSize) {
        throw new Exception('File too large. Maximum size: 5MB');
    }

    // Get teacher ID
    $db = DatabaseConfig::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherInfo = $stmt->fetch();
    
    if (!$teacherInfo) {
        throw new Exception('Teacher record not found');
    }

    // Generate unique filename
    $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFilename = uniqid('question_') . '.' . $fileExt;
    
    // Create upload directory if it doesn't exist
    $uploadDir = BASEPATH . '/assets/assessment_images/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $uploadPath = $uploadDir . $newFilename;
    
    // Move the uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    // Save file info to database
    $db->beginTransaction();
    
    $stmt = $db->prepare(
        "INSERT INTO assessment_images (
            filename, 
            original_filename, 
            file_type, 
            file_size, 
            uploaded_by
        ) VALUES (?, ?, ?, ?, ?)"
    );
    
    $stmt->execute([
        $newFilename,
        $file['name'],
        $file['type'],
        $file['size'],
        $_SESSION['user_id']
    ]);
    
    $imageId = $db->lastInsertId();
    $db->commit();
    
    // Return success with image info
    sendResponse('success', 'Image uploaded successfully', [
        'image_id' => $imageId,
        'filename' => $newFilename,
        'url' => BASE_URL . '/assets/assessment_images/' . $newFilename
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    logError("Image upload error: " . $e->getMessage());
    sendResponse('error', $e->getMessage());
}
?>