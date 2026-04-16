<?php
/**
 * DOIT Faculty Attendance System - Faculty Export
 * Export faculty data to CSV/Excel
 */

// Define system constant for security
define('DOIT_SYSTEM', true);

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

// Require admin authentication
requireAdmin();

// Check session timeout
checkSessionTimeout();

// Get filters
$search = sanitizeInput($_GET['search'] ?? '');
$departmentFilter = (int)($_GET['department'] ?? 0);
$statusFilter = sanitizeInput($_GET['status'] ?? '');

try {
    $whereConditions = ["1=1"];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(f.employee_id LIKE ? OR f.first_name LIKE ? OR f.last_name LIKE ? OR f.email LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    if ($departmentFilter > 0) {
        $whereConditions[] = "f.department_id = ?";
        $params[] = $departmentFilter;
    }
    
    if (!empty($statusFilter)) {
        $whereConditions[] = "f.status = ?";
        $params[] = $statusFilter;
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    // Get faculty data
    $stmt = $pdo->prepare("
        SELECT 
            f.employee_id,
            f.first_name,
            f.middle_name,
            f.last_name,
            f.email,
            f.phone,
            d.name as department_name,
            f.position,
            f.hire_date,
            f.status,
            f.created_at
        FROM faculty f 
        LEFT JOIN departments d ON f.department_id = d.id 
        WHERE $whereClause 
        ORDER BY f.last_name, f.first_name
    ");
    $stmt->execute($params);
    $facultyList = $stmt->fetchAll();
    
    // Prepare data for export
    $exportData = [];
    foreach ($facultyList as $faculty) {
        $exportData[] = [
            $faculty['employee_id'],
            $faculty['first_name'],
            $faculty['middle_name'] ?? '',
            $faculty['last_name'],
            $faculty['email'],
            $faculty['phone'] ?? '',
            $faculty['department_name'] ?? 'N/A',
            $faculty['position'],
            formatDate($faculty['hire_date']),
            $faculty['status'],
            formatDate($faculty['created_at'])
        ];
    }
    
    // Export to CSV
    $headers = [
        'Employee ID',
        'First Name',
        'Middle Name',
        'Last Name',
        'Email',
        'Phone',
        'Department',
        'Position',
        'Hire Date',
        'Status',
        'Date Added'
    ];
    
    $filename = 'faculty_export_' . date('Y-m-d_H-i-s') . '.csv';
    exportToCSV($exportData, $filename, $headers);
    
} catch (PDOException $e) {
    error_log("Faculty export error: " . $e->getMessage());
    redirectWithMessage('faculty.php', 'error', 'Failed to export faculty data.');
}
?>
