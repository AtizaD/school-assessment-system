<?php

// models/Student.php
class Student {
    private $db;
    private $studentId;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }

    public function create($firstName, $lastName, $classId, $userId) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO Students (first_name, last_name, class_id, user_id) 
                 VALUES (?, ?, ?, ?)"
            );
            return $stmt->execute([$firstName, $lastName, $classId, $userId]);
        } catch (PDOException $e) {
            logError("Student creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function getAssessments($studentId) {
        try {
            // Updated to include assessments from both regular class and special enrollments
            $stmt = $this->db->prepare(
                "SELECT DISTINCT a.*, r.score, r.feedback, r.status as result_status,
                        ac.class_id as assessment_class_id, ac.subject_id,
                        s.subject_name,
                        CASE 
                            WHEN sc.sp_id IS NOT NULL THEN 'special'
                            ELSE 'regular'
                        END as enrollment_type
                 FROM Assessments a 
                 JOIN AssessmentClasses ac ON a.assessment_id = ac.assessment_id
                 JOIN Subjects s ON ac.subject_id = s.subject_id
                 LEFT JOIN Results r ON a.assessment_id = r.assessment_id AND r.student_id = ?
                 LEFT JOIN Students st ON st.student_id = ?
                 LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                          AND sc.class_id = ac.class_id 
                                          AND sc.subject_id = ac.subject_id 
                                          AND sc.status = 'active'
                 WHERE (ac.class_id = (SELECT class_id FROM Students WHERE student_id = ?) OR sc.sp_id IS NOT NULL)
                 ORDER BY a.created_at DESC"
            );
            $stmt->execute([$studentId, $studentId, $studentId, $studentId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError("Get assessments failed: " . $e->getMessage());
            return false;
        }
    }

    public function getResults($studentId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.*, a.title as assessment_title 
                 FROM Results r 
                 JOIN Assessments a ON r.assessment_id = a.assessment_id 
                 WHERE r.student_id = ?"
            );
            $stmt->execute([$studentId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError("Get results failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a student is eligible for an assessment (regular class or special enrollment)
     */
    public function isEligibleForAssessment($studentId, $assessmentId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as eligible
                 FROM AssessmentClasses ac
                 LEFT JOIN Students st ON st.student_id = ?
                 LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                          AND sc.class_id = ac.class_id 
                                          AND sc.subject_id = ac.subject_id 
                                          AND sc.status = 'active'
                 WHERE ac.assessment_id = ? 
                 AND (ac.class_id = st.class_id OR sc.sp_id IS NOT NULL)"
            );
            $stmt->execute([$studentId, $studentId, $assessmentId]);
            $result = $stmt->fetch();
            return $result['eligible'] > 0;
        } catch (PDOException $e) {
            logError("Assessment eligibility check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all subjects for a student (regular + special enrollments)
     */
    public function getStudentSubjects($studentId, $semesterId = null) {
        try {
            $semesterCondition = $semesterId ? "AND (tca.semester_id = ? OR tca.semester_id IS NULL)" : "";
            $stmt = $this->db->prepare(
                "SELECT DISTINCT s.subject_id, s.subject_name, s.description,
                        CASE 
                            WHEN sc.sp_id IS NOT NULL THEN 'special'
                            ELSE 'regular'
                        END as enrollment_type,
                        CASE 
                            WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                            ELSE st.class_id
                        END as enrolled_class_id,
                        sc.notes as special_notes
                 FROM Subjects s
                 LEFT JOIN ClassSubjects cs ON s.subject_id = cs.subject_id
                 LEFT JOIN Students st ON st.student_id = ? AND cs.class_id = st.class_id
                 LEFT JOIN Special_Class sc ON sc.student_id = ? 
                                          AND sc.subject_id = s.subject_id 
                                          AND sc.status = 'active'
                 LEFT JOIN TeacherClassAssignments tca ON tca.subject_id = s.subject_id 
                                                      AND tca.class_id = CASE 
                                                          WHEN sc.sp_id IS NOT NULL THEN sc.class_id
                                                          ELSE st.class_id
                                                      END
                                                      $semesterCondition
                 WHERE (cs.class_id IS NOT NULL OR sc.sp_id IS NOT NULL)
                 ORDER BY s.subject_name"
            );
            $params = [$studentId, $studentId];
            if ($semesterId) {
                $params[] = $semesterId;
            }
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError("Get student subjects failed: " . $e->getMessage());
            return false;
        }
    }
}
