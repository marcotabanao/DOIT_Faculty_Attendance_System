<?php
/**
 * DOIT Faculty Attendance System - Faculty Profile
 * Profile management for faculty members
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        redirectWithMessage('profile.php', 'error', 'Invalid request. Please try again.');
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $lastName = sanitizeInput($_POST['last_name'] ?? '');
            $middleName = sanitizeInput($_POST['middle_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            
            // Validation
            if (empty($firstName) || empty($lastName) || empty($email)) {
                redirectWithMessage('profile.php', 'error', 'Please fill in all required fields.');
            }
            
            if (!validateEmail($email)) {
                redirectWithMessage('profile.php', 'error', 'Please enter a valid email address.');
            }
            
            try {
                // Update faculty profile
                $stmt = $pdo->prepare("
                    UPDATE faculty 
                    SET first_name = ?, last_name = ?, middle_name = ?, email = ?, phone = ?
                    WHERE id = ?
                ");
                $stmt->execute([$firstName, $lastName, $middleName, $email, $phone, $_SESSION['faculty_id']]);
                
                // Update session variables
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                
                // Log user action
                logUserAction('update', 'faculty', $_SESSION['faculty_id']);
                
                redirectWithMessage('profile.php', 'success', 'Profile updated successfully.');
                
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                redirectWithMessage('profile.php', 'error', 'Failed to update profile.');
            }
            break;
            
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Validation
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                redirectWithMessage('profile.php', 'error', 'Please fill in all password fields.');
            }
            
            if ($newPassword !== $confirmPassword) {
                redirectWithMessage('profile.php', 'error', 'New passwords do not match.');
            }
            
            if (strlen($newPassword) < 8) {
                redirectWithMessage('profile.php', 'error', 'Password must be at least 8 characters long.');
            }
            
            try {
                // Get current user password
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                // Verify current password
                if (verifyPassword($currentPassword, $user['password_hash'])) {
                    // Update password
                    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$newPasswordHash, $_SESSION['user_id']]);
                    
                    // Log user action
                    logUserAction('update', 'users', $_SESSION['user_id']);
                    
                    redirectWithMessage('profile.php', 'success', 'Password changed successfully.');
                } else {
                    redirectWithMessage('profile.php', 'error', 'Current password is incorrect.');
                }
                
            } catch (PDOException $e) {
                error_log("Password change error: " . $e->getMessage());
                redirectWithMessage('profile.php', 'error', 'Failed to change password.');
            }
            break;
            
        case 'upload_photo':
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadFile($_FILES['profile_photo'], 'uploads/profiles/');
                if ($uploadResult['success']) {
                    try {
                        // Update faculty profile photo
                        $stmt = $pdo->prepare("UPDATE faculty SET profile_photo = ? WHERE id = ?");
                        $stmt->execute([$uploadResult['filename'], $_SESSION['faculty_id']]);
                        
                        // Log user action
                        logUserAction('update', 'faculty', $_SESSION['faculty_id']);
                        
                        redirectWithMessage('profile.php', 'success', 'Profile photo updated successfully.');
                        
                    } catch (PDOException $e) {
                        error_log("Photo upload error: " . $e->getMessage());
                        redirectWithMessage('profile.php', 'error', 'Failed to update profile photo.');
                    }
                } else {
                    redirectWithMessage('profile.php', 'error', $uploadResult['message']);
                }
            } else {
                redirectWithMessage('profile.php', 'error', 'Please select a photo to upload.');
            }
            break;
    }
}

// Get faculty information
try {
    $stmt = $pdo->prepare("
        SELECT f.*, d.name as department_name 
        FROM faculty f 
        LEFT JOIN departments d ON f.department_id = d.id 
        WHERE f.id = ?
    ");
    $stmt->execute([$_SESSION['faculty_id']]);
    $facultyInfo = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Faculty info error: " . $e->getMessage());
    $facultyInfo = null;
}

// Get flash message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SYSTEM_NAME; ?></title>
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
        
        .profile-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .profile-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #667eea;
        }
        
        .photo-upload {
            position: relative;
            display: inline-block;
        }
        
        .photo-upload:hover .upload-overlay {
            opacity: 1;
        }
        
        .upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
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
                        <a class="nav-link active" href="profile.php">
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
                        <a class="nav-link" href="notifications.php">
                            <i class="fas fa-bell me-2"></i> Notifications
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
                    <h2>My Profile</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#passwordModal">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
                
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($facultyInfo): ?>
                    <!-- Profile Information -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card profile-card text-center">
                                <div class="card-body">
                                    <div class="photo-upload mb-3">
                                        <?php if ($facultyInfo['profile_photo']): ?>
                                            <img src="../uploads/profiles/<?php echo htmlspecialchars($facultyInfo['profile_photo']); ?>" 
                                                 class="profile-photo" alt="Profile Photo">
                                        <?php else: ?>
                                            <div class="profile-photo d-flex align-items-center justify-content-center bg-primary text-white">
                                                <i class="fas fa-user fa-3x"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="upload-overlay">
                                            <i class="fas fa-camera text-white fa-2x"></i>
                                        </div>
                                    </div>
                                    <form method="POST" enctype="multipart/form-data" class="mt-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="upload_photo">
                                        <input type="file" name="profile_photo" accept="image/*" class="form-control form-control-sm mb-2" id="profilePhotoInput">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Update Photo</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <div class="card profile-card">
                                <div class="card-body">
                                    <h5 class="card-title">Personal Information</h5>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="employee_id" class="form-label">Employee ID</label>
                                                    <input type="text" class="form-control" id="employee_id" value="<?php echo htmlspecialchars($facultyInfo['employee_id']); ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="first_name" class="form-label">First Name *</label>
                                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['first_name']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="last_name" class="form-label">Last Name *</label>
                                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['last_name']); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="middle_name" class="form-label">Middle Name</label>
                                                    <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['middle_name'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="email" class="form-label">Email *</label>
                                                    <input type="email" class="form-control" id="email" name="email" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['email']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="phone" class="form-label">Phone</label>
                                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['phone'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="department" class="form-label">Department</label>
                                                    <input type="text" class="form-control" id="department" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['department_name'] ?? 'N/A'); ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="position" class="form-label">Position</label>
                                                    <input type="text" class="form-control" id="position" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['position']); ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="hire_date" class="form-label">Hire Date</label>
                                                    <input type="date" class="form-control" id="hire_date" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['hire_date']); ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="status" class="form-label">Status</label>
                                                    <input type="text" class="form-control" id="status" 
                                                           value="<?php echo htmlspecialchars($facultyInfo['status']); ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Update Profile
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Profile information not found.
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Change Password Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password *</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <small class="text-muted">Password must be at least 8 characters long.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle photo upload
        document.getElementById('profilePhotoInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const profilePhoto = document.querySelector('.profile-photo img, .profile-photo');
                    if (profilePhoto.tagName === 'IMG') {
                        profilePhoto.src = e.target.result;
                    }
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
