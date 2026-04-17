<?php
require_once '../includes/auth.php';
requireRole('faculty');
require_once '../config/database.php';
require_once '../includes/functions.php';
$user_id = $_SESSION['user_id'];
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $id = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id,$user_id]);
    header("Location: notifications.php"); exit();
}
if (isset($_GET['mark_all'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
    header("Location: notifications.php"); exit();
}
$page = isset($_GET['page'])?(int)$_GET['page']:1;
$limit=20; $offset=($page-1)*$limit;
$total = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?")->execute([$user_id])?$pdo->prepare("SELECT FOUND_ROWS()")->fetchColumn():0; // simple fix
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?"); $stmt->execute([$user_id]); $total=$stmt->fetchColumn();
$total_pages=ceil($total/$limit);
$stmt=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1,$user_id,PDO::PARAM_INT); $stmt->bindValue(2,$limit,PDO::PARAM_INT); $stmt->bindValue(3,$offset,PDO::PARAM_INT); $stmt->execute();
$notifications=$stmt->fetchAll();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>My Notifications | DOIT</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/theme.css"></head>
<body><div class="container-fluid"><div class="row"><div class="col-md-2 p-0"><?php include '../includes/faculty-sidebar.php'; ?></div><div class="col-md-10 p-0"><?php include '../includes/faculty-tapnav.php'; ?><div class="p-4" style="padding-top: 80px !important;"><div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between"><h5 class="mb-0"><i class="bi bi-bell"></i> My Notifications</h5><div><a href="?mark_all=1" class="btn btn-sm btn-outline-primary" onclick="return confirm('Mark all as read?')">Mark All Read</a><a href="../faculty/dashboard.php" class="btn btn-sm btn-secondary ms-2">Back</a></div></div><div class="card-body"><?php if(empty($notifications)): ?><div class="alert alert-info">No notifications found.</div><?php else: ?><div class="list-group"><?php foreach($notifications as $n): ?><div class="list-group-item <?= $n['is_read']?'':'list-group-item-warning' ?>"><div class="d-flex justify-content-between"><div><h6 class="mb-1"><?= htmlspecialchars($n['title']) ?></h6><p class="mb-1"><?= nl2br(htmlspecialchars($n['message'])) ?></p><small class="text-muted"><?= formatDate($n['created_at'],'Y-m-d H:i:s') ?></small></div><?php if(!$n['is_read']): ?><a href="?mark_read=<?= $n['id'] ?>" class="btn btn-sm btn-success">Mark Read</a><?php else: ?><span class="badge bg-secondary">Read</span><?php endif; ?></div></div><?php endforeach; ?></div><?php if($total_pages>1): ?><nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav><?php endif; endif; ?></div></div></div></div></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script></body></html>