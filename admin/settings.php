<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../config/constants.php';  // Ensure constants are loaded
require_once '../includes/functions.php';

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $site_name = sanitizeInput($_POST['site_name']);
    $session_timeout = (int)$_POST['session_timeout'];
    $academic_year = sanitizeInput($_POST['academic_year']);
    $timezone = sanitizeInput($_POST['timezone']);
    
    updateSetting($pdo, 'site_name', $site_name);
    updateSetting($pdo, 'session_timeout', $session_timeout);
    updateSetting($pdo, 'academic_year', $academic_year);
    updateSetting($pdo, 'timezone', $timezone);
    
    // Handle logo upload - now PROFILE_PHOTO_PATH is defined
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['logo'], PROFILE_PHOTO_PATH, ALLOWED_IMAGE_TYPES);
        if ($upload['success']) {
            updateSetting($pdo, 'logo_path', $upload['filename']);
            $message .= " Logo uploaded successfully.";
        } else {
            $error .= " Logo upload failed: " . $upload['error'];
        }
    }
    
    logActivity($pdo, 'UPDATE', 'settings', 0, "Updated system settings");
    $message = "Settings updated successfully." . ($message ?: '');
}

// Load current settings
$site_name = getSetting($pdo, 'site_name') ?: 'DOIT Faculty Attendance System';
$session_timeout = getSetting($pdo, 'session_timeout') ?: '30';
$academic_year = getSetting($pdo, 'academic_year') ?: date('Y') . '-' . (date('Y')+1);
$timezone = getSetting($pdo, 'timezone') ?: 'Asia/Manila';
$logo_path = getSetting($pdo, 'logo_path');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-4">
            <h2>System Settings</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">General Settings</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label>Site Name</label>
                                    <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars($site_name) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Session Timeout (minutes)</label>
                                    <input type="number" name="session_timeout" class="form-control" value="<?= $session_timeout ?>" min="5" max="120" required>
                                </div>
                                <div class="mb-3">
                                    <label>Academic Year</label>
                                    <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($academic_year) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Timezone</label>
                                    <select name="timezone" class="form-select">
                                        <option value="Asia/Manila" <?= $timezone == 'Asia/Manila' ? 'selected' : '' ?>>Asia/Manila (GMT+8)</option>
                                        <option value="Asia/Shanghai" <?= $timezone == 'Asia/Shanghai' ? 'selected' : '' ?>>Asia/Shanghai</option>
                                        <option value="UTC" <?= $timezone == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                        <option value="America/New_York" <?= $timezone == 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Site Logo</label>
                                    <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/jpg">
                                    <?php if ($logo_path): ?>
                                        <div class="mt-2">
                                            <img src="../assets/uploads/profile_photos/<?= $logo_path ?>" alt="Logo" style="max-height: 60px;">
                                            <small class="text-muted d-block">Current logo</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" name="update_settings" class="btn btn-primary">Save Settings</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">System Information</div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr><th>PHP Version</th><td><?= phpversion() ?></td></tr>
                                <tr><th>MySQL Version</th><td><?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?></td></tr>
                                <tr><th>Server Software</th><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></td></tr>
                                <tr><th>Upload Max Size</th><td><?= ini_get('upload_max_filesize') ?></td></tr>
                                <tr><th>Session Save Path</th><td><?= session_save_path() ?: 'System default' ?></td></tr>
                            </table>
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