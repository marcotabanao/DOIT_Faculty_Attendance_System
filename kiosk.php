<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Kiosk mode - no authentication required for scanning
// But we'll track kiosk sessions
session_start();

// Initialize variables
$message = '';
$error = '';
$last_scan = null;
$today_attendance = null;

// Handle RFID/ID scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_id'])) {
    $scan_id = trim($_POST['scan_id']);
    
    if (empty($scan_id)) {
        $error = "Please scan a valid ID";
    } else {
        try {
            // Look up faculty by employee_id or RFID card number
            $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? OR rfid_card = ? AND role = 'faculty'");
            $stmt->execute([$scan_id, $scan_id]);
            $faculty = $stmt->fetch();
            
            if (!$faculty) {
                $error = "Invalid ID. Faculty member not found.";
            } else {
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
                        $message = "Check-in successful! Welcome, " . htmlspecialchars($faculty['first_name']);
                        $last_scan = $faculty;
                    } else {
                        $error = "Check-in failed. Please try again.";
                    }
                } elseif (!$today_attendance['check_out_time']) {
                    // Check OUT
                    $update = $pdo->prepare("UPDATE attendance SET check_out_time = ?, check_out_method = 'kiosk' WHERE id = ?");
                    if ($update->execute([$time, $today_attendance['id']])) {
                        logActivity($pdo, 'CHECKOUT_KIOSK', 'attendance', $today_attendance['id'], "Checked out via kiosk at $time");
                        $message = "Check-out successful! Goodbye, " . htmlspecialchars($faculty['first_name']);
                        $last_scan = $faculty;
                    } else {
                        $error = "Check-out failed. Please try again.";
                    }
                } else {
                    $error = "Already checked out today. See you tomorrow!";
                }
            }
        } catch (Exception $e) {
            $error = "System error. Please contact administrator.";
            error_log("Kiosk scan error: " . $e->getMessage());
        }
    }
}

// Get recent kiosk activity for display
$recent_scans = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.employee_id, a.check_in_time, a.check_out_time, a.date, a.status
        FROM attendance a
        JOIN users u ON a.faculty_id = u.id
        WHERE a.date = CURDATE() AND (a.check_in_method = 'kiosk' OR a.check_out_method = 'kiosk')
        ORDER BY a.check_in_time DESC, a.check_out_time DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_scans = $stmt->fetchAll();
} catch (Exception $e) {
    // Silent fail for display
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Kiosk | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .kiosk-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .kiosk-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            max-width: 500px;
            width: 100%;
        }
        .scan-area {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            border: 3px dashed #6c757d;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
        }
        .scan-area:hover {
            border-color: #007bff;
            background: linear-gradient(45deg, #e3f2fd, #bbdefb);
        }
        .scan-icon {
            font-size: 4rem;
            color: #007bff;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .success-animation {
            animation: successPulse 0.5s ease;
        }
        @keyframes successPulse {
            0% { transform: scale(1); background-color: rgba(40, 167, 69, 0.1); }
            50% { transform: scale(1.05); background-color: rgba(40, 167, 69, 0.2); }
            100% { transform: scale(1); background-color: transparent; }
        }
        .error-animation {
            animation: errorShake 0.5s ease;
        }
        @keyframes errorShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        .recent-scans {
            max-height: 300px;
            overflow-y: auto;
        }
        .kiosk-header {
            background: linear-gradient(135deg, #800000, #b71c1c);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
        .faculty-info {
            background: rgba(23, 162, 184, 0.1);
            border-left: 4px solid #17a2b8;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .time-display {
            font-size: 2rem;
            font-weight: bold;
            color: #800000;
            text-align: center;
            margin: 20px 0;
        }
        .auto-focus {
            outline: none;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .auto-focus:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
    </style>
</head>
<body>
    <div class="kiosk-container">
        <div class="kiosk-card">
            <div class="kiosk-header">
                <h1><i class="bi bi-qr-code-scan"></i> Attendance Kiosk</h1>
                <p class="mb-0">Scan your ID to check in or out</p>
            </div>
            
            <div class="p-4">
                <div class="time-display" id="currentTime"></div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show success-animation">
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show error-animation">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($last_scan): ?>
                    <div class="faculty-info">
                        <h5><i class="bi bi-person-check"></i> Last Scan Details</h5>
                        <p class="mb-0">
                            <strong>Name:</strong> <?= htmlspecialchars($last_scan['first_name'] . ' ' . $last_scan['last_name']) ?><br>
                            <strong>ID:</strong> <?= htmlspecialchars($last_scan['employee_id']) ?><br>
                            <strong>Time:</strong> <?= date('h:i A') ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="scanForm" class="mt-4">
                    <div class="scan-area">
                        <i class="bi bi-upc-scan scan-icon"></i>
                        <h4>Ready to Scan</h4>
                        <p class="text-muted">Place your ID card near the scanner</p>
                        <input type="text" 
                               name="scan_id" 
                               class="form-control form-control-lg auto-focus" 
                               placeholder="ID will auto-scan..." 
                               autocomplete="off"
                               required>
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle"></i> System will automatically process scanned IDs
                        </small>
                    </div>
                    <div class="mt-3 text-center">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-arrow-clockwise"></i> Manual Scan
                        </button>
                        <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="clearForm()">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($recent_scans)): ?>
                    <div class="mt-4">
                        <h5><i class="bi bi-clock-history"></i> Recent Kiosk Activity</h5>
                        <div class="recent-scans">
                            <div class="list-group">
                                <?php foreach ($recent_scans as $scan): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($scan['first_name'] . ' ' . $scan['last_name']) ?></strong>
                                                <br>
                                                <small class="text-muted">ID: <?= htmlspecialchars($scan['employee_id']) ?></small>
                                            </div>
                                            <div class="text-end">
                                                <?php if ($scan['check_in_time']): ?>
                                                    <span class="badge bg-success">IN <?= date('h:i A', strtotime($scan['check_in_time'])) ?></span>
                                                <?php endif; ?>
                                                <?php if ($scan['check_out_time']): ?>
                                                    <span class="badge bg-warning">OUT <?= date('h:i A', strtotime($scan['check_out_time'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="mt-4 text-center">
                    <small class="text-muted">
                        <i class="bi bi-shield-check"></i> Secure Attendance System | DOIT Faculty Portal
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-focus on scan input
        document.addEventListener('DOMContentLoaded', function() {
            const scanInput = document.querySelector('input[name="scan_id"]');
            scanInput.focus();
            
            // Auto-clear and re-focus after successful scan
            const form = document.getElementById('scanForm');
            form.addEventListener('submit', function() {
                setTimeout(() => {
                    scanInput.value = '';
                    scanInput.focus();
                }, 3000);
            });
            
            // Handle RFID scanner input (usually sends Enter key)
            scanInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    form.submit();
                }
            });
        });
        
        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            const dateString = now.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            document.getElementById('currentTime').innerHTML = 
                timeString + '<br><small>' + dateString + '</small>';
        }
        
        updateTime();
        setInterval(updateTime, 1000);
        
        // Clear form function
        function clearForm() {
            document.querySelector('input[name="scan_id"]').value = '';
            document.querySelector('input[name="scan_id"]').focus();
        }
        
        // Auto-refresh page every 5 minutes to keep session fresh
        setTimeout(() => {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>
