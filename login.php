<?php
require_once 'config/database.php';
require_once 'config/constants.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$error = '';
$logo_path = getSetting($pdo, 'logo_path');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    if (login($pdo, $email, $password)) {
        $redirect = $_SESSION['user_role'] === 'admin' ? 'admin/dashboard.php' : 'faculty/dashboard.php';
        header("Location: $redirect");
        exit();
    } else {
        $error = 'Invalid email or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body {
            background: linear-gradient(135deg, #800000 0%, #5a0000 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <?php if ($logo_path && file_exists(PROFILE_PHOTO_PATH . $logo_path)): ?>
            <img src="assets/uploads/profile_photos/<?= htmlspecialchars($logo_path) ?>" alt="DOIT Logo" height="80">
        <?php else: ?>
            <i class="fas fa-chalkboard-user fa-3x" style="color: #800000;"></i>
        <?php endif; ?>
        <h3 class="mt-3" style="color: #800000;">DOIT Faculty Attendance</h3>
        <p style="color: #DAA520;">Davao Oriental International Technology College</p>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <button type="submit" class="btn btn-primary w-100">Login</button>
        <div class="text-center mt-3"><a href="forgot-password.php">Forgot Password?</a></div>
    </form>
    <hr>
    <div class="text-center text-muted small">
        <strong>Demo:</strong> Admin: admin@doit.edu.ph / Admin@1234<br>
        Faculty: john.smith@doit.edu.ph / Faculty@1234
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>