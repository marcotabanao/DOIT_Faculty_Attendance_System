<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$message = '';

// Get current active semester
$stmt = $pdo->prepare("SELECT id, name FROM semesters WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$active_semester = $stmt->fetch();

if (!$active_semester) {
    $message = '<div class="alert alert-warning">No active semester found. Please contact administrator.</div>';
    $schedules = [];
} else {
    // Get faculty schedule for active semester
    $stmt = $pdo->prepare("
        SELECT s.*, sem.name as semester_name 
        FROM schedules s
        JOIN semesters sem ON s.semester_id = sem.id
        WHERE s.faculty_id = ? AND s.semester_id = ?
        ORDER BY s.day_of_week, s.time_in
    ");
    $stmt->execute([$user_id, $active_semester['id']]);
    $schedules = $stmt->fetchAll();
}

// Days of week mapping
$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

// Organize schedule by day
$weekly_schedule = [];
foreach ($schedules as $sch) {
    $weekly_schedule[$sch['day_of_week']][] = $sch;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Schedule - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <style>
        .schedule-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
            height: 100%;
        }
        .schedule-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
        }
        .time-badge {
            font-size: 0.85rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0">
            <?php include '../includes/faculty-sidebar.php'; ?>
        </div>
        <div class="col-md-10 p-4">
            <h2>My Weekly Schedule</h2>
            <p class="text-muted">
                Semester: <?= htmlspecialchars($active_semester['name'] ?? 'No active semester') ?>
            </p>
            
            <?= $message ?>
            
            <?php if (empty($schedules)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No schedule has been assigned yet for this semester.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php for ($day = 1; $day <= 7; $day++): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card schedule-card">
                                <div class="card-header bg-maroon text-white">
                                    <h5 class="mb-0"><?= $days[$day] ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php if (isset($weekly_schedule[$day]) && !empty($weekly_schedule[$day])): ?>
                                        <?php foreach ($weekly_schedule[$day] as $class): ?>
                                            <div class="mb-3 border-bottom pb-2">
                                                <div class="fw-bold"><?= htmlspecialchars($class['subject_code']) ?></div>
                                                <div class="time-badge">
                                                    <i class="bi bi-clock"></i> <?= formatTime($class['time_in']) ?> - <?= formatTime($class['time_out']) ?>
                                                </div>
                                                <div><i class="bi bi-door-open"></i> <?= htmlspecialchars($class['room']) ?></div>
                                                <small class="text-muted">Semester: <?= htmlspecialchars($active_semester['name']) ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="bi bi-calendar-x fs-3"></i>
                                            <p class="mb-0 mt-2">No classes</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                
                <!-- Legend / Note -->
                <div class="card mt-3">
                    <div class="card-body bg-light">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> Your schedule is based on the active semester. 
                            If you believe there is an error, please contact the administrator.
                        </small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>