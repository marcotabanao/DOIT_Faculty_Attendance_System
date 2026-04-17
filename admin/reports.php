<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$report_type = $_GET['type'] ?? 'attendance_summary';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$department_id = $_GET['department'] ?? '';

// Get departments for filter
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// Build report data based on type
$report_data = [];
$report_title = '';

if ($report_type === 'attendance_summary') {
    $report_title = "Attendance Summary ($start_date to $end_date)";
    
    $sql = "SELECT u.id, u.employee_id, u.first_name, u.last_name, d.name as department,
                   COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                   COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
                   COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                   COUNT(CASE WHEN a.status = 'on_leave' THEN 1 END) as on_leave,
                   SUM(a.late_minutes) as total_late_mins
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN attendance a ON u.id = a.faculty_id AND a.date BETWEEN ? AND ?
            WHERE u.role = 'faculty'";
    $params = [$start_date, $end_date];
    
    if ($department_id) {
        $sql .= " AND u.department_id = ?";
        $params[] = $department_id;
    }
    $sql .= " GROUP BY u.id ORDER BY u.last_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll();
    
} elseif ($report_type === 'leave_usage') {
    $report_title = "Leave Usage Report ($start_date to $end_date)";
    
    $sql = "SELECT u.employee_id, u.first_name, u.last_name, d.name as department,
                   lr.leave_type, lr.start_date, lr.end_date, lr.days_applied, lr.status, lr.created_at
            FROM leave_requests lr
            JOIN users u ON lr.faculty_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE lr.start_date BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    
    if ($department_id) {
        $sql .= " AND u.department_id = ?";
        $params[] = $department_id;
    }
    $sql .= " ORDER BY lr.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll();
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = $report_type . '_' . date('Y-m-d') . '.csv';
    exportToCSV($report_data, $filename);
}

// Prepare chart data for attendance summary
$chart_labels = [];
$chart_present = [];
$chart_late = [];
if ($report_type === 'attendance_summary' && !empty($report_data)) {
    foreach (array_slice($report_data, 0, 10) as $row) {
        $chart_labels[] = $row['first_name'] . ' ' . $row['last_name'];
        $chart_present[] = $row['present'];
        $chart_late[] = $row['late'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-0">
            <?php include '../includes/admin-topnav.php'; ?>
            <div class="p-4" style="padding-top: 80px !important;">
            <h2>Reports & Analytics</h2>
            
            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Report Type</label>
                            <select name="type" class="form-select">
                                <option value="attendance_summary" <?= $report_type == 'attendance_summary' ? 'selected' : '' ?>>Attendance Summary</option>
                                <option value="leave_usage" <?= $report_type == 'leave_usage' ? 'selected' : '' ?>>Leave Usage</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                        </div>
                        <div class="col-md-2">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label>Department</label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= $department_id == $dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Generate</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Export Button -->
            <?php if (!empty($report_data)): ?>
            <div class="mb-3 text-end">
                <a href="?<?= $_SERVER['QUERY_STRING'] ?>&export=csv" class="btn btn-success">Export to CSV</a>
            </div>
            <?php endif; ?>
            
            <!-- Chart (for attendance summary) -->
            <?php if ($report_type == 'attendance_summary' && !empty($chart_labels)): ?>
            <div class="card mb-4">
                <div class="card-header">Top 10 Faculty Attendance</div>
                <div class="card-body">
                    <canvas id="attendanceChart" height="300"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Report Table -->
            <div class="card">
                <div class="card-header">
                    <h5><?= $report_title ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <?php if ($report_type == 'attendance_summary'): ?>
                                        <th>Employee ID</th><th>Name</th><th>Department</th><th>Present</th><th>Late</th><th>Absent</th><th>On Leave</th><th>Total Late (min)</th>
                                    <?php elseif ($report_type == 'leave_usage'): ?>
                                        <th>Employee ID</th><th>Name</th><th>Department</th><th>Leave Type</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Status</th><th>Requested On</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($report_data)): ?>
                                    <tr><td colspan="10" class="text-center">No data found for the selected criteria.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type == 'attendance_summary'): ?>
                                                <td><?= htmlspecialchars($row['employee_id']) ?></td>
                                                <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department'] ?? 'N/A') ?></td>
                                                <td><?= $row['present'] ?></td>
                                                <td><?= $row['late'] ?></td>
                                                <td><?= $row['absent'] ?></td>
                                                <td><?= $row['on_leave'] ?></td>
                                                <td><?= $row['total_late_mins'] ?></td>
                                            <?php elseif ($report_type == 'leave_usage'): ?>
                                                <td><?= htmlspecialchars($row['employee_id']) ?></td>
                                                <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                                <td><?= htmlspecialchars($row['department'] ?? 'N/A') ?></td>
                                                <td><?= getLeaveTypeBadge($row['leave_type']) ?></td>
                                                <td><?= formatDate($row['start_date']) ?></td>
                                                <td><?= formatDate($row['end_date']) ?></td>
                                                <td><?= $row['days_applied'] ?></td>
                                                <td><?= getLeaveStatusBadge($row['status']) ?></td>
                                                <td><?= formatDate($row['created_at'], 'Y-m-d') ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($report_type == 'attendance_summary' && !empty($chart_labels)): ?>
<script>
    const ctx = document.getElementById('attendanceChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                { label: 'Present', data: <?= json_encode($chart_present) ?>, backgroundColor: 'rgba(40,167,69,0.7)' },
                { label: 'Late', data: <?= json_encode($chart_late) ?>, backgroundColor: 'rgba(255,193,7,0.7)' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true }
    });
</script>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>