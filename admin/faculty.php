<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Get departments for dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();

// ==================== ADD FACULTY ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_faculty'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $employee_id = sanitizeInput($_POST['employee_id']);
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $email = sanitizeInput($_POST['email']);
        $department_id = $_POST['department_id'] ?: null;
        $position = sanitizeInput($_POST['position']);
        $hire_date = $_POST['hire_date'];
        $contact_number = sanitizeInput($_POST['contact_number']);
        $leave_balance = (float)$_POST['leave_balance'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        $profile_photo = '';
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['profile_photo'], PROFILE_PHOTO_PATH, ALLOWED_IMAGE_TYPES);
            if ($upload['success']) {
                $profile_photo = $upload['filename'];
            } else {
                $error = $upload['error'];
            }
        }
        
        if (empty($error)) {
            $stmt = $pdo->prepare("INSERT INTO users (employee_id, first_name, last_name, email, password_hash, role, department_id, position, hire_date, contact_number, profile_photo, leave_balance, status) VALUES (?, ?, ?, ?, ?, 'faculty', ?, ?, ?, ?, ?, ?, 'active')");
            if ($stmt->execute([$employee_id, $first_name, $last_name, $email, $password, $department_id, $position, $hire_date, $contact_number, $profile_photo, $leave_balance])) {
                logActivity($pdo, 'CREATE', 'users', $pdo->lastInsertId(), "Added faculty: $first_name $last_name");
                header('Location: faculty.php?success=added');
                exit();
            } else {
                $error = "Failed to add faculty.";
            }
        }
    }
}

// ==================== EDIT FACULTY ====================
if (isset($_GET['edit_id'])) {
    $id = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
    $stmt->execute([$id]);
    $editFaculty = $stmt->fetch();
    if (!$editFaculty) $editFaculty = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_faculty'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $id = (int)$_POST['id'];
        $employee_id = sanitizeInput($_POST['employee_id']);
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $email = sanitizeInput($_POST['email']);
        $department_id = $_POST['department_id'] ?: null;
        $position = sanitizeInput($_POST['position']);
        $hire_date = $_POST['hire_date'];
        $contact_number = sanitizeInput($_POST['contact_number']);
        $leave_balance = (float)$_POST['leave_balance'];
        $status = $_POST['status'];
        
        $updateSQL = "UPDATE users SET employee_id=?, first_name=?, last_name=?, email=?, department_id=?, position=?, hire_date=?, contact_number=?, leave_balance=?, status=?";
        $params = [$employee_id, $first_name, $last_name, $email, $department_id, $position, $hire_date, $contact_number, $leave_balance, $status];
        
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $updateSQL .= ", password_hash=?";
            $params[] = $password;
        }
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['profile_photo'], PROFILE_PHOTO_PATH, ALLOWED_IMAGE_TYPES);
            if ($upload['success']) {
                $updateSQL .= ", profile_photo=?";
                $params[] = $upload['filename'];
                // Delete old photo if needed (optional)
                $old = $pdo->prepare("SELECT profile_photo FROM users WHERE id=?");
                $old->execute([$id]);
                $oldPhoto = $old->fetchColumn();
                if ($oldPhoto && file_exists(PROFILE_PHOTO_PATH . $oldPhoto)) {
                    unlink(PROFILE_PHOTO_PATH . $oldPhoto);
                }
            } else {
                $error = $upload['error'];
            }
        }
        $updateSQL .= " WHERE id=?";
        $params[] = $id;
        
        if (empty($error)) {
            $stmt = $pdo->prepare($updateSQL);
            if ($stmt->execute($params)) {
                logActivity($pdo, 'UPDATE', 'users', $id, "Updated faculty: $first_name $last_name");
                header('Location: faculty.php?success=updated');
                exit();
            } else {
                $error = "Failed to update faculty.";
            }
        }
    }
}

// ==================== DELETE FACULTY ====================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'faculty'");
    if ($stmt->execute([$id])) {
        logActivity($pdo, 'DELETE', 'users', $id, "Deleted faculty ID: $id");
        header('Location: faculty.php?success=deleted');
        exit();
    }
}

// ==================== FILTER & SEARCH ====================
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "SELECT u.*, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.role = 'faculty'";
$params = [];
if ($search) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.employee_id LIKE ? OR u.email LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($department_filter) {
    $query .= " AND u.department_id = ?";
    $params[] = $department_filter;
}
if ($status_filter) {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY u.first_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$faculty_list = $stmt->fetchAll();

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Management | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <?php include '../includes/admin-sidebar.php'; ?>
    </aside>
    <div class="main-content">
        <?php include '../includes/admin-topnav.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Faculty Management</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                <i class="bi bi-person-plus"></i> Add Faculty
            </button>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= ucfirst($_GET['success']) ?> successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search by name, ID, email" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $department_filter == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Faculty Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Photo</th><th>Name</th><th>Email</th><th>Department</th><th>Position</th><th>Leave Bal.</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($faculty_list as $f): ?>
                            <tr>
                                <td><?= htmlspecialchars($f['employee_id']) ?></td>
                                <td>
                                    <?php if ($f['profile_photo'] && file_exists(PROFILE_PHOTO_PATH . $f['profile_photo'])): ?>
                                        <img src="../assets/uploads/profile_photos/<?= htmlspecialchars($f['profile_photo']) ?>" width="40" height="40" class="rounded-circle" style="object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px;"><i class="bi bi-person text-white"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></td>
                                <td><?= htmlspecialchars($f['email']) ?></td>
                                <td><?= htmlspecialchars($f['department_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($f['position']) ?></td>
                                <td><?= number_format($f['leave_balance'], 1) ?></td>
                                <td><span class="badge bg-<?= $f['status'] == 'active' ? 'success' : 'danger' ?>"><?= ucfirst($f['status']) ?></span></td>
                                <td>
                                    <a href="?edit_id=<?= $f['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-pencil"></i></a>
                                    <a href="?delete=<?= $f['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this faculty?')"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Faculty Modal -->
<div class="modal fade" id="addFacultyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-header"><h5>Add New Faculty</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Employee ID *</label><input type="text" name="employee_id" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label>Email *</label><input type="email" name="email" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label>First Name *</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label>Last Name *</label><input type="text" name="last_name" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label>Department</label><select name="department_id" class="form-select"><option value="">Select</option><?php foreach($departments as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6 mb-3"><label>Position</label><input type="text" name="position" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label>Hire Date</label><input type="date" name="hire_date" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label>Contact Number</label><input type="text" name="contact_number" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label>Leave Balance (days)</label><input type="number" step="0.5" name="leave_balance" class="form-control" value="15.00"></div>
                        <div class="col-md-6 mb-3"><label>Password *</label><input type="password" name="password" class="form-control" required></div>
                        <div class="col-md-12 mb-3"><label>Profile Photo</label><input type="file" name="profile_photo" class="form-control" accept="image/*"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_faculty" class="btn btn-primary">Save Faculty</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Faculty Modal (populated via GET parameter, but we can also use direct edit link) -->
<?php if (isset($editFaculty)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('editFacultyModal'));
        editModal.show();
    });
</script>
<div class="modal fade" id="editFacultyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="id" value="<?= $editFaculty['id'] ?>">
                <div class="modal-header"><h5>Edit Faculty</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Employee ID</label><input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($editFaculty['employee_id']) ?>" required></div>
                        <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editFaculty['email']) ?>" required></div>
                        <div class="col-md-6 mb-3"><label>First Name</label><input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($editFaculty['first_name']) ?>" required></div>
                        <div class="col-md-6 mb-3"><label>Last Name</label><input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($editFaculty['last_name']) ?>" required></div>
                        <div class="col-md-6 mb-3"><label>Department</label><select name="department_id" class="form-select"><option value="">Select</option><?php foreach($departments as $d): ?><option value="<?= $d['id'] ?>" <?= ($editFaculty['department_id'] == $d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6 mb-3"><label>Position</label><input type="text" name="position" class="form-control" value="<?= htmlspecialchars($editFaculty['position']) ?>"></div>
                        <div class="col-md-6 mb-3"><label>Hire Date</label><input type="date" name="hire_date" class="form-control" value="<?= $editFaculty['hire_date'] ?>"></div>
                        <div class="col-md-6 mb-3"><label>Contact Number</label><input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($editFaculty['contact_number']) ?>"></div>
                        <div class="col-md-6 mb-3"><label>Leave Balance</label><input type="number" step="0.5" name="leave_balance" class="form-control" value="<?= $editFaculty['leave_balance'] ?>"></div>
                        <div class="col-md-6 mb-3"><label>Status</label><select name="status" class="form-select"><option value="active" <?= $editFaculty['status']=='active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $editFaculty['status']=='inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                        <div class="col-md-12 mb-3"><label>New Password (leave blank to keep current)</label><input type="password" name="password" class="form-control"></div>
                        <div class="col-md-12 mb-3"><label>Profile Photo</label><input type="file" name="profile_photo" class="form-control" accept="image/*"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" name="edit_faculty" class="btn btn-primary">Update Faculty</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Prevent edit modal from reopening if there's no edit_id
    <?php if (!isset($editFaculty)): ?>
    // no action
    <?php endif; ?>
</script>
</body>
</html>