<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

/**
 * Get HR-compliant leave remark notation
 * @param string $leaveType
 * @return string
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

$message = '';
$error = '';
$dtr_data = null;
$faculty_id = '';
$month = date('Y-m');

// Handle DTR generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_dtr'])) {
    $faculty_id = trim($_POST['faculty_id']);
    $month = trim($_POST['month']);
    
    if (empty($faculty_id)) {
        $error = "Please enter a faculty ID";
    } else {
        try {
            // Look up faculty
            $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? AND role = 'faculty'");
            $stmt->execute([$faculty_id]);
            $faculty = $stmt->fetch();
            
            if (!$faculty) {
                $error = "Faculty not found. Please check the ID.";
            } else {
                // Parse month and year
                $year = date('Y', strtotime($month . '-01'));
                $month_num = date('n', strtotime($month . '-01'));
                $days_in_month = date('t', strtotime($month . '-01'));
                
                // Get attendance records for the month
                $attStmt = $pdo->prepare("
                    SELECT * FROM attendance 
                    WHERE faculty_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                    ORDER BY date ASC, check_in_time ASC
                ");
                $attStmt->execute([$faculty['id'], $month]);
                $attendance_records = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get approved leave requests for the month
                $leaveStmt = $pdo->prepare("
                    SELECT * FROM leave_requests 
                    WHERE faculty_id = ? AND status = 'approved' 
                    AND (start_date <= ? AND end_date >= ?)
                    ORDER BY start_date ASC
                ");
                $month_start = $year . '-' . str_pad($month_num, 2, '0', STR_PAD_LEFT) . '-01';
                $month_end = $year . '-' . str_pad($month_num, 2, '0', STR_PAD_LEFT) . '-' . $days_in_month;
                $leaveStmt->execute([$faculty['id'], $month_end, $month_start]);
                $leave_requests = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Create leave date map for easy lookup
                $leave_dates = [];
                foreach ($leave_requests as $leave) {
                    $current = new DateTime($leave['start_date']);
                    $end = new DateTime($leave['end_date']);
                    while ($current <= $end) {
                        $leave_dates[$current->format('Y-m-d')] = $leave;
                        $current->modify('+1 day');
                    }
                }
                
                // Get faculty department
                $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $deptStmt->execute([$faculty['department_id']]);
                $department = $deptStmt->fetchColumn() ?: 'Not Assigned';
                
                // Build DTR data array
                $dtr_data = [];
                
                // Initialize all days of the month
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $date = sprintf('%04d-%02d-%02d', $year, $month_num, $day);
                    $day_of_week = date('N', strtotime($date));
                    
                    // Find attendance record for this day
                    $record = null;
                    foreach ($attendance_records as $att) {
                        if ($att['date'] === $date) {
                            $record = $att;
                            break;
                        }
                    }
                    
                    // Check if this day has approved leave
                    $has_leave = isset($leave_dates[$date]);
                    $leave_info = $has_leave ? $leave_dates[$date] : null;
                    
                    // Determine if it's a weekend
                    $is_weekend = ($day_of_week >= 6);
                    
                    // Build day data according to HR DTR standards
                    $day_data = [
                        'day' => $day,
                        'date' => $date,
                        'day_of_week' => $day_of_week,
                        'is_weekend' => $is_weekend,
                        'has_leave' => $has_leave,
                        'leave_info' => $leave_info
                    ];
                    
                    // Handle different scenarios according to HR standards
                    if ($has_leave && $leave_info) {
                        // Approved leave - blank times, leave remarks
                        $day_data['check_in'] = '';
                        $day_data['check_out'] = '';
                        $day_data['status'] = 'on_leave';
                        $day_data['late_minutes'] = 0;
                        $day_data['remarks'] = getLeaveRemark($leave_info['leave_type']);
                    } elseif ($record && ($record['check_in_time'] || $record['check_out_time'])) {
                        // Has attendance record
                        $day_data['check_in'] = $record['check_in_time'] ? formatTime($record['check_in_time']) : '';
                        $day_data['check_out'] = $record['check_out_time'] ? formatTime($record['check_out_time']) : '';
                        $day_data['status'] = $record['status'] ?? 'present';
                        $day_data['late_minutes'] = $record['late_minutes'] ?? 0;
                        $day_data['remarks'] = $record['remarks'] ?? '';
                    } elseif (!$is_weekend) {
                        // No scan/check-in on weekday - blank times, no scan remarks
                        $day_data['check_in'] = '';
                        $day_data['check_out'] = '';
                        $day_data['status'] = 'no_scan';
                        $day_data['late_minutes'] = 0;
                        $day_data['remarks'] = 'No Scan';
                    } else {
                        // Weekend
                        $day_data['check_in'] = '';
                        $day_data['check_out'] = '';
                        $day_data['status'] = 'weekend';
                        $day_data['late_minutes'] = 0;
                        $day_data['remarks'] = '';
                    }
                    
                    $dtr_data[] = $day_data;
                }
                
                // Calculate summary statistics according to HR standards
                $present_days = 0;
                $late_days = 0;
                $absent_days = 0;
                $leave_days = 0;
                $no_scan_days = 0;
                $total_late_minutes = 0;
                
                foreach ($dtr_data as $day) {
                    if (!$day['is_weekend']) {
                        switch ($day['status']) {
                            case 'present':
                                $present_days++;
                                break;
                            case 'late':
                                $present_days++;
                                $late_days++;
                                $total_late_minutes += $day['late_minutes'];
                                break;
                            case 'on_leave':
                                $leave_days++;
                                break;
                            case 'no_scan':
                                $no_scan_days++;
                                break;
                            default:
                                $absent_days++;
                                break;
                        }
                    }
                }
                
                // Build complete DTR data
                $dtr_data = [
                    'faculty' => [
                        'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                        'employee_id' => $faculty['employee_id'],
                        'department' => $department,
                        'position' => $faculty['position'] ?? 'Not Assigned'
                    ],
                    'month' => date('F Y', strtotime($month . '-01')),
                    'days' => $dtr_data,
                    'summary' => [
                        'present_days' => $present_days,
                        'late_days' => $late_days,
                        'absent_days' => $absent_days,
                        'leave_days' => $leave_days,
                        'no_scan_days' => $no_scan_days,
                        'total_late_minutes' => $total_late_minutes,
                        'total_late_hours' => round($total_late_minutes / 60, 2),
                        'working_days' => $present_days + $late_days + $absent_days + $leave_days + $no_scan_days
                    ]
                ];
                
                $message = "DTR generated successfully for " . htmlspecialchars($dtr_data['faculty']['name']);
            }
        } catch (Exception $e) {
            $error = "Error generating DTR: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Generator | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style media="print">
        @media print {
            .no-print { display: none !important; }
            body { 
                margin: 0; 
                padding: 8px; 
                font-size: 10px;
                font-family: Arial, sans-serif;
                line-height: 1.1;
            }
            .container-fluid { 
                max-width: 210mm; 
                margin: 0 auto; 
                padding: 0;
            }
            .card { 
                border: none; 
                box-shadow: none; 
                margin-bottom: 5px;
            }
            .card-header { 
                padding: 4px 8px; 
                font-size: 12px; 
                font-weight: bold;
            }
            .card-body { padding: 5px; }
            .dtr-table { 
                font-size: 7px !important; 
                width: 100%;
                page-break-inside: auto;
            }
            .dtr-header { background: #f8f9fa !important; }
            .dtr-table th, .dtr-table td { 
                border: 1px solid #000; 
                padding: 1px 2px; 
                text-align: center; 
                vertical-align: middle;
            }
            .dtr-table th { 
                background: #f8f9fa; 
                font-weight: bold; 
                font-size: 6px;
            }
            .table-sm td, .table-sm th { padding: 1px; font-size: 8px; }
            .text-end { text-align: center !important; }
            h5 { font-size: 12px; margin-bottom: 4px; }
            h6 { font-size: 10px; margin-bottom: 2px; }
            .row { margin-bottom: 5px; }
            .col-md-6, .col-md-4, .col-md-8 { 
                flex: 0 0 auto;
                padding: 0 3px;
            }
            .mt-4 { margin-top: 5px !important; }
            .mb-4 { margin-bottom: 5px !important; }
        }
        
        @page {
            size: A4;
            margin: 10mm;
            orientation: portrait;
        }
        
        .dtr-table { 
            border-collapse: collapse; 
            width: 100%; 
            table-layout: fixed;
        }
        .dtr-table th, .dtr-table td { 
            border: 1px solid #dee2e6; 
            padding: 2px; 
            text-align: center; 
            vertical-align: middle;
        }
        .dtr-table th { 
            background: #f8f9fa; 
            font-weight: bold; 
        }
        .dtr-table th:nth-child(1) { width: 6%; }
        .dtr-table th:nth-child(2) { width: 10%; }
        .dtr-table th:nth-child(3) { width: 6%; }
        .dtr-table th:nth-child(4) { width: 10%; }
        .dtr-table th:nth-child(5) { width: 10%; }
        .dtr-table th:nth-child(6) { width: 12%; }
        .dtr-table th:nth-child(7) { width: 8%; }
        .dtr-table th:nth-child(8) { width: 38%; }
        
        .weekend { background: #f8f9fa; }
        .present { background: #d4edda; }
        .late { background: #fff3cd; }
        .absent { background: #f8d7da; }
        .leave { background: #cce5ff; }
        .no-scan { background: #e2e3e5; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <div class="d-flex align-items-center">
                        <img src="assets/uploads/logo.png" alt="DOIT Logo" style="max-height: 50px; margin-right: 20px;">
                        <h2><i class="bi bi-file-earmark-text"></i> DTR Generator</h2>
                    </div>
                    <div>
                        <a href="kiosk.php" class="btn btn-primary">
                            <i class="bi bi-upc-scan"></i> Back to Kiosk
                        </a>
                        <button class="btn btn-success" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print DTR
                        </button>
                    </div>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success no-print"><?= $message ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger no-print"><?= $error ?></div>
                <?php endif; ?>

                <!-- DTR Generator Form -->
                <div class="card mb-4 no-print">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="bi bi-calendar-range"></i> Generate DTR</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Faculty ID</label>
                                <input type="text" name="faculty_id" class="form-control" 
                                       placeholder="e.g., FAC001" value="<?= htmlspecialchars($faculty_id) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Month</label>
                                <input type="month" name="month" class="form-control" 
                                       value="<?= htmlspecialchars($month) ?>" required>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" name="generate_dtr" class="btn btn-primary w-100">
                                    <i class="bi bi-file-earmark-text"></i> Generate DTR
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DTR Display -->
                <?php if ($dtr_data): ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="bi bi-file-earmark-text"></i> Daily Time Record - All Sessions</h5>
                        </div>
                        <div class="card-body">
                            <!-- Faculty Information -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <strong>Name:</strong> <?= htmlspecialchars($dtr_data['faculty']['name']) ?><br>
                                    <strong>Employee ID:</strong> <?= htmlspecialchars($dtr_data['faculty']['employee_id']) ?><br>
                                    <strong>Department:</strong> <?= htmlspecialchars($dtr_data['faculty']['department']) ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Position:</strong> <?= htmlspecialchars($dtr_data['faculty']['position']) ?><br>
                                    <strong>Month:</strong> <?= htmlspecialchars($dtr_data['month']) ?><br>
                                    <strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?>
                                </div>
                            </div>

                            <!-- DTR Table -->
                            <div class="table-responsive">
                                <table class="dtr-table">
                                    <thead class="dtr-header">
                                        <tr>
                                            <th>Day</th>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Session #</th>
                                            <th>Check In</th>
                                            <th>Check Out</th>
                                            <th>Status</th>
                                            <th>Late (min)</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Display all days of the month according to HR standards
                                        foreach ($dtr_data['days'] as $day) {
                                            // Skip weekends if they have no activity
                                            if ($day['is_weekend'] && !$day['check_in'] && !$day['check_out'] && !$day['has_leave']) {
                                                continue;
                                            }
                                            
                                            // Determine row class based on status
                                            $rowClass = '';
                                            switch ($day['status']) {
                                                case 'present':
                                                    $rowClass = 'present';
                                                    break;
                                                case 'late':
                                                    $rowClass = 'late';
                                                    break;
                                                case 'on_leave':
                                                    $rowClass = 'leave';
                                                    break;
                                                case 'no_scan':
                                                    $rowClass = 'no-scan';
                                                    break;
                                                case 'weekend':
                                                    $rowClass = 'weekend';
                                                    break;
                                                default:
                                                    $rowClass = 'absent';
                                                    break;
                                            }
                                            
                                            // Display status according to HR standards
                                            $displayStatus = '';
                                            switch ($day['status']) {
                                                case 'present':
                                                case 'late':
                                                    $displayStatus = ucfirst($day['status']);
                                                    break;
                                                case 'on_leave':
                                                    $displayStatus = 'Leave';
                                                    break;
                                                case 'no_scan':
                                                    $displayStatus = 'No Scan';
                                                    break;
                                                case 'weekend':
                                                    $displayStatus = 'Weekend';
                                                    break;
                                                default:
                                                    $displayStatus = 'Absent';
                                                    break;
                                            }
                                            
                                            ?>
                                            <tr class="<?= $rowClass ?>">
                                                <td><?= $day['day'] ?></td>
                                                <td><?= formatDate($day['date']) ?></td>
                                                <td><?= date('D', strtotime($day['date'])) ?></td>
                                                <td><?= ($day['check_in'] || $day['check_out']) ? '1' : '-' ?></td>
                                                <td><?= $day['check_in'] ?></td>
                                                <td><?= $day['check_out'] ?></td>
                                                <td><?= $displayStatus ?></td>
                                                <td><?= $day['late_minutes'] ?></td>
                                                <td><?= htmlspecialchars($day['remarks']) ?></td>
                                            </tr>
                                            <?php
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Summary -->
                            <div class="row mt-4">
                                <div class="col-md-8">
                                    <h6>Summary</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Total Sessions:</strong></td>
                                            <td><?= count(array_filter($dtr_data['days'], function($day) { return $day['check_in'] || $day['check_out']; })) ?></td>
                                            <td><strong>Present Sessions:</strong></td>
                                            <td><?= $dtr_data['summary']['present_days'] ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Late Sessions:</strong></td>
                                            <td><?= $dtr_data['summary']['late_days'] ?></td>
                                            <td><strong>Leave Days:</strong></td>
                                            <td><?= $dtr_data['summary']['leave_days'] ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>No Scan Days:</strong></td>
                                            <td><?= $dtr_data['summary']['no_scan_days'] ?></td>
                                            <td><strong>Absent Sessions:</strong></td>
                                            <td><?= $dtr_data['summary']['absent_days'] ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Total Late Minutes:</strong></td>
                                            <td><?= $dtr_data['summary']['total_late_minutes'] ?></td>
                                            <td><strong>Total Late Hours:</strong></td>
                                            <td><?= $dtr_data['summary']['total_late_hours'] ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="mt-4">
                                        <p><strong>_________________________</strong></p>
                                        <p>Employee Signature</p>
                                        <br><br>
                                        <p><strong>_________________________</strong></p>
                                        <p>Approved By</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const facultyIdInput = document.querySelector('input[name="faculty_id"]');
    facultyIdInput.focus();
});
</script>
</body>
</html>
