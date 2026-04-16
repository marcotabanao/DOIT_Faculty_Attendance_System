<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Add Semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_semester'])) {
    $name = sanitizeInput($_POST['name']);
    $code = sanitizeInput($_POST['code']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // If this semester is active, deactivate others
    if ($is_active) {
        $pdo->exec("UPDATE semesters SET is_active = 0");
    }
    
    $stmt = $pdo->prepare("INSERT INTO semesters (name, code, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$name, $code, $start_date, $end_date, $is_active])) {
        logActivity($pdo, 'CREATE', 'semesters', $pdo->lastInsertId(), "Added semester: $name");
        $message = "Semester added successfully.";
    } else {
        $error = "Failed to add semester.";
    }
}

// Edit Semester
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM semesters WHERE id = ?");
    $stmt->execute([$id]);
    $editSem = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_semester'])) {
    $id = $_POST['id'];
    $name = sanitizeInput($_POST['name']);
    $code = sanitizeInput($_POST['code']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if ($is_active) {
        $pdo->exec("UPDATE semesters SET is_active = 0 WHERE id != $id");
    }
    
    $stmt = $pdo->prepare("UPDATE semesters SET name=?, code=?, start_date=?, end_date=?, is_active=? WHERE id=?");
    if ($stmt->execute([$name, $code, $start_date, $end_date, $is_active, $id])) {
        logActivity($pdo, 'UPDATE', 'semesters', $id, "Updated semester: $name");
        $message = "Semester updated successfully.";
        header('Location: semesters.php');
        exit();
    } else {
        $error = "Failed to update semester.";
    }
}

// Delete Semester
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM semesters WHERE id = ?");
    if ($stmt->execute([$id])) {
        logActivity($pdo, 'DELETE', 'semesters', $id, "Deleted semester ID: $id");
        header('Location: semesters.php');
        exit();
    }
}

// Set Active Semester (AJAX-like but via GET)
if (isset($_GET['set_active'])) {
    $id = $_GET['set_active'];
    $pdo->exec("UPDATE semesters SET is_active = 0");
    $stmt = $pdo->prepare("UPDATE semesters SET is_active = 1 WHERE id = ?");
    $stmt->execute([$id]);
    logActivity($pdo, 'UPDATE', 'semesters', $id, "Set semester as active");
    header('Location: semesters.php');
    exit();
}

// Get all semesters
$semesters = $pdo->query("SELECT * FROM semesters ORDER BY start_date DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Semesters - DOIT Attendance</title>
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
                <h2>Semesters</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle"></i> Add Semester
                </button>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semesters as $sem): ?>
                            <tr>
                                <td><?= htmlspecialchars($sem['name']) ?></td>
                                <td><?= htmlspecialchars($sem['code']) ?></td>
                                <td><?= formatDate($sem['start_date']) ?></td>
                                <td><?= formatDate($sem['end_date']) ?></td>
                                <td>
                                    <?php if ($sem['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal"
                                            onclick="editSemester(<?= $sem['id'] ?>, '<?= htmlspecialchars($sem['name']) ?>', '<?= htmlspecialchars($sem['code']) ?>', '<?= $sem['start_date'] ?>', '<?= $sem['end_date'] ?>', <?= $sem['is_active'] ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if (!$sem['is_active']): ?>
                                        <a href="?set_active=<?= $sem['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Activate this semester?')">
                                            <i class="bi bi-check-circle"></i> Activate
                                        </a>
                                    <?php endif; ?>
                                    <a href="?delete=<?= $sem['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this semester? This will also delete related schedules.')">
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
                <div class="modal-header"><h5>Add Semester</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Name *</label><input type="text" name="name" class="form-control" required></div>
                    <div class="mb-3"><label>Code *</label><input type="text" name="code" class="form-control" required></div>
                    <div class="mb-3"><label>Start Date *</label><input type="date" name="start_date" class="form-control" required></div>
                    <div class="mb-3"><label>End Date *</label><input type="date" name="end_date" class="form-control" required></div>
                    <div class="mb-3 form-check"><input type="checkbox" name="is_active" class="form-check-input" id="activeCheck"><label class="form-check-label">Active Semester</label></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_semester" class="btn btn-primary">Save</button></div>
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
                <div class="modal-header"><h5>Edit Semester</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Name</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                    <div class="mb-3"><label>Code</label><input type="text" name="code" id="edit_code" class="form-control" required></div>
                    <div class="mb-3"><label>Start Date</label><input type="date" name="start_date" id="edit_start" class="form-control" required></div>
                    <div class="mb-3"><label>End Date</label><input type="date" name="end_date" id="edit_end" class="form-control" required></div>
                    <div class="mb-3 form-check"><input type="checkbox" name="is_active" id="edit_active" class="form-check-input"><label class="form-check-label">Active Semester</label></div>
                </div>
                <div class="modal-footer"><button type="submit" name="edit_semester" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editSemester(id, name, code, start, end, active) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_start').value = start;
    document.getElementById('edit_end').value = end;
    document.getElementById('edit_active').checked = (active == 1);
}
</script>
</body>
</html>