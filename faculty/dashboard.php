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
        exit();
    } elseif ($action === 'checkout' && $todayAtt && !$todayAtt['check_out_time']) {
        $upd = $pdo->prepare("UPDATE attendance SET check_out_time=? WHERE id=?");
        $upd->execute([$time, $todayAtt['id']]);
        logActivity($pdo, 'CHECKOUT', 'attendance', $todayAtt['id'], "Checked out at $time");
        header('Location: dashboard.php');
        exit();
    }
}

// Monthly summary
$monthSummary = $pdo->prepare("SELECT 
    COUNT(CASE WHEN status='present' THEN 1 END) as present, 
    COUNT(CASE WHEN status='late' THEN 1 END) as late,
    COUNT(CASE WHEN status='absent' THEN 1 END) as absent
    FROM attendance WHERE faculty_id=? AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
$monthSummary->execute([$user_id]);
$summary = $monthSummary->fetch();

$leaveBalance = $pdo->prepare("SELECT leave_balance FROM users WHERE id=?")->execute([$user_id]) ? $pdo->prepare("SELECT leave_balance FROM users WHERE id=?")->execute([$user_id]) : 0; // simplified, but we'll fetch properly
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
    <style>
        .check-card {
            background: linear-gradient(145deg, #ffffff 0%, #faf8f5 100%);
            border: 1px solid #eaeaea;
            border-radius: 28px;
            padding: 1.8rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        .stat-card {
            border: none;
            border-radius: 20px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.05); }
        .quick-link {
            border-radius: 40px;
            padding: 0.5rem 1rem;
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <div class="col-md-2"><?php include '../includes/faculty-sidebar.php'; ?></div>
        <div class="col-md-10 p-4" style="background: #f8fafc; min-height: 100vh;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-semibold" style="color: #1e2a3e;">Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?></h2>
                    <p class="text-muted"><?= date('l, F d, Y') ?></p>
                </div>
                <div class="text-muted small">Faculty Portal</div>
            </div>

            <!-- Check-in/out Card -->
            <div class="check-card mb-5">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <h4 class="fw-semibold" style="color: #800000;">Today's Attendance</h4>
                        <?php if($todayAtt): ?>
                            <div class="mt-3">
                                <div class="d-flex gap-4 flex-wrap">
                                    <div><span class="text-muted">Check-in:</span> <strong><?= $todayAtt['check_in_time'] ? formatTime($todayAtt['check_in_time']) : 'Not yet' ?></strong></div>
                                    <div><span class="text-muted">Check-out:</span> <strong><?= $todayAtt['check_out_time'] ? formatTime($todayAtt['check_out_time']) : 'Not yet' ?></strong></div>
                                    <div><span class="text-muted">Status:</span> <?= getStatusBadge($todayAtt['status']) ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="mt-2">You haven't checked in today.</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <form method="POST">
                            <?php if(!$todayAtt || !$todayAtt['check_in_time']): ?>
                                <button name="action" value="checkin" class="btn btn-primary btn-lg rounded-pill px-4"><i class="bi bi-box-arrow-in-right me-2"></i>Check In</button>
                            <?php elseif(!$todayAtt['check_out_time']): ?>
                                <button name="action" value="checkout" class="btn btn-warning btn-lg rounded-pill px-4"><i class="bi bi-box-arrow-right me-2"></i>Check Out</button>
                            <?php else: ?>
                                <button class="btn btn-success btn-lg rounded-pill px-4" disabled><i class="bi bi-check-circle me-2"></i>Completed</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Stats Row -->
            <div class="row g-4 mb-5">
                <div class="col-md-4">
                    <div class="stat-card p-3">
                        <div class="d-flex justify-content-between">
                            <div><span class="text-muted text-uppercase small">Present this month</span><h2 class="mt-2 fw-bold text-success"><?= $summary['present'] ?? 0 ?></h2></div>
                            <i class="bi bi-calendar-check-fill fs-1 text-success opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3">
                        <div class="d-flex justify-content-between">
                            <div><span class="text-muted text-uppercase small">Late arrivals</span><h2 class="mt-2 fw-bold text-warning"><?= $summary['late'] ?? 0 ?></h2></div>
                            <i class="bi bi-clock-fill fs-1 text-warning opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3">
                        <div class="d-flex justify-content-between">
                            <div><span class="text-muted text-uppercase small">Leave balance</span><h2 class="mt-2 fw-bold" style="color: #800000;"><?= number_format($leaveBalance, 1) ?></h2></div>
                            <i class="bi bi-umbrella-fill fs-1 opacity-50" style="color: #800000;"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="stat-card p-3 h-100">
                        <h5 class="fw-semibold">Quick Access</h5>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a href="attendance.php" class="btn btn-outline-primary quick-link"><i class="bi bi-calendar-check me-1"></i>My Attendance</a>
                            <a href="leave-request.php" class="btn btn-outline-warning quick-link"><i class="bi bi-envelope-paper me-1"></i>Request Leave</a>
                            <a href="schedule.php" class="btn btn-outline-info quick-link"><i class="bi bi-clock-history me-1"></i>My Schedule</a>
                            <a href="profile.php" class="btn btn-outline-secondary quick-link"><i class="bi bi-person me-1"></i>Profile</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card p-3 h-100">
                        <h5 class="fw-semibold">Announcement</h5>
                        <p class="text-muted mt-2 mb-0">Please ensure you check in and out daily. Late arrivals are recorded and may affect your attendance record.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>