<?php

// models/Assessment.php
class Assessment {
    private $db;
    private $assessmentId;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }

    public function create($classId, $semesterId, $title, $description, $date) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare(
                "INSERT INTO Assessments (class_id, semester_id, title, description, date) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$classId, $semesterId, $title, $description, $date]);
            $assessmentId = $this->db->lastInsertId();
            
            $this->db->commit();
            return $assessmentId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Assessment creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function addQuestion($assessmentId, $questionText, $questionType, $maxScore) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO Questions (assessment_id, question_text, question_type, max_score) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$assessmentId, $questionText, $questionType, $maxScore]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            logError("Question addition failed: " . $e->getMessage());
            return false;
        }
    }

    public function submitResult($assessmentId, $studentId, $score, $feedback) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO Results (assessment_id, student_id, score, feedback, status) 
                 VALUES (?, ?, ?, ?, 'completed')"
            );
            return $stmt->execute([$assessmentId, $studentId, $score, $feedback]);
        } catch (PDOException $e) {
            logError("Result submission failed: " . $e->getMessage());
            return false;
        }
    }
}