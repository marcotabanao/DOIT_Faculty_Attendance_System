<?php
/**
 * DOIT Faculty Attendance System - Schedule AJAX Handler
 * Handles AJAX requests for schedule management
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
            $scheduleId = (int)($_GET['id'] ?? 0);
            
            if ($scheduleId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
            $stmt->execute([$scheduleId]);
            $schedule = $stmt->fetch();
            
            if ($schedule) {
                echo json_encode(['success' => true, 'schedule' => $schedule]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Schedule not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Schedule AJAX error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
