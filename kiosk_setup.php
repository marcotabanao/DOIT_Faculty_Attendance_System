<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Setup | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3><i class="bi bi-qr-code-scan"></i> Attendance Kiosk Setup</h3>
                    </div>
                    <div class="card-body">
                        <h4>Setup Instructions</h4>
                        <ol>
                            <li><strong>Run Database Migration:</strong><br>
                                Execute the migration script to add kiosk functionality:<br>
                                <code>php database/migrations/add_kiosk_columns.php</code></li>
                            
                            <li><strong>Configure RFID Scanner:</strong><br>
                                Connect your RFID/ID scanner device to the kiosk computer</li>
                            
                            <li><strong>Add RFID Cards to Faculty:</strong><br>
                                Update faculty records with their RFID card numbers</li>
                            
                            <li><strong>Test the Kiosk:</strong><br>
                                Access the kiosk interface and test scanning functionality</li>
                        </ol>
                        
                        <hr>
                        
                        <h4>Quick Test</h4>
                        <p>Test the kiosk system with existing faculty data:</p>
                        
                        <?php
                        // Get sample faculty for testing
                        $test_faculty = [];
                        try {
                            $stmt = $pdo->prepare("SELECT id, employee_id, first_name, last_name FROM users WHERE role = 'faculty' LIMIT 5");
                            $stmt->execute();
                            $test_faculty = $stmt->fetchAll();
                        } catch (Exception $e) {
                            echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                        
                        if (!empty($test_faculty)):
                        ?>
                            <div class="list-group">
                                <?php foreach ($test_faculty as $faculty): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></strong><br>
                                                <small class="text-muted">ID: <?= htmlspecialchars($faculty['employee_id']) ?></small>
                                            </div>
                                            <div>
                                                <a href="kiosk.php?test_id=<?= htmlspecialchars($faculty['employee_id']) ?>" class="btn btn-sm btn-primary" target="_blank">
                                                    <i class="bi bi-box-arrow-in-right"></i> Test Check-in
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> No faculty records found. Please add faculty members first.
                            </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <h4>Database Status</h4>
                        <?php
                        $db_status = [];
                        
                        // Check if kiosk columns exist
                        try {
                            $stmt = $pdo->prepare("DESCRIBE attendance");
                            $stmt->execute();
                            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            $db_status['attendance_columns'] = in_array('check_in_method', $columns) && in_array('check_out_method', $columns);
                        } catch (Exception $e) {
                            $db_status['attendance_columns'] = false;
                        }
                        
                        // Check if rfid_card column exists
                        try {
                            $stmt = $pdo->prepare("DESCRIBE users");
                            $stmt->execute();
                            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            $db_status['users_rfid'] = in_array('rfid_card', $columns);
                        } catch (Exception $e) {
                            $db_status['users_rfid'] = false;
                        }
                        
                        // Check if kiosk_logs table exists
                        try {
                            $stmt = $pdo->prepare("SHOW TABLES LIKE 'kiosk_logs'");
                            $stmt->execute();
                            $db_status['kiosk_logs'] = $stmt->rowCount() > 0;
                        } catch (Exception $e) {
                            $db_status['kiosk_logs'] = false;
                        }
                        ?>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="bi bi-database-check fs-1 <?= $db_status['attendance_columns'] ? 'text-success' : 'text-danger' ?>"></i>
                                        <h5 class="mt-2">Attendance Columns</h5>
                                        <span class="badge bg-<?= $db_status['attendance_columns'] ? 'success' : 'danger' ?>">
                                            <?= $db_status['attendance_columns'] ? 'Ready' : 'Missing' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="bi bi-credit-card fs-1 <?= $db_status['users_rfid'] ? 'text-success' : 'text-danger' ?>"></i>
                                        <h5 class="mt-2">RFID Column</h5>
                                        <span class="badge bg-<?= $db_status['users_rfid'] ? 'success' : 'danger' ?>">
                                            <?= $db_status['users_rfid'] ? 'Ready' : 'Missing' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <i class="bi bi-journal-text fs-1 <?= $db_status['kiosk_logs'] ? 'text-success' : 'text-danger' ?>"></i>
                                        <h5 class="mt-2">Kiosk Logs</h5>
                                        <span class="badge bg-<?= $db_status['kiosk_logs'] ? 'success' : 'danger' ?>">
                                            <?= $db_status['kiosk_logs'] ? 'Ready' : 'Missing' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!$db_status['attendance_columns'] || !$db_status['users_rfid'] || !$db_status['kiosk_logs']): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <strong>Setup Required:</strong> Please run the migration script to complete the database setup.
                            </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <h4>Kiosk Links</h4>
                        <div class="d-grid gap-2 d-md-flex">
                            <a href="kiosk.php" class="btn btn-primary" target="_blank">
                                <i class="bi bi-upc-scan"></i> Basic Kiosk
                            </a>
                            <a href="kiosk_enhanced.php" class="btn btn-success" target="_blank">
                                <i class="bi bi-upc-scan"></i> Enhanced Kiosk
                            </a>
                            <a href="admin/faculty.php" class="btn btn-info">
                                <i class="bi bi-people"></i> Manage Faculty RFID
                            </a>
                        </div>
                        
                        <hr>
                        
                        <h4>RFID Scanner Configuration</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Typical Scanner Settings:</h6>
                                <ul>
                                    <li>Output: Keyboard emulation (HID)</li>
                                    <li>Format: Enter key after scan</li>
                                    <li>Baud rate: 9600 (if serial)</li>
                                    <li>No special prefixes/suffixes</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Test Procedure:</h6>
                                <ol>
                                    <li>Open kiosk interface</li>
                                    <li>Scan test RFID card</li>
                                    <li>Verify ID appears in input field</li>
                                    <li>Check attendance record created</li>
                                </ol>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
