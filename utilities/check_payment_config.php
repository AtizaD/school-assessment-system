<?php
require_once 'config/database.php';

try {
    $db = DatabaseConfig::getInstance()->getConnection();
    echo "Testing database connection...\n";
    
    // Check if PaymentConfig table exists
    $stmt = $db->query("SHOW TABLES LIKE 'PaymentConfig'");
    $exists = $stmt->rowCount() > 0;
    echo "PaymentConfig table exists: " . ($exists ? 'YES' : 'NO') . "\n";
    
    if ($exists) {
        // Check table structure
        echo "\nTable structure:\n";
        $stmt = $db->query("DESCRIBE PaymentConfig");
        while ($row = $stmt->fetch()) {
            echo "  {$row['Field']} - {$row['Type']}\n";
        }
        
        // Check for any data
        echo "\nData count:\n";
        $stmt = $db->query("SELECT COUNT(*) as count FROM PaymentConfig");
        $count = $stmt->fetch()['count'];
        echo "  Total records: $count\n";
        
        if ($count > 0) {
            echo "\nExisting configuration keys:\n";
            $stmt = $db->query("SELECT config_key, is_encrypted FROM PaymentConfig");
            while ($row = $stmt->fetch()) {
                $encrypted = $row['is_encrypted'] ? ' (encrypted)' : '';
                echo "  {$row['config_key']}$encrypted\n";
            }
        }
    }
    
    // Also check if other payment tables exist
    echo "\nChecking other payment-related tables:\n";
    $tables = ['ServicePricing', 'PaymentTransactions', 'PaidServices', 'PaymentWebhooks'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        echo "  $table: " . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    }
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>