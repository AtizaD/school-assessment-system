<?php
/**
 * Database Configuration
 * 
 * Handles database connection settings and provides connection management
 */

// Prevent direct access to this file
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class DatabaseConfig {
    // Database configuration constants
    private const DB_HOST = 'localhost';
    private const DB_NAME = 'bass_shs_test';
    private const DB_USER = 'root';
    private const DB_PASS = '1234';
    private const DB_CHARSET = 'utf8mb4';
    
    private static $instance = null;
    private $connection = null;

    private function __construct() {
        try {
            $dsn = sprintf("mysql:host=%s;dbname=%s;charset=%s",
                self::DB_HOST,
                self::DB_NAME,
                self::DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->connection = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
        } catch (PDOException $e) {
            // Log error and display generic message
            error_log("Connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please contact administrator.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Updated visibility to public
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}