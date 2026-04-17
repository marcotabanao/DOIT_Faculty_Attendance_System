<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $contact_number = sanitizeInput($_POST['contact_number'] ?? '');
        
        $profile_photo = $user['profile_photo'];
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['profile_photo'], PROFILE_PHOTO_PATH, ALLOWED_IMAGE_TYPES);
            if ($upload['success']) {
                if ($profile_photo && file_exists(PROFILE_PHOTO_PATH . $profile_photo)) {
                    unlink(PROFILE_PHOTO_PATH . $profile_photo);
                }
                $profile_photo = $upload['filename'];
            } else {
                $error = $upload['error'];
            }
        }
        
        if (empty($error)) {
            $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, contact_number = ?, profile_photo = ? WHERE id = ?");
            if ($update->execute([$first_name, $last_name, $contact_number, $profile_photo, $user_id])) {
                $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                logActivity($pdo, 'UPDATE', 'users', $user_id, "Admin updated own profile");
                $message = "Profile updated successfully.";
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $error = "Failed to update profile.";
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];
        
        if (!password_verify($current, $user['password_hash'])) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($update->execute([$hash, $user_id])) {
                logActivity($pdo, 'UPDATE', 'users', $user_id, "Admin changed password");
                $message = "Password changed successfully.";
            } else {
                $error = "Failed to change password.";
            }
        }
    }
}

$csrf_token = generateCSRFToken();

function safeHtml($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-4">
            <h2 class="fw-semibold mb-4">My Profile</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= safeHtml($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= safeHtml($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            
            <div class="row g-4">
                <!-- Profile Info Card -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm text-center">
                        <div class="card-body">
                            <?php if (!empty($user['profile_photo']) && file_exists(PROFILE_PHOTO_PATH . $user['profile_photo'])): ?>
                                <img src="../assets/uploads/profile_photos/<?= safeHtml($user['profile_photo']) ?>" alt="Profile" class="rounded-circle mb-3" width="150" height="150" style="object-fit: cover; border: 3px solid #800000;">
                            <?php else: ?>
                                <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 150px; height: 150px;"><i class="bi bi-person fs-1 text-white"></i></div>
                            <?php endif; ?>
                            <h4><?= safeHtml($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                            <p class="text-muted"><?= safeHtml($user['employee_id']) ?></p>
                            <p class="text-muted"><?= safeHtml($user['email']) ?></p>
                            <hr>
                            <div class="text-start">
                                <p><strong><i class="bi bi-telephone me-2"></i>Contact:</strong> <?= safeHtml($user['contact_number'] ?: 'Not set') ?></p>
                                <p><strong><i class="bi bi-calendar me-2"></i>Last Login:</strong> <?= safeHtml(formatDate($user['last_login'], 'Y-m-d H:i')) ?></p>
                                <p><strong><i class="bi bi-clock me-2"></i>Created:</strong> <?= safeHtml(formatDate($user['created_at'], 'Y-m-d H:i')) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Forms -->
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Edit Profile Information</h5></div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-3"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" value="<?= safeHtml($user['first_name']) ?>" required></div>
                                    <div class="col-md-6 mb-3"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" value="<?= safeHtml($user['last_name']) ?>" required></div>
                                </div>
                                <div class="mb-3"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" value="<?= safeHtml($user['contact_number']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Profile Photo</label><input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/jpg"><small class="text-muted">Max 5MB. JPG, PNG only.</small></div>
                                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-key me-2"></i>Change Password</h5></div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="mb-3"><label class="form-label">Current Password *</label><input type="password" name="current_password" class="form-control" required></div>
                                <div class="mb-3"><label class="form-label">New Password *</label><input type="password" name="new_password" class="form-control" required><small class="text-muted">Minimum 6 characters</small></div>
                                <div class="mb-3"><label class="form-label">Confirm New Password *</label><input type="password" name="confirm_password" class="form-control" required></div>
                                <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                            </form>
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