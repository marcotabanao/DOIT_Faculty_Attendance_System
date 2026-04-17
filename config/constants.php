<?php
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/DOIT_FULL_SYSTEM/');
if (!defined('SITE_NAME')) define('SITE_NAME', 'DOIT Faculty Attendance System');
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__) . '/');
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', BASE_PATH . 'assets/uploads/');
if (!defined('PROFILE_PHOTO_PATH')) define('PROFILE_PHOTO_PATH', UPLOAD_PATH . 'profile_photos/');
if (!defined('LEAVE_ATTACHMENT_PATH')) define('LEAVE_ATTACHMENT_PATH', UPLOAD_PATH . 'leave_attachments/');
if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 1800);
if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', 'csrf_token');
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 5242880);   // <-- ADD THIS
if (!defined('ALLOWED_IMAGE_TYPES')) define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg', 'image/gif']);
if (!defined('ALLOWED_DOC_TYPES')) define('ALLOWED_DOC_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>