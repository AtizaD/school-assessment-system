<?php
// models/User.php
class User {
    private $db;
    private $userId;
    private $username;
    private $email;
    private $role;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }

    private function setAuditInfo() {
        $stmt = $this->db->prepare("SET @current_user_id = ?, @client_ip = ?, @client_agent = ?");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    public function create($username, $email, $password, $role) {
        try {
            $this->setAuditInfo(); // Set audit info before operation
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare(
                "INSERT INTO Users (username, email, password_hash, role) 
                 VALUES (?, ?, ?, ?)"
            );
            return $stmt->execute([$username, $email, $hash, $role]);
        } catch (PDOException $e) {
            logError("User creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function updatePassword($userId, $newPassword) {
        try {
            $this->setAuditInfo(); // Set audit info before operation
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare(
                "UPDATE Users SET password_hash = ?, last_password_change = CURRENT_TIMESTAMP 
                 WHERE user_id = ?"
            );
            return $stmt->execute([$hash, $userId]);
        } catch (PDOException $e) {
            logError("Password update failed: " . $e->getMessage());
            return false;
        }
    }

    // Add this new method for general user updates
    public function update($userId, $data) {
        try {
            $this->setAuditInfo(); // Set audit info before operation
            $updateFields = [];
            $params = [];
            
            foreach ($data as $field => $value) {
                if (in_array($field, ['email', 'role'])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $value;
                }
            }
            
            if (empty($updateFields)) {
                return false;
            }
            
            $params[] = $userId;
            $stmt = $this->db->prepare(
                "UPDATE Users SET " . implode(', ', $updateFields) . " 
                 WHERE user_id = ?"
            );
            return $stmt->execute($params);
        } catch (PDOException $e) {
            logError("User update failed: " . $e->getMessage());
            return false;
        }
    }
}
