<?php
session_start();
require_once '../config/database.php';
require_once 'auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $success = $stmt->execute([$user_id]);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit();
}
?>