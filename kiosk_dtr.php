<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

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
                    
                    // Determine if it's a weekend
                    $is_weekend = ($day_of_week >= 6);
                    
                    // Build day data
                    $day_data = [
                        'day' => $day,
                        'date' => $date,
                        'day_of_week' => $day_of_week,
                        'is_weekend' => $is_weekend,
                        'check_in' => $record ? formatTime($record['check_in_time']) : '',
                        'check_out' => $record ? formatTime($record['check_out_time']) : '',
                        'status' => $record ? $record['status'] : ($is_weekend ? 'Weekend' : 'Absent'),
                        'late_minutes' => $record ? ($record['late_minutes'] ?? 0) : 0,
                        'remarks' => $record ? ($record['remarks'] ?? '') : ''
                    ];
                    
                    $dtr_data[] = $day_data;
                }
                
                // Calculate summary statistics
                $present_days = 0;
                $late_days = 0;
                $absent_days = 0;
                $total_late_minutes = 0;
                
                foreach ($dtr_data as $day) {
                    if (!$day['is_weekend']) {
                        if ($day['status'] === 'present') {
                            $present_days++;
                        } elseif ($day['status'] === 'late') {
                            $present_days++;
                            $late_days++;
                            $total_late_minutes += $day['late_minutes'];
                        } elseif ($day['status'] === 'Absent') {
                            $absent_days++;
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
                        'total_late_minutes' => $total_late_minutes,
                        'total_late_hours' => round($total_late_minutes / 60, 2),
                        'working_days' => $present_days + $late_days + $absent_days
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
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <h2><i class="bi bi-file-earmark-text"></i> DTR Generator</h2>
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
                                        // Group sessions by date
                                        $grouped_sessions = [];
                                        foreach ($dtr_data['days'] as $day) {
                                            if ($day['check_in'] || $day['check_out']) {
                                                $date_key = $day['date'];
                                                if (!isset($grouped_sessions[$date_key])) {
                                                    $grouped_sessions[$date_key] = [];
                                                }
                                                $grouped_sessions[$date_key][] = $day;
                                            }
                                        }
                                        
                                        foreach ($grouped_sessions as $date => $sessions) {
                                            $session_num = 1;
                                            foreach ($sessions as $session) {
                                                ?>
                                                <tr class="<?= $session['is_weekend'] ? 'weekend' : ($session['status'] === 'present' ? 'present' : ($session['status'] === 'late' ? 'late' : 'absent')) ?>">
                                                    <td><?= $session['day'] ?></td>
                                                    <td><?= formatDate($session['date']) ?></td>
                                                    <td><?= $session_num ?></td>
                                                    <td><?= $session['check_in'] ?></td>
                                                    <td><?= $session['check_out'] ?></td>
                                                    <td><?= ucfirst($session['status']) ?></td>
                                                    <td><?= $session['late_minutes'] ?></td>
                                                    <td><?= htmlspecialchars($session['remarks']) ?></td>
                                                </tr>
                                                <?php
                                                $session_num++;
                                            }
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
                                            <td><?= array_sum(array_column($dtr_data['days'], function($day) { return isset($day['check_in']) || isset($day['check_out']) ? 1 : 0; })) ?></td>
                                            <td><strong>Present Sessions:</strong></td>
                                            <td><?= $dtr_data['summary']['present_days'] ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Late Sessions:</strong></td>
                                            <td><?= $dtr_data['summary']['late_days'] ?></td>
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
