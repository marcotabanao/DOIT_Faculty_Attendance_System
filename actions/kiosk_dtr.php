<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'dtr_data' => null];

try {
    // Get faculty ID from request
    $faculty_id = $_POST['faculty_id'] ?? '';
    $month = $_POST['month'] ?? date('Y-m');
    
    if (empty($faculty_id)) {
        throw new Exception('Faculty ID is required');
    }
    
    // Look up faculty
    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ? AND role = 'faculty'");
    $stmt->execute([$faculty_id]);
    $faculty = $stmt->fetch();
    
    if (!$faculty) {
        throw new Exception('Faculty not found');
    }
    
    // Parse month and year
    $year = date('Y', strtotime($month . '-01'));
    $month_num = date('n', strtotime($month . '-01'));
    $days_in_month = date('t', strtotime($month . '-01'));
    
    // Get attendance records for the month
    $attStmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE faculty_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
        ORDER BY date ASC
    ");
    $attStmt->execute([$faculty['id'], $month]);
    $attendance_records = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get faculty department
    $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $deptStmt->execute([$faculty['department_id']]);
    $department = $deptStmt->fetchColumn() ?: 'Not Assigned';
    
    // Build DTR data array
    $dtr_data = [];
    
    // Initialize all days of the month
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month_num, $day);
        $day_of_week = date('N', strtotime($date));
        
        // Find attendance record for this day
        $record = null;
        foreach ($attendance_records as $att) {
            if ($att['date'] === $date) {
                $record = $att;
                break;
            }
        }
        
        // Determine if it's a weekend (Saturday=6, Sunday=7)
        $is_weekend = ($day_of_week >= 6);
        
        // Build day data
        $day_data = [
            'day' => $day,
            'date' => $date,
            'day_of_week' => $day_of_week,
            'is_weekend' => $is_weekend,
            'check_in' => $record ? formatTime($record['check_in_time']) : '',
            'check_out' => $record ? formatTime($record['check_out_time']) : '',
            'status' => $record ? $record['status'] : ($is_weekend ? 'Weekend' : 'Absent'),
            'late_minutes' => $record ? ($record['late_minutes'] ?? 0) : 0,
            'remarks' => $record ? ($record['remarks'] ?? '') : ''
        ];
        
        $dtr_data[] = $day_data;
    }
    
    // Calculate summary statistics
    $present_days = 0;
    $late_days = 0;
    $absent_days = 0;
    $total_late_minutes = 0;
    
    foreach ($dtr_data as $day) {
        if (!$day['is_weekend']) {
            if ($day['status'] === 'present') {
                $present_days++;
            } elseif ($day['status'] === 'late') {
                $present_days++;
                $late_days++;
                $total_late_minutes += $day['late_minutes'];
            } elseif ($day['status'] === 'Absent') {
                $absent_days++;
            }
        }
    }
    
    // Build complete response
    $response['success'] = true;
    $response['message'] = 'DTR data retrieved successfully';
    $response['dtr_data'] = [
        'faculty' => [
            'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
            'employee_id' => $faculty['employee_id'],
            'department' => $department,
            'position' => $faculty['position'] ?? 'Not Assigned'
        ],
        'month' => date('F Y', strtotime($month . '-01')),
        'days' => $dtr_data,
        'summary' => [
            'present_days' => $present_days,
            'late_days' => $late_days,
            'absent_days' => $absent_days,
            'total_late_minutes' => $total_late_minutes,
            'total_late_hours' => round($total_late_minutes / 60, 2),
            'working_days' => $present_days + $late_days + $absent_days
        ]
    ];
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Kiosk DTR error: " . $e->getMessage());
}

echo json_encode($response);
?>
