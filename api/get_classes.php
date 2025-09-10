<?php
// api/get_classes.php
define('BASEPATH', dirname(dirname(__FILE__)));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';

header('Content-Type: application/json');

try {
    // Get database connection
    $db = DatabaseConfig::getInstance()->getConnection();

    // Check if we're filtering by subject (for teacher assignments)
    $subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
    $teacherId = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
    
    if ($subjectId) {
        // Base query to get classes linked to the specified subject
        $baseQuery = "
            SELECT c.class_id, c.class_name, c.level, p.program_name
            FROM classes c
            JOIN programs p ON c.program_id = p.program_id
            JOIN classsubjects cs ON c.class_id = cs.class_id
            WHERE cs.subject_id = ?
        ";
        
        // If teacher_id is provided, also get assignment information
        if ($teacherId) {
            $query = "
                SELECT c.class_id, c.class_name, c.level, p.program_name,
                       tca.assignment_id,
                       tca.is_primary_instructor,
                       CASE WHEN tca.assignment_id IS NOT NULL THEN 1 ELSE 0 END as is_assigned
                FROM classes c
                JOIN programs p ON c.program_id = p.program_id
                JOIN classsubjects cs ON c.class_id = cs.class_id
                LEFT JOIN TeacherClassAssignments tca ON (
                    c.class_id = tca.class_id 
                    AND tca.subject_id = ? 
                    AND tca.teacher_id = ?
                )
                WHERE cs.subject_id = ?
                ORDER BY p.program_name, c.level, c.class_name
            ";
            $stmt = $db->prepare($query);
            $stmt->execute([$subjectId, $teacherId, $subjectId]);
        } else {
            $stmt = $db->prepare($baseQuery . " ORDER BY p.program_name, c.level, c.class_name");
            $stmt->execute([$subjectId]);
        }
        
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'classes' => $classes
        ]);
        exit;
    }

    // Original functionality - filter by program and level (POST method)
    // Validate program_id
    if (!isset($_POST['program_id'])) {
        throw new Exception('Program ID is required');
    }

    $programId = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);
    if (!$programId) {
        throw new Exception('Invalid program ID');
    }

    // Validate level
    if (!isset($_POST['level'])) {
        throw new Exception('Level is required');
    }
    
    $level = trim(sanitizeInput($_POST['level']));
    if (empty($level)) {
        throw new Exception('Invalid level');
    }
    
    // Modified query to use lowercase table name and standard MySQL functions
    // instead of REGEXP_SUBSTR which might not be available in all MySQL versions
    $stmt = $db->prepare(
        "SELECT class_id, class_name 
         FROM classes 
         WHERE program_id = ? 
         AND level = ? 
         ORDER BY 
            CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(class_name, ' ', -1), ' ', 1) AS UNSIGNED), 
            class_name"
    );
    $stmt->execute([$programId, $level]);
    
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true, 
        'classes' => $classes
    ]);

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}