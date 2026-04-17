<?php
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT id, employee_id, first_name, last_name, email, department_id, position, hire_date, contact_number, leave_balance, status FROM users WHERE id = ? AND role = 'faculty'");
    $stmt->execute([$id]);
    $faculty = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($faculty) {
        echo json_encode(['success' => true] + $faculty);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
?>