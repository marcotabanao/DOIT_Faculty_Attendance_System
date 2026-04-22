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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | DOIT Faculty Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fb;
            height: 100vh;
            overflow: hidden;
        }
        .split-container { display: flex; height: 100vh; width: 100%; }
        /* LEFT PANEL – unchanged */
        .left-panel {
            flex: 1;
            background: #faf8f5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            border-right: 1px solid #eaeaea;
        }
        .brand-content { text-align: center; max-width: 400px; }
        .brand-logo { max-width: 120px; margin-bottom: 1.5rem; }
        .brand-content h1 { font-size: 2rem; font-weight: 600; color: #800000; margin-bottom: 0.5rem; letter-spacing: -0.3px; }
        .brand-content .gold { color: #b8860b; }
        .brand-content p { color: #5a626e; font-size: 0.9rem; line-height: 1.5; margin-top: 0.5rem; }
        
        /* RIGHT PANEL – floating card with larger text */
        .right-panel {
            flex: 1;
            background: #f5f7fb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .floating-card {
            background: white;
            border-radius: 28px;
            padding: 2rem 2rem;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0,0,0,0.02);
            transition: transform 0.2s ease;
        }
        .floating-card:hover {
            transform: translateY(-4px);
        }
        .login-header { text-align: center; margin-bottom: 1.8rem; }
        .login-header img { max-height: 60px; margin-bottom: 0.5rem; }
        .login-header h3 { font-size: 1.7rem; font-weight: 600; color: #1e2a3e; margin: 0.5rem 0 0.25rem; }
        .login-header p { font-size: 0.95rem; color: #6c757d; }
        
        .form-label { 
            font-weight: 500; 
            color: #2c3e50; 
            font-size: 0.9rem; 
            margin-bottom: 0.4rem; 
        }
        .form-control {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            transition: 0.15s;
            background: #fefefe;
        }
        .form-control:focus {
            border-color: #800000;
            box-shadow: 0 0 0 3px rgba(128,0,0,0.08);
            outline: none;
        }
        .btn-login {
            background-color: #800000;
            border: none;
            border-radius: 40px;
            padding: 0.8rem;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: background 0.2s;
        }
        .btn-login:hover { background-color: #600000; }
        
        .forgot-link, .register-link {
            color: #800000;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .forgot-link:hover, .register-link:hover { text-decoration: underline; }
        
        .demo-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 0.9rem;
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.8rem;
            color: #4d586b;
            border: 1px solid #edf2f7;
        }
        .demo-box strong { color: #800000; }
        
        hr { margin: 1.2rem 0; background-color: #e9ecef; }
        .alert { border-radius: 14px; font-size: 0.9rem; }
        
        @media (max-width: 768px) {
            .split-container { flex-direction: column; }
            .left-panel { border-right: none; border-bottom: 1px solid #eaeaea; padding: 1.5rem; }
            .right-panel { padding: 1.5rem; }
            body { overflow: auto; }
        }
    </style>
</head>
<body>
<div class="split-container">
    <!-- Left panel (unchanged) -->
    <div class="left-panel">
        <div class="brand-content">
            <?php if ($logo_path && file_exists(PROFILE_PHOTO_PATH . $logo_path)): ?>
                <img src="assets/uploads/profile_photos/<?= htmlspecialchars($logo_path) ?>" alt="DOIT Logo" class="brand-logo">
            <?php else: ?>
                <img src="assets/images/doit-logo.png" alt="DOIT Logo" class="brand-logo" onerror="this.onerror=null; this.src='https://via.placeholder.com/120x120?text=DOIT'">
            <?php endif; ?>
            <h1>DOIT <span class="gold">Faculty</span> Attendance</h1>
            <p>Davao Oriental International<br>Technology College</p>
            <div style="width: 50px; height: 2px; background: #e2c7a1; margin: 1rem auto;"></div>
            <p class="small">Track attendance • Manage leaves • Real‑time reports</p>
        </div>
    </div>

    <!-- Right panel: floating card with login form (larger text) -->
    <div class="right-panel">
        <div class="floating-card">
            <div class="login-header">
                <?php if ($logo_path && file_exists(PROFILE_PHOTO_PATH . $logo_path)): ?>
                    <img src="assets/uploads/profile_photos/<?= htmlspecialchars($logo_path) ?>" alt="DOIT Logo">
                <?php else: ?>
                    <i class="fas fa-graduation-cap fa-2x" style="color: #800000;"></i>
                <?php endif; ?>
                <h3>Welcome back</h3>
                <p>Sign in to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" placeholder="name@doit.edu.ph" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" placeholder="Enter your password" required id="passwordInput">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" style="border-radius: 0 14px 14px 0;">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-login">Sign in</button>
            </form>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                <span class="text-muted small">or</span>
                <a href="register.php" class="register-link">Create an account</a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (togglePassword && passwordInput && toggleIcon) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle icon
            if (type === 'password') {
                toggleIcon.classList.remove('bi-eye-slash');
                toggleIcon.classList.add('bi-eye');
            } else {
                toggleIcon.classList.remove('bi-eye');
                toggleIcon.classList.add('bi-eye-slash');
            }
        });
    }
});
</script>
</body>
</html>