<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle check-in/out (same as dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $today = date('Y-m-d');
    $time = date('H:i:s');

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
    $stmt->execute([$user_id, $today]);
    $today_att = $stmt->fetch();

    if ($action === 'checkin' && !$today_att) {
        $day_of_week = date('N');
        $schStmt = $pdo->prepare("SELECT time_in FROM schedules WHERE faculty_id = ? AND day_of_week = ? AND semester_id = (SELECT id FROM semesters WHERE is_active = 1 LIMIT 1)");
        $schStmt->execute([$user_id, $day_of_week]);
        $schedule = $schStmt->fetch();

        $status = 'present';
        $late_minutes = 0;
        if ($schedule && $time > $schedule['time_in']) {
            $status = 'late';
            $late_minutes = (strtotime($time) - strtotime($schedule['time_in'])) / 60;
        }

        $insert = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, status, late_minutes) VALUES (?, ?, ?, ?, ?)");
        if ($insert->execute([$user_id, $today, $time, $status, $late_minutes])) {
            logActivity($pdo, 'CHECKIN', 'attendance', $pdo->lastInsertId(), "Checked in at $time");
            $message = "Checked in successfully.";
        } else {
            $error = "Check-in failed.";
        }
        header("Location: attendance.php");
        exit();
    } elseif ($action === 'checkout' && $today_att && !$today_att['check_out_time']) {
        $update = $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE id = ?");
        if ($update->execute([$time, $today_att['id']])) {
            logActivity($pdo, 'CHECKOUT', 'attendance', $today_att['id'], "Checked out at $time");
            $message = "Checked out successfully.";
        } else {
            $error = "Check-out failed.";
        }
        header("Location: attendance.php");
        exit();
    }
}

// Get filter parameters
$month = $_GET['month'] ?? date('Y-m');
$year = substr($month, 0, 4);
$month_num = substr($month, 5, 2);

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date DESC");
$stmt->execute([$user_id, $month]);
$records = $stmt->fetchAll();

// Debug: Show the actual query and results
$debug_query = "SELECT * FROM attendance WHERE faculty_id = $user_id AND DATE_FORMAT(date, '%Y-%m') = '$month' ORDER BY date DESC";
$debug_count = count($records);

$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
$stmt->execute([$user_id, $today]);
$today_att = $stmt->fetch();

$present = 0;
$late = 0;
$absent = 0;
$on_leave = 0;
$total_days = date('t', strtotime($month));
foreach ($records as $rec) {
    switch ($rec['status']) {
        case 'present': $present++; break;
        case 'late': $late++; break;
        case 'absent': $absent++; break;
        case 'on_leave': $on_leave++; break;
    }
}
$not_marked = $total_days - ($present + $late + $absent + $on_leave);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0">
            <?php include '../includes/faculty-sidebar.php'; ?>
        </div>
        <div class="col-md-10 p-0">
            <?php include '../includes/faculty-tapnav.php'; ?>
            <div class="p-4" style="padding-top: 80px !important;">
                <h2>My Attendance</h2>
                <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3" onsubmit="console.log('Form submitting with month:', this.month.value); return true;">
                            <div class="col-auto"><label>Select Month</label><input type="month" name="month" class="form-control" value="<?= $month ?>" onchange="console.log('Month changed to:', this.value)"></div>
                            <div class="col-auto d-flex align-items-end"><button type="submit" class="btn btn-primary">View</button></div>
                        </form>
                        <?php if (isset($_GET['month'])): ?>
                            <div class="alert alert-info mt-3">
                                <small>Currently viewing: <?= date('F Y', strtotime($month)) ?> (Month parameter: <?= htmlspecialchars($month) ?>)</small><br>
                                <small>Records found: <?= $debug_count ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-2"><div class="card bg-success text-white"><div class="card-body text-center"><h5>Present</h5><h3><?= $present ?></h3></div></div></div>
                    <div class="col-md-2"><div class="card bg-warning text-dark"><div class="card-body text-center"><h5>Late</h5><h3><?= $late ?></h3></div></div></div>
                    <div class="col-md-2"><div class="card bg-danger text-white"><div class="card-body text-center"><h5>Absent</h5><h3><?= $absent ?></h3></div></div></div>
                    <div class="col-md-2"><div class="card bg-primary text-white"><div class="card-body text-center"><h5>On Leave</h5><h3><?= $on_leave ?></h3></div></div></div>
                    <div class="col-md-2"><div class="card bg-secondary text-white"><div class="card-body text-center"><h5>Not Marked</h5><h3><?= $not_marked ?></h3></div></div></div>
                    <div class="col-md-2"><div class="card bg-info text-white"><div class="card-body text-center"><h5>Total Days</h5><h3><?= $total_days ?></h3></div></div></div>
                </div>

                <div class="card">
                    <div class="card-header">Attendance Records for <?= date('F Y', strtotime($month)) ?></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-dark"><tr><th>Date</th><th>Day</th><th>Check In</th><th>Check Out</th><th>Status</th><th>Late (min)</th><th>Remarks</th></tr></thead>
                                <tbody>
                                    <?php if (empty($records)): ?><tr><td colspan="7" class="text-center">No records for this month.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($records as $rec): ?>
                                        <tr>
                                            <td><?= formatDate($rec['date']) ?></td>
                                            <td><?= date('l', strtotime($rec['date'])) ?></td>
                                            <td><?= $rec['check_in_time'] ? formatTime($rec['check_in_time']) : '-' ?></td>
                                            <td><?= $rec['check_out_time'] ? formatTime($rec['check_out_time']) : '-' ?></td>
                                            <td><?= getStatusBadge($rec['status']) ?></td>
                                            <td><?= $rec['late_minutes'] ?></td>
                                            <td><?= htmlspecialchars($rec['remarks'] ?? '') ?></td>
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
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>