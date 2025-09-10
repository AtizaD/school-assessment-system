<?php
/**
 * Subject Alternatives API Endpoint
 * Handles CRUD operations for subject alternative groups
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require admin role
try {
    requireRole('admin');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Validate CSRF token
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$action = sanitizeInput($_POST['action'] ?? '');

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    switch ($action) {
        case 'create':
            $class_id = sanitizeInput($_POST['class_id'] ?? '');
            $group_name = sanitizeInput($_POST['group_name'] ?? '');
            $subject_ids = $_POST['subject_ids'] ?? [];
            
            // Validate required fields
            if (empty($class_id) || empty($group_name) || empty($subject_ids)) {
                echo json_encode(['success' => false, 'message' => 'Class, group name, and subjects are required']);
                exit;
            }
            
            // Validate subject_ids is array and has at least 2 items
            if (!is_array($subject_ids) || count($subject_ids) < 2) {
                echo json_encode(['success' => false, 'message' => 'At least 2 subjects must be selected for an alternative group']);
                exit;
            }
            
            // Validate class exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            if ($stmt->fetchColumn() == 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid class selected']);
                exit;
            }
            
            // Validate all subjects exist
            $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
            $stmt = $db->prepare("SELECT COUNT(*) FROM subjects WHERE subject_id IN ($subject_placeholders)");
            $stmt->execute($subject_ids);
            if ($stmt->fetchColumn() != count($subject_ids)) {
                echo json_encode(['success' => false, 'message' => 'One or more invalid subjects selected']);
                exit;
            }
            
            // Check if group name already exists for this class
            $stmt = $db->prepare("SELECT COUNT(*) FROM subject_alternatives WHERE class_id = ? AND group_name = ?");
            $stmt->execute([$class_id, $group_name]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'A group with this name already exists for this class']);
                exit;
            }
            
            // Check if any subject is already in another alternative group for this class
            $stmt = $db->prepare("
                SELECT sa.group_name, s.subject_name
                FROM subject_alternatives sa
                JOIN subjects s ON JSON_CONTAINS(sa.subject_ids, CAST(s.subject_id AS JSON))
                WHERE sa.class_id = ? AND s.subject_id IN ($subject_placeholders)
            ");
            $stmt->execute(array_merge([$class_id], $subject_ids));
            $conflicts = $stmt->fetchAll();
            
            if (!empty($conflicts)) {
                $conflict_msg = "These subjects are already in other groups: ";
                foreach ($conflicts as $conflict) {
                    $conflict_msg .= $conflict['subject_name'] . " (in " . $conflict['group_name'] . "), ";
                }
                echo json_encode(['success' => false, 'message' => rtrim($conflict_msg, ', ')]);
                exit;
            }
            
            // Insert new alternative group
            $stmt = $db->prepare("
                INSERT INTO subject_alternatives (class_id, group_name, subject_ids) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$class_id, $group_name, json_encode(array_map('intval', $subject_ids))]);
            
            // Get class and subject names for logging
            $stmt = $db->prepare("SELECT class_name FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $class_info = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id IN ($subject_placeholders)");
            $stmt->execute($subject_ids);
            $subject_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            logSystemActivity(
                'SubjectAlternatives',
                "Created alternative group '{$group_name}' for {$class_info['class_name']} with subjects: " . implode(', ', $subject_names),
                'INFO',
                $_SESSION['user_id']
            );
            
            echo json_encode([
                'success' => true, 
                'message' => "Alternative group '{$group_name}' created successfully"
            ]);
            break;
            
        case 'update':
            $alt_id = sanitizeInput($_POST['alt_id'] ?? '');
            $class_id = sanitizeInput($_POST['class_id'] ?? '');
            $group_name = sanitizeInput($_POST['group_name'] ?? '');
            $subject_ids = $_POST['subject_ids'] ?? [];
            
            // Validate required fields
            if (empty($alt_id) || empty($class_id) || empty($group_name) || empty($subject_ids)) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
                exit;
            }
            
            // Validate subject_ids is array and has at least 2 items
            if (!is_array($subject_ids) || count($subject_ids) < 2) {
                echo json_encode(['success' => false, 'message' => 'At least 2 subjects must be selected for an alternative group']);
                exit;
            }
            
            // Check if alternative group exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM subject_alternatives WHERE alt_id = ?");
            $stmt->execute([$alt_id]);
            if ($stmt->fetchColumn() == 0) {
                echo json_encode(['success' => false, 'message' => 'Alternative group not found']);
                exit;
            }
            
            // Check for group name conflicts (excluding current record)
            $stmt = $db->prepare("SELECT COUNT(*) FROM subject_alternatives WHERE class_id = ? AND group_name = ? AND alt_id != ?");
            $stmt->execute([$class_id, $group_name, $alt_id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'A group with this name already exists for this class']);
                exit;
            }
            
            // Check if any subject is already in another alternative group for this class (excluding current group)
            $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
            $stmt = $db->prepare("
                SELECT sa.group_name, s.subject_name
                FROM subject_alternatives sa
                JOIN subjects s ON JSON_CONTAINS(sa.subject_ids, CAST(s.subject_id AS JSON))
                WHERE sa.class_id = ? AND sa.alt_id != ? AND s.subject_id IN ($subject_placeholders)
            ");
            $stmt->execute(array_merge([$class_id, $alt_id], $subject_ids));
            $conflicts = $stmt->fetchAll();
            
            if (!empty($conflicts)) {
                $conflict_msg = "These subjects are already in other groups: ";
                foreach ($conflicts as $conflict) {
                    $conflict_msg .= $conflict['subject_name'] . " (in " . $conflict['group_name'] . "), ";
                }
                echo json_encode(['success' => false, 'message' => rtrim($conflict_msg, ', ')]);
                exit;
            }
            
            // Update alternative group
            $stmt = $db->prepare("
                UPDATE subject_alternatives 
                SET class_id = ?, group_name = ?, subject_ids = ?
                WHERE alt_id = ?
            ");
            $stmt->execute([$class_id, $group_name, json_encode(array_map('intval', $subject_ids)), $alt_id]);
            
            // Get class name for logging
            $stmt = $db->prepare("SELECT class_name FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $class_info = $stmt->fetch();
            
            logSystemActivity(
                'SubjectAlternatives',
                "Updated alternative group '{$group_name}' for {$class_info['class_name']}",
                'INFO',
                $_SESSION['user_id']
            );
            
            echo json_encode([
                'success' => true, 
                'message' => "Alternative group '{$group_name}' updated successfully"
            ]);
            break;
            
        case 'delete':
            $alt_id = sanitizeInput($_POST['alt_id'] ?? '');
            
            if (empty($alt_id)) {
                echo json_encode(['success' => false, 'message' => 'Alternative group ID is required']);
                exit;
            }
            
            // Get alternative group info for logging before deletion
            $stmt = $db->prepare("
                SELECT sa.group_name, c.class_name
                FROM subject_alternatives sa
                JOIN classes c ON sa.class_id = c.class_id
                WHERE sa.alt_id = ?
            ");
            $stmt->execute([$alt_id]);
            $info = $stmt->fetch();
            
            if (!$info) {
                echo json_encode(['success' => false, 'message' => 'Alternative group not found']);
                exit;
            }
            
            // Start transaction to delete alternative group and related special enrollments
            $db->beginTransaction();
            
            try {
                // First, get the subjects in this alternative group to clean up special_class enrollments
                $stmt = $db->prepare("SELECT class_id, subject_ids FROM subject_alternatives WHERE alt_id = ?");
                $stmt->execute([$alt_id]);
                $group_data = $stmt->fetch();
                
                if ($group_data) {
                    $class_id = $group_data['class_id'];
                    $subject_ids = json_decode($group_data['subject_ids'], true);
                    
                    if (!empty($subject_ids)) {
                        // Delete related special class enrollments
                        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
                        $stmt = $db->prepare("
                            DELETE FROM special_class 
                            WHERE class_id = ? AND subject_id IN ($subject_placeholders)
                        ");
                        $stmt->execute(array_merge([$class_id], $subject_ids));
                    }
                }
                
                // Delete alternative group
                $stmt = $db->prepare("DELETE FROM subject_alternatives WHERE alt_id = ?");
                $stmt->execute([$alt_id]);
                
                if ($stmt->rowCount() == 0) {
                    throw new Exception('Alternative group not found');
                }
                
                // Commit transaction
                $db->commit();
                
                logSystemActivity(
                    'SubjectAlternatives',
                    "Deleted alternative group '{$info['group_name']}' from {$info['class_name']}",
                    'INFO',
                    $_SESSION['user_id']
                );
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Alternative group '{$info['group_name']}' deleted successfully"
                ]);
                
            } catch (Exception $e) {
                // Rollback on error
                $db->rollBack();
                throw $e;
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (PDOException $e) {
    logError("Subject Alternatives API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred. Please try again.'
    ]);
} catch (Exception $e) {
    logError("Subject Alternatives API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred. Please try again.'
    ]);
}
?>