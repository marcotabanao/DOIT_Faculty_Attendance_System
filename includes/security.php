<?php
/**
 * DOIT Faculty Attendance System - Security Functions
 * Contains all security-related functions
 */

// Prevent direct access
if (!defined('DOIT_SYSTEM')) {
    exit('Direct access denied');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

/**
 * Hash password securely
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Sanitize input
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate secure random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Check if user is faculty
 */
function isFaculty() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'faculty';
}

/**
 * Require authentication
 */
function requireAuth() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

/**
 * Require admin role
 */
function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        header('Location: unauthorized.php');
        exit;
    }
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (isLoggedIn()) {
        $last_activity = $_SESSION['last_activity'] ?? 0;
        if (time() - $last_activity > SESSION_TIMEOUT) {
            session_destroy();
            header('Location: login.php?timeout=1');
            exit;
        }
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Log user action
 */
function logUserAction($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    global $pdo;
    
    if (!isLoggedIn()) return false;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowedTypes = []) {
    if (empty($allowedTypes)) {
        $allowedTypes = ALLOWED_EXTENSIONS;
    }
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File size exceeds maximum limit'];
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    // Check MIME type
    $allowedMimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    return ['success' => true];
}

/**
 * Upload file securely
 */
function uploadFile($file, $subfolder = '') {
    $validation = validateFileUpload($file);
    if (!$validation['success']) {
        return $validation;
    }
    
    $uploadDir = UPLOAD_PATH . $subfolder;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = generateRandomString() . '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    }
    
    return ['success' => false, 'message' => 'Failed to upload file'];
}

/**
 * Generate password reset token
 */
function generatePasswordResetToken($userId) {
    global $pdo;
    
    $token = generateRandomString(64);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_reset_token = ?, password_reset_expires = ? 
            WHERE id = ?
        ");
        $stmt->execute([$token, $expires, $userId]);
        
        return $token;
    } catch (PDOException $e) {
        error_log("Password reset token error: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify password reset token
 */
function verifyPasswordResetToken($token) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE password_reset_token = ? 
            AND password_reset_expires > NOW()
            AND is_active = 1
        ");
        $stmt->execute([$token]);
        
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Password reset verification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear password reset token
 */
function clearPasswordResetToken($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_reset_token = NULL, password_reset_expires = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Clear password reset token error: " . $e->getMessage());
        return false;
    }
}
?>
