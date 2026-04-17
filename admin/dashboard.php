<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$totalFaculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty'")->fetchColumn();
$presentToday = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date=CURDATE() AND status='present'")->fetchColumn();
$onLeaveToday = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE CURDATE() BETWEEN start_date AND end_date AND status='approved'")->fetchColumn();
$pendingLeaves = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();

$recent = $pdo->query("SELECT a.*, u.first_name, u.last_name FROM attendance a JOIN users u ON a.faculty_id=u.id ORDER BY a.created_at DESC LIMIT 10")->fetchAll();

$trend = $pdo->query("SELECT date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present FROM attendance WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY date ORDER BY date")->fetchAll();
$labels = [];
$data = [];
foreach ($trend as $t) {
    $labels[] = date('M d', strtotime($t['date']));
    $data[] = $t['present'];
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
</script>
</body>
</html>