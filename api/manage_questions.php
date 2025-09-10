<?php
// api/manage_questions.php

requireRole('teacher');

$error = '';
$success = '';
$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$assessmentId) {
    redirectTo('assessments.php');
}

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // Get teacher ID
    $stmt = $db->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherId = $stmt->fetch()['teacher_id'];

    // Get assessment details with authorization check
    // Modified to match the database schema and ensure proper joins
    $stmt = $db->prepare(
        "SELECT a.*, c.class_name, s.subject_name
         FROM assessments a
         JOIN assessmentclasses ac ON a.assessment_id = ac.assessment_id
         JOIN classes c ON ac.class_id = c.class_id
         JOIN subjects s ON ac.subject_id = s.subject_id
         JOIN teacherclassassignments tca ON c.class_id = tca.class_id AND ac.subject_id = tca.subject_id
         WHERE a.assessment_id = ? AND tca.teacher_id = ? AND a.status = 'pending'"
    );
    $stmt->execute([$assessmentId, $teacherId]);
    $assessment = $stmt->fetch();

    if (!$assessment) {
        throw new Exception('Assessment not found, unauthorized, or already completed');
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid request');
        }

        $db->beginTransaction();

        try {
            switch ($_POST['action']) {
                case 'add_question':
                    $questionText = sanitizeInput($_POST['question_text']);
                    $questionType = sanitizeInput($_POST['question_type']);
                    $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);
                    $answerCount = filter_var($_POST['answer_count'] ?? 1, FILTER_VALIDATE_INT);
                    $answerMode = sanitizeInput($_POST['answer_mode'] ?? 'exact');
                    $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
                    
                    // Fix: Convert empty or invalid imageId to NULL
                    $imageId = ($imageId === false || $imageId === null || $imageId === 0) ? null : $imageId;

                    // Validate inputs
                    if ($maxScore <= 0 || $maxScore > 100) {
                        throw new Exception('Invalid score value');
                    }

                    if ($answerCount < 1) {
                        throw new Exception('At least one answer is required');
                    }

                    // Handle different question types
                    if ($questionType === 'MCQ') {
                        // Validate MCQ options
                        if (!isset($_POST['options']) || count($_POST['options']) < 2) {
                            throw new Exception('MCQ questions must have at least 2 options');
                        }

                        if (!isset($_POST['correct_option'])) {
                            throw new Exception('Please select a correct answer');
                        }

                        // Insert question
                        $stmt = $db->prepare(
                            "INSERT INTO questions (
                                assessment_id,
                                question_text,
                                question_type,
                                max_score,
                                answer_count,
                                answer_mode,
                                multiple_answers_allowed,
                                image_id
                            ) VALUES (?, ?, ?, ?, 1, 'exact', 0, ?)"
                        );
                        
                        $stmt->execute([
                            $assessmentId,
                            $questionText,
                            $questionType,
                            $maxScore,
                            $imageId  // Now properly handles NULL values
                        ]);

                        $questionId = $db->lastInsertId();

                        // Insert MCQ options
                        $stmt = $db->prepare(
                            "INSERT INTO mcqquestions (
                                question_id,
                                answer_text,
                                is_correct
                            ) VALUES (?, ?, ?)"
                        );

                        foreach ($_POST['options'] as $index => $option) {
                            $isCorrect = isset($_POST['correct_option']) && 
                                       $_POST['correct_option'] == $index ? 1 : 0;
                            $stmt->execute([
                                $questionId,
                                sanitizeInput($option),
                                $isCorrect
                            ]);
                        }

                    } else {
                        // Handle Short Answer type
                        if ($answerMode === 'any_match') {
                            // Validate and process multiple valid answers
                            $validAnswers = array_filter(
                                array_map('trim', $_POST['valid_answers'] ?? [])
                            );
                            
                            if (empty($validAnswers)) {
                                throw new Exception('At least one valid answer is required');
                            }

                            $correctAnswer = json_encode($validAnswers);
                            $multipleAnswers = 1;
                        } else {
                            // Single exact answer
                            $correctAnswer = sanitizeInput($_POST['correct_answer']);
                            if (empty($correctAnswer)) {
                                throw new Exception('Correct answer is required');
                            }
                            $multipleAnswers = 0;
                        }

                        // Insert short answer question
                        $stmt = $db->prepare(
                            "INSERT INTO questions (
                                assessment_id,
                                question_text,
                                question_type,
                                correct_answer,
                                max_score,
                                answer_count,
                                answer_mode,
                                multiple_answers_allowed,
                                image_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        
                        $stmt->execute([
                            $assessmentId,
                            $questionText,
                            $questionType,
                            $correctAnswer,
                            $maxScore,
                            $answerCount,
                            $answerMode,
                            $multipleAnswers,
                            $imageId  // Now properly handles NULL values
                        ]);
                    }

                    $success = 'Question added successfully';
                    break;

                case 'edit_question':
                    $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
                    $questionText = sanitizeInput($_POST['question_text']);
                    $maxScore = filter_var($_POST['max_score'], FILTER_VALIDATE_FLOAT);
                    $questionType = sanitizeInput($_POST['question_type']);
                    $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
                    
                    // Fix: Convert empty or invalid imageId to NULL
                    $imageId = ($imageId === false || $imageId === null || $imageId === 0) ? null : $imageId;
                    
                    // Verify question belongs to this assessment
                    $stmt = $db->prepare(
                        "SELECT question_type, 
                                (SELECT COUNT(*) FROM studentanswers WHERE question_id = ?) as answer_count 
                         FROM questions 
                         WHERE question_id = ? AND assessment_id = ?"
                    );
                    $stmt->execute([$questionId, $questionId, $assessmentId]);
                    $questionInfo = $stmt->fetch();

                    if (!$questionInfo) {
                        throw new Exception('Question not found');
                    }

                    if ($questionInfo['answer_count'] > 0) {
                        throw new Exception('Cannot edit question: students have already submitted answers');
                    }

                    if ($questionType === 'MCQ') {
                        // Update MCQ question
                        $stmt = $db->prepare(
                            "UPDATE questions 
                             SET question_text = ?,
                                 max_score = ?,
                                 answer_count = 1,
                                 answer_mode = 'exact',
                                 multiple_answers_allowed = 0,
                                 image_id = ?
                             WHERE question_id = ?"
                        );
                        $stmt->execute([$questionText, $maxScore, $imageId, $questionId]);

                        // Update MCQ options
                        $stmt = $db->prepare("DELETE FROM mcqquestions WHERE question_id = ?");
                        $stmt->execute([$questionId]);

                        $stmt = $db->prepare(
                            "INSERT INTO mcqquestions (
                                question_id,
                                answer_text,
                                is_correct
                            ) VALUES (?, ?, ?)"
                        );

                        foreach ($_POST['options'] as $index => $option) {
                            $isCorrect = isset($_POST['correct_option']) && 
                                       $_POST['correct_option'] == $index ? 1 : 0;
                            $stmt->execute([$questionId, sanitizeInput($option), $isCorrect]);
                        }
                    } else {
                        // Update Short Answer question
                        $answerMode = sanitizeInput($_POST['answer_mode']);
                        $answerCount = filter_var($_POST['answer_count'] ?? 1, FILTER_VALIDATE_INT);

                        if ($answerMode === 'any_match') {
                            $validAnswers = array_filter(
                                array_map('trim', $_POST['valid_answers'] ?? [])
                            );
                            $correctAnswer = json_encode($validAnswers);
                            $multipleAnswers = 1;
                        } else {
                            $correctAnswer = sanitizeInput($_POST['correct_answer']);
                            $multipleAnswers = 0;
                        }

                        $stmt = $db->prepare(
                            "UPDATE questions 
                             SET question_text = ?,
                                 correct_answer = ?,
                                 max_score = ?,
                                 answer_count = ?,
                                 answer_mode = ?,
                                 multiple_answers_allowed = ?,
                                 image_id = ?
                             WHERE question_id = ?"
                        );
                        
                        $stmt->execute([
                            $questionText,
                            $correctAnswer,
                            $maxScore,
                            $answerCount,
                            $answerMode,
                            $multipleAnswers,
                            $imageId,  // Now properly handles NULL values
                            $questionId
                        ]);
                    }

                    $success = 'Question updated successfully';
                    break;

                case 'delete_question':
                    $questionId = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
                    
                    // Verify no answers exist
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM studentanswers WHERE question_id = ?"
                    );
                    $stmt->execute([$questionId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('Cannot delete question: students have already submitted answers');
                    }

                    // First delete MCQ options if they exist
                    $stmt = $db->prepare("DELETE FROM mcqquestions WHERE question_id = ?");
                    $stmt->execute([$questionId]);
                    
                    // Then delete the question
                    $stmt = $db->prepare("DELETE FROM questions WHERE question_id = ? AND assessment_id = ?");
                    $stmt->execute([$questionId, $assessmentId]);

                    $success = 'Question deleted successfully';
                    break;
            }

            $db->commit();
            
            // Add this redirect to prevent form resubmission on refresh
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // This is an AJAX request, so just return JSON
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $success
                ]);
                exit;
            } else {
                // This is a regular POST, so redirect to prevent resubmission
                $_SESSION['success'] = $success;
                header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $assessmentId);
                exit;
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Get questions with their options
    $stmt = $db->prepare(
        "SELECT q.*, q.image_id,
                COUNT(DISTINCT sa.answer_id) as answer_count
         FROM questions q
         LEFT JOIN studentanswers sa ON q.question_id = sa.question_id
         WHERE q.assessment_id = ?
         GROUP BY q.question_id
         ORDER BY q.created_at ASC"
    );
    $stmt->execute([$assessmentId]);
    $questions = $stmt->fetchAll();

    // Get MCQ options for questions and image info
    foreach ($questions as &$question) {
        if ($question['question_type'] === 'MCQ') {
            $stmt = $db->prepare(
                "SELECT * FROM mcqquestions 
                 WHERE question_id = ? 
                 ORDER BY mcq_id"
            );
            $stmt->execute([$question['question_id']]);
            $question['options'] = $stmt->fetchAll();
        } elseif ($question['answer_mode'] === 'any_match') {
            $question['valid_answers'] = json_decode($question['correct_answer'], true);
        }
        
        // Get image info if exists
        if (!empty($question['image_id'])) {
            $stmt = $db->prepare(
                "SELECT filename FROM assessment_images 
                 WHERE image_id = ?"
            );
            $stmt->execute([$question['image_id']]);
            $imageData = $stmt->fetch();
            if ($imageData) {
                $question['image_url'] = BASE_URL . '/assets/assessment_images/' . $imageData['filename'];
            }
        }
    }
    unset($question);

} catch (Exception $e) {
    $error = $e->getMessage();
    logError("Edit assessment error: " . $e->getMessage());
}

// Return JSON response for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($error),
        'error' => $error,
        'message' => $success,
        'questions' => $questions ?? []
    ]);
    exit;
}

$pageTitle = 'Manage Questions';
require_once INCLUDES_PATH . '/bass/base_header.php';
?>