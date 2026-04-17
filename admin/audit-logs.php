<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR a.details LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}
if ($action_filter) {
    $where[] = "a.action = ?";
    $params[] = $action_filter;
}
if ($date_from) {
    $where[] = "DATE(a.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where[] = "DATE(a.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Count total records
$count_sql = "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $where_sql";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get logs
$sql = "SELECT a.*, u.first_name, u.last_name, u.email 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        $where_sql 
        ORDER BY a.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get distinct actions for filter dropdown
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs - DOIT</title>
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
            <h2>Audit Logs</h2>
            <p class="text-muted">Track all admin actions, including create, update, delete, and leave reviews.</p>

            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" placeholder="User name, email, details..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Action</label>
                            <select name="action" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($actions as $act): ?>
                                    <option value="<?= htmlspecialchars($act) ?>" <?= $action_filter == $act ? 'selected' : '' ?>><?= htmlspecialchars($act) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Filter</button>
                            <a href="audit-logs.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Logs Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr><td colspan="6" class="text-center">No audit logs found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= formatDate($log['created_at'], 'Y-m-d H:i:s') ?></td>
                                        <td>
                                            <?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?><br>
                                            <small><?= htmlspecialchars($log['email']) ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                        <td>
                                            <?php if ($log['target_table']): ?>
                                                <?= htmlspecialchars($log['target_table']) ?> #<?= $log['target_id'] ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= nl2br(htmlspecialchars($log['details'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($log['ip_address']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($action_filter) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Previous</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($action_filter) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($action_filter) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>