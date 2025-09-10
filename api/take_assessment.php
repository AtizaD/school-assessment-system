<?php
/**
 * API Handler for Student Assessment Submissions
 * 
 * Handles saving student answers, automatic submission on timer expiry,
 * and final assessment submission with grading.
 */

// Define base path and include necessary files
define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';
require_once BASEPATH . '/api/grade_assessment.php'; // Include the existing grading API

// Ensure only students can access this API
requireRole('student');

// Set header to return JSON response
header('Content-Type: application/json');

// Initialize response array
$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'data' => null
];

try {
    // Get database connection
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get student ID from session
    $stmt = $db->prepare("SELECT student_id FROM Students WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch();
    
    if (!$student) {
        throw new Exception('Student record not found');
    }
    
    $studentId = $student['student_id'];
    
    // Process based on action type
    $action = $_POST['action'] ?? ($_GET['action'] ?? 'save');
    
    switch ($action) {
        case 'save':
            // Save a single answer (auto-save functionality)
            handleSaveAnswer($db, $studentId);
            break;
            
        case 'sync':
            // Sync client and server time
            handleTimeSync();
            break;
            
        case 'autosubmit':
            // Handle automatic submission on timer expiry
            handleAutoSubmit($db, $studentId);
            break;
            
        case 'submit':
            // Handle final assessment submission
            handleSubmitAssessment($db, $studentId);
            break;
            
        default:
            throw new Exception('Unknown action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    logError("Assessment API error: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
exit;

/**
 * Save a single answer (used for auto-save functionality)
 */
function handleSaveAnswer($db, $studentId) {
    global $response;
    
    // Verify CSRF token for security
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }
    
    // Get required parameters
    $assessmentId = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
    $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
    $answerText = $_POST['answer_text'] ?? '';
    
    if (!$assessmentId || !$questionId) {
        throw new Exception('Missing required parameters');
    }
    
    // Verify the assessment is available for this student
    verifyAssessmentAccess($db, $assessmentId, $studentId);
    
    // Check if answer already exists
    $stmt = $db->prepare(
        "SELECT answer_id FROM StudentAnswers 
         WHERE assessment_id = ? AND student_id = ? AND question_id = ?"
    );
    $stmt->execute([$assessmentId, $studentId, $questionId]);
    $existingAnswer = $stmt->fetch();
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        if ($existingAnswer) {
            // Update existing answer
            $stmt = $db->prepare(
                "UPDATE StudentAnswers 
                 SET answer_text = ?, updated_at = CURRENT_TIMESTAMP 
                 WHERE answer_id = ?"
            );
            $stmt->execute([$answerText, $existingAnswer['answer_id']]);
        } else {
            // Insert new answer
            $stmt = $db->prepare(
                "INSERT INTO StudentAnswers (
                    assessment_id, 
                    student_id, 
                    question_id, 
                    answer_text
                ) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$assessmentId, $studentId, $questionId, $answerText]);
        }
        
        // Commit transaction
        $db->commit();
        
        // Return success response
        $response['status'] = 'success';
        $response['message'] = 'Answer saved';
        $response['data'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'question_id' => $questionId
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        throw $e;
    }
}

/**
 * Sync client and server time
 */
function handleTimeSync() {
    global $response;
    
    // Get client timestamp
    $clientTimestamp = filter_input(INPUT_GET, 'client_time', FILTER_VALIDATE_INT);
    
    if (!$clientTimestamp) {
        throw new Exception('Invalid client timestamp');
    }
    
    // Get current server timestamp
    $serverTimestamp = time();
    
    // Calculate offset
    $offset = $serverTimestamp - $clientTimestamp;
    
    // Return synchronization data
    $response['status'] = 'success';
    $response['message'] = 'Time synchronized';
    $response['data'] = [
        'server_time' => $serverTimestamp,
        'client_time' => $clientTimestamp,
        'offset' => $offset,
        'server_datetime' => date('Y-m-d H:i:s', $serverTimestamp)
    ];
}

/**
 * Handle automatic submission on timer expiry
 */
function handleAutoSubmit($db, $studentId) {
    global $response;
    
    // Get assessment ID
    $assessmentId = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
    $attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
    
    if (!$assessmentId || !$attemptId) {
        throw new Exception('Missing required parameters');
    }
    
    // Verify CSRF token for security
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }
    
    // Verify assessment access
    verifyAssessmentAccess($db, $assessmentId, $studentId);
    
    // Verify the attempt belongs to this student
    $stmt = $db->prepare(
        "SELECT start_time, status FROM AssessmentAttempts 
         WHERE attempt_id = ? AND student_id = ? AND assessment_id = ?"
    );
    $stmt->execute([$attemptId, $studentId, $assessmentId]);
    $attempt = $stmt->fetch();
    
    if (!$attempt) {
        throw new Exception('Invalid attempt');
    }
    
    if ($attempt['status'] !== 'in_progress') {
        throw new Exception('Assessment already completed');
    }
    
    // Get assessment duration
    $stmt = $db->prepare(
        "SELECT duration FROM Assessments WHERE assessment_id = ?"
    );
    $stmt->execute([$assessmentId]);
    $assessment = $stmt->fetch();
    
    if (!$assessment || !$assessment['duration']) {
        throw new Exception('Invalid assessment or no time limit set');
    }
    
    // Check if time has actually expired
    $startTime = strtotime($attempt['start_time']);
    $endTime = $startTime + ($assessment['duration'] * 60);
    $currentTime = time();
    
    if ($currentTime < $endTime) {
        // Time hasn't expired yet - this could be a client-side timing issue
        // We'll log this but still allow the submission
        logSystemActivity(
            'Assessment',
            "Early auto-submit detected for assessment ID: $assessmentId, student ID: $studentId",
            'WARNING'
        );
    }
    
    // Mark attempt as completed with expired status
    $stmt = $db->prepare(
        "UPDATE AssessmentAttempts 
         SET status = 'expired', end_time = CURRENT_TIMESTAMP 
         WHERE attempt_id = ?"
    );
    $stmt->execute([$attemptId]);
    
    // Use the existing gradeAssessment function from the included file
    $score = gradeAssessment($assessmentId, $studentId);
    
    // Record the result
    saveAssessmentResult($db, $assessmentId, $studentId, $score);
    
    // Return success response
    $response['status'] = 'success';
    $response['message'] = 'Assessment auto-submitted due to time expiry';
    $response['data'] = [
        'score' => $score,
        'redirect_url' => BASE_URL . '/student/view_result.php?id=' . $assessmentId
    ];
}

/**
 * Handle final assessment submission
 */
function handleSubmitAssessment($db, $studentId) {
    global $response;
    
    // Verify CSRF token for security
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }
    
    // Get assessment ID and attempt ID
    $assessmentId = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
    $attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
    
    if (!$assessmentId) {
        throw new Exception('Missing assessment ID');
    }
    
    // Verify assessment access
    verifyAssessmentAccess($db, $assessmentId, $studentId);
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Verify and update attempt status
        $stmt = $db->prepare(
            "SELECT status FROM AssessmentAttempts 
             WHERE attempt_id = ? AND student_id = ? AND assessment_id = ?"
        );
        $stmt->execute([$attemptId, $studentId, $assessmentId]);
        $attempt = $stmt->fetch();
        
        if (!$attempt) {
            throw new Exception('Invalid attempt');
        }
        
        if ($attempt['status'] !== 'in_progress') {
            throw new Exception('Assessment already completed');
        }
        
        // Save any final answers that were submitted
        if (isset($_POST['answer']) && is_array($_POST['answer'])) {
            foreach ($_POST['answer'] as $questionId => $answerData) {
                $questionId = filter_var($questionId, FILTER_VALIDATE_INT);
                
                if (!$questionId) {
                    continue;
                }
                
                // Get question type
                $stmt = $db->prepare(
                    "SELECT question_type, answer_mode FROM Questions 
                     WHERE question_id = ? AND assessment_id = ?"
                );
                $stmt->execute([$questionId, $assessmentId]);
                $question = $stmt->fetch();
                
                if (!$question) {
                    continue;
                }
                
                // Process answer based on question type
                if ($question['question_type'] === 'MCQ') {
                    // For MCQ, we get the option ID
                    $answerText = filter_var($answerData, FILTER_VALIDATE_INT);
                } else if ($question['answer_mode'] === 'any_match' && is_array($answerData)) {
                    // For multi-answer questions, join answers with newline
                    $answerText = implode("\n", array_map('sanitizeInput', $answerData));
                } else {
                    // For text answers
                    $answerText = sanitizeInput($answerData);
                }
                
                // Check if answer already exists
                $stmt = $db->prepare(
                    "SELECT answer_id FROM StudentAnswers 
                     WHERE assessment_id = ? AND student_id = ? AND question_id = ?"
                );
                $stmt->execute([$assessmentId, $studentId, $questionId]);
                $existingAnswer = $stmt->fetch();
                
                if ($existingAnswer) {
                    // Update existing answer
                    $stmt = $db->prepare(
                        "UPDATE StudentAnswers 
                         SET answer_text = ?, updated_at = CURRENT_TIMESTAMP 
                         WHERE answer_id = ?"
                    );
                    $stmt->execute([$answerText, $existingAnswer['answer_id']]);
                } else {
                    // Insert new answer
                    $stmt = $db->prepare(
                        "INSERT INTO StudentAnswers (
                            assessment_id, 
                            student_id, 
                            question_id, 
                            answer_text
                        ) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$assessmentId, $studentId, $questionId, $answerText]);
                }
            }
        }
        
        // Mark attempt as completed
        $stmt = $db->prepare(
            "UPDATE AssessmentAttempts 
             SET status = 'completed', end_time = CURRENT_TIMESTAMP 
             WHERE attempt_id = ?"
        );
        $stmt->execute([$attemptId]);
        
        // Use the existing gradeAssessment function from the included file
        $score = gradeAssessment($assessmentId, $studentId);
        
        // Record the result
        saveAssessmentResult($db, $assessmentId, $studentId, $score);
        
        // Commit transaction
        $db->commit();
        
        // Log successful submission
        logSystemActivity(
            'Assessment',
            "Assessment ID: $assessmentId successfully submitted by student ID: $studentId",
            'INFO'
        );
        
        // Return success response
        $response['status'] = 'success';
        $response['message'] = 'Assessment submitted successfully';
        $response['data'] = [
            'score' => $score,
            'redirect_url' => BASE_URL . '/student/view_result.php?id=' . $assessmentId
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        throw $e;
    }
}

/**
 * Verify assessment access for the student
 */
function verifyAssessmentAccess($db, $assessmentId, $studentId) {
    // Check if assessment exists and is available
    $stmt = $db->prepare(
        "SELECT a.assessment_id FROM Assessments a
         JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
         JOIN Students s ON ac.class_id = s.class_id
         WHERE a.assessment_id = ? 
         AND s.student_id = ?
         AND (a.status IN ('pending', 'completed'))"
    );
    $stmt->execute([$assessmentId, $studentId]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Assessment not found or not available');
    }
    
    // Check if the assessment is available today or allows late submission
    $stmt = $db->prepare(
        "SELECT a.assessment_id FROM Assessments a
         WHERE a.assessment_id = ?
         AND (a.date = CURRENT_DATE OR (a.allow_late_submission = 1 AND a.date >= DATE_SUB(CURRENT_DATE, INTERVAL a.late_submission_days DAY)))"
    );
    $stmt->execute([$assessmentId]);
    
    if (!$stmt->fetch()) {
        throw new Exception('Assessment is not available today');
    }
    
    // Check if assessment is already completed
    $stmt = $db->prepare(
        "SELECT status FROM Results 
         WHERE assessment_id = ? AND student_id = ?"
    );
    $stmt->execute([$assessmentId, $studentId]);
    $result = $stmt->fetch();
    
    if ($result && $result['status'] === 'completed') {
        throw new Exception('Assessment already completed');
    }
}

/**
 * Save assessment result to the database
 */
function saveAssessmentResult($db, $assessmentId, $studentId, $score) {
    // Check if result record exists
    $stmt = $db->prepare(
        "SELECT result_id FROM Results 
         WHERE assessment_id = ? AND student_id = ?"
    );
    $stmt->execute([$assessmentId, $studentId]);
    $existingResult = $stmt->fetch();
    
    if ($existingResult) {
        // Update existing result
        $stmt = $db->prepare(
            "UPDATE Results 
             SET score = ?, status = 'completed', updated_at = CURRENT_TIMESTAMP 
             WHERE result_id = ?"
        );
        $stmt->execute([$score, $existingResult['result_id']]);
    } else {
        // Insert new result
        $stmt = $db->prepare(
            "INSERT INTO Results (
                assessment_id, 
                student_id, 
                score,
                status
            ) VALUES (?, ?, ?, 'completed')"
        );
        $stmt->execute([$assessmentId, $studentId, $score]);
    }
}