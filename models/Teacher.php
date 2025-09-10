<?php
// models/Teacher.php
class Teacher {
    private $db;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }

    public function create($firstName, $lastName, $email, $userId) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO Teachers (first_name, last_name, email, user_id) 
                 VALUES (?, ?, ?, ?)"
            );
            return $stmt->execute([$firstName, $lastName, $email, $userId]);
        } catch (PDOException $e) {
            logError("Teacher creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function getClasses($teacherId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT c.*, p.program_name 
                 FROM Classes c
                 JOIN Programs p ON c.program_id = p.program_id
                 JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
                 WHERE tca.teacher_id = ?
                 AND tca.is_primary_instructor = TRUE"
            );
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError("Get classes failed: " . $e->getMessage());
            return false;
        }
    }

    public function getStudents($teacherId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT s.*, c.class_name 
                 FROM Students s 
                 JOIN Classes c ON s.class_id = c.class_id
                 JOIN TeacherClassAssignments tca ON c.class_id = tca.class_id
                 WHERE tca.teacher_id = ?"
            );
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError("Get students failed: " . $e->getMessage());
            return false;
        }
    }
}