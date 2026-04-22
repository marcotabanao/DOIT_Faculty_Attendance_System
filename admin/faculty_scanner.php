<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';
$faculty = null;
$scan_id = '';

// Handle faculty ID scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_id'])) {
    $scan_id = trim($_POST['scan_id']);
    
    if (empty($scan_id)) {
        $error = "Please enter a faculty ID";
    } else {
        try {
            // Look up faculty by employee_id
            $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? AND role = 'faculty'");
            $stmt->execute([$scan_id]);
            $faculty = $stmt->fetch();
            
            if (!$faculty) {
                $error = "Faculty not found. You can add this faculty or check the ID.";
            } else {
                // Faculty found - get additional details
                $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $deptStmt->execute([$faculty['department_id']]);
                $department = $deptStmt->fetchColumn();
                
                // Get today's attendance
                $today = date('Y-m-d');
                $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE faculty_id = ? AND date = ?");
                $attStmt->execute([$faculty['id'], $today]);
                $today_attendance = $attStmt->fetch();
                
                // Get current semester schedule
                $schStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE faculty_id = ? AND semester_id = (SELECT id FROM semesters WHERE is_active = 1 LIMIT 1)");
                $schStmt->execute([$faculty['id']]);
                $has_schedule = $schStmt->fetchColumn() > 0;
                
                $message = "Faculty found: " . htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']);
                
                // Store faculty data for editing
                $_SESSION['edit_faculty'] = $faculty;
            }
        } catch (Exception $e) {
            $error = "Database error: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Handle faculty update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_faculty'])) {
    $employee_id = trim($_POST['employee_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $department_id = trim($_POST['department_id']);
    $position = trim($_POST['position']);
    $status = trim($_POST['status']);
    
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "All required fields must be filled";
    } else {
        try {
            // Check if new employee ID already exists (excluding current faculty)
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND id != ? AND role = 'faculty'");
            $checkStmt->execute([$employee_id, $faculty['id']]);
            if ($checkStmt->fetch()) {
                $error = "Employee ID '$employee_id' already exists for another faculty member. Please choose a different ID.";
            } else {
                // Store old employee ID for logging
                $old_employee_id = $faculty['employee_id'] ?? '';
                
                // Update faculty record
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, department_id = ?, position = ?, status = ?
                    WHERE employee_id = ? AND role = 'faculty'
                ");
                
                if ($updateStmt->execute([$first_name, $last_name, $email, $department_id, $position, $status, $employee_id])) {
                    logActivity($pdo, 'UPDATE_FACULTY', 'users', $faculty['id'] ?? null, "Updated faculty: $old_employee_id to $employee_id");
                    $message = "Faculty updated successfully!";
                    
                    // Clear edit session and reload faculty data
                    unset($_SESSION['edit_faculty']);
                    
                    // Get updated faculty data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? AND role = 'faculty'");
                    $stmt->execute([$employee_id]);
                    $faculty = $stmt->fetch();
                    
                    // Update session with new faculty data for edit form
                    $_SESSION['edit_faculty'] = $faculty;
                } else {
                    $error = "Failed to update faculty. Please try again.";
                }
            }
        } catch (Exception $e) {
            $error = "Error updating faculty: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Handle new faculty creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_faculty'])) {
    $employee_id = trim($_POST['employee_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $department_id = trim($_POST['department_id']);
    $position = trim($_POST['position']);
    
    if (empty($employee_id) || empty($first_name) || empty($last_name) || empty($email)) {
        $error = "All required fields must be filled";
    } else {
        try {
            // Check if employee_id already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
            $checkStmt->execute([$employee_id]);
            if ($checkStmt->fetch()) {
                $error = "Employee ID already exists";
            } else {
                // Insert new faculty
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (employee_id, first_name, last_name, email, role, department_id, position, status, created_at) 
                    VALUES (?, ?, ?, ?, 'faculty', ?, ?, 'active', NOW())
                ");
                
                if ($insertStmt->execute([$employee_id, $first_name, $last_name, $email, $department_id, $position])) {
                    logActivity($pdo, 'CREATE_FACULTY', 'users', $pdo->lastInsertId(), "Created faculty: $employee_id");
                    $message = "Faculty added successfully! You can now scan this ID.";
                    
                    // Auto-load the new faculty details
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? AND role = 'faculty'");
                    $stmt->execute([$employee_id]);
                    $faculty = $stmt->fetch();
                } else {
                    $error = "Failed to add faculty. Please try again.";
                }
            }
        } catch (Exception $e) {
            $error = "Error adding faculty: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Get departments for dropdown
$deptStmt = $pdo->prepare("SELECT id, name FROM departments ORDER BY name");
$deptStmt->execute();
$departments = $deptStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Scanner | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-0 pt-4">
            <?php include '../includes/admin-topnav.php'; ?>
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-upc-scan"></i> Faculty Scanner</h2>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                        <i class="bi bi-person-plus"></i> Add New Faculty
                    </button>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <!-- Scanner Section -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="bi bi-upc-scan"></i> Faculty ID Scanner</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Scan or Enter Faculty ID</label>
                                <input type="text" name="scan_id" class="form-control form-control-lg" 
                                       placeholder="e.g., FAC001" value="<?= htmlspecialchars($scan_id) ?>" 
                                       autofocus>
                                <small class="text-muted">Enter faculty ID or scan employee badge</small>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Faculty Details Section -->
                <?php if ($faculty): ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5><i class="bi bi-person-badge"></i> Faculty Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td><strong>Name:</strong></td>
                                            <td><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Employee ID:</strong></td>
                                            <td><?= htmlspecialchars($faculty['employee_id']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Email:</strong></td>
                                            <td><?= htmlspecialchars($faculty['email']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Department:</strong></td>
                                            <td><?= htmlspecialchars($department) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Position:</strong></td>
                                            <td><?= htmlspecialchars($faculty['position']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Status:</strong></td>
                                            <td>
                                                <span class="badge bg-<?= $faculty['status'] === 'active' ? 'success' : 'danger' ?>">
                                                    <?= ucfirst($faculty['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Today's Attendance</h6>
                                    <?php if ($today_attendance): ?>
                                        <div class="alert alert-<?= $today_attendance['status'] === 'present' ? 'success' : ($today_attendance['status'] === 'late' ? 'warning' : 'info') ?>">
                                            <strong>Status:</strong> <?= ucfirst($today_attendance['status']) ?><br>
                                            <?php if ($today_attendance['check_in_time']): ?>
                                                <strong>Check-in:</strong> <?= formatTime($today_attendance['check_in_time']) ?><br>
                                            <?php endif; ?>
                                            <?php if ($today_attendance['check_out_time']): ?>
                                                <strong>Check-out:</strong> <?= formatTime($today_attendance['check_out_time']) ?><br>
                                            <?php endif; ?>
                                            <?php if ($today_attendance['late_minutes'] > 0): ?>
                                                <strong>Late by:</strong> <?= $today_attendance['late_minutes'] ?> minutes
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-clock"></i> No attendance record for today
                                        </div>
                                    <?php endif; ?>
                                    
                                    <h6 class="mt-3">Schedule Status</h6>
                                    <?php if ($has_schedule): ?>
                                        <div class="alert alert-info">
                                            <i class="bi bi-calendar-check"></i> Has active schedule
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-secondary">
                                            <i class="bi bi-calendar-x"></i> No active schedule
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Edit Faculty Button -->
                <?php if ($faculty && isset($_SESSION['edit_faculty'])): ?>
                    <div class="card mt-4">
                        <div class="card-header bg-warning text-white">
                            <h5><i class="bi bi-pencil-square"></i> Edit Faculty</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="update_faculty" value="1">
                                <div class="col-md-6">
                                    <label class="form-label">Employee ID</label>
                                    <input type="text" name="employee_id" class="form-control" 
                                           value="<?= htmlspecialchars($faculty['employee_id']) ?>">
                                    <small class="text-muted">Warning: Changing employee ID may affect attendance records</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?= htmlspecialchars($faculty['email']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" name="first_name" class="form-control" 
                                           value="<?= htmlspecialchars($faculty['first_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" 
                                           value="<?= htmlspecialchars($faculty['last_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department *</label>
                                    <select name="department_id" class="form-select" required>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>" 
                                                    <?= $dept['id'] == $faculty['department_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($dept['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Position</label>
                                    <input type="text" name="position" class="form-control" 
                                           value="<?= htmlspecialchars($faculty['position']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" <?= $faculty['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $faculty['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-pencil-square"></i> Update Faculty
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Faculty Modal -->
<div class="modal fade" id="addFacultyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Faculty</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employee ID *</label>
                            <input type="text" name="employee_id" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department *</label>
                            <select name="department_id" class="form-select" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_faculty" class="btn btn-primary">Add Faculty</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const scanInput = document.querySelector('input[name="scan_id"]');
    scanInput.focus();
    
    // Auto-clear and re-focus after search
    const form = document.querySelector('form');
    form.addEventListener('submit', function() {
        setTimeout(() => {
            scanInput.value = '';
            scanInput.focus();
        }, 2000);
    });
});

// Cancel edit function
function cancelEdit() {
    <?php if (isset($_SESSION['edit_faculty'])): ?>
        if (confirm('Are you sure you want to cancel editing? Any unsaved changes will be lost.')) {
            window.location.href = 'faculty_scanner.php';
        }
    <?php else: ?>
        window.location.href = 'faculty_scanner.php';
    <?php endif; ?>
}
</script>
</body>
</html>
