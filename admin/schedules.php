<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Get faculty list for dropdown
$facultyList = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name")->fetchAll();

// Get semesters for dropdown
$semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY start_date DESC")->fetchAll();

// Add Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    $faculty_id = $_POST['faculty_id'];
    $semester_id = $_POST['semester_id'];
    $day_of_week = $_POST['day_of_week'];
    $time_in = $_POST['time_in'];
    $time_out = $_POST['time_out'];
    $subject_code = sanitizeInput($_POST['subject_code']);
    $room = sanitizeInput($_POST['room']);
    
    $stmt = $pdo->prepare("INSERT INTO schedules (faculty_id, semester_id, day_of_week, time_in, time_out, subject_code, room) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$faculty_id, $semester_id, $day_of_week, $time_in, $time_out, $subject_code, $room])) {
        logActivity($pdo, 'CREATE', 'schedules', $pdo->lastInsertId(), "Added schedule for faculty ID $faculty_id");
        $message = "Schedule added successfully.";
    } else {
        $error = "Failed to add schedule.";
    }
}

// Edit Schedule
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
    $stmt->execute([$id]);
    $editSched = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule'])) {
    $id = $_POST['id'];
    $faculty_id = $_POST['faculty_id'];
    $semester_id = $_POST['semester_id'];
    $day_of_week = $_POST['day_of_week'];
    $time_in = $_POST['time_in'];
    $time_out = $_POST['time_out'];
    $subject_code = sanitizeInput($_POST['subject_code']);
    $room = sanitizeInput($_POST['room']);
    
    $stmt = $pdo->prepare("UPDATE schedules SET faculty_id=?, semester_id=?, day_of_week=?, time_in=?, time_out=?, subject_code=?, room=? WHERE id=?");
    if ($stmt->execute([$faculty_id, $semester_id, $day_of_week, $time_in, $time_out, $subject_code, $room, $id])) {
        logActivity($pdo, 'UPDATE', 'schedules', $id, "Updated schedule ID $id");
        $message = "Schedule updated successfully.";
        header('Location: schedules.php');
        exit();
    } else {
        $error = "Failed to update schedule.";
    }
}

// Delete Schedule
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
    if ($stmt->execute([$id])) {
        logActivity($pdo, 'DELETE', 'schedules', $id, "Deleted schedule ID $id");
        header('Location: schedules.php');
        exit();
    }
}

// Get all schedules with faculty and semester names
$schedules = $pdo->query("
    SELECT s.*, u.first_name, u.last_name, sem.name as semester_name 
    FROM schedules s 
    JOIN users u ON s.faculty_id = u.id 
    JOIN semesters sem ON s.semester_id = sem.id 
    ORDER BY s.day_of_week, s.time_in
")->fetchAll();

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedules - DOIT Attendance</title>
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
                <h2>Faculty Schedules</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle"></i> Add Schedule
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
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Faculty</th>
                                <th>Semester</th>
                                <th>Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Subject</th>
                                <th>Room</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $sch): ?>
                            <tr>
                                <td><?= htmlspecialchars($sch['first_name'] . ' ' . $sch['last_name']) ?></td>
                                <td><?= htmlspecialchars($sch['semester_name']) ?></td>
                                <td><?= $days[$sch['day_of_week'] - 1] ?></td>
                                <td><?= formatTime($sch['time_in']) ?></td>
                                <td><?= formatTime($sch['time_out']) ?></td>
                                <td><?= htmlspecialchars($sch['subject_code']) ?></td>
                                <td><?= htmlspecialchars($sch['room']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal"
                                            onclick="editSchedule(<?= $sch['id'] ?>, <?= $sch['faculty_id'] ?>, <?= $sch['semester_id'] ?>, <?= $sch['day_of_week'] ?>, '<?= $sch['time_in'] ?>', '<?= $sch['time_out'] ?>', '<?= htmlspecialchars($sch['subject_code']) ?>', '<?= htmlspecialchars($sch['room']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="?delete=<?= $sch['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this schedule?')">
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
                <div class="modal-header"><h5>Add Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Faculty *</label>
                        <select name="faculty_id" class="form-control" required>
                            <option value="">Select Faculty</option>
                            <?php foreach ($facultyList as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label>Semester *</label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">Select Semester</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>"><?= htmlspecialchars($sem['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label>Day of Week *</label>
                        <select name="day_of_week" class="form-control" required>
                            <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option>
                            <option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option>
                            <option value="7">Sunday</option>
                        </select>
                    </div>
                    <div class="mb-3"><label>Time In *</label><input type="time" name="time_in" class="form-control" required></div>
                    <div class="mb-3"><label>Time Out *</label><input type="time" name="time_out" class="form-control" required></div>
                    <div class="mb-3"><label>Subject Code *</label><input type="text" name="subject_code" class="form-control" required></div>
                    <div class="mb-3"><label>Room *</label><input type="text" name="room" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_schedule" class="btn btn-primary">Save</button></div>
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
                <div class="modal-header"><h5>Edit Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Faculty</label>
                        <select name="faculty_id" id="edit_faculty" class="form-control" required>
                            <?php foreach ($facultyList as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label>Semester</label>
                        <select name="semester_id" id="edit_semester" class="form-control" required>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>"><?= htmlspecialchars($sem['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label>Day of Week</label>
                        <select name="day_of_week" id="edit_day" class="form-control" required>
                            <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option>
                            <option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option>
                            <option value="7">Sunday</option>
                        </select>
                    </div>
                    <div class="mb-3"><label>Time In</label><input type="time" name="time_in" id="edit_time_in" class="form-control" required></div>
                    <div class="mb-3"><label>Time Out</label><input type="time" name="time_out" id="edit_time_out" class="form-control" required></div>
                    <div class="mb-3"><label>Subject Code</label><input type="text" name="subject_code" id="edit_subject" class="form-control" required></div>
                    <div class="mb-3"><label>Room</label><input type="text" name="room" id="edit_room" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button type="submit" name="edit_schedule" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editSchedule(id, faculty_id, semester_id, day, time_in, time_out, subject, room) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_faculty').value = faculty_id;
    document.getElementById('edit_semester').value = semester_id;
    document.getElementById('edit_day').value = day;
    document.getElementById('edit_time_in').value = time_in;
    document.getElementById('edit_time_out').value = time_out;
    document.getElementById('edit_subject').value = subject;
    document.getElementById('edit_room').value = room;
}
</script>
</body>
</html>