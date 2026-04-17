<?php
// This block assumes you already have $pdo and $_SESSION['user_id'] available
// Usually you would include this after 'auth.php' and 'database.php'

// Get notification count (unread)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$notification_count = $stmt->fetchColumn();

// Get recent unread notifications (limit 5)
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$recent_notifications = $stmt->fetchAll();
?>

<nav class="admin-topnav">
    <div class="topnav-left">
        <button class="mobile-menu-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <span class="topnav-title">DOIT Admin Panel</span>
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
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($notif['link'] ?? 'notifications.php') ?>">
                                <strong><?= htmlspecialchars($notif['title']) ?></strong><br>
                                <small><?= htmlspecialchars($notif['message']) ?></small>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a class="dropdown-item text-center" href="../notifications.php">View All Notifications</a>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-center" href="#" onclick="markAllAsRead(); return false;">Mark All as Read</a></li>
            </ul>
        </div>

        <!-- User Dropdown -->
        <div class="nav-item dropdown">
            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars($_SESSION['user_name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> My Profile</a></li>
                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear"></i> System Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<script>
function markAllAsRead() {
    fetch('includes/mark-notifications-read.php', {
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
</script>

