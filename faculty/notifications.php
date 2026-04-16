<?php
/**
 * DOIT Faculty Attendance System - Faculty Notifications
 * Notifications viewing for faculty members
 */

// Define system constant for security
define('DOIT_SYSTEM', true);

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

// Require faculty authentication
requireAuth();
if (!isFaculty()) {
    header('Location: ../unauthorized.php');
    exit;
}

// Check session timeout
checkSessionTimeout();

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        redirectWithMessage('notifications.php', 'error', 'Invalid request. Please try again.');
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            
            if ($notificationId > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                    $stmt->execute([$notificationId, $_SESSION['user_id']]);
                    
                    redirectWithMessage('notifications.php', 'success', 'Notification marked as read.');
                    
                } catch (PDOException $e) {
                    error_log("Notification update error: " . $e->getMessage());
                    redirectWithMessage('notifications.php', 'error', 'Failed to update notification.');
                }
            }
            break;
            
        case 'mark_all_read':
            try {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$_SESSION['user_id']]);
                
                redirectWithMessage('notifications.php', 'success', 'All notifications marked as read.');
                
            } catch (PDOException $e) {
                error_log("Bulk notification update error: " . $e->getMessage());
                redirectWithMessage('notifications.php', 'error', 'Failed to update notifications.');
            }
            break;
    }
}

// Get notifications
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll();
    
    // Get unread count
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unreadCount = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Notifications error: " . $e->getMessage());
    $notifications = [];
    $unreadCount = 0;
}

// Get flash message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            margin: 0.25rem 0;
            transition: all 0.3s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .notification-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            margin-bottom: 1rem;
        }
        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .notification-item {
            border-left: 4px solid #667eea;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #e7f3ff;
            border-left-color: #0066cc;
            font-weight: 500;
        }
        
        .type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }
        
        .notification-icon.success {
            background: #28a745;
        }
        
        .notification-icon.error {
            background: #dc3545;
        }
        
        .notification-icon.warning {
            background: #ffc107;
            color: #212529;
        }
        
        .notification-icon.info {
            background: #17a2b8;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center mb-4">
                    <h4><i class="fas fa-graduation-cap"></i> DOIT</h4>
                    <small>Faculty Portal</small>
                </div>
                
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-2"></i> My Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <i class="fas fa-clock me-2"></i> My Schedule
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance.php">
                            <i class="fas fa-check-circle me-2"></i> My Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="leaves.php">
                            <i class="fas fa-file-medical me-2"></i> Leave Requests
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="notifications.php">
                            <i class="fas fa-bell me-2"></i> Notifications
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
                
                <div class="mt-auto pt-4">
                    <div class="text-center">
                        <small class="d-block">Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?></small>
                        <a href="../logout.php" class="btn btn-sm btn-outline-light mt-2">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </nav>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content p-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Notifications</h2>
                    <div>
                        <?php if ($unreadCount > 0): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-check-double"></i> Mark All Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Notifications List -->
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No notifications found.</p>
                    </div>
                <?php else: ?>
                    <div class="notification-card">
                        <div class="card-body p-0">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                                    <div class="d-flex align-items-start">
                                        <div class="notification-icon <?php echo $notification['type']; ?> me-3">
                                            <i class="fas fa-<?php 
                                                echo $notification['type'] === 'success' ? 'check' : 
                                                     ($notification['type'] === 'error' ? 'times' : 
                                                     ($notification['type'] === 'warning' ? 'exclamation' : 'info'); 
                                            ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                <div>
                                                    <?php if (!$notification['is_read']): ?>
                                                        <span class="type-badge badge bg-primary">New</span>
                                                    <?php endif; ?>
                                                    <span class="type-badge badge bg-<?php echo $notification['type']; ?>">
                                                        <?php echo ucfirst($notification['type']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <p class="mb-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo formatDateTime($notification['created_at']); ?>
                                                </small>
                                                <?php if (!$notification['is_read']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="action" value="mark_read">
                                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-check"></i> Mark Read
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Summary Statistics -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php echo count($notifications); ?></h3>
                                <p class="mb-0">Total Notifications</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?php echo count($notifications) - $unreadCount; ?></h3>
                                <p class="mb-0">Read</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h3 class="text-warning"><?php echo $unreadCount; ?></h3>
                                <p class="mb-0">Unread</p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh notifications every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
