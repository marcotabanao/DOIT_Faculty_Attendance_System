<?php
// Ensure constants are loaded (especially PROFILE_PHOTO_PATH)
if (!defined('PROFILE_PHOTO_PATH')) {
    require_once __DIR__ . '/../config/constants.php';
}
// Fetch logo path from database
$logo_path = getSetting($pdo, 'logo_path');
?>
<div class="sidebar">
    <div class="text-center py-4">
        <?php if ($logo_path && file_exists(PROFILE_PHOTO_PATH . $logo_path)): ?>
            <img src="../assets/uploads/profile_photos/<?= htmlspecialchars($logo_path) ?>" alt="DOIT Logo" style="max-height: 70px; margin-bottom: 10px;">
        <?php else: ?>
            <i class="bi bi-calendar-check fs-1 text-white"></i>
        <?php endif; ?>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : '' ?>" href="attendance.php">
            <i class="bi bi-calendar-check"></i> My Attendance
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'leave-request.php' ? 'active' : '' ?>" href="leave-request.php">
            <i class="bi bi-envelope-paper"></i> Leave Requests
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'schedule.php' ? 'active' : '' ?>" href="schedule.php">
            <i class="bi bi-clock-history"></i> My Schedule
        </a>
    </nav>
</div>