<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Today's attendance (for the old method – can be removed or kept)
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
$stmt->execute([$user_id, $today]);
$todayAtt = $stmt->fetch();

// Monthly summary (for stats)
$monthSummary = $pdo->prepare("SELECT COUNT(CASE WHEN status='present' THEN 1 END) as present, COUNT(CASE WHEN status='late' THEN 1 END) as late FROM attendance WHERE faculty_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
$monthSummary->execute([$user_id]);
$summary = $monthSummary->fetch();

$balStmt = $pdo->prepare("SELECT leave_balance FROM users WHERE id=?");
$balStmt->execute([$user_id]);
$leaveBalance = $balStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty Dashboard | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/faculty-sidebar.php'; ?></div>
        <div class="col-md-10 p-0">
            <?php include '../includes/faculty-tapnav.php'; ?>
            <div class="pt-4">
                
                <!-- Stats Cards (unchanged) -->
                <div class="row g-4 mb-4">
                    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between"><div><h6 class="text-muted">Present This Month</h6><h2 class="text-success"><?= $summary['present'] ?? 0 ?></h2></div><i class="bi bi-calendar-check fs-1 text-success opacity-50"></i></div></div></div></div>
                    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between"><div><h6 class="text-muted">Late Arrivals</h6><h2 class="text-warning"><?= $summary['late'] ?? 0 ?></h2></div><i class="bi bi-clock fs-1 text-warning opacity-50"></i></div></div></div></div>
                    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between"><div><h6 class="text-muted">Leave Balance</h6><h2 class="text-primary"><?= number_format($leaveBalance, 1) ?></h2></div><i class="bi bi-umbrella fs-1 text-primary opacity-50"></i></div></div></div></div>
                </div>

                <!-- Quick Links (unchanged) -->
                <div class="row g-4">
                    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h5>Quick Access</h5><div class="mt-3 d-flex flex-wrap gap-2"><a href="attendance.php" class="btn btn-outline-primary"><i class="bi bi-calendar-check me-1"></i>My Attendance</a><a href="leave-request.php" class="btn btn-outline-warning"><i class="bi bi-envelope-paper me-1"></i>Request Leave</a><a href="schedule.php" class="btn btn-outline-info"><i class="bi bi-clock-history me-1"></i>My Schedule</a><a href="profile.php" class="btn btn-outline-secondary"><i class="bi bi-person me-1"></i>Profile</a></div></div></div></div>
                    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h5>Announcement</h5><p class="text-muted mt-2 mb-0">Please scan your ID for quick check‑in/out. Late arrivals are recorded.</p></div></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>