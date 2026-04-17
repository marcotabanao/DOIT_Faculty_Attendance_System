<?php
if (!isset($pdo)) require_once __DIR__ . '/../config/database.php';
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['user_id'])) return;
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$notification_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$recent_notifications = $stmt->fetchAll();

// Get user profile photo
$stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch();
$profile_photo = $user_profile['profile_photo'] ?? '';
$profile_photo_path = '';
if ($profile_photo && file_exists(__DIR__ . '/../assets/uploads/profile_photos/' . $profile_photo)) {
    $profile_photo_path = '../assets/uploads/profile_photos/' . $profile_photo;
}
?>
<nav class="admin-topnav">
    <div class="topnav-left">
        <button class="mobile-menu-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <span class="topnav-title">DOIT Faculty Portal</span>
    </div>

    <div class="topnav-right">
        <!-- Notifications Dropdown -->
        <div class="nav-item dropdown">
            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button">
                <i class="bi bi-bell"></i>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?= $notification_count ?></span>
                <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" style="width: 320px;">
                <li><h6 class="dropdown-header">Recent Notifications</h6></li>
                <?php if (empty($recent_notifications)): ?>
                    <li><a class="dropdown-item" href="#">No new notifications</a></li>
                <?php else: ?>
                    <?php foreach ($recent_notifications as $notif): ?>
                        <?php
                        $link = $notif['link'] ?? '';
                        if (strpos($link, 'approve')!==false || strpos($link, 'reject')!==false || strpos($link, 'admin/leaves')!==false) 
                            $link = '../faculty/leave-request.php';
                        if (empty($link)) 
                            $link = '../faculty/leave-request.php';
                        ?>
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($link) ?>">
                                <strong><?= htmlspecialchars($notif['title']) ?></strong><br>
                                <small><?= htmlspecialchars($notif['message']) ?></small>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <li><a class="dropdown-item text-center" href="../faculty/notifications.php">View All Notifications</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-center" href="#" onclick="markAllAsRead(); return false;">Mark All as Read</a></li>
            </ul>
        </div>

        <!-- User Dropdown -->
        <div class="nav-item dropdown">
            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button">
                <?php if ($profile_photo_path): ?>
                    <img src="<?= htmlspecialchars($profile_photo_path) ?>" alt="Profile" class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover; border: 2px solid #800000;">
                <?php else: ?>
                    <i class="bi bi-person-circle"></i>
                <?php endif; ?>
                <span><?= htmlspecialchars($_SESSION['user_name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> My Profile</a></li>
                <li><a class="dropdown-item" href="attendance.php"><i class="bi bi-calendar-check"></i> My Attendance</a></li>
                <li><a class="dropdown-item" href="leave-request.php"><i class="bi bi-envelope-paper"></i> Leave Requests</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<script>
function markAllAsRead() {
    fetch('../includes/mark-notifications-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'user_id=<?= $_SESSION['user_id'] ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to mark notifications as read.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred.');
    });
}

function toggleSidebar() {
    document.querySelector('.sidebar')?.classList.toggle('mobile-open');
}

document.addEventListener('DOMContentLoaded', function() {
    [].slice.call(document.querySelectorAll('.dropdown-toggle')).map(el => new bootstrap.Dropdown(el));
});
</script>