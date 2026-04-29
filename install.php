<?php
/**
 * DOIT Faculty Attendance System - Installation Script
 * One-click database setup and initial configuration
 */

// Prevent direct access if already installed
if (file_exists('config/installed.lock')) {
    header('Location: login.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DOIT Faculty Attendance System - Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .install-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .step {
            display: none;
        }
        .step.active {
            display: block;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            color: #667eea;
            font-weight: bold;
        }
        .progress-step {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin: 10px 0;
        }
        .progress-step.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .progress-step.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body class="bg-light">
    <div class="install-container">
        <div class="logo">
            <img src="assets/uploads/logo.png" alt="DOIT Logo" style="max-height: 80px; margin-bottom: 20px;">
            <h1><i class="fas fa-graduation-cap"></i> DOIT</h1>
            <p class="text-muted">Faculty Attendance System Installation</p>
        </div>

        <div id="step1" class="step active">
            <h3>Step 1: Database Configuration</h3>
            <p>Please verify your database connection settings:</p>
            
            <?php
            $db_test = false;
            try {
                $pdo = new PDO("mysql:host=localhost", "root", "");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db_test = true;
                echo '<div class="progress-step success">MySQL Connection: SUCCESS</div>';
            } catch (PDOException $e) {
                echo '<div class="progress-step error">MySQL Connection: FAILED - ' . $e->getMessage() . '</div>';
            }
            ?>

            <form method="POST" action="install.php?action=database">
                <div class="mb-3">
                    <label class="form-label">Database Host</label>
                    <input type="text" class="form-control" name="db_host" value="localhost" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Database Username</label>
                    <input type="text" class="form-control" name="db_user" value="root" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Database Password</label>
                    <input type="password" class="form-control" name="db_pass" value="">
                </div>
                <div class="mb-3">
                    <label class="form-label">Database Name</label>
                    <input type="text" class="form-control" name="db_name" value="doit_attendance" required>
                </div>
                <button type="submit" class="btn btn-primary" <?php echo !$db_test ? 'disabled' : ''; ?>>
                    Continue to Database Setup
                </button>
            </form>
        </div>

        <?php
        if ($_GET['action'] === 'database') {
            $db_host = $_POST['db_host'] ?? 'localhost';
            $db_user = $_POST['db_user'] ?? 'root';
            $db_pass = $_POST['db_pass'] ?? '';
            $db_name = $_POST['db_name'] ?? 'doit_attendance';
            
            try {
                $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create database
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$db_name`");
                
                // Import schema
                $schema_file = __DIR__ . '/database/schema.sql';
                if (file_exists($schema_file)) {
                    $schema_sql = file_get_contents($schema_file);
                    $pdo->exec($schema_sql);
                }
                
                echo '<div id="step2" class="step active">';
                echo '<h3>Step 2: Database Setup Complete</h3>';
                echo '<div class="progress-step success">Database Created: SUCCESS</div>';
                echo '<div class="progress-step success">Schema Imported: SUCCESS</div>';
                
                // Create admin user
                $admin_hash = password_hash('Admin@1234', PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute(['admin', 'admin@doit.edu.ph', $admin_hash, 'admin', 1]);
                
                echo '<div class="progress-step success">Admin User Created: SUCCESS</div>';
                
                // Update config file
                $config_file = __DIR__ . '/config/config.php';
                $config_content = file_get_contents($config_file);
                $config_content = preg_replace("/define\('DB_HOST', '[^']*'\)/", "define('DB_HOST', '$db_host')", $config_content);
                $config_content = preg_replace("/define\('DB_USER', '[^']*'\)/", "define('DB_USER', '$db_user')", $config_content);
                $config_content = preg_replace("/define\('DB_PASS', '[^']*'\)/", "define('DB_PASS', '$db_pass')", $config_content);
                $config_content = preg_replace("/define\('DB_NAME', '[^']*'\)/", "define('DB_NAME', '$db_name')", $config_content);
                file_put_contents($config_file, $config_content);
                
                echo '<div class="progress-step success">Configuration Updated: SUCCESS</div>';
                
                // Create lock file
                file_put_contents(__DIR__ . '/config/installed.lock', date('Y-m-d H:i:s'));
                
                echo '<div class="alert alert-success">';
                echo '<h4>Installation Complete!</h4>';
                echo '<p>The DOIT Faculty Attendance System has been successfully installed.</p>';
                echo '<strong>Login Credentials:</strong><br>';
                echo 'Username: <code>admin</code><br>';
                echo 'Password: <code>Admin@1234</code><br><br>';
                echo '<a href="login.php" class="btn btn-primary">Go to Login Page</a>';
                echo '</div>';
                echo '</div>';
                
            } catch (PDOException $e) {
                echo '<div id="step2" class="step active">';
                echo '<h3>Database Setup Failed</h3>';
                echo '<div class="progress-step error">Error: ' . $e->getMessage() . '</div>';
                echo '<a href="install.php" class="btn btn-secondary">Try Again</a>';
                echo '</div>';
            }
        }
        ?>
    </div>
</body>
</html>
