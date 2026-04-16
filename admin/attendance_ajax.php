<?php
/**
 * DOIT Faculty Attendance System - Attendance AJAX Handler
 * Handles AJAX requests for attendance management
 */

// Define system constant for security
define('DOIT_SYSTEM', true);

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

// Require admin authentication
requireAdmin();

// Check session timeout
checkSessionTimeout();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            $attendanceId = (int)($_GET['id'] ?? 0);
            
            if ($attendanceId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid attendance ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
            $stmt->execute([$attendanceId]);
            $attendance = $stmt->fetch();
            
            if ($attendance) {
                echo json_encode(['success' => true, 'attendance' => $attendance]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Attendance AJAX error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
