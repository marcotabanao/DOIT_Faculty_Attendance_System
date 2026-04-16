<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Today's attendance
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
$stmt->execute([$user_id, $today]);
$todayAtt = $stmt->fetch();

// Handle check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $time = date('H:i:s');
    if ($action === 'checkin' && !$todayAtt) {
        // Determine late status based on schedule
        $day = date('N');
        $schStmt = $pdo->prepare("SELECT time_in FROM schedules WHERE faculty_id=? AND day_of_week=? AND semester_id=(SELECT id FROM semesters WHERE is_active=1 LIMIT 1)");
        $schStmt->execute([$user_id, $day]);
        $schedule = $schStmt->fetch();
        $status = 'present';
        $lateMins = 0;
        if ($schedule && $time > $schedule['time_in']) {
            $status = 'late';
            $lateMins = (strtotime($time) - strtotime($schedule['time_in'])) / 60;
        }
        $ins = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, status, late_minutes) VALUES (?,?,?,?,?)");
        $ins->execute([$user_id, $today, $time, $status, $lateMins]);
        logActivity($pdo, 'CHECKIN', 'attendance', $pdo->lastInsertId(), "Checked in at $time");
        header('Location: dashboard.php');
    } elseif ($action === 'checkout' && $todayAtt && !$todayAtt['check_out_time']) {
        $upd = $pdo->prepare("UPDATE attendance SET check_out_time=? WHERE id=?");
        $upd->execute([$time, $todayAtt['id']]);
        logActivity($pdo, 'CHECKOUT', 'attendance', $todayAtt['id'], "Checked out at $time");
        header('Location: dashboard.php');
    }
}
// Get summary for current month
$monthSummary = $pdo->prepare("SELECT COUNT(CASE WHEN status='present' THEN 1 END) as present, COUNT(CASE WHEN status='late' THEN 1 END) as late FROM attendance WHERE faculty_id=? AND MONTH(date)=MONTH(CURDATE())");
$monthSummary->execute([$user_id]);
$summary = $monthSummary->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .check-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; padding: 20px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0 sidebar">
            <div class="text-center py-4"><i class="bi bi-calendar-check fs-1 text-white"></i><h5 class="text-white">DOIT</h5></div>
            <nav class="nav flex-column">
                <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link" href="attendance.php"><i class="bi bi-calendar-check"></i> My Attendance</a>
                <a class="nav-link" href="leave-request.php"><i class="bi bi-envelope-paper"></i> Leave Requests</a>
                <a class="nav-link" href="schedule.php"><i class="bi bi-clock-history"></i> My Schedule</a>
                <a class="nav-link" href="profile.php"><i class="bi bi-person"></i> Profile</a>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </nav>
        </div>
        <div class="col-md-10 p-4">
            <h2>Welcome, <?php echo $_SESSION['user_name']; ?></h2>
            <div class="check-card mt-3">
                <div class="row">
                    <div class="col-md-6">
                        <h4>Today: <?php echo date('F d, Y'); ?></h4>
                        <?php if($todayAtt): ?>
                            <p>Check-in: <?php echo $todayAtt['check_in_time'] ? formatTime($todayAtt['check_in_time']) : 'Not yet'; ?></p>
                            <p>Check-out: <?php echo $todayAtt['check_out_time'] ? formatTime($todayAtt['check_out_time']) : 'Not yet'; ?></p>
                            <p>Status: <?php echo getStatusBadge($todayAtt['status']); ?></p>
                        <?php else: ?>
                            <p>Not checked in yet</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <form method="POST">
                            <?php if(!$todayAtt || !$todayAtt['check_in_time']): ?>
                                <button name="action" value="checkin" class="btn btn-light btn-lg"><i class="bi bi-box-arrow-in-right"></i> Check In</button>
                            <?php elseif(!$todayAtt['check_out_time']): ?>
                                <button name="action" value="checkout" class="btn btn-warning btn-lg"><i class="bi bi-box-arrow-right"></i> Check Out</button>
                            <?php else: ?>
                                <button class="btn btn-success btn-lg" disabled>Completed</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-4"><div class="card"><div class="card-body"><h6>Present This Month</h6><h2><?php echo $summary['present'] ?? 0; ?></h2></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><h6>Late Arrivals</h6><h2 class="text-warning"><?php echo $summary['late'] ?? 0; ?></h2></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><h6>Leave Balance</h6><?php $bal = $pdo->prepare("SELECT leave_balance FROM users WHERE id=?"); $bal->execute([$user_id]); echo '<h2>'.number_format($bal->fetchColumn(),1).'</h2>'; ?></div></div></div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>