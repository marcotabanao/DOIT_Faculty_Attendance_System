<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Fetch current user data
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
        $contact_number = sanitizeInput($_POST['contact_number']);
        $position = sanitizeInput($_POST['position']);
        
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
            $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, contact_number = ?, position = ?, profile_photo = ? WHERE id = ?");
            if ($update->execute([$first_name, $last_name, $contact_number, $position, $profile_photo, $user_id])) {
                $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                logActivity($pdo, 'UPDATE', 'users', $user_id, "Faculty updated own profile");
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
                logActivity($pdo, 'UPDATE', 'users', $user_id, "Faculty changed password");
                $message = "Password changed successfully.";
            } else {
                $error = "Failed to change password.";
            }
        }
    }
}

// Get department name
$dept_name = 'Not assigned';
if ($user['department_id']) {
    $dept = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $dept->execute([$user['department_id']]);
    $dept_name = $dept->fetchColumn() ?: 'Not assigned';
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
    <style>
        .profile-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .profile-sidebar {
            text-align: center;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 20px;
        }
        .profile-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #800000;
            margin-bottom: 1rem;
        }
    </style>
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
            <h2>My Profile</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Profile Info Card -->
                <div class="col-md-4 mb-4">
                    <div class="profile-sidebar">
                        <?php if ($user['profile_photo'] && file_exists(PROFILE_PHOTO_PATH . $user['profile_photo'])): ?>
                            <img src="../assets/uploads/profile_photos/<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile Photo" class="profile-photo">
                        <?php else: ?>
                            <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px;">
                                <i class="bi bi-person fs-1 text-white"></i>
                            </div>
                        <?php endif; ?>
                        <h4 class="mt-3"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                        <p class="text-muted"><?= htmlspecialchars($user['employee_id']) ?></p>
                        <p class="text-muted"><?= htmlspecialchars($user['email']) ?></p>
                        <hr>
                        <div class="text-start">
                            <p><strong><i class="bi bi-building"></i> Department:</strong> <?= htmlspecialchars($dept_name) ?></p>
                            <p><strong><i class="bi bi-briefcase"></i> Position:</strong> <?= htmlspecialchars($user['position'] ?: 'Not set') ?></p>
                            <p><strong><i class="bi bi-telephone"></i> Contact:</strong> <?= htmlspecialchars($user['contact_number'] ?: 'Not set') ?></p>
                            <p><strong><i class="bi bi-calendar"></i> Leave Balance:</strong> <?= number_format($user['leave_balance'], 1) ?> days</p>
                            <p><strong><i class="bi bi-calendar-date"></i> Hire Date:</strong> <?= formatDate($user['hire_date']) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Profile Form -->
                <div class="col-md-8">
                    <div class="card profile-card mb-4">
                        <div class="card-header">Edit Profile Information</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label>First Name *</label>
                                        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label>Last Name *</label>
                                        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label>Contact Number</label>
                                        <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($user['contact_number']) ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label>Position</label>
                                        <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($user['position']) ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Profile Photo</label>
                                    <input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/jpg">
                                    <small class="text-muted">Max 5MB. JPG, PNG only.</small>
                                </div>
                                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Change Password Form -->
                    <div class="card profile-card">
                        <div class="card-header">Change Password</div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="mb-3">
                                    <label>Current Password *</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>New Password *</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                <div class="mb-3">
                                    <label>Confirm New Password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                            </form>
                        </div>
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