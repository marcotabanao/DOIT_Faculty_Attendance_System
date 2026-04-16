<?php
require_once 'config/database.php';
require_once 'config/constants.php';
require_once 'includes/functions.php';
session_start();

$error = '';
$success = '';

// Get departments for dropdown
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection (optional but good)
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Sanitize and validate
        $employee_id = sanitizeInput($_POST['employee_id']);
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        $contact_number = sanitizeInput($_POST['contact_number']);
        $position = sanitizeInput($_POST['position']);
        $department_id = $_POST['department_id'] ?: null;
        
        // Validation
        if (empty($employee_id) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Check if employee_id or email already exists
            $check = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? OR email = ?");
            $check->execute([$employee_id, $email]);
            if ($check->fetch()) {
                $error = 'Employee ID or Email already exists.';
            } else {
                // Hash password
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // Insert new faculty (role = faculty, status = active, leave_balance = 15)
                $stmt = $pdo->prepare("INSERT INTO users (employee_id, first_name, last_name, email, password_hash, role, department_id, position, contact_number, status, leave_balance) VALUES (?, ?, ?, ?, ?, 'faculty', ?, ?, ?, 'active', 15.00)");
                if ($stmt->execute([$employee_id, $first_name, $last_name, $email, $hash, $department_id, $position, $contact_number])) {
                    $success = 'Account created successfully! You can now login.';
                    // Optionally send notification to admin
                    // Clear CSRF token
                    unset($_SESSION['csrf_token']);
                    // Redirect after 3 seconds? Or just show success.
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account - DOIT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body { background: linear-gradient(135deg, #800000 0%, #5a0000 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .register-card { background: white; border-radius: 20px; padding: 30px; width: 100%; max-width: 600px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="register-card">
    <div class="text-center mb-4">
        <i class="fas fa-user-plus fa-3x" style="color: #800000;"></i>
        <h3 class="mt-2" style="color: #800000;">Faculty Registration</h3>
        <p style="color: #DAA520;">Davao Oriental International Technology College</p>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?> <a href="login.php">Login here</a></div>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label>Employee ID *</label>
                <input type="text" name="employee_id" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Email *</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>First Name *</label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Last Name *</label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Password *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Contact Number</label>
                <input type="text" name="contact_number" class="form-control">
            </div>
            <div class="col-md-6 mb-3">
                <label>Position</label>
                <input type="text" name="position" class="form-control" placeholder="e.g., Instructor, Professor">
            </div>
            <div class="col-md-12 mb-3">
                <label>Department</label>
                <select name="department_id" class="form-select">
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">If not selected, admin will assign later.</small>
            </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">Register</button>
        <div class="text-center mt-3">
            <a href="login.php">Already have an account? Login</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>