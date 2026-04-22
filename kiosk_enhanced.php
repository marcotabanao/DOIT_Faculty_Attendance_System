<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Initialize variables
$message = '';
$error = '';
$last_scan = null;
$today_attendance = null;
$recent_scans = [];

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
                        
                        $response = [
                            'success' => true,
                            'message' => "Session closed! Goodbye, " . htmlspecialchars($faculty['first_name']),
                            'faculty' => [
                                'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                                'employee_id' => $faculty['employee_id']
                            ],
                            'action' => 'checkout',
                            'time' => date('h:i A'),
                            'session_number' => countRecentScans($faculty['id'], $pdo) + 1
                        ];
                    } else {
                        $response = [
                            'success' => false,
                            'message' => "Failed to close session. Please try again."
                        ];
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
                        
                        $response = [
                            'success' => true,
                            'message' => "Session started! Welcome, " . htmlspecialchars($faculty['first_name']),
                            'faculty' => [
                                'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                                'employee_id' => $faculty['employee_id'],
                                'status' => $status,
                                'late_minutes' => $late_minutes
                            ],
                            'action' => 'checkin',
                            'time' => date('h:i A'),
                            'session_number' => countRecentScans($faculty['id'], $pdo) + 1
                        ];
                    } else {
                        $response = [
                            'success' => false,
                            'message' => "Failed to start session. Please try again."
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'message' => $e->getMessage()
            ];
            error_log("Kiosk scan error: " . $e->getMessage());
        }
    }
    
    // Return JSON response for AJAX requests
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Get recent kiosk activity for display
function getRecentScans($faculty_id, $pdo, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.first_name, u.last_name, u.employee_id 
        FROM attendance a 
        JOIN users u ON a.faculty_id = u.id 
        WHERE a.faculty_id = ? 
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$faculty_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get today's attendance summary
function getTodaySummary($faculty_id, $pdo) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
            SUM(late_minutes) as total_late_minutes
        FROM attendance 
        WHERE faculty_id = ? AND date = ?
    ");
    $stmt->execute([$faculty_id, $today]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Kiosk | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        :root {
            --primary-color: #800000;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        body {
            background: linear-gradient(135deg, #800000 0%, #5c0000 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .enhanced-kiosk {
            display: flex;
            min-height: 100vh;
        }
        
        .kiosk-main {
            flex: 1;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .kiosk-sidebar {
            flex: 0 0 400px;
            background: linear-gradient(180deg, var(--dark-color) 0%, #2c1810 100%);
            color: white;
            padding: 30px 20px;
            overflow-y: auto;
        }
        
        .kiosk-content {
            flex: 1;
            padding: 40px;
        }
        
        .scan-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 30px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .scan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }
        
        .scan-icon {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            display: block;
            text-align: center;
        }
        
        .scan-input {
            position: relative;
            margin-bottom: 20px;
        }
        
        .scan-input input {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--primary-color);
            border-radius: 10px;
            font-size: 1.2rem;
            background: white;
            transition: all 0.3s ease;
        }
        
        .scan-input input:focus {
            outline: none;
            border-color: var(--success-color);
            box-shadow: 0 0 0 5px rgba(40, 167, 69, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .recent-scans {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .scan-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--info-color);
        }
        
        .scan-time {
            color: var(--secondary-color);
            font-size: 0.8rem;
        }
        
        .session-badge {
            background: var(--info-color);
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .success-animation {
            animation: slideInRight 0.5s ease-out;
        }
        
        .error-animation {
            animation: slideInRight 0.5s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @media (max-width: 768px) {
            .enhanced-kiosk {
                flex-direction: column;
            }
            
            .kiosk-main {
                border-right: none;
            }
            
            .kiosk-sidebar {
                flex: 0 0 auto;
                width: 100%;
                max-height: 200px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="enhanced-kiosk">
    <div class="kiosk-main">
        <div class="kiosk-content">
            <div class="scan-card">
                <div class="scan-icon">
                    <i class="bi bi-upc-scan"></i>
                </div>
                <h2 class="text-center mb-4">Enhanced Kiosk</h2>
                
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
                
                <form method="POST" id="scanForm" class="scan-input">
                    <input type="text" 
                           name="scan_id" 
                           class="form-control form-control-lg" 
                           placeholder="RFID will auto-scan..." 
                           autocomplete="off"
                           required>
                </form>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= getTodayScansCount($pdo) ?></div>
                    <div class="stat-label">Today's Scans</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= getActiveUsersCount($pdo) ?></div>
                    <div class="stat-label">Active Sessions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= getTotalFacultyCount($pdo) ?></div>
                    <div class="stat-label">Total Faculty</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="kiosk-sidebar">
        <h4 class="mb-3"><i class="bi bi-clock-history"></i> Recent Activity</h4>
        
        <div class="recent-scans">
            <?php 
            // Get recent scans for display (demo data)
            $recent_scans = [
                [
                    'name' => 'John Doe',
                    'time' => '08:30 AM',
                    'action' => 'checkin',
                    'session_number' => 1
                ],
                [
                    'name' => 'Jane Smith',
                    'time' => '01:15 PM',
                    'action' => 'checkout',
                    'session_number' => 1
                ],
                [
                    'name' => 'Mike Johnson',
                    'time' => '02:45 PM',
                    'action' => 'checkin',
                    'session_number' => 2
                ]
            ];
            
            if (!empty($recent_scans)): ?>
                <?php foreach ($recent_scans as $scan): ?>
                    <div class="scan-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($scan['name']) ?></strong>
                                <div class="scan-time"><?= formatTime($scan['time']) ?></div>
                            </div>
                            <div class="session-badge">Session #<?= $scan['session_number'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-muted">
                    <i class="bi bi-inbox"></i> No recent activity
                </div>
            <?php endif; ?>
        </div>
        
        <div class="mt-auto">
            <h5><i class="bi bi-gear"></i> Kiosk Settings</h5>
            <div class="mb-3">
                <label class="form-label">Auto-refresh Interval</label>
                <select class="form-select" id="refreshInterval">
                    <option value="30000">5 minutes</option>
                    <option value="60000">10 minutes</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Sound Effects</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="soundEnabled" checked>
                    <label class="form-check-label" for="soundEnabled">Enable scan sounds</label>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <a href="login.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Login
                </a>
                <a href="kiosk_dtr.php" class="btn btn-info btn-sm">
                    <i class="bi bi-file-earmark-text"></i> DTR Generator
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const scanForm = document.getElementById('scanForm');
    const scanInput = document.querySelector('input[name="scan_id"]');
    
    // Auto-focus on scan input
    scanInput.focus();
    
    // Handle form submission
    scanForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(scanForm);
        const scanId = formData.get('scan_id');
        
        if (!scanId) {
            showError('Please scan a valid ID');
            return;
        }
        
        // Show loading state
        showLoading();
        
        // Send AJAX request
        fetch('', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                showSuccess(data.message, data.faculty, data.action, data.session_number);
                
                // Update recent scans
                updateRecentScans(data.faculty, data.action, data.time);
                
                // Update stats
                updateStats();
                
                // Clear form
                scanForm.reset();
                scanInput.focus();
                
                // Play sound if enabled
                playScanSound();
                
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            hideLoading();
            showError('Connection error. Please try again.');
        });
    });
    
    function showSuccess(message, faculty, action, sessionNumber) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show success-animation';
        alertDiv.innerHTML = `
            <i class="bi bi-check-circle-fill"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.querySelector('.kiosk-content');
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 3000);
    }
    
    function showError(message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show error-animation';
        alertDiv.innerHTML = `
            <i class="bi bi-exclamation-triangle-fill"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const container = document.querySelector('.kiosk-content');
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 5000);
    }
    
    function showLoading() {
        const scanCard = document.querySelector('.scan-card');
        scanCard.style.opacity = '0.7';
        scanCard.style.pointerEvents = 'none';
    }
    
    function hideLoading() {
        const scanCard = document.querySelector('.scan-card');
        scanCard.style.opacity = '1';
        scanCard.style.pointerEvents = 'auto';
    }
    
    function updateRecentScans(faculty, action, time) {
        // This would typically be updated via AJAX
        // For demo purposes, we'll use a simple approach
        const recentScans = [
            {
                name: faculty.first_name + ' ' + faculty.last_name,
                time: time,
                action: action,
                session_number: Math.floor(Math.random() * 100) + 1
            }
        ];
        
        const recentScansDiv = document.querySelector('.recent-scans');
        if (recentScansDiv) {
            recentScansDiv.innerHTML = recentScans.map(scan => `
                <div class="scan-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${scan.name}</strong>
                            <div class="scan-time">${formatTime(scan.time)}</div>
                        </div>
                        <div class="session-badge">Session #${scan.session_number}</div>
                    </div>
                </div>
            `).join('');
        }
    }
    
    function updateStats() {
        // Update statistics with animation
        const statNumbers = document.querySelectorAll('.stat-number');
        statNumbers.forEach(stat => {
            const currentValue = parseInt(stat.textContent);
            const targetValue = currentValue + 1;
            animateNumber(stat, currentValue, targetValue);
        });
    }
    
    function animateNumber(element, start, end) {
        const duration = 1000;
        const startTime = performance.now();
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const currentValue = start + (end - start) * progress;
            
            element.textContent = currentValue;
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    }
    
    function playScanSound() {
        const soundEnabled = document.getElementById('soundEnabled').checked;
        if (soundEnabled) {
            // Create a simple beep sound
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            oscillator.connect(audioContext.destination);
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            const gainNode = audioContext.createGain();
            gainNode.gain.value = 0.1;
            gainNode.connect(oscillator);
            gainNode.connect(audioContext.destination);
            
            oscillator.start();
            setTimeout(() => {
                oscillator.stop();
            }, 100);
        }
    }
    
    // Initialize auto-refresh
    let refreshInterval = 30000; // 5 minutes default
    
    document.getElementById('refreshInterval').addEventListener('change', function() {
        refreshInterval = parseInt(this.value);
        setAutoRefresh();
    });
    
    function setAutoRefresh() {
        setTimeout(() => {
            window.location.reload();
        }, refreshInterval);
    }
    
    // Helper functions for statistics
    function getTodayScansCount($pdo) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM attendance WHERE DATE(created_at) = CURDATE()");
        $stmt->execute();
        return $stmt->fetchColumn();
    }
    
    function getActiveUsersCount($pdo) {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT faculty_id) as count FROM attendance WHERE check_out_time IS NULL AND DATE(created_at) = CURDATE()");
        $stmt->execute();
        return $stmt->fetchColumn();
    }
    
    function getTotalFacultyCount($pdo) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'faculty'");
        $stmt->execute();
        return $stmt->fetchColumn();
    }
</script>
</body>
</html>
