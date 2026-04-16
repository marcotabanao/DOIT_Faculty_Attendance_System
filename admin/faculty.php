<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';
// Handle CRUD operations
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Get departments for dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();

// Add Faculty
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = sanitizeInput($_POST['employee_id']);
    $first_name = sanitizeInput($_POST['first_name']);
    $last_name = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $department_id = $_POST['department_id'];
    $position = sanitizeInput($_POST['position']);
    $hire_date = $_POST['hire_date'];
    $contact_number = sanitizeInput($_POST['contact_number']);
    $leave_balance = $_POST['leave_balance'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Handle profile photo upload
    $profile_photo = '';
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['profile_photo'], PROFILE_PHOTO_PATH);
        if ($upload['success']) {
            $profile_photo = $upload['filename'];
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO users (employee_id, first_name, last_name, email, password_hash, role, department_id, position, hire_date, contact_number, profile_photo, leave_balance) VALUES (?, ?, ?, ?, ?, 'faculty', ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$employee_id, $first_name, $last_name, $email, $password, $department_id, $position, $hire_date, $contact_number, $profile_photo, $leave_balance])) {
        logActivity($pdo, 'CREATE', 'users', $pdo->lastInsertId(), "Added faculty: $first_name $last_name");
        header('Location: faculty.php?success=added');
        exit();
    }
}

// Edit Faculty
if ($action === 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $faculty = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
    $faculty->execute([$id]);
    $faculty = $faculty->fetch();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $employee_id = sanitizeInput($_POST['employee_id']);
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $email = sanitizeInput($_POST['email']);
        $department_id = $_POST['department_id'];
        $position = sanitizeInput($_POST['position']);
        $hire_date = $_POST['hire_date'];
        $contact_number = sanitizeInput($_POST['contact_number']);
        $leave_balance = $_POST['leave_balance'];
        $status = $_POST['status'];
        
        $updateSQL = "UPDATE users SET employee_id=?, first_name=?, last_name=?, email=?, department_id=?, position=?, hire_date=?, contact_number=?, leave_balance=?, status=?";
        $params = [$employee_id, $first_name, $last_name, $email, $department_id, $position, $hire_date, $contact_number, $leave_balance, $status];
        
        // Handle password update
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $updateSQL .= ", password_hash=?";
            $params[] = $password;
        }
        
        // Handle profile photo
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['profile_photo'], PROFILE_PHOTO_PATH);
            if ($upload['success']) {
                $updateSQL .= ", profile_photo=?";
                $params[] = $upload['filename'];
            }
        }
        
        $updateSQL .= " WHERE id=?";
        $params[] = $id;
        
        $stmt = $pdo->prepare($updateSQL);
        if ($stmt->execute($params)) {
            logActivity($pdo, 'UPDATE', 'users', $id, "Updated faculty: $first_name $last_name");
            header('Location: faculty.php?success=updated');
            exit();
        }
    }
}

// Delete Faculty
if ($action === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'faculty'");
    if ($stmt->execute([$id])) {
        logActivity($pdo, 'DELETE', 'users', $id, "Deleted faculty ID: $id");
        header('Location: faculty.php?success=deleted');
        exit();
    }
}

// Get all faculty for listing
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "SELECT u.*, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.role = 'faculty'";
$params = [];

if ($search) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.employee_id LIKE ? OR u.email LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Management - DOIT Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<button class="mobile-menu-toggle" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
</button>
<?php include '../includes/admin-sidebar.php'; ?>
<div class="main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Faculty Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                        <i class="bi bi-person-plus"></i> Add Faculty
                    </button>
                </div>
                
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                        if ($_GET['success'] == 'added') echo 'Faculty added successfully!';
                        if ($_GET['success'] == 'updated') echo 'Faculty updated successfully!';
                        if ($_GET['success'] == 'deleted') echo 'Faculty deleted successfully!';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="search" placeholder="Search by name, ID, email" value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Faculty Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="facultyTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Photo</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Leave Balance</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($faculty_list as $faculty): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($faculty['employee_id']); ?></td>
                                        <td>
                                            <?php if ($faculty['profile_photo']): ?>
                                                <img src="../assets/uploads/profile_photos/<?php echo $faculty['profile_photo']; ?>" width="40" height="40" class="rounded-circle">
                                            <?php else: ?>
                                                <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                    <i class="bi bi-person text-white"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($faculty['email']); ?></td>
                                        <td><?php echo htmlspecialchars($faculty['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($faculty['position']); ?></td>
                                        <td><?php echo number_format($faculty['leave_balance'], 1); ?> days</td>
                                        <td>
                                            <span class="badge bg-<?php echo $faculty['status'] == 'active' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($faculty['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="editFaculty(<?php echo $faculty['id']; ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteFaculty(<?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
    
    <!-- Add Faculty Modal -->
    <div class="modal fade" id="addFacultyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Faculty</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employee ID *</label>
                                <input type="text" class="form-control" name="employee_id" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department *</label>
                                <select class="form-select" name="department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Position</label>
                                <input type="text" class="form-control" name="position">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Hire Date</label>
                                <input type="date" class="form-control" name="hire_date">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact_number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Leave Balance (days)</label>
                                <input type="number" class="form-control" name="leave_balance" step="0.5" value="15">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Profile Photo</label>
                                <input type="file" class="form-control" name="profile_photo" accept="image/*">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Faculty</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}
        $(document).ready(function() {
            $('#facultyTable').DataTable({
                pageLength: 25,
                order: [[2, 'asc']]
            });
        });
        
        function editFaculty(id) {
            window.location.href = `faculty.php?action=edit&id=${id}`;
        }
        
        function deleteFaculty(id, name) {
            if (confirm(`Are you sure you want to delete faculty: ${name}?`)) {
                window.location.href = `faculty.php?action=delete&id=${id}`;
            }
        }
    </script>
</body>
</html>