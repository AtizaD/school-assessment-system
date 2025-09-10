<?php
//api/grade_assessment
function gradeAssessment($assessmentId, $studentId) {
    $db = DatabaseConfig::getInstance()->getConnection();
    
    // First, check if this assessment uses question pooling
    $stmt = $db->prepare(
        "SELECT use_question_limit, questions_to_answer 
         FROM assessments 
         WHERE assessment_id = ?"
    );
    $stmt->execute([$assessmentId]);
    $assessmentSettings = $stmt->fetch();
    
    $selectedQuestionIds = [];
    
    if ($assessmentSettings['use_question_limit'] && $assessmentSettings['questions_to_answer']) {
        // This assessment uses question pooling - get the specific questions for this student
        $stmt = $db->prepare(
            "SELECT question_order 
             FROM assessmentattempts 
             WHERE assessment_id = ? AND student_id = ?"
        );
        $stmt->execute([$assessmentId, $studentId]);
        $attempt = $stmt->fetch();
        
        if ($attempt && !empty($attempt['question_order'])) {
            $selectedQuestionIds = json_decode($attempt['question_order'], true);
            
            if (!is_array($selectedQuestionIds)) {
                $selectedQuestionIds = [];
            }
        }
        
        // If we have selected questions, only grade those
        if (!empty($selectedQuestionIds)) {
            $placeholders = str_repeat('?,', count($selectedQuestionIds) - 1) . '?';
            $stmt = $db->prepare(
                "SELECT 
                    q.*,
                    sa.answer_text as student_answer,
                    CASE 
                        WHEN q.question_type = 'MCQ' THEN (
                            SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                            FROM mcqquestions mcq 
                            WHERE mcq.question_id = q.question_id
                        )
                        ELSE NULL
                    END as mcq_data
                 FROM questions q
                 LEFT JOIN studentanswers sa ON (
                    sa.question_id = q.question_id 
                    AND sa.student_id = ? 
                    AND sa.assessment_id = ?
                 )
                 WHERE q.question_id IN ($placeholders)
                 ORDER BY FIELD(q.question_id, " . implode(',', $selectedQuestionIds) . ")"
            );
            $stmt->execute(array_merge([$studentId, $assessmentId], $selectedQuestionIds));
            $questions = $stmt->fetchAll();
        } else {
            // No selected questions found - this shouldn't happen, but handle gracefully
            $questions = [];
        }
    } else {
        // Normal assessment - grade all questions
        $stmt = $db->prepare(
            "SELECT 
                q.*,
                sa.answer_text as student_answer,
                CASE 
                    WHEN q.question_type = 'MCQ' THEN (
                        SELECT GROUP_CONCAT(CONCAT(mcq.mcq_id, ':', mcq.answer_text, ':', mcq.is_correct) SEPARATOR '|')
                        FROM mcqquestions mcq 
                        WHERE mcq.question_id = q.question_id
                    )
                    ELSE NULL
                END as mcq_data
             FROM questions q
             LEFT JOIN studentanswers sa ON (
                sa.question_id = q.question_id 
                AND sa.student_id = ? 
                AND sa.assessment_id = ?
             )
             WHERE q.assessment_id = ?"
        );
        $stmt->execute([$studentId, $assessmentId, $assessmentId]);
        $questions = $stmt->fetchAll();
    }

    $totalScore = 0;
    $maxScore = 0;

    foreach ($questions as $question) {
        $questionScore = 0;
        $maxScore += $question['max_score'];

        if (!empty($question['student_answer'])) {
            if ($question['question_type'] === 'MCQ') {
                // Grade MCQ
                $options = [];
                $mcqData = explode('|', $question['mcq_data']);
                foreach ($mcqData as $data) {
                    if (!empty($data)) {
                        list($id, $text, $isCorrect) = explode(':', $data);
                        $options[$id] = $isCorrect;
                    }
                }

                if (isset($options[$question['student_answer']]) && $options[$question['student_answer']]) {
                    $questionScore = $question['max_score'];
                }

            } else {
                // Grade Short Answer
                if ($question['answer_mode'] === 'exact') {
                    // Exact match comparison (case-insensitive)
                    if (strcasecmp(trim($question['student_answer']), trim($question['correct_answer'])) === 0) {
                        $questionScore = $question['max_score'];
                    }

                } else {
                    // Multiple answer matching with duplicate prevention
                    $validAnswers = json_decode($question['correct_answer'], true);
                    $studentAnswers = array_map('trim', explode("\n", $question['student_answer']));
                    $requiredCount = $question['answer_count'];
                    
                    // Remove empty answers and duplicates
                    $studentAnswers = array_filter($studentAnswers, function($answer) {
                        return !empty(trim($answer));
                    });
                    $studentAnswers = array_unique(array_map('strtolower', $studentAnswers));
                    
                    $correctCount = 0;
                    $usedAnswers = []; // Track which valid answers have been used
                    
                    // Convert valid answers to lowercase for case-insensitive comparison
                    $validAnswers = array_map('strtolower', $validAnswers);
                    
                    foreach ($studentAnswers as $answer) {
                        $answerKey = array_search($answer, $validAnswers);
                        if ($answerKey !== false && !in_array($answerKey, $usedAnswers)) {
                            $correctCount++;
                            $usedAnswers[] = $answerKey; // Mark this valid answer as used
                        }
                    }

                    // Calculate partial credit based on unique correct answers
                    $questionScore = ($question['max_score'] / $requiredCount) * min($correctCount, $requiredCount);
                }
            }
        }

        // Store question score - modified to use lowercase table names
        $stmt = $db->prepare(
            "UPDATE studentanswers 
             SET score = ? 
             WHERE assessment_id = ? 
             AND student_id = ? 
             AND question_id = ?"
        );
        $stmt->execute([$questionScore, $assessmentId, $studentId, $question['question_id']]);

        $totalScore += $questionScore;
    }

    // Return raw score
    return $totalScore;
}
?>