<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

// Handle Add/Edit/Delete operations
$message = '';
$error = '';

// Add Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
    $code = sanitizeInput($_POST['code']);
    $name = sanitizeInput($_POST['name']);
    
    $stmt = $pdo->prepare("INSERT INTO departments (code, name) VALUES (?, ?)");
    if ($stmt->execute([$code, $name])) {
        logActivity($pdo, 'CREATE', 'departments', $pdo->lastInsertId(), "Added department: $name");
        $message = "Department added successfully.";
    } else {
        $error = "Failed to add department.";
    }
}

// Edit Department
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    $editDept = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_department'])) {
    $id = $_POST['id'];
    $code = sanitizeInput($_POST['code']);
    $name = sanitizeInput($_POST['name']);
    
    $stmt = $pdo->prepare("UPDATE departments SET code = ?, name = ? WHERE id = ?");
    if ($stmt->execute([$code, $name, $id])) {
        logActivity($pdo, 'UPDATE', 'departments', $id, "Updated department: $name");
        $message = "Department updated successfully.";
        header('Location: departments.php');
        exit();
    }
}

// Delete Department
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
    if ($stmt->execute([$id])) {
        logActivity($pdo, 'DELETE', 'departments', $id, "Deleted department ID: $id");
        header('Location: departments.php');
        exit();
    }
}

// Get all departments
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - DOIT Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Departments</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle"></i> Add Department
                </button>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr><th>Code</th><th>Name</th><th>Created</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td><?= htmlspecialchars($dept['code']) ?></td>
                                <td><?= htmlspecialchars($dept['name']) ?></td>
                                <td><?= formatDate($dept['created_at']) ?></td>
                                <td>
                                    <a href="?edit=<?= $dept['id'] ?>" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal" onclick="editDepartment(<?= $dept['id'] ?>, '<?= htmlspecialchars($dept['code']) ?>', '<?= htmlspecialchars($dept['name']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete=<?= $dept['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this department?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Add Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Code</label><input type="text" name="code" class="form-control" required></div>
                    <div class="mb-3"><label>Name</label><input type="text" name="name" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_department" class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header"><h5>Edit Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Code</label><input type="text" name="code" id="edit_code" class="form-control" required></div>
                    <div class="mb-3"><label>Name</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="submit" name="edit_department" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editDepartment(id, code, name) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_name').value = name;
}
</script>
</body>
</html>