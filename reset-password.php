<?php
require_once 'config/database.php';
require_once 'config/constants.php';
require_once 'includes/functions.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();
if (!$reset) $error = "Invalid or expired token.";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    if ($password !== $confirm) $error = "Passwords do not match.";
    elseif (strlen($password) < 6) $error = "Password must be at least 6 characters.";
    else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?")->execute([$hash, $reset['email']]);
        $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        $success = "Password reset successful. <a href='login.php'>Login here</a>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Reset Password</div>
                <div class="card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
                    <?php if (!$error && !$success && $reset): ?>
                    <form method="POST">
                        <div class="mb-3"><label>New Password</label><input type="password" name="password" class="form-control" required></div>
                        <div class="mb-3"><label>Confirm Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary">Reset</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>