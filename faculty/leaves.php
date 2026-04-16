<?php
/**
 * DOIT Faculty Attendance System - Faculty Leave Management
 * Leave request management for faculty members
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
        redirectWithMessage('leaves.php', 'error', 'Invalid request. Please try again.');
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'request':
            $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $reason = sanitizeInput($_POST['reason'] ?? '');
            
            // Validation
            if ($leaveTypeId <= 0 || empty($startDate) || empty($endDate) || empty($reason)) {
                redirectWithMessage('leaves.php', 'error', 'Please fill in all required fields.');
            }
            
            if (strtotime($startDate) > strtotime($endDate)) {
                redirectWithMessage('leaves.php', 'error', 'Start date must be before end date.');
            }
            
            if (strtotime($startDate) < strtotime(date('Y-m-d'))) {
                redirectWithMessage('leaves.php', 'error', 'Start date cannot be in the past.');
            }
            
            try {
                // Calculate total days
                $totalDays = calculateDays($startDate, $endDate);
                
                // Check leave credits
                $stmt = $pdo->prepare("
                    SELECT remaining_credits FROM leave_credits 
                    WHERE faculty_id = ? AND leave_type_id = ? AND year = YEAR(?)
                ");
                $stmt->execute([$_SESSION['faculty_id'], $leaveTypeId, $startDate]);
                $credit = $stmt->fetch();
                
                if (!$credit || $credit['remaining_credits'] < $totalDays) {
                    redirectWithMessage('leaves.php', 'error', 'Insufficient leave credits for this request.');
                }
                
                // Handle file upload
                $attachmentPath = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = uploadFile($_FILES['attachment'], 'leaves/');
                    if ($uploadResult['success']) {
                        $attachmentPath = $uploadResult['filename'];
                    } else {
                        redirectWithMessage('leaves.php', 'error', $uploadResult['message']);
                    }
                }
                
                // Insert leave request
                $stmt = $pdo->prepare("
                    INSERT INTO leave_requests (faculty_id, leave_type_id, start_date, end_date, total_days, reason, attachment_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['faculty_id'], $leaveTypeId, $startDate, $endDate, $totalDays, $reason, $attachmentPath]);
                $leaveId = $pdo->lastInsertId();
                
                // Create notification for admin
                $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
                foreach ($admins as $admin) {
                    createNotification(
                        $admin['id'],
                        'New Leave Request',
                        "New leave request from " . $_SESSION['first_name'] . " " . $_SESSION['last_name'],
                        'info',
                        '../admin/leaves.php'
                    );
                }
                
                logUserAction('create', 'leave_requests', $leaveId, null, [
                    'faculty_id' => $_SESSION['faculty_id'],
                    'leave_type_id' => $leaveTypeId,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
                
                redirectWithMessage('leaves.php', 'success', 'Leave request submitted successfully.');
                
            } catch (PDOException $e) {
                error_log("Leave request error: " . $e->getMessage());
                redirectWithMessage('leaves.php', 'error', 'Failed to submit leave request.');
            }
            break;
    }
}

// Get data
try {
    // Get leave types
    $leaveTypes = getLeaveTypes();
    
    // Get faculty leave requests
    $stmt = $pdo->prepare("
        SELECT lr.*, lt.name as leave_type_name, u.first_name as approved_by_name, u.last_name as approved_by_last_name
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.faculty_id = ?
        ORDER BY lr.created_at DESC
    ");
    $stmt->execute([$_SESSION['faculty_id']]);
    $leaveRequests = $stmt->fetchAll();
    
    // Get leave credits
    $leaveCredits = getFacultyLeaveCredits($_SESSION['faculty_id']);
    
} catch (PDOException $e) {
    error_log("Faculty leaves error: " . $e->getMessage());
    $leaveTypes = [];
    $leaveRequests = [];
    $leaveCredits = [];
}

// Get flash message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - <?php echo SYSTEM_NAME; ?></title>
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
        
        .leave-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .leave-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .credit-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        .credit-card:hover {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
        }
        
        .progress-bar {
            transition: width 0.3s ease;
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
                        <a class="nav-link" href="profile.php">
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
                        <a class="nav-link active" href="leaves.php">
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
                    <h2>Leave Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leaveModal">
                        <i class="fas fa-plus"></i> Request Leave
                    </button>
                </div>
                
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Leave Credits -->
                <div class="card leave-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">My Leave Credits</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($leaveCredits)): ?>
                            <div class="text-center py-3">
                                <i class="fas fa-hourglass-half fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No leave credits available</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($leaveCredits as $credit): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="credit-card">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($credit['name']); ?></h6>
                                                <span class="badge bg-primary"><?php echo $credit['year']; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <small class="text-muted">Credits Used</small>
                                                <div class="progress" style="height: 8px;">
                                                    <?php 
                                                    $percentage = $credit['total_credits'] > 0 ? 
                                                        ($credit['used_credits'] / $credit['total_credits']) * 100 : 0;
                                                    ?>
                                                    <div class="progress-bar bg-<?php echo $percentage > 80 ? 'danger' : ($percentage > 60 ? 'warning' : 'success'); ?>" 
                                                         style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo $credit['used_credits']; ?> used</small>
                                                <strong><?php echo $credit['remaining_credits']; ?> remaining</strong>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Leave Requests -->
                <div class="card leave-card">
                    <div class="card-header">
                        <h5 class="mb-0">My Leave Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($leaveRequests)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No leave requests found.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leaveModal">
                                    <i class="fas fa-plus"></i> Request Your First Leave
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Leave Type</th>
                                            <th>Duration</th>
                                            <th>Days</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Applied On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($leaveRequests as $request): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($request['leave_type_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <?php echo formatDate($request['start_date']); ?> - <?php echo formatDate($request['end_date']); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $request['total_days']; ?> day(s)</span>
                                                </td>
                                                <td>
                                                    <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars($request['reason']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge badge bg-<?php 
                                                        echo $request['status'] === 'Approved' ? 'success' : 
                                                             ($request['status'] === 'Rejected' ? 'danger' : 'warning'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($request['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatDate($request['created_at']); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <?php if ($request['attachment_path']): ?>
                                                            <a href="../uploads/leaves/<?php echo htmlspecialchars($request['attachment_path']); ?>" 
                                                               target="_blank" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-file"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-outline-info" onclick="viewDetails(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Leave Request Modal -->
    <div class="modal fade" id="leaveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="request">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="leave_type_id" class="form-label">Leave Type *</label>
                            <select class="form-select" id="leave_type_id" name="leave_type_id" required onchange="updateCreditInfo()">
                                <option value="">Select Leave Type</option>
                                <?php foreach ($leaveTypes as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" data-max-days="<?php echo $type['max_days_per_year']; ?>" data-requires-attachment="<?php echo $type['requires_attachment']; ?>">
                                        <?php echo htmlspecialchars($type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" required 
                                           min="<?php echo date('Y-m-d'); ?>" onchange="calculateDays()">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">End Date *</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" required 
                                           min="<?php echo date('Y-m-d'); ?>" onchange="calculateDays()">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason *</label>
                            <textarea class="form-control" id="reason" name="reason" rows="4" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="attachment" class="form-label">Attachment</label>
                            <input type="file" class="form-control" id="attachment" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small class="text-muted" id="attachment_note">Optional attachment (PDF, DOC, DOCX, JPG, PNG)</small>
                        </div>
                        
                        <div id="credit_info" class="alert alert-info" style="display: none;">
                            <small id="credit_text"></small>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Leave Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <!-- Details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update credit information
        function updateCreditInfo() {
            const select = document.getElementById('leave_type_id');
            const option = select.options[select.selectedIndex];
            const creditInfo = document.getElementById('credit_info');
            const creditText = document.getElementById('credit_text');
            const attachmentNote = document.getElementById('attachment_note');
            
            if (select.value) {
                const maxDays = option.dataset.maxDays;
                const requiresAttachment = option.dataset.requiresAttachment === '1';
                
                creditText.textContent = `Maximum days allowed: ${maxDays}`;
                creditInfo.style.display = 'block';
                
                if (requiresAttachment) {
                    attachmentNote.textContent = 'Attachment required for this leave type';
                    attachmentNote.classList.add('text-danger');
                } else {
                    attachmentNote.textContent = 'Optional attachment (PDF, DOC, DOCX, JPG, PNG)';
                    attachmentNote.classList.remove('text-danger');
                }
            } else {
                creditInfo.style.display = 'none';
            }
        }
        
        // Calculate days
        function calculateDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
                
                const creditInfo = document.getElementById('credit_info');
                const creditText = document.getElementById('credit_text');
                
                if (days > 0) {
                    creditText.textContent = `Total days: ${days}`;
                } else {
                    creditText.textContent = 'Invalid date range';
                }
            }
        }
        
        // View details
        function viewDetails(leaveId) {
            <?php foreach ($leaveRequests as $request): ?>
                if (<?php echo $request['id']; ?> === leaveId) {
                    const content = `
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Leave Type:</strong> <?php echo htmlspecialchars($request['leave_type_name']); ?><br>
                                <strong>Start Date:</strong> <?php echo formatDate($request['start_date']); ?><br>
                                <strong>End Date:</strong> <?php echo formatDate($request['end_date']); ?><br>
                                <strong>Total Days:</strong> <?php echo $request['total_days']; ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong> <span class="badge bg-<?php 
                                    echo $request['status'] === 'Approved' ? 'success' : 
                                         ($request['status'] === 'Rejected' ? 'danger' : 'warning'); 
                                ?>"><?php echo htmlspecialchars($request['status']); ?></span><br>
                                <strong>Applied On:</strong> <?php echo formatDate($request['created_at']); ?>
                                <?php if ($request['approved_date']): ?>
                                    <br><strong>Processed On:</strong> <?php echo formatDateTime($request['approved_date']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-3">
                            <strong>Reason:</strong><br>
                            <?php echo nl2br(htmlspecialchars($request['reason'])); ?>
                        </div>
                        <?php if ($request['rejection_reason']): ?>
                            <div class="mt-3">
                                <strong>Rejection Reason:</strong><br>
                                <span class="text-danger"><?php echo htmlspecialchars($request['rejection_reason']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($request['approved_by_name']): ?>
                            <div class="mt-3">
                                <strong>Processed By:</strong> <?php echo htmlspecialchars($request['approved_by_name'] . ' ' . $request['approved_by_last_name']); ?>
                            </div>
                        <?php endif; ?>
                    `;
                    document.getElementById('detailsContent').innerHTML = content;
                    new bootstrap.Modal(document.getElementById('detailsModal')).show();
                }
            <?php endforeach; ?>
        }
        
        // Validate date relationship
        document.getElementById('start_date').addEventListener('change', function() {
            const endDate = document.getElementById('end_date');
            endDate.min = this.value;
            if (endDate.value && endDate.value < this.value) {
                endDate.value = '';
            }
            calculateDays();
        });
        
        document.getElementById('end_date').addEventListener('change', function() {
            const startDate = document.getElementById('start_date');
            if (startDate.value && this.value < startDate.value) {
                this.value = '';
            }
            calculateDays();
        });
    </script>
</body>
</html>
