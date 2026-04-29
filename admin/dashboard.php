<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

// Create $conn variable to match the database connection
$conn = $pdo;

// Handle AJAX requests for DTR updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'update_dtr') {
        $attendanceId = $_POST['attendance_id'] ?? 0;
        $userId = $_POST['user_id'] ?? 0;
        $date = $_POST['date'] ?? '';
        $updates = $_POST['updates'] ?? [];
        
        try {
            // Validate input
            if (!$attendanceId || !$userId || !$date) {
                throw new Exception('Invalid parameters');
            }
            
            // Build update query
            $setClause = [];
            $params = [];
            
            foreach ($updates as $field => $value) {
                if ($field === 'check_in_time' || $field === 'check_out_time') {
                    $setClause[] = "$field = ?";
                    $params[] = $value ?: null;
                } elseif ($field === 'status') {
                    $setClause[] = "status = ?";
                    $params[] = $value;
                } elseif ($field === 'remarks') {
                    $setClause[] = "remarks = ?";
                    $params[] = sanitizeInput($value);
                }
            }
            
            if (empty($setClause)) {
                throw new Exception('No valid fields to update');
            }
            
            $params[] = $attendanceId;
            
            $sql = "UPDATE attendance SET " . implode(', ', $setClause) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            // Log the activity
            logActivity($conn, 'UPDATE', 'attendance', $attendanceId, "Updated DTR record for user $userId on $date");
            
            echo json_encode([
                'success' => true,
                'message' => 'DTR record updated successfully'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error updating DTR record: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] === 'create_attendance') {
        $userId = $_POST['user_id'] ?? 0;
        $date = $_POST['date'] ?? '';
        
        try {
            // Validate input
            if (!$userId || !$date) {
                throw new Exception('Invalid parameters');
            }
            
            // Check if attendance record already exists
            $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE faculty_id = ? AND date = ?");
            $checkStmt->execute([$userId, $date]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                throw new Exception('Attendance record already exists for this date');
            }
            
            // Create new attendance record
            $insertStmt = $conn->prepare("
                INSERT INTO attendance (faculty_id, date, status, created_at, updated_at) 
                VALUES (?, ?, 'no_scan', NOW(), NOW())
            ");
            $insertStmt->execute([$userId, $date]);
            $attendanceId = $conn->lastInsertId();
            
            // Log the activity
            logActivity($conn, 'CREATE', 'attendance', $attendanceId, "Created attendance record for user $userId on $date");
            
            echo json_encode([
                'success' => true,
                'message' => 'Attendance record created successfully',
                'attendance_id' => $attendanceId
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error creating attendance record: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] === 'refresh_dtr') {
        $facultyFilter = $_POST['faculty_filter'] ?? '';
        $monthFilter = $_POST['month_filter'] ?? '';
        $statusFilter = $_POST['status_filter'] ?? '';
        $rangeFilter = $_POST['range_filter'] ?? '30';
        
        try {
            // Build the same query as in the main page
            $dtrQuery = "
                SELECT 
                    u.id as user_id, u.first_name, u.last_name, u.employee_id,
                    a.id as attendance_id, a.date, a.check_in_time, a.check_out_time, a.status,
                    a.late_minutes, a.remarks,
                    lr.leave_type, lr.status as leave_status,
                    CASE 
                        WHEN lr.status = 'approved' AND a.date BETWEEN lr.start_date AND lr.end_date THEN 'leave'
                        WHEN a.check_in_time IS NOT NULL OR a.check_out_time IS NOT NULL THEN 'attendance'
                        WHEN a.date IS NOT NULL AND DAYOFWEEK(a.date) BETWEEN 2 AND 6 THEN 'no_scan'
                        ELSE 'no_record'
                    END as record_type
                FROM users u
                LEFT JOIN attendance a ON u.id = a.faculty_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                LEFT JOIN leave_requests lr ON u.id = lr.faculty_id 
                    AND lr.status = 'approved' 
                    AND (a.date BETWEEN lr.start_date AND lr.end_date OR a.date IS NULL)
                WHERE u.role = 'faculty' AND u.status = 'active'
            ";
            
            // Apply filters
            $params = [];
            if ($facultyFilter) {
                $dtrQuery .= " AND u.id = ?";
                $params[] = $facultyFilter;
            }
            
            $dtrQuery .= " ORDER BY a.date DESC, u.first_name, u.last_name LIMIT 100";
            
            $stmt = $conn->prepare($dtrQuery);
            $stmt->execute($params);
            $dtrRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data for JSON response
            $formattedRecords = [];
            foreach ($dtrRecords as $dtr) {
                $timeIn = '';
                $timeOut = '';
                $remarks = '';
                $statusClass = '';
                $displayStatus = '';
                
                if ($dtr['record_type'] === 'leave' && $dtr['leave_type']) {
                    $remarks = getLeaveRemark($dtr['leave_type']);
                    $statusClass = 'status-leave';
                    $displayStatus = 'On Leave';
                } elseif ($dtr['record_type'] === 'attendance' && ($dtr['check_in_time'] || $dtr['check_out_time'])) {
                    $timeIn = $dtr['check_in_time'] ? formatTime($dtr['check_in_time']) : '';
                    $timeOut = $dtr['check_out_time'] ? formatTime($dtr['check_out_time']) : '';
                    $remarks = generateAttendanceRemarks($dtr);
                    $statusClass = $dtr['status'] === 'present' ? 'status-present' : ($dtr['status'] === 'late' ? 'status-late' : 'status-absent');
                    $displayStatus = ucfirst($dtr['status'] ?? 'present');
                } elseif ($dtr['record_type'] === 'no_scan') {
                    $remarks = 'No Scan';
                    $statusClass = 'status-no-scan';
                    $displayStatus = 'No Scan';
                }
                
                $formattedRecords[] = [
                    'attendance_id' => $dtr['attendance_id'],
                    'user_id' => $dtr['user_id'],
                    'first_name' => $dtr['first_name'],
                    'last_name' => $dtr['last_name'],
                    'employee_id' => $dtr['employee_id'],
                    'date' => $dtr['date'],
                    'day' => $dtr['date'] ? date('D', strtotime($dtr['date'])) : '',
                    'time_in' => $timeIn ?: '-',
                    'time_out' => $timeOut ?: '-',
                    'status' => $displayStatus ?: '-',
                    'remarks' => $remarks ?: '-',
                    'status_class' => $statusClass
                ];
            }
            
            echo json_encode([
                'success' => true,
                'records' => $formattedRecords,
                'total' => count($formattedRecords)
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error refreshing DTR data: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    // Handle get_dtr_details AJAX request
    if ($_POST['ajax_action'] === 'get_dtr_details') {
        $attendanceId = $_POST['attendance_id'] ?? 0;
        $userId = $_POST['user_id'] ?? 0;
        
        try {
            if (!$attendanceId || !$userId) {
                throw new Exception('Invalid parameters');
            }
            
            // Fetch detailed DTR information
            $stmt = $conn->prepare("
                SELECT 
                    a.id as attendance_id,
                    a.date,
                    a.check_in_time,
                    a.check_out_time,
                    a.status,
                    a.remarks,
                    a.created_at,
                    a.updated_at,
                    u.first_name,
                    u.last_name,
                    u.employee_id
                FROM attendance a
                JOIN users u ON a.faculty_id = u.id
                WHERE a.id = ? AND a.faculty_id = ?
            ");
            $stmt->execute([$attendanceId, $userId]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$details) {
                throw new Exception('DTR record not found');
            }
            
            // Format the response
            $response = [
                'success' => true,
                'details' => [
                    'attendance_id' => $details['attendance_id'],
                    'faculty_name' => $details['first_name'] . ' ' . $details['last_name'],
                    'employee_id' => $details['employee_id'],
                    'date' => formatDate($details['date']),
                    'check_in_time' => $details['check_in_time'] ? formatTime($details['check_in_time']) : null,
                    'check_out_time' => $details['check_out_time'] ? formatTime($details['check_out_time']) : null,
                    'status' => $details['status'],
                    'remarks' => $details['remarks'],
                    'created_at' => date('Y-m-d H:i:s', strtotime($details['created_at'])),
                    'updated_at' => date('Y-m-d H:i:s', strtotime($details['updated_at']))
                ]
            ];
            
            echo json_encode($response);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching DTR details: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

$totalFaculty = $conn->query("SELECT COUNT(*) FROM users WHERE role='faculty'")->fetchColumn();
$presentToday = $conn->query("SELECT COUNT(*) FROM attendance WHERE date=CURDATE() AND status='present'")->fetchColumn();
$onLeaveToday = $conn->query("SELECT COUNT(*) FROM leave_requests WHERE CURDATE() BETWEEN start_date AND end_date AND status='approved'")->fetchColumn();
$pendingLeaves = $conn->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();

$recent = $conn->query("SELECT a.*, u.first_name, u.last_name FROM attendance a JOIN users u ON a.faculty_id=u.id ORDER BY a.created_at DESC LIMIT 10")->fetchAll();

$trend = $conn->query("SELECT date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present FROM attendance WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY date ORDER BY date")->fetchAll();
$labels = [];
$data = [];
foreach ($trend as $t) {
    $labels[] = date('M d', strtotime($t['date']));
    $data[] = $t['present'];
}

/**
 * Get HR-compliant leave remark notation
 */
function getLeaveRemark($leaveType) {
    $leaveRemarks = [
        'vacation' => 'VL', // Vacation Leave
        'sick' => 'SL',    // Sick Leave
        'emergency' => 'EL', // Emergency Leave
        'maternity' => 'ML', // Maternity Leave
        'paternity' => 'PL', // Paternity Leave
        'other' => 'OL'    // Other Leave
    ];
    
    return $leaveRemarks[$leaveType] ?? 'Leave';
}

/**
 * Generate attendance remarks based on record details
 */
function generateAttendanceRemarks($record) {
    $remarks = [];
    
    // Add late remarks if applicable
    if ($record['status'] === 'late' && isset($record['late_minutes']) && $record['late_minutes'] > 0) {
        $remarks[] = $record['late_minutes'] . ' min late';
    }
    
    // Add early out remarks
    if ($record['check_out_time'] && $record['check_out_time'] < '17:00:00') {
        $remarks[] = 'Early Out';
    }
    
    // Add overtime remarks
    if ($record['check_out_time'] && $record['check_out_time'] > '18:00:00') {
        $remarks[] = 'Overtime';
    }
    
    // Add custom remarks if any
    if (!empty($record['remarks'])) {
        $remarks[] = $record['remarks'];
    }
    
    return implode(', ', $remarks);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* DTR Status Styles */
        .status-leave { background: #cce5ff !important; }
        .status-no-scan { background: #e2e3e5 !important; }
        .status-present { background: #d4edda !important; }
        .status-late { background: #fff3cd !important; }
        .status-absent { background: #f8d7da !important; }
        .status-weekend { background: #f8f9fa !important; }

        /* DTR Table Enhancements */
        #dtrTable .avatar-sm {
            font-weight: 600;
            font-size: 11px;
        }

        #dtrTable .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        /* DTR Modal Styles */
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        /* DTR Editing Mode Styles */
        .editing-mode {
            background-color: #fff3cd !important;
            border: 2px solid #ffc107 !important;
        }

        .editing-mode .editable-field input,
        .editing-mode .editable-field select {
            border: 1px solid #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .editable-field {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .editable-field:hover {
            background-color: #f8f9fa;
        }

        .editing-mode .editable-field:hover {
            background-color: transparent;
        }

        /* Live Update Indicator Animation */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        #liveStatus .bi-circle-fill {
            animation: pulse 2s infinite;
        }

        /* Notification Styles */
        .alert-dismissible {
            animation: slideInRight 0.3s ease-out;
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dtr-filters .row > div {
                margin-bottom: 0.5rem;
            }
            
            #dtrTable {
                font-size: 0.8rem;
            }
            
            .avatar-sm {
                width: 25px !important;
                height: 25px !important;
                font-size: 10px !important;
            }

            .btn-group-sm .btn {
                padding: 0.125rem 0.25rem;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
<button class="mobile-menu-toggle" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
</button>
<?php include '../includes/admin-sidebar.php'; ?>
<?php include '../includes/admin-topnav.php'; ?>
<div class="main-content">
            <h2>Dashboard</h2>
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card stat-card position-relative">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Faculty</h5>
                            <h2 class="mb-0"><?= $totalFaculty ?></h2>
                            <i class="bi bi-people stat-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card position-relative">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Present Today</h5>
                            <h2 class="mb-0 text-success"><?= $presentToday ?></h2>
                            <i class="bi bi-check-circle stat-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card position-relative">
                        <div class="card-body">
                            <h5 class="card-title text-muted">On Leave Today</h5>
                            <h2 class="mb-0 text-primary"><?= $onLeaveToday ?></h2>
                            <i class="bi bi-calendar-x stat-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card position-relative">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Pending Leaves</h5>
                            <h2 class="mb-0 text-warning"><?= $pendingLeaves ?></h2>
                            <i class="bi bi-envelope-paper stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">Attendance Trend (Last 7 Days)</div>
                        <div class="card-body">
                            <canvas id="attendanceChart" height="300"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">Quick Actions</div>
                        <div class="card-body">
                            <a href="attendance.php" class="btn btn-primary w-100 mb-2">Mark Attendance</a>
                            <a href="faculty.php?action=add" class="btn btn-success w-100 mb-2">Add Faculty</a>
                            <a href="leaves.php" class="btn btn-info w-100">Review Leaves (<?= $pendingLeaves ?>)</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">Recent Check-ins/Check-outs</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Faculty</th><th>Date</th><th>Check In</th><th>Check Out</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $r): ?>
                                <tr>
                                    <td><?= $r['first_name'] . ' ' . $r['last_name'] ?></td>
                                    <td><?= formatDate($r['date']) ?></td>
                                    <td><?= $r['check_in_time'] ? formatTime($r['check_in_time']) : '-' ?></td>
                                    <td><?= $r['check_out_time'] ? formatTime($r['check_out_time']) : '-' ?></td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Enhanced DTR Logs Management Section -->
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Daily Time Record (DTR) Management</h5>
                        <small class="text-muted">Comprehensive attendance tracking with HR-compliant formatting</small>
                        <div class="mt-1">
                            <span class="badge bg-success" id="liveStatus" style="display:none;">
                                <i class="bi bi-circle-fill me-1"></i>Live Updates Active
                            </span>
                            <small class="text-muted" id="lastUpdate">Last updated: Just now</small>
                        </div>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary" onclick="showDTRModal()">
                            <i class="bi bi-file-earmark-plus me-1"></i>Generate DTR
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportCurrentDTR()">
                            <i class="bi bi-download me-1"></i>Export
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="toggleLiveUpdates()" id="liveToggleBtn">
                            <i class="bi bi-wifi me-1"></i>Live Updates
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="refreshDTR()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Enhanced DTR Filters -->
                    <div class="row mb-3 g-2">
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Faculty</label>
                            <select class="form-select form-select-sm" id="dtrFacultyFilter">
                                <option value="">All Faculty</option>
                                <?php 
                                $facultyList = $conn->query("SELECT id, first_name, last_name, employee_id FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($facultyList as $f): ?>
                                    <option value="<?= $f['id'] ?>"><?= $f['first_name'] . ' ' . $f['last_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Month</label>
                            <input type="month" class="form-control form-control-sm" id="dtrMonthFilter" value="<?= date('Y-m') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Status</label>
                            <select class="form-select form-select-sm" id="dtrStatusFilter">
                                <option value="">All Status</option>
                                <option value="present">Present</option>
                                <option value="late">Late</option>
                                <option value="on_leave">On Leave</option>
                                <option value="no_scan">No Scan</option>
                                <option value="absent">Absent</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Date Range</label>
                            <select class="form-select form-select-sm" id="dtrRangeFilter">
                                <option value="7">Last 7 Days</option>
                                <option value="30" selected>Last 30 Days</option>
                                <option value="90">Last 90 Days</option>
                                <option value="all">All Records</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Search</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="dtrSearch" placeholder="Search by name, ID, or remarks...">
                                <button class="btn btn-outline-primary" type="button" onclick="searchDTR()">
                                    <i class="bi bi-search"></i>
                                </button>
                                <button class="btn btn-outline-secondary" type="button" onclick="clearFilters()">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced DTR Table with HR-Compliant Formatting -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="dtrTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width: 25%;">Faculty Member</th>
                                    <th style="width: 10%;">Date</th>
                                    <th style="width: 8%;">Day</th>
                                    <th style="width: 12%;">Time In</th>
                                    <th style="width: 12%;">Time Out</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 15%;">Remarks</th>
                                    <th style="width: 8%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="dtrTableBody">
                                <?php
                                // Get enhanced DTR records with better leave handling
                                $dtrQuery = "
                                    SELECT 
                                        u.id as user_id, u.first_name, u.last_name, u.employee_id,
                                        a.id as attendance_id, a.date, a.check_in_time, a.check_out_time, a.status,
                                        a.late_minutes, a.remarks,
                                        lr.leave_type, lr.status as leave_status,
                                        CASE 
                                            WHEN lr.status = 'approved' AND a.date BETWEEN lr.start_date AND lr.end_date THEN 'leave'
                                            WHEN a.check_in_time IS NOT NULL OR a.check_out_time IS NOT NULL THEN 'attendance'
                                            WHEN a.date IS NOT NULL AND DAYOFWEEK(a.date) BETWEEN 2 AND 6 THEN 'no_scan'
                                            ELSE 'no_record'
                                        END as record_type
                                    FROM users u
                                    LEFT JOIN attendance a ON u.id = a.faculty_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                    LEFT JOIN leave_requests lr ON u.id = lr.faculty_id 
                                        AND lr.status = 'approved' 
                                        AND (a.date BETWEEN lr.start_date AND lr.end_date OR a.date IS NULL)
                                    WHERE u.role = 'faculty' AND u.status = 'active'
                                    ORDER BY a.date DESC, u.first_name, u.last_name
                                    LIMIT 100
                                ";
                                $dtrRecords = $conn->query($dtrQuery)->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($dtrRecords as $dtr):
                                    // Generate HR-compliant DTR entry
                                    $timeIn = '';
                                    $timeOut = '';
                                    $remarks = '';
                                    $statusClass = '';
                                    $displayStatus = '';
                                    
                                    if ($dtr['record_type'] === 'leave' && $dtr['leave_type']) {
                                        // Approved leave - blank times, leave remarks
                                        $timeIn = '';
                                        $timeOut = '';
                                        $remarks = getLeaveRemark($dtr['leave_type']);
                                        $statusClass = 'status-leave';
                                        $displayStatus = 'On Leave';
                                    } elseif ($dtr['record_type'] === 'attendance' && ($dtr['check_in_time'] || $dtr['check_out_time'])) {
                                        // Has attendance record
                                        $timeIn = $dtr['check_in_time'] ? formatTime($dtr['check_in_time']) : '';
                                        $timeOut = $dtr['check_out_time'] ? formatTime($dtr['check_out_time']) : '';
                                        $remarks = generateAttendanceRemarks($dtr);
                                        $statusClass = $dtr['status'] === 'present' ? 'status-present' : ($dtr['status'] === 'late' ? 'status-late' : 'status-absent');
                                        $displayStatus = ucfirst($dtr['status'] ?? 'present');
                                    } elseif ($dtr['record_type'] === 'no_scan') {
                                        // No scan/check-in on weekday - blank times, no scan remarks
                                        $timeIn = '';
                                        $timeOut = '';
                                        $remarks = 'No Scan';
                                        $statusClass = 'status-no-scan';
                                        $displayStatus = 'No Scan';
                                    } else {
                                        // Weekend or no record
                                        $timeIn = '';
                                        $timeOut = '';
                                        $remarks = '';
                                        $statusClass = 'status-weekend';
                                        $displayStatus = 'Weekend';
                                    }
                                ?>
                                <tr class="<?= $statusClass ?> dtr-record" data-id="<?= $dtr['attendance_id'] ?? 0 ?>" data-faculty-id="<?= $dtr['user_id'] ?>" data-date="<?= $dtr['date'] ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px; font-weight: 600;">
                                                <?= strtoupper(substr($dtr['first_name'], 0, 1) . substr($dtr['last_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-truncate" style="max-width: 150px;"><?= $dtr['first_name'] . ' ' . $dtr['last_name'] ?></div>
                                                <small class="text-muted">ID: <?= $dtr['employee_id'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $dtr['date'] ? formatDate($dtr['date']) : '-' ?></td>
                                    <td><?= $dtr['date'] ? date('D', strtotime($dtr['date'])) : '-' ?></td>
                                    <td class="text-center editable-field" data-field="check_in_time" data-id="<?= $dtr['attendance_id'] ?? 0 ?>" data-user-id="<?= $dtr['user_id'] ?>" data-date="<?= $dtr['date'] ?>">
                                        <?= $timeIn ?: '-' ?>
                                    </td>
                                    <td class="text-center editable-field" data-field="check_out_time" data-id="<?= $dtr['attendance_id'] ?? 0 ?>" data-user-id="<?= $dtr['user_id'] ?>" data-date="<?= $dtr['date'] ?>">
                                        <?= $timeOut ?: '-' ?>
                                    </td>
                                    <td class="text-center editable-status" data-field="status" data-id="<?= $dtr['attendance_id'] ?? 0 ?>" data-user-id="<?= $dtr['user_id'] ?>" data-date="<?= $dtr['date'] ?>">
                                        <?= $displayStatus ? getStatusBadge(strtolower(str_replace(' ', '_', $displayStatus))) : '-' ?>
                                    </td>
                                    <td class="text-start editable-field" data-field="remarks" data-id="<?= $dtr['attendance_id'] ?? 0 ?>" data-user-id="<?= $dtr['user_id'] ?>" data-date="<?= $dtr['date'] ?>">
                                        <?= $remarks ?: '-' ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($dtr['attendance_id'] && $dtr['attendance_id'] > 0): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleEditMode(this, <?= $dtr['attendance_id'] ?>)" title="Toggle Edit Mode">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success btn-sm" onclick="saveDTRRecord(<?= $dtr['attendance_id'] ?>, <?= $dtr['user_id'] ?>, '<?= $dtr['date'] ?>')" title="Save Changes" style="display:none;">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info btn-sm" onclick="viewDTRDetails(<?= $dtr['attendance_id'] ?>, <?= $dtr['user_id'] ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="createAttendanceRecord(<?= $dtr['user_id'] ?>, '<?= $dtr['date'] ?>')" title="Create Attendance Record">
                                                    <i class="bi bi-plus-circle"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info btn-sm" onclick="viewFacultySchedule(<?= $dtr['user_id'] ?>, '<?= $dtr['date'] ?>')" title="View Faculty Schedule">
                                                    <i class="bi bi-calendar3"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Enhanced DTR Summary Statistics -->
                    <div class="row mt-4">
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="mb-1">DTR Summary Statistics</h6>
                                    <small class="text-muted">Showing <span id="dtrRecordCount"><?= count($dtrRecords) ?></span> records</small>
                                </div>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-success" onclick="exportCurrentDTR()">
                                        <i class="bi bi-download me-1"></i>Export CSV
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" onclick="printDTR()">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Statistics Cards -->
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <div class="card border-success">
                                        <div class="card-body text-center py-2">
                                            <h5 class="mb-0 text-success" id="presentCount">0</h5>
                                            <small class="text-muted">Present</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="card border-warning">
                                        <div class="card-body text-center py-2">
                                            <h5 class="mb-0 text-warning" id="lateCount">0</h5>
                                            <small class="text-muted">Late</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="card border-primary">
                                        <div class="card-body text-center py-2">
                                            <h5 class="mb-0 text-primary" id="leaveCount">0</h5>
                                            <small class="text-muted">On Leave</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="card border-secondary">
                                        <div class="card-body text-center py-2">
                                            <h5 class="mb-0 text-secondary" id="noScanCount">0</h5>
                                            <small class="text-muted">No Scan</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="mb-3">Quick Actions</h6>
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="showDTRModal()">
                                            <i class="bi bi-file-earmark-plus me-1"></i>Generate Monthly DTR
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-info" onclick="showBulkEditModal()">
                                            <i class="bi bi-pencil-square me-1"></i>Bulk Edit Records
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="showImportModal()">
                                            <i class="bi bi-upload me-1"></i>Import Attendance
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DTR Generation Modal -->
        <div class="modal fade" id="dtrModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Generate Daily Time Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="dtrGenerationForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Faculty Member</label>
                                    <select class="form-select" name="faculty_id" required>
                                        <option value="">Select Faculty</option>
                                        <?php foreach ($facultyList as $f): ?>
                                            <option value="<?= $f['id'] ?>"><?= $f['first_name'] . ' ' . $f['last_name'] ?> (<?= $f['employee_id'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Month</label>
                                    <input type="month" class="form-control" name="month" value="<?= date('Y-m') ?>" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_weekends" id="includeWeekends">
                                        <label class="form-check-label" for="includeWeekends">
                                            Include weekends in DTR
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="generateDTR()">Generate DTR</button>
                    </div>
                </div>
            </div>
        </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}
    const ctx = document.getElementById('attendanceChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                label: 'Present',
                data: <?= json_encode($data) ?>,
                borderColor: '#800000',
                backgroundColor: 'rgba(128,0,0,0.1)',
                tension: 0.4
            }]
        },
        options: { responsive: true, maintainAspectRatio: true }
    });

    // DTR Management JavaScript Functions
    function showDTRModal() {
        const modal = new bootstrap.Modal(document.getElementById('dtrModal'));
        modal.show();
    }

    function generateDTR() {
        const form = document.getElementById('dtrGenerationForm');
        const formData = new FormData(form);
        
        // Validate form
        if (!formData.get('faculty_id') || !formData.get('month')) {
            alert('Please select faculty member and month');
            return;
        }
        
        // Show loading state
        const generateBtn = document.querySelector('button[onclick="generateDTR()"]');
        const originalText = generateBtn.innerHTML;
        generateBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generating...';
        generateBtn.disabled = true;
        
        // Simulate DTR generation (in real implementation, this would make an AJAX call)
        setTimeout(() => {
            // Redirect to DTR management page with parameters
            const facultyId = formData.get('faculty_id');
            const month = formData.get('month');
            window.location.href = `dtr_management.php?faculty_id=${facultyId}&month=${month}`;
        }, 1500);
    }

    function refreshDTR() {
        // Show loading state
        const refreshBtn = document.querySelector('button[onclick="refreshDTR()"]');
        const originalHTML = refreshBtn.innerHTML;
        refreshBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        refreshBtn.disabled = true;
        
        // Refresh the page (in real implementation, this would make an AJAX call)
        setTimeout(() => {
            location.reload();
        }, 1000);
    }

    function searchDTR() {
        const searchTerm = document.getElementById('dtrSearch').value.toLowerCase();
        const facultyFilter = document.getElementById('dtrFacultyFilter').value;
        const monthFilter = document.getElementById('dtrMonthFilter').value;
        const statusFilter = document.getElementById('dtrStatusFilter').value;
        const rangeFilter = document.getElementById('dtrRangeFilter').value;
        
        const rows = document.querySelectorAll('#dtrTableBody tr');
        let visibleCount = 0;
        let presentCount = 0;
        let lateCount = 0;
        let leaveCount = 0;
        let noScanCount = 0;
        
        rows.forEach(row => {
            const facultyName = row.cells[0].textContent.toLowerCase();
            const date = row.cells[1].textContent;
            const status = row.cells[5].textContent.toLowerCase();
            const remarks = row.cells[6].textContent.toLowerCase();
            
            let showRow = true;
            
            // Search filter
            if (searchTerm && !facultyName.includes(searchTerm) && !date.includes(searchTerm) && !status.includes(searchTerm) && !remarks.includes(searchTerm)) {
                showRow = false;
            }
            
            // Faculty filter
            if (facultyFilter) {
                const facultyId = row.getAttribute('data-faculty-id');
                if (facultyId !== facultyFilter) {
                    showRow = false;
                }
            }
            
            // Month filter
            if (monthFilter) {
                const rowDate = new Date(date);
                const rowMonth = rowDate.toISOString().slice(0, 7);
                if (rowMonth !== monthFilter) {
                    showRow = false;
                }
            }
            
            // Status filter
            if (statusFilter) {
                const rowStatus = status.toLowerCase().replace(' ', '_');
                if (rowStatus !== statusFilter) {
                    showRow = false;
                }
            }
            
            // Date range filter
            if (rangeFilter && rangeFilter !== 'all') {
                const rowDate = new Date(date);
                const daysDiff = Math.floor((new Date() - rowDate) / (1000 * 60 * 60 * 24));
                if (daysDiff > parseInt(rangeFilter)) {
                    showRow = false;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
            if (showRow) {
                visibleCount++;
                
                // Update statistics
                if (status.includes('present')) presentCount++;
                else if (status.includes('late')) lateCount++;
                else if (status.includes('leave')) leaveCount++;
                else if (status.includes('no scan')) noScanCount++;
            }
        });
        
        // Update record count and statistics
        document.getElementById('dtrRecordCount').textContent = visibleCount;
        document.getElementById('presentCount').textContent = presentCount;
        document.getElementById('lateCount').textContent = lateCount;
        document.getElementById('leaveCount').textContent = leaveCount;
        document.getElementById('noScanCount').textContent = noScanCount;
    }

    function clearFilters() {
        document.getElementById('dtrFacultyFilter').value = '';
        document.getElementById('dtrMonthFilter').value = new Date().toISOString().slice(0, 7);
        document.getElementById('dtrStatusFilter').value = '';
        document.getElementById('dtrRangeFilter').value = '30';
        document.getElementById('dtrSearch').value = '';
        searchDTR();
    }

    function exportCurrentDTR() {
        const facultyFilter = document.getElementById('dtrFacultyFilter').value;
        const monthFilter = document.getElementById('dtrMonthFilter').value;
        const statusFilter = document.getElementById('dtrStatusFilter').value;
        const rangeFilter = document.getElementById('dtrRangeFilter').value;
        
        // Build export parameters
        const params = new URLSearchParams({
            faculty_id: facultyFilter || '',
            month: monthFilter || '',
            status: statusFilter || '',
            range: rangeFilter || '30',
            export: 'csv'
        });
        
        // Redirect to DTR management page with export parameters
        window.location.href = `dtr_management.php?${params.toString()}`;
    }

    function showBulkEditModal() {
        alert('Bulk Edit functionality would open a modal here for editing multiple DTR records simultaneously.');
    }

    function showImportModal() {
        alert('Import functionality would open a modal here for importing attendance data from CSV files.');
    }

    // Live editing and real-time update functionality
    let liveUpdateInterval = null;
    let isLiveUpdatesActive = false;
    let currentEditingRow = null;

    function toggleEditMode(button, recordId) {
        const row = button.closest('tr');
        const isEditing = row.classList.contains('editing-mode');
        
        // Check if this is a valid attendance record (not weekend/no-scan)
        if (recordId === 0) {
            showNotification('Cannot edit records without attendance ID. Please create an attendance record first.', 'warning');
            return;
        }
        
        if (isEditing) {
            // Exit edit mode
            exitEditMode(row);
        } else {
            // Enter edit mode
            enterEditMode(row, recordId);
        }
    }

    function enterEditMode(row, recordId) {
        // Exit any existing edit mode
        if (currentEditingRow && currentEditingRow !== row) {
            exitEditMode(currentEditingRow);
        }
        
        currentEditingRow = row;
        row.classList.add('editing-mode');
        
        // Make fields editable and store original values
        const editableFields = row.querySelectorAll('.editable-field');
        editableFields.forEach(field => {
            const currentValue = field.textContent.trim();
            const fieldType = field.dataset.field;
            
            // Store original value
            field.dataset.originalValue = currentValue;
            
            if (fieldType === 'status') {
                // Extract status value from badge
                let statusValue = 'present';
                if (currentValue.includes('Late')) statusValue = 'late';
                else if (currentValue.includes('Absent')) statusValue = 'absent';
                else if (currentValue.includes('Leave')) statusValue = 'on_leave';
                else if (currentValue.includes('No Scan')) statusValue = 'no_scan';
                
                // Create status dropdown
                const select = document.createElement('select');
                select.className = 'form-select form-select-sm';
                select.innerHTML = `
                    <option value="present" ${statusValue === 'present' ? 'selected' : ''}>Present</option>
                    <option value="late" ${statusValue === 'late' ? 'selected' : ''}>Late</option>
                    <option value="absent" ${statusValue === 'absent' ? 'selected' : ''}>Absent</option>
                    <option value="on_leave" ${statusValue === 'on_leave' ? 'selected' : ''}>On Leave</option>
                    <option value="no_scan" ${statusValue === 'no_scan' ? 'selected' : ''}>No Scan</option>
                `;
                field.innerHTML = '';
                field.appendChild(select);
            } else if (fieldType === 'check_in_time' || fieldType === 'check_out_time') {
                // Create time input
                const input = document.createElement('input');
                input.type = 'time';
                input.className = 'form-control form-control-sm text-center';
                input.value = currentValue !== '-' ? currentValue : '';
                field.innerHTML = '';
                field.appendChild(input);
            } else {
                // Create text input for remarks
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control form-control-sm';
                input.value = currentValue !== '-' ? currentValue : '';
                field.innerHTML = '';
                field.appendChild(input);
            }
        });
        
        // Update buttons
        const buttonGroup = row.querySelector('.btn-group');
        const editBtn = buttonGroup.querySelector('button[onclick*="toggleEditMode"]');
        const saveBtn = buttonGroup.querySelector('button[onclick*="saveDTRRecord"]');
        
        editBtn.style.display = 'none';
        saveBtn.style.display = 'inline-block';
        
        // Highlight the row
        row.style.backgroundColor = '#fff3cd';
    }

    function exitEditMode(row) {
        row.classList.remove('editing-mode');
        currentEditingRow = null;
        
        // Restore original values from data attributes
        const editableFields = row.querySelectorAll('.editable-field');
        editableFields.forEach(field => {
            const fieldType = field.dataset.field;
            const originalValue = field.dataset.originalValue || '-';
            
            // Restore the original display
            if (fieldType === 'status') {
                field.innerHTML = getStatusBadge(originalValue === '-' ? 'present' : originalValue);
            } else if (fieldType === 'check_in_time' || fieldType === 'check_out_time') {
                field.innerHTML = originalValue === '-' ? '<span class="text-muted">-</span>' : originalValue;
            } else {
                field.innerHTML = originalValue === '-' ? '-' : originalValue;
            }
        });
        
        // Update buttons
        const buttonGroup = row.querySelector('.btn-group');
        const editBtn = buttonGroup.querySelector('button[onclick*="toggleEditMode"]');
        const saveBtn = buttonGroup.querySelector('button[onclick*="saveDTRRecord"]');
        
        editBtn.style.display = 'inline-block';
        saveBtn.style.display = 'none';
        
        // Remove highlight
        row.style.backgroundColor = '';
    }

    function saveDTRRecord(recordId, userId, date) {
        const row = document.querySelector(`tr[data-id="${recordId}"]`);
        if (!row) {
            console.error('Row not found for record ID:', recordId);
            return;
        }
        
        // Check if this is a valid attendance record (not weekend/no-scan)
        if (recordId === 0) {
            showNotification('Cannot save records without attendance ID. Please create an attendance record first.', 'warning');
            return;
        }
        
        // Collect updated values
        const updates = {};
        const editableFields = row.querySelectorAll('.editable-field');
        
        editableFields.forEach(field => {
            const fieldType = field.dataset.field;
            let value;
            
            if (fieldType === 'status') {
                value = field.querySelector('select').value;
            } else {
                value = field.querySelector('input').value;
            }
            
            if (value && value !== '-') {
                updates[fieldType] = value;
            }
        });
        
        // Show loading state
        const saveBtn = row.querySelector('button[onclick*="saveDTRRecord"]');
        const originalHTML = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        saveBtn.disabled = true;
        
        // Make AJAX call to update DTR record
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                ajax_action: 'update_dtr',
                attendance_id: recordId,
                user_id: userId,
                date: date,
                updates: JSON.stringify(updates)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success feedback
                showNotification(data.message, 'success');
                
                // Exit edit mode
                exitEditMode(row);
                
                // Refresh data silently
                refreshDTR(true);
            } else {
                // Show error feedback
                showNotification(data.message, 'danger');
                
                // Restore button state
                saveBtn.innerHTML = originalHTML;
                saveBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error updating DTR record:', error);
            showNotification('Error updating DTR record. Please try again.', 'danger');
            
            // Restore button state
            saveBtn.innerHTML = originalHTML;
            saveBtn.disabled = false;
        });
    }

    function toggleLiveUpdates() {
        const btn = document.getElementById('liveToggleBtn');
        const statusBadge = document.getElementById('liveStatus');
        
        if (isLiveUpdatesActive) {
            // Stop live updates
            clearInterval(liveUpdateInterval);
            isLiveUpdatesActive = false;
            
            btn.innerHTML = '<i class="bi bi-wifi me-1"></i>Live Updates';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-info');
            
            statusBadge.style.display = 'none';
        } else {
            // Start live updates
            isLiveUpdatesActive = true;
            
            btn.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Stop Live';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-success');
            
            statusBadge.style.display = 'inline-block';
            
            // Update every 30 seconds
            liveUpdateInterval = setInterval(() => {
                refreshDTR(true); // Silent refresh
            }, 30000);
            
            // Initial refresh
            refreshDTR(true);
        }
    }

    function refreshDTR(silent = false) {
        const refreshBtn = document.querySelector('button[onclick="refreshDTR()"]');
        let originalHTML = '';
        
        if (!silent && refreshBtn) {
            // Show loading state
            originalHTML = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            refreshBtn.disabled = true;
        }
        
        // Get current filter values with error handling
        const facultyFilter = document.getElementById('dtrFacultyFilter')?.value || '';
        const monthFilter = document.getElementById('dtrMonthFilter')?.value || '';
        const statusFilter = document.getElementById('dtrStatusFilter')?.value || '';
        const rangeFilter = document.getElementById('dtrRangeFilter')?.value || '30';
        
        // Make AJAX call to refresh DTR data
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                ajax_action: 'refresh_dtr',
                faculty_filter: facultyFilter,
                month_filter: monthFilter,
                status_filter: statusFilter,
                range_filter: rangeFilter
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Update the DTR table with new data
                updateDTRTable(data.records);
                
                // Update statistics
                updateStatistics(data.records);
                
                // Update last update time
                updateLastUpdateTime();
                
                if (!silent) {
                    showNotification('DTR data refreshed!', 'info');
                }
            } else {
                if (!silent) {
                    showNotification(data.message || 'Error refreshing DTR data', 'danger');
                }
            }
        })
        .catch(error => {
            console.error('Error refreshing DTR data:', error);
            
            if (!silent) {
                showNotification('Error refreshing DTR data: ' + error.message, 'danger');
            }
        })
        .finally(() => {
            // Always restore button state
            if (!silent && refreshBtn && originalHTML) {
                refreshBtn.innerHTML = originalHTML;
                refreshBtn.disabled = false;
            }
        });

    function updateDTRTable(records) {
        const tbody = document.getElementById('dtrTableBody');
        
        if (!tbody) {
            console.error('DTR table body element not found');
            return;
        }
        
        // Clear existing rows
        tbody.innerHTML = '';
        
        if (!records || records.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-calendar-x me-2"></i>
                        No DTR records found
                    </td>
                </tr>
            `;
            const countElement = document.getElementById('dtrRecordCount');
            if (countElement) countElement.textContent = '0';
            return;
        }
        
        // Add new rows
        records.forEach(record => {
            const row = document.createElement('tr');
            row.className = (record.status_class || '') + ' dtr-record';
            row.setAttribute('data-faculty-id', record.user_id || '');
            row.setAttribute('data-date', record.date || '');
            row.setAttribute('data-id', record.attendance_id || '');
            
            // Escape HTML to prevent XSS
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            };
            
            // Get initials safely
            const initials = (record.first_name?.charAt(0) || '') + (record.last_name?.charAt(0) || '');
            
            row.innerHTML = `
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px; font-weight: 600;">
                            ${initials.toUpperCase()}
                        </div>
                        <div>
                            <div class="fw-semibold text-truncate" style="max-width: 150px;" title="${escapeHtml(record.first_name + ' ' + record.last_name)}">
                                ${escapeHtml(record.first_name + ' ' + record.last_name)}
                            </div>
                            <small class="text-muted">ID: ${escapeHtml(record.employee_id || '')}</small>
                        </div>
                    </div>
                </td>
                <td>${record.date ? formatDate(record.date) : '-'}</td>
                <td>${escapeHtml(record.day || '-')}</td>
                <td class="text-center editable-field" data-field="check_in_time" data-id="${record.attendance_id || ''}" data-user-id="${record.user_id || ''}" data-date="${record.date || ''}">
                    ${escapeHtml(record.time_in || '-')}
                </td>
                <td class="text-center editable-field" data-field="check_out_time" data-id="${record.attendance_id || ''}" data-user-id="${record.user_id || ''}" data-date="${record.date || ''}">
                    ${escapeHtml(record.time_out || '-')}
                </td>
                <td class="text-center editable-status" data-field="status" data-id="${record.attendance_id || ''}" data-user-id="${record.user_id || ''}" data-date="${record.date || ''}">
                    ${getStatusBadge((record.status || '').toLowerCase().replace(' ', '_'))}
                </td>
                <td class="text-start editable-field" data-field="remarks" data-id="${record.attendance_id || ''}" data-user-id="${record.user_id || ''}" data-date="${record.date || ''}">
                    ${escapeHtml(record.remarks || '-')}
                </td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleEditMode(this, ${record.attendance_id || 0})" title="Toggle Edit Mode">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="saveDTRRecord(${record.attendance_id || 0}, ${record.user_id || 0}, '${record.date || ''}')" title="Save Changes" style="display:none;">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </div>
                </td>
            `;
            
            tbody.appendChild(row);
        });
        
        // Update record count
        const countElement = document.getElementById('dtrRecordCount');
        if (countElement) countElement.textContent = records.length;
    }

    function updateStatistics(records) {
        let presentCount = 0;
        let lateCount = 0;
        let leaveCount = 0;
        let noScanCount = 0;
        
        if (records && records.length > 0) {
            records.forEach(record => {
                const status = (record.status || '').toLowerCase();
                if (status.includes('present')) presentCount++;
                else if (status.includes('late')) lateCount++;
                else if (status.includes('leave')) leaveCount++;
                else if (status.includes('no scan')) noScanCount++;
            });
        }
        
        // Update statistics displays with error handling
        const elements = {
            'presentCount': presentCount,
            'lateCount': lateCount,
            'leaveCount': leaveCount,
            'noScanCount': noScanCount
        };
        
        Object.keys(elements).forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = elements[id];
            } else {
                console.warn('Statistics element not found:', id);
            }
        });
    }

    function updateLastUpdateTime() {
        const lastUpdateElement = document.getElementById('lastUpdate');
        if (lastUpdateElement) {
            const now = new Date();
            lastUpdateElement.textContent = `Last updated: ${now.toLocaleTimeString()}`;
        } else {
            console.warn('Last update element not found');
        }
    }

    function showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    function editDTR(recordId) {
        // This function is now handled by toggleEditMode
        console.log('Edit DTR record:', recordId);
    }

    function viewDTRDetails(recordId, userId) {
        // Check if this is a valid attendance record (not weekend/no-scan)
        if (recordId === 0) {
            showNotification('Cannot view details for records without attendance ID. Please create an attendance record first.', 'warning');
            return;
        }
        
        // Fetch detailed information about this DTR record
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                ajax_action: 'get_dtr_details',
                attendance_id: recordId,
                user_id: userId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create modal to show DTR details
                showDTRDetailsModal(data.details);
            } else {
                showNotification('Error fetching DTR details: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error fetching DTR details:', error);
            showNotification('Error fetching DTR details. Please try again.', 'danger');
        });
    }
    
    function showDTRDetailsModal(details) {
        // Create modal HTML
        const modalHtml = `
            <div class="modal fade" id="dtrDetailsModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">DTR Record Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Faculty Information</h6>
                                    <p><strong>Name:</strong> ${details.faculty_name}</p>
                                    <p><strong>Employee ID:</strong> ${details.employee_id}</p>
                                    <p><strong>Date:</strong> ${details.date}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6>Attendance Details</h6>
                                    <p><strong>Check In:</strong> ${details.check_in_time || '-'}</p>
                                    <p><strong>Check Out:</strong> ${details.check_out_time || '-'}</p>
                                    <p><strong>Status:</strong> <span class="badge bg-${getStatusColor(details.status)}">${details.status}</span></p>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Additional Information</h6>
                                    <p><strong>Remarks:</strong> ${details.remarks || 'No remarks'}</p>
                                    <p><strong>Created:</strong> ${details.created_at}</p>
                                    <p><strong>Last Updated:</strong> ${details.updated_at}</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" onclick="editDTRFromModal(${details.attendance_id})">Edit Record</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('dtrDetailsModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Add modal to body and show it
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('dtrDetailsModal'));
        modal.show();
    }
    
    function getStatusColor(status) {
        const colors = {
            'present': 'success',
            'late': 'warning',
            'absent': 'danger',
            'on_leave': 'info',
            'no_scan': 'secondary'
        };
        return colors[status] || 'secondary';
    }
    
    function editDTRFromModal(recordId) {
        // Close modal and enter edit mode
        const modal = bootstrap.Modal.getInstance(document.getElementById('dtrDetailsModal'));
        modal.hide();
        
        // Find the row and enter edit mode
        const row = document.querySelector(`tr[data-id="${recordId}"]`);
        if (row) {
            const editBtn = row.querySelector('button[onclick*="toggleEditMode"]');
            if (editBtn) {
                editBtn.click();
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    function createAttendanceRecord(userId, date) {
        // Create a new attendance record for the user on the given date
        if (!confirm(`Create attendance record for user ${userId} on ${date}?`)) {
            return;
        }
        
        // Show loading state
        showNotification('Creating attendance record...', 'info');
        
        // Make AJAX call to create attendance record
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                ajax_action: 'create_attendance',
                user_id: userId,
                date: date
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showNotification('Attendance record created successfully!', 'success');
                // Refresh the DTR table
                refreshDTR(true);
            } else {
                showNotification(data.message || 'Error creating attendance record', 'danger');
            }
        })
        .catch(error => {
            console.error('Error creating attendance record:', error);
            showNotification('Error creating attendance record: ' + error.message, 'danger');
        });
    }

    function viewFacultySchedule(userId, date) {
        // View faculty schedule for the given date
        const url = `dtr_management.php?faculty_id=${userId}&date_type=specific&specific_date=${date}`;
        window.open(url, '_blank');
    }

    function exportDTR() {
        // In real implementation, this would export the DTR data
        const facultyFilter = document.getElementById('dtrFacultyFilter').value;
        const monthFilter = document.getElementById('dtrMonthFilter').value;
        const statusFilter = document.getElementById('dtrStatusFilter').value;
        
        // Build export parameters
        const params = new URLSearchParams({
            faculty_id: facultyFilter || '',
            month: monthFilter || '',
            status: statusFilter || '',
            export: 'csv'
        });
        
        // Simulate export (in real implementation, this would download a file)
        alert(`Exporting DTR data with filters:\nFaculty: ${facultyFilter || 'All'}\nMonth: ${monthFilter || 'All'}\nStatus: ${statusFilter || 'All'}\n\n(This would download a CSV file in the full implementation)`);
    }

    function printDTR() {
        // Print only the DTR table
        const printContent = document.querySelector('.card-body').innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = `
            <div style="padding: 20px;">
                <h3>Daily Time Record (DTR) Logs</h3>
                <p>Generated on: ${new Date().toLocaleString()}</p>
                ${printContent}
            </div>
        `;
        
        window.print();
        document.body.innerHTML = originalContent;
        location.reload(); // Reload to restore functionality
    }

    // Add event listeners for DTR management
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize statistics on page load
        initializeDTRStatistics();
        
        // Initialize live update time
        updateLastUpdateTime();
        
        // Auto-apply filters when they change
        ['dtrFacultyFilter', 'dtrMonthFilter', 'dtrStatusFilter', 'dtrRangeFilter'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', searchDTR);
            }
        });
        
        // Search on Enter key
        const searchInput = document.getElementById('dtrSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    searchDTR();
                }
            });
            
            // Real-time search as user types
            searchInput.addEventListener('input', function() {
                if (this.value.length > 2 || this.value.length === 0) {
                    searchDTR();
                }
            });
        }
        
        // Add keyboard shortcuts for editing
        document.addEventListener('keydown', function(e) {
            // Escape key to exit edit mode
            if (e.key === 'Escape' && currentEditingRow) {
                exitEditMode(currentEditingRow);
            }
            
            // Ctrl+S to save current edit
            if (e.ctrlKey && e.key === 's' && currentEditingRow) {
                e.preventDefault();
                const saveBtn = currentEditingRow.querySelector('button[onclick*="saveDTRRecord"]');
                if (saveBtn) {
                    saveBtn.click();
                }
            }
        });
        
        // Auto-enable live updates for today's data
        const today = new Date().toISOString().slice(0, 10);
        const hasTodayData = document.querySelector(`#dtrTableBody tr[data-date="${today}"]`);
        
        if (hasTodayData) {
            // Automatically enable live updates if there's data for today
            setTimeout(() => {
                toggleLiveUpdates();
                showNotification('Live updates enabled for today\'s attendance data', 'info');
            }, 2000);
        }
    });

    // Initialize DTR statistics
    function initializeDTRStatistics() {
        const rows = document.querySelectorAll('#dtrTableBody tr');
        let presentCount = 0;
        let lateCount = 0;
        let leaveCount = 0;
        let noScanCount = 0;
        
        rows.forEach(row => {
            const status = row.cells[5].textContent.toLowerCase();
            if (status.includes('present')) presentCount++;
            else if (status.includes('late')) lateCount++;
            else if (status.includes('leave')) leaveCount++;
            else if (status.includes('no scan')) noScanCount++;
        });
        
        // Update statistics displays
        document.getElementById('presentCount').textContent = presentCount;
        document.getElementById('lateCount').textContent = lateCount;
        document.getElementById('leaveCount').textContent = leaveCount;
        document.getElementById('noScanCount').textContent = noScanCount;
        document.getElementById('dtrRecordCount').textContent = rows.length;
    }
</script>
</body>
</html>