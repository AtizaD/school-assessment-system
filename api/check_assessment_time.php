<?php
// api/check_assessment_time.php
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

requireRole('student');

// Send JSON response
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
    
    // Get JSON data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!validateCSRFToken($data['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }
    
    $assessmentId = filter_var($data['assessment_id'] ?? 0, FILTER_VALIDATE_INT);
    $attemptId = filter_var($data['attempt_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$assessmentId || !$attemptId) {
        throw new Exception('Invalid assessment or attempt ID');
    }
    
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student info
    $stmt = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $studentInfo = $stmt->fetch();
    
    if (!$studentInfo) {
        throw new Exception('Student record not found');
    }
    
    // Get assessment and attempt info
    $stmt = $db->prepare(
        "SELECT a.duration, aa.start_time, aa.status, aa.option_orders, ar.reset_type,
         (SELECT COUNT(*) FROM studentanswers 
          WHERE assessment_id = a.assessment_id AND student_id = ?) as answers_count
         FROM assessments a
         JOIN assessmentattempts aa ON a.assessment_id = aa.assessment_id
         LEFT JOIN (
             SELECT assessment_id, student_id, reset_type 
             FROM assessmentresets 
             WHERE assessment_id = ? AND student_id = ? 
             ORDER BY reset_time DESC LIMIT 1
         ) ar ON ar.assessment_id = a.assessment_id AND ar.student_id = aa.student_id
         WHERE a.assessment_id = ? 
         AND aa.attempt_id = ?
         AND aa.student_id = ?"
    );
    $stmt->execute([
        $studentInfo['student_id'],
        $assessmentId, 
        $studentInfo['student_id'], 
        $assessmentId, 
        $attemptId, 
        $studentInfo['student_id']
    ]);
    $assessmentData = $stmt->fetch();
    
    if (!$assessmentData) {
        throw new Exception('Assessment or attempt not found');
    }
    
    if ($assessmentData['status'] === 'completed') {
        sendResponse('success', 'Assessment already completed', ['timeLeft' => 0]);
        exit;
    }
    
    // Calculate time left
    $timeLeft = 0;
    if ($assessmentData['duration']) {
        $startTime = strtotime($assessmentData['start_time']);
        
        // Determine duration based on reset type
        $duration = $assessmentData['duration'];
        if ($assessmentData['reset_type'] === 'partial') {
            $duration = 5; // 5-minute timer for partial resets
        }
        
        $endTime = $startTime + ($duration * 60);
        $timeLeft = max(0, $endTime - time());
        
        // If time is up but not submitted
        if ($timeLeft === 0 && $assessmentData['status'] === 'in_progress') {
            // Auto-submit the assessment when time is up
            try {
                // Begin transaction
                $db->beginTransaction();
                
                // 1. Mark attempt as completed
                $stmt = $db->prepare(
                    "UPDATE assessmentattempts 
                     SET status = 'completed', end_time = CURRENT_TIMESTAMP 
                     WHERE attempt_id = ?"
                );
                $stmt->execute([$attemptId]);
                
                // 2. Grade the assessment
                require_once BASEPATH . '/api/grade_assessment.php';
                $score = gradeAssessment($assessmentId, $studentInfo['student_id']);
                
                // 3. Create result record
                $stmt = $db->prepare(
                    "INSERT INTO results 
                        (assessment_id, student_id, score, status) 
                     VALUES (?, ?, ?, 'completed')
                     ON DUPLICATE KEY UPDATE score = VALUES(score), status = VALUES(status)"
                );
                $stmt->execute([$assessmentId, $studentInfo['student_id'], $score]);
                
                // Commit transaction
                $db->commit();
                
                // Log the auto-submission
                log('INFO', 'Assessment auto-submitted due to time expiration', [
                    'assessment_id' => $assessmentId,
                    'student_id' => $studentInfo['student_id'],
                    'attempt_id' => $attemptId,
                    'score' => $score
                ]);
                
                // Return a flag to the client
                sendResponse('success', 'Assessment auto-submitted due to time expiration', [
                    'timeLeft' => 0,
                    'status' => 'completed',
                    'autoSubmitted' => true,
                    'redirect' => BASE_URL . '/student/view_result.php?id=' . $assessmentId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Roll back transaction on error
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                
                logError("Auto-submission error: " . $e->getMessage());
                
                // Still return 0 time to client to trigger client-side submission as fallback
                sendResponse('error', 'Auto-submission failed', [
                    'timeLeft' => 0,
                    'status' => $assessmentData['status'],
                    'errorMessage' => 'Server auto-submission failed. Please submit manually or refresh the page.'
                ]);
                exit;
            }
        }
    }
    
    // Return data to client
    sendResponse('success', '', [
        'timeLeft' => $timeLeft,
        'status' => $assessmentData['status'],
        'answersCount' => $assessmentData['answers_count']
    ]);
    
} catch (Exception $e) {
    logError("Assessment time check error: " . $e->getMessage());
    sendResponse('error', $e->getMessage());
}
?>