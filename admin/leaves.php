<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Approve leave request
if (isset($_GET['approve'])) {
    $id = $_GET['approve'];
    $remarks = isset($_POST['remarks']) ? sanitizeInput($_POST['remarks']) : '';
    
    // Get leave details to deduct days
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    
    if ($leave) {
        $days = getDateDifference($leave['start_date'], $leave['end_date']);
        
        // Update leave request status
        $update = $pdo->prepare("UPDATE leave_requests SET status = 'approved', admin_remarks = ?, reviewed_by = ?, reviewed_at = NOW(), days_applied = ? WHERE id = ?");
        $update->execute([$remarks, $_SESSION['user_id'], $days, $id]);
        
        // Deduct from faculty leave balance
        $deduct = $pdo->prepare("UPDATE users SET leave_balance = leave_balance - ? WHERE id = ?");
        $deduct->execute([$days, $leave['faculty_id']]);
        
        // Send notification to faculty
        sendNotification($pdo, $leave['faculty_id'], 'leave_approved', 'Leave Request Approved', "Your leave request from " . formatDate($leave['start_date']) . " to " . formatDate($leave['end_date']) . " has been approved.", "faculty/leave-request.php");
        
        logActivity($pdo, 'APPROVE_LEAVE', 'leave_requests', $id, "Approved leave request for faculty ID: {$leave['faculty_id']}");
        $message = "Leave request approved. {$days} day(s) deducted from leave balance.";
    }
    header('Location: leaves.php');
    exit();
}

// Reject leave request
if (isset($_GET['reject'])) {
    $id = $_GET['reject'];
    $remarks = isset($_POST['remarks']) ? sanitizeInput($_POST['remarks']) : '';
    
    $update = $pdo->prepare("UPDATE leave_requests SET status = 'rejected', admin_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $update->execute([$remarks, $_SESSION['user_id'], $id]);
    
    // Get faculty_id for notification
    $stmt = $pdo->prepare("SELECT faculty_id FROM leave_requests WHERE id = ?");
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    if ($leave) {
        sendNotification($pdo, $leave['faculty_id'], 'leave_rejected', 'Leave Request Rejected', "Your leave request has been rejected. Reason: $remarks", "faculty/leave-request.php");
    }
    
    logActivity($pdo, 'REJECT_LEAVE', 'leave_requests', $id, "Rejected leave request ID: $id");
    $message = "Leave request rejected.";
    header('Location: leaves.php');
    exit();
}

// Filter by status
$status_filter = $_GET['status'] ?? 'pending';
$search = $_GET['search'] ?? '';

$query = "SELECT lr.*, u.first_name, u.last_name, u.employee_id 
          FROM leave_requests lr 
          JOIN users u ON lr.faculty_id = u.id 
          WHERE 1=1";
$params = [];

if ($status_filter != 'all') {
    $query .= " AND lr.status = ?";
    $params[] = $status_filter;
}
if ($search) {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.employee_id LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}
$query .= " ORDER BY lr.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leaves = $stmt->fetchAll();

// Count pending
$pendingCount = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Requests - DOIT</title>
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
            <h2>Leave Requests <span class="badge bg-warning">Pending: <?= $pendingCount ?></span></h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Search by name or ID" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All</option>
                                <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Leave Requests Table -->
            <div class="card">
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Faculty</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']) ?><br><small><?= $leave['employee_id'] ?></small></td>
                                <td><?= getLeaveTypeBadge($leave['leave_type']) ?></td>
                                <td><?= formatDate($leave['start_date']) ?> → <?= formatDate($leave['end_date']) ?></td>
                                <td><?= getDateDifference($leave['start_date'], $leave['end_date']) ?> days</td>
                                <td><?= nl2br(htmlspecialchars($leave['reason'])) ?></td>
                                <td><?= getLeaveStatusBadge($leave['status']) ?></td>
                                <td>
                                    <?php if ($leave['status'] == 'pending'): ?>
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal" onclick="setLeaveId(<?= $leave['id'] ?>)">
                                            <i class="bi bi-check"></i> Approve
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="setLeaveIdReject(<?= $leave['id'] ?>)">
                                            <i class="bi bi-x"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">Reviewed on <?= formatDate($leave['reviewed_at'], 'M d, Y') ?></span>
                                        <?php if ($leave['admin_remarks']): ?>
                                            <br><small>Remarks: <?= htmlspecialchars($leave['admin_remarks']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($leave['attachment']): ?>
                                        <br><a href="../assets/uploads/leave_attachments/<?= $leave['attachment'] ?>" target="_blank" class="btn btn-sm btn-info mt-1">View Attachment</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($leaves)): ?>
                            <tr><td colspan="7" class="text-center">No leave requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="leave_id" id="approve_id">
                <div class="modal-header"><h5>Approve Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Remarks (Optional)</label><textarea name="remarks" class="form-control" rows="3"></textarea></div>
                    <p>Leave days will be deducted from faculty's balance.</p>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-success" name="approve_submit">Confirm Approve</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="leave_id" id="reject_id">
                <div class="modal-header"><h5>Reject Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>Reason for Rejection *</label><textarea name="remarks" class="form-control" rows="3" required></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-danger" name="reject_submit">Confirm Reject</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setLeaveId(id) {
    document.getElementById('approve_id').value = id;
    document.getElementById('approveModal').querySelector('form').action = '?approve=' + id;
}
function setLeaveIdReject(id) {
    document.getElementById('reject_id').value = id;
    document.getElementById('rejectModal').querySelector('form').action = '?reject=' + id;
}
</script>
            </div>
        </div>
    </div>
</div>
</body>
</html>