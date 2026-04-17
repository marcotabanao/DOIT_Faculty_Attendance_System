<?php
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 5242880);
if (!defined('ALLOWED_IMAGE_TYPES')) define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg', 'image/gif']);
if (!defined('ALLOWED_DOC_TYPES')) define('ALLOWED_DOC_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generateUniqueFilename($originalName) {
    return uniqid() . '_' . time() . '.' . pathinfo($originalName, PATHINFO_EXTENSION);
}

function uploadFile($file, $targetDir, $allowedTypes = null, $maxSize = MAX_FILE_SIZE) {
    if ($file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'error' => 'Upload failed'];
    if ($file['size'] > $maxSize) return ['success' => false, 'error' => 'File too large'];
    $allowedTypes = $allowedTypes ?: ALLOWED_IMAGE_TYPES;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedTypes)) return ['success' => false, 'error' => 'Invalid file type'];
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $filename = generateUniqueFilename($file['name']);
    if (move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['success' => false, 'error' => 'Failed to save'];
}

function getDateDifference($start, $end) {
    return (new DateTime($start))->diff(new DateTime($end))->days + 1;
}

function formatDate($date, $format = 'M d, Y') {
    return $date ? date($format, strtotime($date)) : '';
}

function formatTime($time, $format = 'h:i A') {
    return $time ? date($format, strtotime($time)) : '';
}

function getLeaveTypeBadge($type) {
    if (empty($type)) {
        return '<span class="badge bg-secondary">Unknown</span>';
    }
    $map = [
        'sick' => 'info',
        'vacation' => 'success',
        'emergency' => 'danger',
        'maternity' => 'pink',
        'paternity' => 'primary',
        'other' => 'secondary'
    ];
    $color = $map[$type] ?? 'secondary';
    return "<span class='badge bg-{$color}'>" . ucfirst($type) . "</span>";
}

function getLeaveStatusBadge($status) {
    if (empty($status)) {
        return '<span class="badge bg-secondary">Unknown</span>';
    }
    $map = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger'
    ];
    $color = $map[$status] ?? 'secondary';
    return "<span class='badge bg-{$color}'>" . ucfirst($status) . "</span>";
}

function getStatusBadge($status) {
    if (empty($status)) {
        return '<span class="badge bg-secondary">Unknown</span>';
    }
    $map = [
        'present' => 'success',
        'absent' => 'danger',
        'late' => 'warning',
        'half_day' => 'info',
        'on_leave' => 'primary'
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class='badge bg-{$color}'>$label</span>";
}

function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($out, array_keys($data[0]));
        foreach ($data as $row) fputcsv($out, $row);
    }
    fclose($out);
    exit();
}

function getSetting($pdo, $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

function updateSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return '';
}

function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = ['message' => $message, 'type' => $type];
}
?>