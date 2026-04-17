<?php
require_once '../config/database.php';

echo "Adding kiosk functionality columns to attendance table...\n";

try {
    // Add check_in_method and check_out_method columns to attendance table
    $sql = "ALTER TABLE attendance 
            ADD COLUMN check_in_method VARCHAR(20) DEFAULT 'manual' AFTER late_minutes,
            ADD COLUMN check_out_method VARCHAR(20) DEFAULT 'manual' AFTER check_out_time";
    
    $pdo->exec($sql);
    echo "Successfully added check_in_method and check_out_method columns to attendance table.\n";
    
    // Add rfid_card column to users table for storing RFID card numbers
    $sql = "ALTER TABLE users 
            ADD COLUMN rfid_card VARCHAR(100) NULL AFTER employee_id";
    
    $pdo->exec($sql);
    echo "Successfully added rfid_card column to users table.\n";
    
    // Create kiosk_logs table for tracking kiosk activity
    $sql = "CREATE TABLE IF NOT EXISTS kiosk_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        scan_id VARCHAR(100) NOT NULL,
        action ENUM('checkin', 'checkout') NOT NULL,
        success BOOLEAN NOT NULL DEFAULT TRUE,
        message TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql);
    echo "Successfully created kiosk_logs table.\n";
    
    // Add index for better performance
    $sql = "CREATE INDEX idx_kiosk_logs_user_date ON kiosk_logs(user_id, created_at)";
    $pdo->exec($sql);
    echo "Successfully added index to kiosk_logs table.\n";
    
    echo "\nKiosk database setup completed successfully!\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist. Migration skipped.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
