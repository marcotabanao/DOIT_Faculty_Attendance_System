<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$error = '';
$last_scan = null;
$scan_status = ''; // 'valid' or 'invalid'
$last_scan_type = ''; // 'check-in' or 'check-out'

if (!isset($_SESSION['kiosk_last_scan_time'])) {
    $_SESSION['kiosk_last_scan_time'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_id'])) {
    $scan_id = trim($_POST['scan_id']);
    $scan_token = $_POST['scan_token'] ?? '';
    
    // Only process if we actually have a scan ID (not empty or just whitespace)
    if (!empty($scan_id) && strlen($scan_id) >= 3 && !preg_match('/^\s*$/', $scan_id)) {
        // Additional validation to prevent blank submissions
        if (empty($scan_token)) {
            $error = "Invalid request.";
            $scan_status = 'invalid';
        } else {
        $parts = explode('_', $scan_token);
        if (count($parts) < 2 || !is_numeric($parts[1])) {
            $error = "Invalid token.";
            $scan_status = 'invalid';
        } else {
            $token_time = (int)$parts[1];
            $current_time = time();
            
            if ($current_time - $token_time > 30) {
                $error = "Scan expired. Please scan again.";
                $scan_status = 'invalid';
            } elseif ($token_time <= $_SESSION['kiosk_last_scan_time']) {
                $error = "Duplicate scan ignored.";
                $scan_status = 'invalid';
            } else {
                $_SESSION['kiosk_last_scan_time'] = $token_time;
                
                try {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE (employee_id = ? OR rfid_card = ?) AND role = 'faculty'");
                    $stmt->execute([$scan_id, $scan_id]);
                    $faculty = $stmt->fetch();
                    
                    if (!$faculty) {
                        $error = "ID not recognized. No account found.";
                        $scan_status = 'invalid';
                    } else {
                        $scan_status = 'valid';
                        $today = date('Y-m-d');
                        $time = date('H:i:s');
                        
                        // Check for any open session (check-in without check-out)
                        $openStmt = $pdo->prepare("
                            SELECT * FROM attendance 
                            WHERE faculty_id = ? AND date = ? 
                            AND check_in_time IS NOT NULL 
                            AND (check_out_time IS NULL OR check_out_time = '')
                            ORDER BY check_in_time DESC LIMIT 1
                        ");
                        $openStmt->execute([$faculty['id'], $today]);
                        $open_session = $openStmt->fetch();
                        
                        if ($open_session) {
                            // Check-out
                            $update = $pdo->prepare("UPDATE attendance SET check_out_time = ?, check_out_method = 'kiosk' WHERE id = ?");
                            if ($update->execute([$time, $open_session['id']])) {
                                logActivity($pdo, 'LOGOUT_KIOSK', 'attendance', $open_session['id'], "Logged out via kiosk at $time");
                                $message = "Check-out successful! Goodbye, " . htmlspecialchars($faculty['first_name']);
                                $last_scan = $faculty;
                                $last_scan_type = 'check-out';
                            } else {
                                $error = "Check-out failed.";
                                $scan_status = 'invalid';
                            }
                        } else {
                            // Check-in
                            $day = date('N');
                            $schStmt = $pdo->prepare("SELECT time_in FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND semester_id = (SELECT id FROM semesters WHERE is_active = 1 LIMIT 1)");
                            $schStmt->execute([$faculty['id'], $day]);
                            $schedule = $schStmt->fetch();
                            
                            $status = 'present';
                            $late_minutes = 0;
                            if ($schedule && $time > $schedule['time_in']) {
                                $status = 'late';
                                $late_minutes = (strtotime($time) - strtotime($schedule['time_in'])) / 60;
                            }
                            
                            $insert = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, status, late_minutes, check_in_method) VALUES (?, ?, ?, ?, ?, 'kiosk')");
                            if ($insert->execute([$faculty['id'], $today, $time, $status, $late_minutes])) {
                                logActivity($pdo, 'LOGIN_KIOSK', 'attendance', $pdo->lastInsertId(), "Logged in via kiosk at $time");
                                $message = "Check-in successful! Welcome, " . htmlspecialchars($faculty['first_name']);
                                $last_scan = $faculty;
                                $last_scan_type = 'check-in';
                            } else {
                                $error = "Check-in failed.";
                                $scan_status = 'invalid';
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = "System error. Contact admin.";
                    $scan_status = 'invalid';
                    error_log("Kiosk error: " . $e->getMessage());
                }
            }
        }
        }
    } else {
        // Empty or invalid scan ID - don't show error for page loads
        // Only show error if this was an actual scan attempt
        if (isset($_POST['scan_id']) && strlen(trim($_POST['scan_id'])) > 0) {
            $error = "Invalid scan. Please scan a valid ID.";
            $scan_status = 'invalid';
        }
    }
}

// Get recent activity
$recent_scans = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.employee_id, a.check_in_time, a.check_out_time, a.date, a.status
        FROM attendance a
        JOIN users u ON a.faculty_id = u.id
        WHERE a.date = CURDATE() AND (a.check_in_method = 'kiosk' OR a.check_out_method = 'kiosk')
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_scans = $stmt->fetchAll();
} catch (Exception $e) {}
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
        body { background: linear-gradient(135deg, #800000 0%, #5a0000 100%); min-height: 100vh; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .kiosk-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .kiosk-card { background: rgba(255,255,255,0.95); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); max-width: 500px; width: 100%; }
        .scan-area { background: linear-gradient(45deg,#f8f9fa,#e9ecef); border: 3px dashed #800000; border-radius: 15px; padding: 40px; text-align: center; transition: 0.3s; }
        .scan-area.valid { border-color: #28a745; background: linear-gradient(45deg,#d4edda,#c3e6cb); }
        .scan-area.invalid { border-color: #dc3545; background: linear-gradient(45deg,#f8d7da,#f5c6cb); }
        .scan-icon { font-size: 4rem; color: #800000; margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
        .kiosk-header { background: linear-gradient(135deg,#800000,#b71c1c); color: white; border-radius: 20px 20px 0 0; padding: 30px; text-align: center; }
        .faculty-info { background: rgba(23,162,184,0.1); border-left: 4px solid #17a2b8; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .time-display { font-size: 2rem; font-weight: bold; color: #800000; text-align: center; margin: 20px 0; }
        .recent-scans { max-height: 300px; overflow-y: auto; }
        .readonly-input {
            background-color: #e9ecef;
            cursor: not-allowed;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: #495057;
        }
        .status-message { margin-top: 15px; padding: 15px; border-radius: 10px; text-align: center; font-weight: bold; font-size: 1.1rem; }
        .status-valid { background: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
        .status-invalid { background: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
        .status-info { background: #d1ecf1; color: #0c5460; border: 2px solid #bee5eb; }
    </style>
</head>
<body>
<div class="kiosk-container">
    <div class="kiosk-card">
        <div class="kiosk-header">
            <h1><i class="bi bi-upc-scan"></i> Attendance Kiosk</h1>
            <p>Tap your ID card on the scanner</p>
        </div>
        <div class="p-4">
            <div class="time-display" id="currentTime"></div>
            
            <!-- Dynamic status display -->
            <div id="statusDisplay" class="status-message" style="display:none;"></div>
            
            <?php if ($message): ?>
                <div class="alert alert-success" style="display:block;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger" style="display:block;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($last_scan): ?>
                <div class="faculty-info">
                    <h5><i class="bi bi-person-check"></i> Last Scan</h5>
                    <p><strong><?= htmlspecialchars($last_scan['first_name'] . ' ' . $last_scan['last_name']) ?></strong><br>
                    ID: <?= htmlspecialchars($last_scan['employee_id']) ?><br>
                    Time: <?= date('h:i A') ?><br>
                    Status: <?php 
                        if ($last_scan_type === 'check-in') {
                            echo '<span class="badge bg-success">Check-in</span>';
                        } elseif ($last_scan_type === 'check-out') {
                            echo '<span class="badge bg-warning">Check-out</span>';
                        } else {
                            echo '<span class="badge bg-secondary">Unknown</span>';
                        }
                    ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="scanForm" name="scanForm">
                <input type="hidden" name="scan_token" id="scanToken" value="">
                <div class="scan-area" id="scanArea">
                    <i class="bi bi-upc-scan scan-icon"></i>
                    <h4>RFID Scanner Ready</h4>
                    <p class="text-muted">Tap your card – typing is disabled</p>
                    <input type="text" name="scan_id" id="scanInput" class="form-control form-control-lg" placeholder="Waiting for scan..." autocomplete="off" style="position: absolute; left: -9999px; opacity: 0; pointer-events: none;" tabindex="-1">
                    <input type="text" id="scanDisplay" class="form-control form-control-lg" placeholder="Waiting for scan..." readonly style="text-align:center; font-size:1.2rem; font-weight:bold; background-color:#f8f9fa; cursor:not-allowed;" tabindex="-1">
                    <small class="text-muted mt-2 d-block"><i class="bi bi-info-circle"></i> Only RFID/barcode scanning is allowed</small>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary btn-lg" onclick="clearForm()">Reset</button>
                </div>
            </form>
            
            <?php if (!empty($recent_scans)): ?>
                <div class="mt-4">
                    <h5>Recent Activity</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th>Faculty Name</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_scans as $scan): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($scan['first_name'] . ' ' . $scan['last_name']) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($scan['check_in_time'] && !$scan['check_out_time']): ?>
                                                <!-- Show check-in time if not checked out yet -->
                                                <span class="badge bg-success"><?= date('h:i A', strtotime($scan['check_in_time'])) ?></span>
                                            <?php elseif ($scan['check_in_time'] && $scan['check_out_time']): ?>
                                                <!-- Show check-out time if checked out -->
                                                <span class="badge bg-warning"><?= date('h:i A', strtotime($scan['check_out_time'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($scan['check_in_time'] && !$scan['check_out_time']) {
                                                $status = 'Checked In';
                                                $badgeClass = 'bg-success';
                                            } elseif ($scan['check_in_time'] && $scan['check_out_time']) {
                                                $status = 'Checked Out';
                                                $badgeClass = 'bg-warning';
                                            } else {
                                                $status = 'Unknown';
                                                $badgeClass = 'bg-secondary';
                                            }
                                            ?>
                                            <span class="badge <?= $badgeClass ?>"><?= $status ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="mt-4 text-center">
                <a href="login.php" class="btn btn-outline-secondary btn-sm">Admin Login</a>
                <a href="kiosk_dtr.php" class="btn btn-info btn-sm">Print DTR</a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let isSubmitting = false;
    let initialLoad = true;
    let timeoutId = null;

    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('scanInput'); // Hidden input for scanner
        const display = document.getElementById('scanDisplay'); // Visible display field
        const form = document.getElementById('scanForm');
        input.value = '';
        display.value = '';
        generateToken();
        setTimeout(() => { initialLoad = false; }, 1000);
        
        // Focus on hidden input field to capture scanner input
        input.focus();
        
        // Scanner input detection
        let scanTimeout = null;
        let lastScanValue = '';
        
        // Monitor hidden input for scanner data
        input.addEventListener('input', function() {
            if (initialLoad) return;
            
            const val = this.value.trim();
            
            // Don't process if value is empty or too short
            if (val.length < 3) {
                display.value = val; // Still show in display field
                return;
            }
            
            display.value = val; // Show in display field
            
            // Clear any existing timeout
            if (scanTimeout) {
                clearTimeout(scanTimeout);
            }
            
            // Additional validation to prevent blank submissions
            if (val.length >= 3 && val !== '' && !val.match(/^\s*$/)) {
                showStatus('Processing...', 'info');
                
                // Wait a moment to ensure full scan is captured
                scanTimeout = setTimeout(() => {
                    const currentValue = input.value.trim();
                    // Double-check value is still valid before submitting
                    if (currentValue === val && currentValue.length >= 3 && !isSubmitting && !currentValue.match(/^\s*$/)) {
                        generateToken();
                        submitScan();
                    }
                }, 200);
            }
        });
        
        // Handle paste events (some scanners use paste)
        input.addEventListener('paste', function(e) {
            if (initialLoad) return;
            
            setTimeout(() => {
                const val = this.value.trim();
                display.value = val; // Show in display field
                if (val.length >= 3 && !isSubmitting) {
                    generateToken();
                    showStatus('Processing...', 'info');
                    submitScan();
                }
            }, 100);
        });
        
        // Handle Enter key (many scanners send Enter after scan)
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && this.value.trim().length >= 3 && !initialLoad && !isSubmitting) {
                e.preventDefault();
                generateToken();
                submitScan();
            }
        });
        
        // Re-focus on hidden input when clicking on display
        display.addEventListener('click', function() {
            input.focus();
        });
        
        // Keep focus on hidden input
        setInterval(() => {
            if (document.activeElement !== input) {
                input.focus();
            }
        }, 1000);
    });
    
    function generateToken() {
        document.getElementById('scanToken').value = 'scan_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    function showStatus(message, type) {
        const statusDiv = document.getElementById('statusDisplay');
        const scanArea = document.getElementById('scanArea');
        statusDiv.innerHTML = message;
        statusDiv.style.display = 'block';
        statusDiv.className = 'status-message';
        if (type === 'valid') {
            statusDiv.classList.add('status-valid');
            scanArea.classList.add('valid');
            setTimeout(() => {
                scanArea.classList.remove('valid');
            }, 2000);
        } else if (type === 'invalid') {
            statusDiv.classList.add('status-invalid');
            scanArea.classList.add('invalid');
            setTimeout(() => {
                scanArea.classList.remove('invalid');
            }, 2000);
        } else {
            statusDiv.classList.add('status-info');
        }
        if (timeoutId) clearTimeout(timeoutId);
        timeoutId = setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 3000);
    }
    
    function submitScan() {
        if (isSubmitting) return;
        isSubmitting = true;
        const form = document.getElementById('scanForm');
        const formData = new FormData(form);
        
        // Show processing status immediately
        showStatus('Processing scan...', 'info');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            // Parse the HTML response to extract messages
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Find success or error messages
            const successAlert = doc.querySelector('.alert-success');
            const errorAlert = doc.querySelector('.alert-danger');
            const facultyInfo = doc.querySelector('.faculty-info');
            
            // Update the current page with the response
            if (successAlert) {
                showStatus(successAlert.textContent.trim(), 'valid');
                // Update last scan info if available
                if (facultyInfo) {
                    // Update or add faculty display
                    const existingInfo = document.querySelector('.faculty-info');
                    if (existingInfo) {
                        existingInfo.innerHTML = facultyInfo.innerHTML;
                    } else {
                        const scanArea = document.getElementById('scanArea');
                        scanArea.insertAdjacentHTML('afterend', facultyInfo.outerHTML);
                    }
                }
                // Update recent activity
                updateRecentActivity(doc);
            } else if (errorAlert) {
                showStatus(errorAlert.textContent.trim(), 'invalid');
            } else {
                showStatus('Scan processed but no response received', 'invalid');
            }
            
            // Reset for next scan
            setTimeout(() => {
                resetForNextScan();
            }, 2000);
        })
        .catch(error => { 
            console.error('Scan submission error:', error);
            showStatus('Connection error. Please try again.', 'invalid');
            setTimeout(() => {
                resetForNextScan();
            }, 2000);
        });
    }
    
    function resetForNextScan() {
        isSubmitting = false;
        const input = document.getElementById('scanInput');
        const display = document.getElementById('scanDisplay');
        
        // Clear values
        input.value = '';
        display.value = '';
        
        // Generate new token and focus
        generateToken();
        input.focus();
        
        // Clear status after delay
        setTimeout(() => {
            const statusDiv = document.getElementById('statusDisplay');
            if (statusDiv) {
                statusDiv.style.display = 'none';
            }
            const scanArea = document.getElementById('scanArea');
            if (scanArea) {
                scanArea.classList.remove('valid', 'invalid');
            }
        }, 1000);
    }
    
    function clearForm() {
        const input = document.getElementById('scanInput');
        const display = document.getElementById('scanDisplay');
        const statusDiv = document.getElementById('statusDisplay');
        const scanArea = document.getElementById('scanArea');
        
        // Clear values
        input.value = '';
        display.value = '';
        
        // Focus and generate token
        input.focus();
        generateToken();
        
        // Clear status
        if (statusDiv) {
            statusDiv.style.display = 'none';
        }
        if (scanArea) {
            scanArea.classList.remove('valid', 'invalid');
        }
    }
    
    function updateRecentActivity(doc) {
        // Update recent activity if available
        const recentActivitySection = doc.querySelector('.table-responsive');
        if (recentActivitySection) {
            const currentSection = document.querySelector('.table-responsive');
            if (currentSection) {
                currentSection.innerHTML = recentActivitySection.innerHTML;
            }
        }
        
        // Also update the entire recent activity section if needed
        const recentActivityDiv = doc.querySelector('.mt-4');
        if (recentActivityDiv && recentActivityDiv.querySelector('table')) {
            const currentDiv = document.querySelector('.mt-4');
            if (currentDiv && currentDiv.querySelector('table')) {
                currentDiv.innerHTML = recentActivityDiv.innerHTML;
            }
        }
    }
    
    function updateTime() {
        const now = new Date();
        const currentTimeElement = document.getElementById('currentTime');
        if (currentTimeElement) {
            currentTimeElement.innerHTML = now.toLocaleTimeString() + '<br><small>' + now.toLocaleDateString(undefined, {weekday:'long', year:'numeric', month:'long', day:'numeric'}) + '</small>';
        }
    }
    updateTime();
    setInterval(updateTime, 1000);
</script>
</body>
</html>