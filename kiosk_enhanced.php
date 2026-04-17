<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Kiosk session management
session_start();

// Initialize kiosk session if not exists
if (!isset($_SESSION['kiosk_session'])) {
    $_SESSION['kiosk_session'] = [
        'started' => date('Y-m-d H:i:s'),
        'last_activity' => time(),
        'scan_count' => 0,
        'location' => 'Main Kiosk'
    ];
}

// Update last activity
$_SESSION['kiosk_session']['last_activity'] = time();

// Auto-logout after 30 minutes of inactivity
if (time() - $_SESSION['kiosk_session']['last_activity'] > 1800) {
    session_destroy();
    header('Location: kiosk_enhanced.php');
    exit;
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

// Get system stats for kiosk dashboard
$stats = [
    'total_faculty' => 0,
    'checked_in_today' => 0,
    'pending_checkout' => 0
];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'faculty'");
    $stmt->execute();
    $stats['total_faculty'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND check_in_method = 'kiosk'");
    $stmt->execute();
    $stats['checked_in_today'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND check_in_time IS NOT NULL AND check_out_time IS NULL AND check_in_method = 'kiosk'");
    $stmt->execute();
    $stats['pending_checkout'] = $stmt->fetchColumn();
} catch (Exception $e) {
    // Silent fail
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
        .kiosk-main {
            display: flex;
            gap: 20px;
            max-width: 1200px;
            width: 100%;
        }
        .kiosk-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
        }
        .scan-area {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            border: 3px dashed #6c757d;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .scan-area.scanning {
            border-color: #007bff;
            background: linear-gradient(45deg, #e3f2fd, #bbdefb);
            animation: scanning 1s ease-in-out;
        }
        .scan-area.success {
            border-color: #28a745;
            background: linear-gradient(45deg, #d4edda, #c3e6cb);
            animation: successPulse 0.5s ease;
        }
        .scan-area.error {
            border-color: #dc3545;
            background: linear-gradient(45deg, #f8d7da, #f5c6cb);
            animation: errorShake 0.5s ease;
        }
        @keyframes scanning {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        @keyframes errorShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
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
        .kiosk-header {
            background: linear-gradient(135deg, #800000, #b71c1c);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
        .stats-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin-bottom: 15px;
            border-left: 4px solid #800000;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #800000;
        }
        .recent-scans {
            max-height: 400px;
            overflow-y: auto;
        }
        .time-display {
            font-size: 2.5rem;
            font-weight: bold;
            color: #800000;
            text-align: center;
            margin: 20px 0;
        }
        .auto-focus {
            outline: none;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            font-size: 1.2rem;
            padding: 15px;
        }
        .auto-focus:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        .scan-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            display: none;
        }
        .scan-result.success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid #28a745;
        }
        .scan-result.error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
        }
        .loading-spinner {
            display: none;
            margin: 20px 0;
        }
        .session-info {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.9);
            padding: 10px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        @media (max-width: 768px) {
            .kiosk-main {
                flex-direction: column;
            }
            .time-display {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="session-info">
        <i class="bi bi-clock"></i> Session: <?= date('h:i A', $_SESSION['kiosk_session']['started']) ?> | 
        Scans: <?= $_SESSION['kiosk_session']['scan_count'] ?>
    </div>

    <div class="kiosk-container">
        <div class="kiosk-main">
            <!-- Main Scanner -->
            <div class="kiosk-card flex-grow-1">
                <div class="kiosk-header">
                    <h1><i class="bi bi-qr-code-scan"></i> Attendance Kiosk</h1>
                    <p class="mb-0">Scan your ID to check in or out</p>
                </div>
                
                <div class="p-4">
                    <div class="time-display" id="currentTime"></div>
                    
                    <div class="scan-area" id="scanArea">
                        <i class="bi bi-upc-scan scan-icon"></i>
                        <h4>Ready to Scan</h4>
                        <p class="text-muted">Place your ID card near the scanner</p>
                        <input type="text" 
                               name="scan_id" 
                               id="scanInput"
                               class="form-control form-control-lg auto-focus" 
                               placeholder="ID will auto-scan..." 
                               autocomplete="off">
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle"></i> System will automatically process scanned IDs
                        </small>
                    </div>
                    
                    <div class="loading-spinner text-center" id="loadingSpinner">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Processing...</span>
                        </div>
                        <p class="mt-2">Processing scan...</p>
                    </div>
                    
                    <div class="scan-result" id="scanResult"></div>
                    
                    <div class="mt-3 text-center">
                        <button type="button" class="btn btn-primary btn-lg" onclick="manualScan()">
                            <i class="bi bi-arrow-clockwise"></i> Manual Scan
                        </button>
                        <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="clearForm()">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <small class="text-muted">
                            <i class="bi bi-shield-check"></i> Secure Attendance System | DOIT Faculty Portal
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Side Panel -->
            <div class="d-flex flex-column" style="width: 350px;">
                <!-- Stats -->
                <div class="stats-card">
                    <h5><i class="bi bi-graph-up"></i> Today's Stats</h5>
                    <div class="row mt-3">
                        <div class="col-4">
                            <div class="stats-number"><?= $stats['total_faculty'] ?></div>
                            <small>Total Faculty</small>
                        </div>
                        <div class="col-4">
                            <div class="stats-number"><?= $stats['checked_in_today'] ?></div>
                            <small>Checked In</small>
                        </div>
                        <div class="col-4">
                            <div class="stats-number"><?= $stats['pending_checkout'] ?></div>
                            <small>Pending</small>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="kiosk-card flex-grow-1">
                    <div class="card-header bg-white">
                        <h5><i class="bi bi-clock-history"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body p-2">
                        <div class="recent-scans">
                            <?php if (!empty($recent_scans)): ?>
                                <div class="list-group">
                                    <?php foreach ($recent_scans as $scan): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars(substr($scan['first_name'] . ' ' . $scan['last_name'], 0, 15)) ?></strong>
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
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-1"></i>
                                    <p>No activity yet today</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-focus on scan input
        document.addEventListener('DOMContentLoaded', function() {
            const scanInput = document.getElementById('scanInput');
            const scanArea = document.getElementById('scanArea');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const scanResult = document.getElementById('scanResult');
            
            scanInput.focus();
            
            // Handle RFID scanner input (usually sends Enter key)
            scanInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && this.value.trim()) {
                    processScan(this.value.trim());
                }
            });
            
            // Auto-clear and re-focus after scan
            function resetForm() {
                scanInput.value = '';
                scanInput.focus();
                scanArea.className = 'scan-area';
                scanResult.style.display = 'none';
                loadingSpinner.style.display = 'none';
            }
            
            // Process scan with AJAX
            function processScan(scanId) {
                if (!scanId.trim()) return;
                
                // Show loading
                scanArea.className = 'scan-area scanning';
                loadingSpinner.style.display = 'block';
                scanResult.style.display = 'none';
                
                // Send AJAX request
                fetch('actions/kiosk_scan.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'scan_id=' + encodeURIComponent(scanId)
                })
                .then(response => response.json())
                .then(data => {
                    loadingSpinner.style.display = 'none';
                    
                    if (data.success) {
                        scanArea.className = 'scan-area success';
                        showResult(data.message, 'success');
                        updateSessionCount();
                        setTimeout(() => {
                            resetForm();
                            location.reload(); // Refresh to update recent activity
                        }, 3000);
                    } else {
                        scanArea.className = 'scan-area error';
                        showResult(data.message, 'error');
                        setTimeout(resetForm, 3000);
                    }
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    scanArea.className = 'scan-area error';
                    showResult('System error. Please try again.', 'error');
                    setTimeout(resetForm, 3000);
                    console.error('Scan error:', error);
                });
            }
            
            // Show result message
            function showResult(message, type) {
                scanResult.className = 'scan-result ' + type;
                scanResult.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} fs-4 me-3"></i>
                        <div>
                            <strong>${type === 'success' ? 'Success' : 'Error'}</strong><br>
                            <span>${message}</span>
                        </div>
                    </div>
                `;
                scanResult.style.display = 'block';
            }
            
            // Manual scan function
            window.manualScan = function() {
                const scanId = scanInput.value.trim();
                if (scanId) {
                    processScan(scanId);
                } else {
                    scanArea.className = 'scan-area error';
                    showResult('Please enter or scan an ID first', 'error');
                    setTimeout(() => {
                        scanArea.className = 'scan-area';
                        scanResult.style.display = 'none';
                    }, 2000);
                }
            };
            
            // Clear form function
            window.clearForm = function() {
                resetForm();
            };
            
            // Update session count (client-side only)
            function updateSessionCount() {
                const sessionInfo = document.querySelector('.session-info');
                const currentCount = parseInt(sessionInfo.textContent.match(/Scans: (\d+)/)[1]);
                sessionInfo.innerHTML = sessionInfo.innerHTML.replace(/Scans: \d+/, `Scans: ${currentCount + 1}`);
            }
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
        
        // Auto-refresh recent activity every 30 seconds
        setInterval(() => {
            // Only refresh if not in the middle of scanning
            if (document.getElementById('loadingSpinner').style.display !== 'block') {
                fetch(window.location.href + '?refresh_activity=1')
                    .then(() => location.reload());
            }
        }, 30000);
        
        // Prevent page from being cached
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>
</html>
