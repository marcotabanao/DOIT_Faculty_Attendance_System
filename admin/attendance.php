<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get all faculty
$faculty_list = $pdo->query("SELECT id, first_name, last_name, employee_id FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name")->fetchAll();

// Manual attendance entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $faculty_id = $_POST['faculty_id'];
    $date = $_POST['date'];
    $check_in = $_POST['check_in'] ?: null;
    $check_out = $_POST['check_out'] ?: null;
    $status = $_POST['status'];
    $remarks = sanitizeInput($_POST['remarks'] ?? '');
    
    // Check if attendance already exists
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE faculty_id = ? AND date = ?");
    $stmt->execute([$faculty_id, $date]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Update
        $update = $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = ?, status = ?, remarks = ?, updated_at = NOW() WHERE faculty_id = ? AND date = ?");
        if ($update->execute([$check_in, $check_out, $status, $remarks, $faculty_id, $date])) {
            logActivity($pdo, 'UPDATE', 'attendance', $exists['id'], "Updated attendance for faculty ID $faculty_id on $date");
            $message = "Attendance updated successfully.";
        } else {
            $error = "Failed to update attendance.";
        }
    } else {
        // Insert
        $insert = $pdo->prepare("INSERT INTO attendance (faculty_id, date, check_in_time, check_out_time, status, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($insert->execute([$faculty_id, $date, $check_in, $check_out, $status, $remarks, $_SESSION['user_id']])) {
            logActivity($pdo, 'CREATE', 'attendance', $pdo->lastInsertId(), "Marked attendance for faculty ID $faculty_id on $date");
            $message = "Attendance marked successfully.";
        } else {
            $error = "Failed to mark attendance.";
        }
    }
    header("Location: attendance.php?date=$date");
    exit();
}

// Get attendance for selected date
$attendance = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name, u.employee_id 
    FROM attendance a 
    RIGHT JOIN users u ON a.faculty_id = u.id AND a.date = ? 
    WHERE u.role = 'faculty'
    ORDER BY u.first_name
");
$attendance->execute([$selected_date]);
$attendance_data = $attendance->fetchAll();

// Get status summary for the date - FIXED: added a. prefix to status
$summary = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
        COUNT(CASE WHEN a.status = 'on_leave' THEN 1 END) as on_leave,
        COUNT(CASE WHEN a.status IS NULL THEN 1 END) as not_marked
    FROM attendance a 
    RIGHT JOIN users u ON a.faculty_id = u.id AND a.date = ? 
    WHERE u.role = 'faculty'
");
$summary->execute([$selected_date]);
$stats = $summary->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/admin-sidebar.php'; ?></div>
        <div class="col-md-10 p-0">
            <?php include '../includes/admin-topnav.php'; ?>
            <div class="p-4" style="padding-top: 80px !important;">
            <h2>Daily Attendance</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <!-- Date Selector -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label>Select Date</label>
                            <input type="date" name="date" class="form-control" value="<?= $selected_date ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">View</button>
                        </div>
                        <div class="col-md-6 d-flex align-items-end justify-content-end">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#markModal">
                                <i class="bi bi-plus-circle"></i> Mark Attendance (Manual)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-2"><div class="card bg-success text-white"><div class="card-body text-center"><h5>Present</h5><h3><?= $stats['present'] ?? 0 ?></h3></div></div></div>
                <div class="col-md-2"><div class="card bg-warning text-dark"><div class="card-body text-center"><h5>Late</h5><h3><?= $stats['late'] ?? 0 ?></h3></div></div></div>
                <div class="col-md-2"><div class="card bg-danger text-white"><div class="card-body text-center"><h5>Absent</h5><h3><?= $stats['absent'] ?? 0 ?></h3></div></div></div>
                <div class="col-md-2"><div class="card bg-primary text-white"><div class="card-body text-center"><h5>On Leave</h5><h3><?= $stats['on_leave'] ?? 0 ?></h3></div></div></div>
                <div class="col-md-2"><div class="card bg-secondary text-white"><div class="card-body text-center"><h5>Not Marked</h5><h3><?= $stats['not_marked'] ?? 0 ?></h3></div></div></div>
                <div class="col-md-2"><div class="card bg-info text-white"><div class="card-body text-center"><h5>Total Faculty</h5><h3><?= count($faculty_list) ?></h3></div></div></div>
            </div>
            
            <!-- Attendance Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Name</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Status</th>
                                    <th>Late (mins)</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_data as $att): ?>
                                <tr>
                                    <td><?= htmlspecialchars($att['employee_id']) ?></td>
                                    <td><?= htmlspecialchars($att['first_name'] . ' ' . $att['last_name']) ?></td>
                                    <td><?= $att['check_in_time'] ? formatTime($att['check_in_time']) : '-' ?></td>
                                    <td><?= $att['check_out_time'] ? formatTime($att['check_out_time']) : '-' ?></td>
                                    <td><?= getStatusBadge($att['status'] ?? 'absent') ?></td>
                                    <td><?= $att['late_minutes'] ?? 0 ?></td>
                                    <td><?= htmlspecialchars($att['remarks'] ?? '') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal"
                                                onclick="editAttendance(<?= $att['faculty_id'] ?>, '<?= $att['check_in_time'] ?>', '<?= $att['check_out_time'] ?>', '<?= $att['status'] ?? 'absent' ?>', '<?= htmlspecialchars($att['remarks'] ?? '') ?>')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
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
</div>

<!-- Mark Attendance Modal -->
<div class="modal fade" id="markModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Mark Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Faculty *</label>
                        <select name="faculty_id" class="form-control" required>
                            <option value="">Select Faculty</option>
                            <?php foreach ($faculty_list as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?> (<?= $f['employee_id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label>Date *</label><input type="date" name="date" class="form-control" value="<?= $selected_date ?>" required></div>
                    <div class="mb-3"><label>Check In Time</label><input type="time" name="check_in" class="form-control"></div>
                    <div class="mb-3"><label>Check Out Time</label><input type="time" name="check_out" class="form-control"></div>
                    <div class="mb-3"><label>Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                            <option value="half_day">Half Day</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                    <div class="mb-3"><label>Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="mark_attendance" class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="faculty_id" id="edit_faculty_id">
                <input type="hidden" name="date" value="<?= $selected_date ?>">
                <div class="modal-header"><h5>Edit Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Check In Time</label><input type="time" name="check_in" id="edit_check_in" class="form-control"></div>
                    <div class="mb-3"><label>Check Out Time</label><input type="time" name="check_out" id="edit_check_out" class="form-control"></div>
                    <div class="mb-3"><label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                            <option value="half_day">Half Day</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                    <div class="mb-3"><label>Remarks</label><textarea name="remarks" id="edit_remarks" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="mark_attendance" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editAttendance(faculty_id, check_in, check_out, status, remarks) {
    document.getElementById('edit_faculty_id').value = faculty_id;
    document.getElementById('edit_check_in').value = check_in;
    document.getElementById('edit_check_out').value = check_out;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_remarks').value = remarks;
}
</script>
            </div>
        </div>
    </div>
</div>
</body>
</html>