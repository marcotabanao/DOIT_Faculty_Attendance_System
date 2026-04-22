<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'faculty' => null,
    'action' => '',
    'time' => date('h:i A')
];

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get and validate scan ID
    $scan_id = trim($_POST['scan_id'] ?? '');
    if (empty($scan_id)) {
        throw new Exception('Please scan a valid ID');
    }
    
    // Look up faculty by employee_id or RFID card number
    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? OR rfid_card = ? AND role = 'faculty'");
    $stmt->execute([$scan_id, $scan_id]);
    $faculty = $stmt->fetch();
    
    if (!$faculty) {
        throw new Exception('Invalid ID. Faculty member not found.');
    }
    
    $today = date('Y-m-d');
    $time = date('H:i:s');
    
    // Check if already checked in today
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
    $stmt->execute([$faculty['id'], $today]);
    $today_attendance = $stmt->fetch();
    
    if (!$today_attendance || !$today_attendance['check_in_time']) {
        // Check IN
        $day_of_week = date('N');
        $schStmt = $pdo->prepare("SELECT time_in FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND semester_id = (SELECT id FROM semesters WHERE is_active = 1 LIMIT 1)");
        $schStmt->execute([$faculty['id'], $day_of_week]);
        $schedule = $schStmt->fetch();
        
        $status = 'present';
        $late_minutes = 0;
        if ($schedule && $time > $schedule['time_in']) {
            $status = 'late';
            $late_minutes = (strtotime($time) - strtotime($schedule['time_in'])) / 60;
        }
        
        $insert = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, status, late_minutes, check_in_method) VALUES (?, ?, ?, ?, ?, 'kiosk')");
        if ($insert->execute([$faculty['id'], $today, $time, $status, $late_minutes])) {
            logActivity($pdo, 'CHECKIN_KIOSK', 'attendance', $pdo->lastInsertId(), "Checked in via kiosk at $time");
            
            $response['success'] = true;
            $response['message'] = "Check-in successful! Welcome, " . htmlspecialchars($faculty['first_name']);
            $response['faculty'] = [
                'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                'employee_id' => $faculty['employee_id'],
                'status' => $status,
                'late_minutes' => $late_minutes
            ];
            $response['action'] = 'checkin';
            $response['time'] = date('h:i A');
        } else {
            throw new Exception('Check-in failed. Please try again.');
        }
    } elseif (!$today_attendance['check_out_time']) {
        // Check OUT
        $update = $pdo->prepare("UPDATE attendance SET check_out_time = ?, check_out_method = 'kiosk' WHERE id = ?");
        if ($update->execute([$time, $today_attendance['id']])) {
            logActivity($pdo, 'CHECKOUT_KIOSK', 'attendance', $today_attendance['id'], "Checked out via kiosk at $time");
            
            $response['success'] = true;
            $response['message'] = "Check-out successful! Goodbye, " . htmlspecialchars($faculty['first_name']);
            $response['faculty'] = [
                'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                'employee_id' => $faculty['employee_id']
            ];
            $response['action'] = 'checkout';
            $response['time'] = date('h:i A');
        } else {
            throw new Exception('Check-out failed. Please try again.');
        }
    } else {
        // Check for open session (check-in without check-out) for today
        $openSessionStmt = $pdo->prepare("
            SELECT * FROM attendance 
            WHERE faculty_id = ? AND date = ? 
            AND check_in_time IS NOT NULL 
            AND check_out_time IS NULL 
            ORDER BY check_in_time DESC 
            LIMIT 1
        ");
        $openSessionStmt->execute([$faculty['id'], $today]);
        $open_session = $openSessionStmt->fetch();
        
        if ($open_session) {
            // Close open session with check-out
            $update = $pdo->prepare("
                UPDATE attendance 
                SET check_out_time = ?, check_out_method = 'kiosk' 
                WHERE id = ?
            ");
            if ($update->execute([$time, $open_session['id']])) {
                logActivity($pdo, 'CLOSE_SESSION_KIOSK', 'attendance', $open_session['id'], "Closed open session via kiosk at $time");
                
                $response['success'] = true;
                $response['message'] = "Session closed! Goodbye, " . htmlspecialchars($faculty['first_name']);
                $response['faculty'] = [
                    'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                    'employee_id' => $faculty['employee_id']
                ];
                $response['action'] = 'checkout';
                $response['time'] = date('h:i A');
            } else {
                throw new Exception('Failed to close session. Please try again.');
            }
        } else {
            // No open session, create new check-in
            $day_of_week = date('N');
            $schStmt = $pdo->prepare("SELECT time_in FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND semester_id = (SELECT id FROM semesters WHERE is_active = 1 LIMIT 1)");
            $schStmt->execute([$faculty['id'], $day_of_week]);
            $schedule = $schStmt->fetch();
            
            $status = 'present';
            $late_minutes = 0;
            if ($schedule && $time > $schedule['time_in']) {
                $status = 'late';
                $late_minutes = (strtotime($time) - strtotime($schedule['time_in'])) / 60;
            }
            
            $insert = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, status, late_minutes, check_in_method) VALUES (?, ?, ?, ?, 'kiosk')");
            if ($insert->execute([$faculty['id'], $today, $time, $status, $late_minutes])) {
                logActivity($pdo, 'OPEN_SESSION_KIOSK', 'attendance', $pdo->lastInsertId(), "Opened new session via kiosk at $time");
                
                $response['success'] = true;
                $response['message'] = "Session started! Welcome, " . htmlspecialchars($faculty['first_name']);
                $response['faculty'] = [
                    'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                    'employee_id' => $faculty['employee_id'],
                    'status' => $status,
                    'late_minutes' => $late_minutes
                ];
                $response['action'] = 'checkin';
                $response['time'] = date('h:i A');
            } else {
                throw new Exception('Failed to start session. Please try again.');
            }
        }
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Kiosk scan error: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
?>
