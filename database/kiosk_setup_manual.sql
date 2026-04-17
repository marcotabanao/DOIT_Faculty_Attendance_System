-- ============================================
-- DOIT Faculty Attendance System - Kiosk Setup
-- Execute these queries manually in phpMyAdmin
-- ============================================

-- 1. Add check_in_method and check_out_method columns to attendance table
ALTER TABLE doit_attendance.attendance 
ADD COLUMN check_in_method VARCHAR(20) DEFAULT 'manual' AFTER late_minutes,
ADD COLUMN check_out_method VARCHAR(20) DEFAULT 'manual' AFTER check_out_time;

-- 2. Add rfid_card column to users table for storing RFID card numbers
ALTER TABLE doit_attendance.users 
ADD COLUMN rfid_card VARCHAR(100) NULL AFTER employee_id;

-- 3. Create kiosk_logs table for tracking kiosk activity (without foreign key first)
CREATE TABLE IF NOT EXISTS doit_attendance.kiosk_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    scan_id VARCHAR(100) NOT NULL,
    action ENUM('checkin', 'checkout') NOT NULL,
    success BOOLEAN NOT NULL DEFAULT TRUE,
    message TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3b. Skip foreign key constraint for now (table will work without it)
-- Note: Foreign key can be added later if needed

-- 4. Add index for better performance on kiosk_logs
CREATE INDEX idx_kiosk_logs_user_date ON doit_attendance.kiosk_logs(user_id, created_at);

-- 5. (Optional) Add some sample RFID card numbers for testing
-- Update these with your actual RFID card numbers
UPDATE doit_attendance.users SET rfid_card = 'RFID001' WHERE employee_id = 'FAC001' AND role = 'faculty';
UPDATE doit_attendance.users SET rfid_card = 'RFID002' WHERE employee_id = 'FAC002' AND role = 'faculty';
UPDATE doit_attendance.users SET rfid_card = 'RFID003' WHERE employee_id = 'FAC003' AND role = 'faculty';

-- 6. Verify the setup
SELECT 
    'attendance_table' as table_name, 
    COUNT(*) as columns_added 
FROM information_schema.columns 
WHERE table_schema = 'doit_attendance'
AND table_name = 'attendance' 
AND column_name IN ('check_in_method', 'check_out_method')
UNION ALL
SELECT 
    'users_table' as table_name, 
    COUNT(*) as columns_added 
FROM information_schema.columns 
WHERE table_schema = 'doit_attendance'
AND table_name = 'users' 
AND column_name = 'rfid_card'
UNION ALL
SELECT 
    'kiosk_logs_table' as table_name, 
    COUNT(*) as table_exists 
FROM information_schema.tables 
WHERE table_schema = 'doit_attendance'
AND table_name = 'kiosk_logs';

-- ============================================
-- Setup Complete!
-- You can now use the kiosk system.
-- ============================================
