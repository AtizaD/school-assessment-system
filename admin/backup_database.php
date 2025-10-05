<?php
/**
 * Database Backup - School Assessment Management System
 * Creates a complete SQL backup before student promotion
 */

define('BASEPATH', dirname(__DIR__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require admin role
requireRole('admin');

header('Content-Type: application/json');

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Create backups directory if it doesn't exist
    $backupDir = BASEPATH . '/database/backups';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    // Generate backup filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/promotion_backup_' . $timestamp . '.sql';

    // Database credentials
    $dbHost = 'localhost';
    $dbName = 'bass_shs_test';
    $dbUser = 'root';
    $dbPass = '1234';

    // Create mysqldump command (redirect stderr to avoid warning in SQL file)
    $command = sprintf(
        'mysqldump -u %s -p%s -h %s %s 2>nul > "%s"',
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbHost),
        escapeshellarg($dbName),
        $backupFile
    );

    // Execute backup
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        throw new Exception('Backup command failed: ' . implode("\n", $output));
    }

    // Verify backup file was created and has content
    if (!file_exists($backupFile) || filesize($backupFile) === 0) {
        throw new Exception('Backup file was not created or is empty');
    }

    // Get backup file size
    $fileSize = filesize($backupFile);
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);

    // Log the backup
    logActivity(
        $_SESSION['user_id'],
        'database_backup',
        "Created promotion backup: " . basename($backupFile) . " ({$fileSizeMB}MB)"
    );

    echo json_encode([
        'success' => true,
        'message' => 'Database backup created successfully',
        'filename' => basename($backupFile),
        'size' => $fileSizeMB . ' MB',
        'timestamp' => $timestamp,
        'path' => $backupFile
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    logError('Backup Error: ' . $e->getMessage());
}
