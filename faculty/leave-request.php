<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';

// Fallback constants
if (!defined('LEAVE_ATTACHMENT_PATH')) define('LEAVE_ATTACHMENT_PATH', dirname(__DIR__) . '/assets/uploads/leave_attachments/');
if (!defined('ALLOWED_DOC_TYPES')) define('ALLOWED_DOC_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 5242880);

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $leave_type = $_POST['leave_type'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $reason = sanitizeInput($_POST['reason']);
        
        if (empty($leave_type) || empty($start_date) || empty($end_date) || empty($reason)) {
            $error = 'Please fill in all required fields.';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error = 'Start date must be before end date.';
        } elseif (strtotime($start_date) < strtotime(date('Y-m-d'))) {
            $error = 'Start date cannot be in the past.';
        } else {
            $days = getDateDifference($start_date, $end_date);
            $stmt = $pdo->prepare("SELECT leave_balance FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $balance = $stmt->fetchColumn();
            
            if ($balance < $days) {
                $error = "Insufficient leave balance. Available: $balance days, Requested: $days days.";
            } else {
                $attachment = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($_FILES['attachment'], LEAVE_ATTACHMENT_PATH, ALLOWED_DOC_TYPES);
                    if ($upload['success']) $attachment = $upload['filename'];
                    else $error = $upload['error'];
                }
                if (empty($error)) {
                    $stmt = $pdo->prepare("INSERT INTO leave_requests (faculty_id, leave_type, start_date, end_date, reason, attachment, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    if ($stmt->execute([$user_id, $leave_type, $start_date, $end_date, $reason, $attachment])) {
                        $leave_id = $pdo->lastInsertId();
                        logActivity($pdo, 'CREATE', 'leave_requests', $leave_id, "Faculty {$user_id} requested leave: $leave_type from $start_date to $end_date");
                        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
                        foreach ($admins as $admin) {
                            sendNotification($pdo, $admin['id'], 'leave_request', 'New Leave Request', "Faculty {$_SESSION['user_name']} requested $days days of $leave_type leave.", "../admin/leaves.php");
                        }
                        $message = "Leave request submitted successfully. Wait for admin approval.";
                    } else {
                        $error = "Failed to submit request. Please try again.";
                    }
                }
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT leave_balance FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$leave_balance = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE faculty_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$leave_requests = $stmt->fetchAll();

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Requests | DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 p-0"><?php include '../includes/faculty-sidebar.php'; ?></div>
        <div class="col-md-10 p-0">
            <?php include '../includes/faculty-tapnav.php'; ?>
            <div class="p-4" style="padding-top: 80px !important;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Leave Requests</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leaveModal">Request Leave</button>
                </div>
                <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <div class="card mb-4"><div class="card-header">Leave Balance</div><div class="card-body text-center"><h2 class="text-primary"><?= number_format($leave_balance, 1) ?></h2><p class="text-muted">Available leave days</p></div></div>
                <div class="card"><div class="card-header">My Leave Requests</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered"><thead class="table-dark"><tr><th>Type</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Reason</th><th>Status</th><th>Attachment</th><th>Submitted</th></tr></thead>
                <tbody><?php if (empty($leave_requests)): ?><tr><td colspan="8" class="text-center">No leave requests found.</td></tr><?php else: ?><?php foreach ($leave_requests as $req): ?><tr><td><?= getLeaveTypeBadge($req['leave_type']) ?></td><td><?= formatDate($req['start_date']) ?></td><td><?= formatDate($req['end_date']) ?></td><td><?= getDateDifference($req['start_date'], $req['end_date']) ?> days</td><td><?= nl2br(htmlspecialchars($req['reason'])) ?></td><td><?= getLeaveStatusBadge($req['status']) ?></td><td><?php if ($req['attachment']): ?><a href="../assets/uploads/leave_attachments/<?= htmlspecialchars($req['attachment']) ?>" target="_blank" class="btn btn-sm btn-info">View</a><?php else: ?><span class="text-muted">None</span><?php endif; ?></td><td><?= formatDate($req['created_at'], 'Y-m-d H:i') ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
            </div>
        </div>
    </div>
</div>
<!-- Leave Request Modal (same as before) -->
<div class="modal fade" id="leaveModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><div class="modal-header"><h5>Request Leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Leave Type *</label><select name="leave_type" class="form-select" required><option value="">Select Type</option><option value="sick">Sick Leave</option><option value="vacation">Vacation Leave</option><option value="emergency">Emergency Leave</option><option value="maternity">Maternity Leave</option><option value="paternity">Paternity Leave</option><option value="other">Other</option></select></div><div class="row"><div class="col-md-6 mb-3"><label>Start Date *</label><input type="date" name="start_date" class="form-control" required min="<?= date('Y-m-d') ?>"></div><div class="col-md-6 mb-3"><label>End Date *</label><input type="date" name="end_date" class="form-control" required min="<?= date('Y-m-d') ?>"></div></div><div class="mb-3"><label>Reason *</label><textarea name="reason" class="form-control" rows="4" required></textarea></div><div class="mb-3"><label>Attachment (optional)</label><input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"><small class="text-muted">Max 5MB. Allowed: PDF, DOC, DOCX, JPG, PNG</small></div><div class="alert alert-info"><i class="bi bi-info-circle"></i> Your current leave balance: <strong><?= number_format($leave_balance, 1) ?></strong> days.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="submit_request" class="btn btn-primary">Submit Request</button></div></form></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>const startDate = document.querySelector('input[name="start_date"]'); const endDate = document.querySelector('input[name="end_date"]'); startDate.addEventListener('change', function() { endDate.min = this.value; if (endDate.value && endDate.value < this.value) endDate.value = ''; }); endDate.addEventListener('change', function() { if (startDate.value && this.value < startDate.value) this.value = ''; });</script>
</body>
</html>