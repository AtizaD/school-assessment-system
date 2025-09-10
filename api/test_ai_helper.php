<?php
// Test AI Helper API
if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}

require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('teacher');

header('Content-Type: application/json');

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM Teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherData = $stmt->fetch();
    
    if (!$teacherData) {
        throw new Exception('Teacher record not found');
    }
    
    $teacherId = $teacherData['teacher_id'];
    
    // Test assessment query
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name,
                COUNT(q.question_id) as question_count
         FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Classes c ON ac.class_id = c.class_id
         JOIN Subjects s ON ac.subject_id = s.subject_id
         JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         LEFT JOIN Questions q ON a.assessment_id = q.assessment_id
         WHERE tca.teacher_id = ?
         GROUP BY a.assessment_id
         ORDER BY a.created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$teacherId]);
    $assessments = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'teacher_id' => $teacherId,
        'assessment_count' => count($assessments),
        'assessments' => $assessments
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>