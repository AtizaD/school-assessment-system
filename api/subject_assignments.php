<?php
/**
 * Subject Assignments API Endpoint
 * Handles student assignments to alternative subjects
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
        case 'load_assignments':
            $class_id = sanitizeInput($_POST['class_id'] ?? '');
            
            if (empty($class_id)) {
                echo json_encode(['success' => false, 'message' => 'Class ID is required']);
                exit;
            }
            
            // Get alternative groups for this class
            $stmt = $db->prepare("
                SELECT alt_id, group_name, subject_ids, max_students_per_subject
                FROM subject_alternatives 
                WHERE class_id = ?
                ORDER BY group_name
            ");
            $stmt->execute([$class_id]);
            $groups = $stmt->fetchAll();
            
            // Get all students in this class
            $stmt = $db->prepare("
                SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name
                FROM students 
                WHERE class_id = ?
                ORDER BY first_name, last_name
            ");
            $stmt->execute([$class_id]);
            $students = $stmt->fetchAll();
            
            // Get current assignments
            $assignments = [];
            $assigned_student_ids = [];
            
            foreach ($groups as $group) {
                $subject_ids = json_decode($group['subject_ids'], true);
                $group_assignments = [];
                
                foreach ($subject_ids as $subject_id) {
                    $stmt = $db->prepare("
                        SELECT s.student_id, CONCAT(s.first_name, ' ', s.last_name) as student_name
                        FROM special_class sc
                        JOIN students s ON sc.student_id = s.student_id
                        WHERE sc.class_id = ? AND sc.subject_id = ? AND sc.status = 'active'
                        ORDER BY s.first_name, s.last_name
                    ");
                    $stmt->execute([$class_id, $subject_id]);
                    $subject_students = $stmt->fetchAll();
                    
                    $group_assignments[$subject_id] = $subject_students;
                    
                    // Track assigned students
                    foreach ($subject_students as $student) {
                        $assigned_student_ids[] = $student['student_id'];
                    }
                }
                
                $assignments[$group['alt_id']] = $group_assignments;
            }
            
            // Get unassigned students
            $unassigned = array_filter($students, function($student) use ($assigned_student_ids) {
                return !in_array($student['student_id'], $assigned_student_ids);
            });
            
            echo json_encode([
                'success' => true,
                'groups' => $groups,
                'students' => $students,
                'assignments' => $assignments,
                'unassigned' => array_values($unassigned)
            ]);
            break;
            
        case 'assign_students':
            $student_ids_json = $_POST['student_ids'] ?? '[]';
            $subject_id = sanitizeInput($_POST['subject_id'] ?? '');
            $group_id = sanitizeInput($_POST['group_id'] ?? '');
            $class_id = sanitizeInput($_POST['class_id'] ?? '');
            
            // Parse student IDs
            $student_ids = json_decode($student_ids_json, true);
            
            if (empty($student_ids) || empty($subject_id) || empty($group_id) || empty($class_id)) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            // Validate that the subject belongs to the specified group
            $stmt = $db->prepare("SELECT subject_ids FROM subject_alternatives WHERE alt_id = ?");
            $stmt->execute([$group_id]);
            $group_data = $stmt->fetch();
            
            if (!$group_data) {
                echo json_encode(['success' => false, 'message' => 'Alternative group not found']);
                exit;
            }
            
            $group_subject_ids = json_decode($group_data['subject_ids'], true);
            if (!in_array($subject_id, $group_subject_ids)) {
                echo json_encode(['success' => false, 'message' => 'Subject does not belong to the specified alternative group']);
                exit;
            }
            
            // Capacity constraints removed - no longer checking max_students_per_subject
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                $assigned_count = 0;
                $errors = [];
                
                foreach ($student_ids as $student_id) {
                    $student_id = sanitizeInput($student_id);
                    
                    // Validate student exists and belongs to this class
                    $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE student_id = ? AND class_id = ?");
                    $stmt->execute([$student_id, $class_id]);
                    if ($stmt->fetchColumn() == 0) {
                        $errors[] = "Invalid student ID: $student_id";
                        continue;
                    }
                    
                    // Remove student from other subjects in this alternative group (mutual exclusion)
                    $stmt = $db->prepare("
                        DELETE FROM special_class 
                        WHERE student_id = ? AND class_id = ? AND subject_id IN (" . 
                        str_repeat('?,', count($group_subject_ids) - 1) . '?' . ")
                    ");
                    $params = array_merge([$student_id, $class_id], $group_subject_ids);
                    $stmt->execute($params);
                    
                    // Add student to new subject
                    $stmt = $db->prepare("
                        INSERT INTO special_class (class_id, subject_id, student_id, status) 
                        VALUES (?, ?, ?, 'active')
                        ON DUPLICATE KEY UPDATE status = 'active'
                    ");
                    $stmt->execute([$class_id, $subject_id, $student_id]);
                    $assigned_count++;
                }
                
                // Commit transaction
                $db->commit();
                
                // Log the assignment
                $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE subject_id = ?");
                $stmt->execute([$subject_id]);
                $subject_info = $stmt->fetch();
                
                $stmt = $db->prepare("SELECT class_name FROM classes WHERE class_id = ?");
                $stmt->execute([$class_id]);
                $class_info = $stmt->fetch();
                
                logSystemActivity(
                    'SubjectAssignments',
                    "Assigned $assigned_count students to {$subject_info['subject_name']} in {$class_info['class_name']}",
                    'INFO',
                    $_SESSION['user_id']
                );
                
                $message = "Successfully assigned $assigned_count students";
                if (!empty($errors)) {
                    $message .= ". Errors: " . implode(', ', $errors);
                }
                
                echo json_encode(['success' => true, 'message' => $message]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'auto_balance_group':
            $group_id = sanitizeInput($_POST['group_id'] ?? '');
            
            if (empty($group_id)) {
                echo json_encode(['success' => false, 'message' => 'Group ID is required']);
                exit;
            }
            
            // Get group information
            $stmt = $db->prepare("
                SELECT sa.class_id, sa.group_name, sa.subject_ids, c.class_name
                FROM subject_alternatives sa
                JOIN classes c ON sa.class_id = c.class_id
                WHERE sa.alt_id = ?
            ");
            $stmt->execute([$group_id]);
            $group = $stmt->fetch();
            
            if (!$group) {
                echo json_encode(['success' => false, 'message' => 'Alternative group not found']);
                exit;
            }
            
            $subject_ids = json_decode($group['subject_ids'], true);
            $class_id = $group['class_id'];
            
            // Get all students in the class
            $stmt = $db->prepare("SELECT student_id FROM students WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $all_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get currently assigned students for this group
            $stmt = $db->prepare("
                SELECT DISTINCT student_id FROM special_class 
                WHERE class_id = ? AND subject_id IN (" . str_repeat('?,', count($subject_ids) - 1) . '?' . ")
            ");
            $stmt->execute(array_merge([$class_id], $subject_ids));
            $assigned_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get unassigned students
            $unassigned_students = array_diff($all_students, $assigned_students);
            
            if (empty($unassigned_students)) {
                echo json_encode(['success' => false, 'message' => 'No unassigned students found for this group']);
                exit;
            }
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Distribute students evenly
                $students_per_subject = floor(count($unassigned_students) / count($subject_ids));
                $remainder = count($unassigned_students) % count($subject_ids);
                
                $student_index = 0;
                $assigned_count = 0;
                
                foreach ($subject_ids as $i => $subject_id) {
                    $count_for_this_subject = $students_per_subject + ($i < $remainder ? 1 : 0);
                    
                    for ($j = 0; $j < $count_for_this_subject && $student_index < count($unassigned_students); $j++) {
                        $student_id = $unassigned_students[$student_index];
                        
                        $stmt = $db->prepare("
                            INSERT INTO special_class (class_id, subject_id, student_id, status) 
                            VALUES (?, ?, ?, 'active')
                        ");
                        $stmt->execute([$class_id, $subject_id, $student_id]);
                        
                        $assigned_count++;
                        $student_index++;
                    }
                }
                
                // Commit transaction
                $db->commit();
                
                logSystemActivity(
                    'SubjectAssignments',
                    "Auto-balanced $assigned_count students in group '{$group['group_name']}' for {$group['class_name']}",
                    'INFO',
                    $_SESSION['user_id']
                );
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Successfully auto-balanced $assigned_count students across " . count($subject_ids) . " subjects"
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'auto_balance_all':
            $class_id = sanitizeInput($_POST['class_id'] ?? '');
            
            if (empty($class_id)) {
                echo json_encode(['success' => false, 'message' => 'Class ID is required']);
                exit;
            }
            
            // Get all alternative groups for this class
            $stmt = $db->prepare("SELECT alt_id FROM subject_alternatives WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $group_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($group_ids)) {
                echo json_encode(['success' => false, 'message' => 'No alternative groups found for this class']);
                exit;
            }
            
            $total_assigned = 0;
            
            foreach ($group_ids as $group_id) {
                // Simulate the auto_balance_group action for each group
                $_POST['group_id'] = $group_id;
                ob_start();
                
                // Get group information
                $stmt = $db->prepare("
                    SELECT class_id, subject_ids FROM subject_alternatives WHERE alt_id = ?
                ");
                $stmt->execute([$group_id]);
                $group = $stmt->fetch();
                
                if ($group) {
                    $subject_ids = json_decode($group['subject_ids'], true);
                    
                    // Get unassigned students for this group
                    $stmt = $db->prepare("SELECT student_id FROM students WHERE class_id = ?");
                    $stmt->execute([$class_id]);
                    $all_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $stmt = $db->prepare("
                        SELECT DISTINCT student_id FROM special_class 
                        WHERE class_id = ? AND subject_id IN (" . str_repeat('?,', count($subject_ids) - 1) . '?' . ")
                    ");
                    $stmt->execute(array_merge([$class_id], $subject_ids));
                    $assigned_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $unassigned_students = array_diff($all_students, $assigned_students);
                    
                    if (!empty($unassigned_students)) {
                        // Auto-balance this group
                        $students_per_subject = floor(count($unassigned_students) / count($subject_ids));
                        $remainder = count($unassigned_students) % count($subject_ids);
                        
                        $student_index = 0;
                        
                        foreach ($subject_ids as $i => $subject_id) {
                            $count_for_this_subject = $students_per_subject + ($i < $remainder ? 1 : 0);
                            
                            for ($j = 0; $j < $count_for_this_subject && $student_index < count($unassigned_students); $j++) {
                                $student_id = $unassigned_students[$student_index];
                                
                                $stmt = $db->prepare("
                                    INSERT INTO special_class (class_id, subject_id, student_id, status) 
                                    VALUES (?, ?, ?, 'active')
                                ");
                                $stmt->execute([$class_id, $subject_id, $student_id]);
                                
                                $total_assigned++;
                                $student_index++;
                            }
                        }
                    }
                }
                
                ob_end_clean();
            }
            
            // Get class name for logging
            $stmt = $db->prepare("SELECT class_name FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $class_info = $stmt->fetch();
            
            logSystemActivity(
                'SubjectAssignments',
                "Auto-balanced all alternative groups for {$class_info['class_name']}: $total_assigned students assigned",
                'INFO',
                $_SESSION['user_id']
            );
            
            echo json_encode([
                'success' => true, 
                'message' => "Successfully auto-balanced all groups: $total_assigned students assigned across " . count($group_ids) . " alternative groups"
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (PDOException $e) {
    logError("Subject Assignments API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred. Please try again.'
    ]);
} catch (Exception $e) {
    logError("Subject Assignments API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred. Please try again.'
    ]);
}
?>