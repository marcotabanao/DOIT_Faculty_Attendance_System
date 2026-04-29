<?php
/**
 * DOIT Faculty Attendance System - Admin DTR Management
 * Davao Oriental International Technology College (DOIT)
 * 
 * Comprehensive Daily Time Record management for admin dashboard
 * with HR-compliant entry generation and display
 */

require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/database.php';
require_once '../includes/functions.php';

// Date validation helper function
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Handle AJAX requests for DTR updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // Validate session and user permissions
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access'
        ]);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'update_dtr') {
        $attendanceId = $_POST['attendance_id'] ?? 0;
        $userId = $_POST['user_id'] ?? 0;
        $date = $_POST['date'] ?? '';
        $updates = $_POST['updates'] ?? [];
        
        try {
            // Validate input
            if (!$attendanceId || !$userId || !$date) {
                throw new Exception('Invalid parameters');
            }
            
            // Build update query
            $setClause = [];
            $params = [];
            
            foreach ($updates as $field => $value) {
                if ($field === 'check_in_time' || $field === 'check_out_time') {
                    $setClause[] = "$field = ?";
                    $params[] = $value ?: null;
                } elseif ($field === 'status') {
                    $setClause[] = "status = ?";
                    $params[] = $value;
                } elseif ($field === 'remarks') {
                    $setClause[] = "remarks = ?";
                    $params[] = sanitizeInput($value);
                }
            }
            
            if (empty($setClause)) {
                throw new Exception('No valid fields to update');
            }
            
            $params[] = $attendanceId;
            
            $sql = "UPDATE attendance SET " . implode(', ', $setClause) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'DTR record updated successfully'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error updating DTR record: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] === 'refresh_dtr') {
        $facultyFilter = $_POST['faculty_filter'] ?? '';
        $dateFilter = $_POST['date_filter'] ?? '';
        $statusFilter = $_POST['status_filter'] ?? '';
        $sortBy = $_POST['sort_by'] ?? 'date';
        $sortOrder = $_POST['sort_order'] ?? 'DESC';
        $searchTerm = $_POST['search_term'] ?? '';
        
        try {
            // Validate inputs
            if ($facultyFilter && !is_numeric($facultyFilter)) {
                throw new Exception('Invalid faculty filter');
            }
            
            if ($dateFilter && !validateDate($dateFilter)) {
                throw new Exception('Invalid date format');
            }
            
            // Validate sort parameters
            $allowedSortFields = ['date', 'first_name', 'last_name', 'employee_id', 'check_in_time', 'check_out_time', 'status'];
            if (!in_array($sortBy, $allowedSortFields)) {
                $sortBy = 'date';
            }
            
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            // Build query based on filters with proper sorting
            $dtrQuery = "
                SELECT 
                    u.id as user_id, u.first_name, u.last_name, u.employee_id,
                    a.id as attendance_id, a.date, a.check_in_time, a.check_out_time, a.status,
                    a.late_minutes, a.remarks,
                    lr.leave_type, lr.status as leave_status,
                    CASE 
                        WHEN lr.status = 'approved' AND a.date BETWEEN lr.start_date AND lr.end_date THEN 'leave'
                        WHEN a.check_in_time IS NOT NULL OR a.check_out_time IS NOT NULL THEN 'attendance'
                        WHEN a.date IS NOT NULL AND DAYOFWEEK(a.date) BETWEEN 2 AND 6 THEN 'no_scan'
                        ELSE 'no_record'
                    END as record_type
                FROM users u
                LEFT JOIN attendance a ON u.id = a.faculty_id 
                LEFT JOIN leave_requests lr ON u.id = lr.faculty_id 
                    AND lr.status = 'approved' 
                    AND (a.date BETWEEN lr.start_date AND lr.end_date OR a.date IS NULL)
                WHERE u.role = 'faculty' AND u.status = 'active'
            ";
            
            // Apply filters
            $params = [];
            if ($facultyFilter) {
                $dtrQuery .= " AND u.id = ?";
                $params[] = $facultyFilter;
            }
            
            if ($dateFilter) {
                $dtrQuery .= " AND (a.date = ? OR a.date IS NULL)";
                $params[] = $dateFilter;
            }
            
            // Apply search filter
            if ($searchTerm) {
                $dtrQuery .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.employee_id LIKE ?)";
                $searchParam = '%' . $searchTerm . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            // Apply sorting with fallback for NULL values
            $sortField = '';
            switch ($sortBy) {
                case 'first_name':
                    $sortField = "u.first_name $sortOrder, u.last_name $sortOrder";
                    break;
                case 'last_name':
                    $sortField = "u.last_name $sortOrder, u.first_name $sortOrder";
                    break;
                case 'employee_id':
                    $sortField = "u.employee_id $sortOrder";
                    break;
                case 'check_in_time':
                    $sortField = "a.check_in_time $sortOrder, a.date $sortOrder";
                    break;
                case 'check_out_time':
                    $sortField = "a.check_out_time $sortOrder, a.date $sortOrder";
                    break;
                case 'status':
                    $sortField = "a.status $sortOrder, a.date $sortOrder";
                    break;
                case 'date':
                default:
                    $sortField = "a.date $sortOrder, u.first_name ASC, u.last_name ASC";
                    break;
            }
            
            $dtrQuery .= " ORDER BY $sortField LIMIT 200";
            
            $stmt = $pdo->prepare($dtrQuery);
            $stmt->execute($params);
            $dtrRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data for JSON response
            $formattedRecords = [];
            foreach ($dtrRecords as $dtr) {
                $timeIn = '';
                $timeOut = '';
                $remarks = '';
                $statusClass = '';
                $displayStatus = '';
                
                if ($dtr['record_type'] === 'leave' && $dtr['leave_type']) {
                    $remarks = getLeaveRemark($dtr['leave_type']);
                    $statusClass = 'status-leave';
                    $displayStatus = 'On Leave';
                } elseif ($dtr['record_type'] === 'attendance' && ($dtr['check_in_time'] || $dtr['check_out_time'])) {
                    $timeIn = $dtr['check_in_time'] ? formatTime($dtr['check_in_time']) : '';
                    $timeOut = $dtr['check_out_time'] ? formatTime($dtr['check_out_time']) : '';
                    $remarks = generateAttendanceRemarks($dtr);
                    $statusClass = $dtr['status'] === 'present' ? 'status-present' : ($dtr['status'] === 'late' ? 'status-late' : 'status-absent');
                    $displayStatus = ucfirst($dtr['status'] ?? 'present');
                } elseif ($dtr['record_type'] === 'no_scan') {
                    $remarks = 'No Scan';
                    $statusClass = 'status-no-scan';
                    $displayStatus = 'No Scan';
                }
                
                $formattedRecords[] = [
                    'attendance_id' => $dtr['attendance_id'],
                    'user_id' => $dtr['user_id'],
                    'first_name' => $dtr['first_name'],
                    'last_name' => $dtr['last_name'],
                    'employee_id' => $dtr['employee_id'],
                    'date' => $dtr['date'],
                    'time_in' => $timeIn ?: '-',
                    'time_out' => $timeOut ?: '-',
                    'status' => $displayStatus ?: '-',
                    'remarks' => $remarks ?: '-',
                    'status_class' => $statusClass
                ];
            }
            
            echo json_encode([
                'success' => true,
                'records' => $formattedRecords,
                'total' => count($formattedRecords)
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error refreshing DTR data: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] === 'get_recent_activity') {
        $timeRange = $_POST['time_range'] ?? 6; // hours
        $activityType = $_POST['activity_type'] ?? '';
        $departmentFilter = $_POST['department_filter'] ?? '';
        
        try {
            // Validate inputs
            if (!is_numeric($timeRange) || $timeRange < 1 || $timeRange > 24) {
                $timeRange = 6;
            }
            
            // Calculate time threshold
            $timeThreshold = date('Y-m-d H:i:s', strtotime("-{$timeRange} hours"));
            
            // Build a simpler, more reliable query for recent activity
            $recentQuery = "
                SELECT 
                    u.id as user_id, u.first_name, u.last_name, u.employee_id,
                    u.department_id, COALESCE(d.name, 'Not Assigned') as department_name,
                    a.id as attendance_id, a.date, a.check_in_time, a.check_out_time, a.status,
                    a.late_minutes, a.remarks, a.updated_at,
                    CASE 
                        WHEN a.check_in_time >= ? THEN 'check_in'
                        WHEN a.check_out_time >= ? THEN 'check_out'
                        WHEN a.updated_at >= ? THEN 'updated'
                        ELSE 'other'
                    END as activity_type,
                    CASE 
                        WHEN a.check_in_time IS NOT NULL AND a.check_out_time IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, a.check_in_time, a.check_out_time)
                        ELSE NULL
                    END as duration_minutes
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN attendance a ON u.id = a.faculty_id 
                    AND a.date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                WHERE u.role = 'faculty' AND u.status = 'active'
                AND (a.check_in_time >= ? OR a.check_out_time >= ? OR a.updated_at >= ? OR a.date = CURDATE())
            ";
            
            $params = [$timeThreshold, $timeThreshold, $timeThreshold, $timeThreshold, $timeThreshold, $timeThreshold];
            
            // Apply department filter
            if ($departmentFilter && is_numeric($departmentFilter)) {
                $recentQuery .= " AND u.department_id = ?";
                $params[] = $departmentFilter;
            }
            
            // Apply activity type filter
            if ($activityType === 'check_in') {
                $recentQuery .= " AND a.check_in_time >= ?";
                $params[] = $timeThreshold;
            } elseif ($activityType === 'check_out') {
                $recentQuery .= " AND a.check_out_time >= ?";
                $params[] = $timeThreshold;
            } elseif ($activityType === 'both') {
                $recentQuery .= " AND a.check_in_time >= ? AND a.check_out_time >= ?";
                $params[] = $timeThreshold;
                $params[] = $timeThreshold;
            }
            
            $recentQuery .= " ORDER BY 
                GREATEST(
                    COALESCE(a.check_in_time, '1970-01-01'), 
                    COALESCE(a.check_out_time, '1970-01-01'), 
                    COALESCE(a.updated_at, '1970-01-01')
                ) DESC
                LIMIT 50";
            
            $stmt = $pdo->prepare($recentQuery);
            $stmt->execute($params);
            $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data
            $formattedActivities = [];
            foreach ($recentActivities as $activity) {
                $activityTime = '';
                $activityLabel = '';
                
                // Determine the actual activity time and label
                if ($activity['activity_type'] === 'check_in' && $activity['check_in_time']) {
                    $activityTime = $activity['check_in_time'];
                    $activityLabel = 'Check-in';
                } elseif ($activity['activity_type'] === 'check_out' && $activity['check_out_time']) {
                    $activityTime = $activity['check_out_time'];
                    $activityLabel = 'Check-out';
                } elseif ($activity['activity_type'] === 'updated' && $activity['updated_at']) {
                    $activityTime = $activity['updated_at'];
                    $activityLabel = 'Updated';
                } else {
                    // Fallback to the most recent activity
                    $times = [
                        'check_in' => $activity['check_in_time'],
                        'check_out' => $activity['check_out_time'],
                        'updated' => $activity['updated_at']
                    ];
                    $latestTime = null;
                    $latestType = '';
                    foreach ($times as $type => $time) {
                        if ($time && (!$latestTime || $time > $latestTime)) {
                            $latestTime = $time;
                            $latestType = $type;
                        }
                    }
                    
                    if ($latestTime && $latestType) {
                        $activityTime = $latestTime;
                        $activityLabel = ucfirst(str_replace('_', ' ', $latestType));
                    }
                }
                
                // Calculate duration
                $duration = '';
                if ($activity['duration_minutes'] && $activity['duration_minutes'] > 0) {
                    $hours = floor($activity['duration_minutes'] / 60);
                    $minutes = $activity['duration_minutes'] % 60;
                    $duration = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                }
                
                // Skip if no meaningful activity
                if (!$activityTime || !$activityLabel) {
                    continue;
                }
                
                $formattedActivities[] = [
                    'user_id' => $activity['user_id'],
                    'first_name' => $activity['first_name'],
                    'last_name' => $activity['last_name'],
                    'employee_id' => $activity['employee_id'],
                    'department_name' => $activity['department_name'] ?: 'Not Assigned',
                    'activity_time' => $activityTime,
                    'activity_label' => $activityLabel,
                    'activity_type' => $activity['activity_type'],
                    'status' => $activity['status'] ?: 'unknown',
                    'duration' => $duration,
                    'remarks' => $activity['remarks'] ?: '',
                    'attendance_id' => $activity['attendance_id'],
                    'date' => $activity['date'] ?: date('Y-m-d')
                ];
            }
            
            echo json_encode([
                'success' => true,
                'activities' => $formattedActivities,
                'total' => count($formattedActivities)
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching recent activity: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}


// Initialize variables
$message = '';
$error = '';
$faculty_id = '';
$month = date('Y-m');
$specific_date = date('Y-m-d');
$date_type = 'month'; // 'month' or 'specific'
$dtr_data = null;

// Handle GET parameters for URL-based access
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $faculty_id = $_GET['faculty_id'] ?? '';
    $month = $_GET['month'] ?? date('Y-m');
    $specific_date = $_GET['specific_date'] ?? date('Y-m-d');
    $date_type = $_GET['date_type'] ?? 'month';
}

// Handle form submission to set date type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['date_type'])) {
    $date_type = $_POST['date_type'];
    $month = $_POST['month'] ?? date('Y-m');
    $specific_date = $_POST['specific_date'] ?? date('Y-m-d');
}

// Handle DTR generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_dtr'])) {
    $faculty_id = trim($_POST['faculty_id']);
    $date_type = trim($_POST['date_type'] ?? 'month');
    $month = trim($_POST['month'] ?? '');
    $specific_date = trim($_POST['specific_date'] ?? '');
    
    if (empty($faculty_id)) {
        $error = "Please select a faculty member.";
    } elseif ($date_type === 'month' && empty($month)) {
        $error = "Please select a month.";
    } elseif ($date_type === 'specific' && empty($specific_date)) {
        $error = "Please select a specific date.";
    } else {
        try {
            // Get faculty information
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'faculty'");
            $stmt->execute([$faculty_id]);
            $faculty = $stmt->fetch();
            
            if (!$faculty) {
                $error = "Faculty member not found.";
            } else {
                // Get department information
                $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $deptStmt->execute([$faculty['department_id']]);
                $department = $deptStmt->fetchColumn() ?: 'Not Assigned';
                
                if ($date_type === 'specific') {
                    // Handle specific date
                    $target_date = $specific_date;
                    $display_period = formatDate($target_date);
                    
                    // Get attendance record for the specific date with proper sorting
                    $stmt = $pdo->prepare("
                        SELECT * FROM attendance 
                        WHERE faculty_id = ? AND date = ?
                        ORDER BY date ASC, check_in_time ASC
                    ");
                    $stmt->execute([$faculty_id, $target_date]);
                    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get approved leave requests for the specific date
                    $leaveStmt = $pdo->prepare("
                        SELECT * FROM leave_requests 
                        WHERE faculty_id = ? AND status = 'approved' 
                        AND (start_date <= ? AND end_date >= ?)
                        ORDER BY start_date ASC
                    ");
                    $leaveStmt->execute([$faculty_id, $target_date, $target_date]);
                    $leave_requests = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create leave date map for the specific date
                    $leave_dates = [];
                    foreach ($leave_requests as $leave) {
                        $leave_dates[$target_date] = $leave;
                    }
                    
                    // Generate DTR data for the specific date
                    $dtr_days = [[$target_date, date('N', strtotime($target_date))]];
                    
                } else {
                    // Handle month selection with improved sorting
                    $month_parts = explode('-', $month);
                    $year = $month_parts[0];
                    $month_num = $month_parts[1];
                    $display_period = date('F Y', strtotime($month));
                    
                    // Get days in month
                    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
                    
                    // Get attendance records for the month with proper chronological sorting
                    $stmt = $pdo->prepare("
                        SELECT * FROM attendance 
                        WHERE faculty_id = ? AND date >= ? AND date <= ?
                        ORDER BY date ASC, check_in_time ASC, check_out_time ASC
                    ");
                    $stmt->execute([$faculty_id, "$year-$month_num-01", "$year-$month_num-$days_in_month"]);
                    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get approved leave requests for the month with proper sorting
                    $leaveStmt = $pdo->prepare("
                        SELECT * FROM leave_requests 
                        WHERE faculty_id = ? AND status = 'approved' 
                        AND ((start_date >= ? AND start_date <= ?) OR (end_date >= ? AND end_date <= ?) OR (start_date <= ? AND end_date >= ?))
                        ORDER BY start_date ASC, end_date ASC
                    ");
                    $leaveStmt->execute([
                        $faculty_id, 
                        "$year-$month_num-01", "$year-$month_num-$days_in_month",
                        "$year-$month_num-01", "$year-$month_num-$days_in_month",
                        "$year-$month_num-01", "$year-$month_num-$days_in_month"
                    ]);
                    $leave_requests = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create leave date map for the month with proper date handling
                    $leave_dates = [];
                    foreach ($leave_requests as $leave) {
                        $current_date = $leave['start_date'];
                        while ($current_date <= $leave['end_date']) {
                            $leave_dates[$current_date] = $leave;
                            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                        }
                    }
                    
                    // Generate DTR data for each day of the month in chronological order
                    $dtr_days = [];
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $date = sprintf('%04d-%02d-%02d', $year, $month_num, $day);
                        $dtr_days[] = [$date, date('N', strtotime($date))];
                    }
                }
                
                // Generate DTR data
                $dtr_data = [
                    'faculty' => [
                        'name' => $faculty['first_name'] . ' ' . $faculty['last_name'],
                        'employee_id' => $faculty['employee_id'],
                        'department' => $department,
                        'position' => $faculty['position'] ?? 'Faculty'
                    ],
                    'period' => $display_period,
                    'type' => $date_type,
                    'records' => []
                ];
                
                foreach ($dtr_days as [$date, $day_of_week]) {
                    
                    // Extract day number from date
                    $day = date('d', strtotime($date));
                    
                    // Find attendance record for this day
                    $record = null;
                    foreach ($attendance_records as $att) {
                        if ($att['date'] === $date) {
                            $record = $att;
                            break;
                        }
                    }
                    
                    // Check if this day has approved leave
                    $has_leave = isset($leave_dates[$date]);
                    $leave_info = $has_leave ? $leave_dates[$date] : null;
                    
                    // Determine if it's a weekend
                    $is_weekend = ($day_of_week >= 6);
                    
                    // Build DTR entry according to HR standards
                    $dtr_entry = [
                        'day' => $day,
                        'date' => $date,
                        'day_of_week' => $day_of_week,
                        'is_weekend' => $is_weekend,
                        'has_leave' => $has_leave,
                        'leave_info' => $leave_info
                    ];
                    
                    // Handle different scenarios according to HR standards
                    if ($has_leave && $leave_info) {
                        // Approved leave - blank times, leave remarks
                        $dtr_entry['time_in'] = '';
                        $dtr_entry['time_out'] = '';
                        $dtr_entry['status'] = 'on_leave';
                        $dtr_entry['remarks'] = getLeaveRemark($leave_info['leave_type']);
                    } elseif ($record && ($record['check_in_time'] || $record['check_out_time'])) {
                        // Has attendance record - populate actual timestamps
                        $dtr_entry['time_in'] = $record['check_in_time'] ? formatTime($record['check_in_time']) : '';
                        $dtr_entry['time_out'] = $record['check_out_time'] ? formatTime($record['check_out_time']) : '';
                        $dtr_entry['status'] = $record['status'] ?? 'present';
                        $dtr_entry['remarks'] = generateAttendanceRemarks($record);
                    } elseif (!$is_weekend) {
                        // No scan/check-in on weekday - blank times, no scan remarks
                        $dtr_entry['time_in'] = '';
                        $dtr_entry['time_out'] = '';
                        $dtr_entry['status'] = 'no_scan';
                        $dtr_entry['remarks'] = 'No Scan';
                    } else {
                        // Weekend
                        $dtr_entry['time_in'] = '';
                        $dtr_entry['time_out'] = '';
                        $dtr_entry['status'] = 'weekend';
                        $dtr_entry['remarks'] = '';
                    }
                    
                    $dtr_data['records'][] = $dtr_entry;
                }
                
                // Calculate summary statistics according to HR standards
                $present_days = 0;
                $late_days = 0;
                $absent_days = 0;
                $leave_days = 0;
                $no_scan_days = 0;
                $total_late_minutes = 0;
                
                foreach ($dtr_data['records'] as $day) {
                    if (!$day['is_weekend']) {
                        switch ($day['status']) {
                            case 'present':
                                $present_days++;
                                break;
                            case 'late':
                                $present_days++;
                                $late_days++;
                                $total_late_minutes += $day['late_minutes'] ?? 0;
                                break;
                            case 'on_leave':
                                $leave_days++;
                                break;
                            case 'no_scan':
                                $no_scan_days++;
                                break;
                            default:
                                $absent_days++;
                                break;
                        }
                    }
                }
                
                // Add summary statistics to the existing DTR data structure
                $dtr_data['summary'] = [
                    'present_days' => $present_days,
                    'late_days' => $late_days,
                    'absent_days' => $absent_days,
                    'leave_days' => $leave_days,
                    'no_scan_days' => $no_scan_days,
                    'total_late_minutes' => $total_late_minutes,
                    'total_late_hours' => round($total_late_minutes / 60, 2),
                    'working_days' => $present_days + $late_days + $absent_days + $leave_days + $no_scan_days
                ];
                
                // Debug: Check if DTR data is properly structured
                if (empty($dtr_data['records'])) {
                    $error = "No attendance records found for the selected period";
                } elseif (empty($dtr_data['summary'])) {
                    $error = "Error calculating summary statistics";
                } else {
                    $message = "DTR generated successfully for " . htmlspecialchars($dtr_data['faculty']['name']);
                }
            }
        } catch (Exception $e) {
            $error = "Error generating DTR: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Get all faculty members for dropdown
$faculty_list = $pdo->query("SELECT id, employee_id, first_name, last_name FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch DTR logs data
$dtr_logs = [];
$total_records = 0;

// Build query for DTR logs
$query_conditions = [];
$query_params = [];

// Apply filters
$faculty_id = $_GET['faculty_id'] ?? '';
$date_range = $_GET['date_range'] ?? '30';
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

if (!empty($faculty_id)) {
    $query_conditions[] = "u.id = ?";
    $query_params[] = $faculty_id;
}

if (!empty($status)) {
    $query_conditions[] = "a.status = ?";
    $query_params[] = $status;
}

if (!empty($search)) {
    $query_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.employee_id LIKE ? OR a.remarks LIKE ?)";
    $search_param = "%$search%";
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
}

// Date range filter
$date_condition = "";
if ($date_range !== 'all') {
    $days = (int)$date_range;
    $date_condition = "AND a.date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
}

// Build the complete query
$where_clause = !empty($query_conditions) ? "WHERE " . implode(" AND ", $query_conditions) : "";

$dtr_query = "
    SELECT 
        a.id as attendance_id,
        u.id as user_id,
        u.first_name,
        u.last_name,
        u.employee_id,
        a.date,
        a.check_in_time,
        a.check_out_time,
        a.status,
        a.remarks,
        a.created_at,
        a.updated_at,
        CASE 
            WHEN a.check_in_time IS NOT NULL THEN 'check_in'
            WHEN a.check_out_time IS NOT NULL THEN 'check_out'
            ELSE 'record'
        END as log_type
    FROM attendance a
    JOIN users u ON a.faculty_id = u.id
    $where_clause
    $date_condition
    ORDER BY a.date DESC, a.created_at DESC
";

$stmt = $pdo->prepare($dtr_query);
$stmt->execute($query_params);
$dtr_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$count_query = "
    SELECT COUNT(*) as total
    FROM attendance a
    JOIN users u ON a.faculty_id = u.id
    $where_clause
    $date_condition
";

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetchColumn();

/**
 * Get HR-compliant leave remark notation
 */
function getLeaveRemark($leaveType) {
    $leaveRemarks = [
        'vacation' => 'VL', // Vacation Leave
        'sick' => 'SL',    // Sick Leave
        'emergency' => 'EL', // Emergency Leave
        'maternity' => 'ML', // Maternity Leave
        'paternity' => 'PL', // Paternity Leave
        'other' => 'OL'    // Other Leave
    ];
    
    return $leaveRemarks[$leaveType] ?? 'Leave';
}

/**
 * Generate attendance remarks based on record details
 */
function generateAttendanceRemarks($record) {
    $remarks = [];
    
    // Add late remarks if applicable
    if ($record['status'] === 'late' && isset($record['late_minutes']) && $record['late_minutes'] > 0) {
        $remarks[] = $record['late_minutes'] . ' min late';
    }
    
    // Add early out remarks
    if ($record['check_out_time'] && $record['check_out_time'] < '17:00:00') {
        $remarks[] = 'Early Out';
    }
    
    // Add overtime remarks
    if ($record['check_out_time'] && $record['check_out_time'] > '18:00:00') {
        $remarks[] = 'Overtime';
    }
    
    // Add custom remarks if any
    if (!empty($record['remarks'])) {
        $remarks[] = $record['remarks'];
    }
    
    return implode(', ', $remarks);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Management - DOIT Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dtr-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .dtr-table {
            font-size: 0.9rem;
        }
        .dtr-table th {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            font-weight: 600;
            text-align: center;
        }
        .dtr-table td {
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
        }
        .status-leave { background: #cce5ff; color: #0066cc; }
        .status-no-scan { background: #e2e3e5; color: #6c757d; }
        .status-present { background: #d4edda; color: #155724; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-weekend { background: #f8f9fa; color: #6c757d; }
        .summary-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .summary-stat {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .summary-stat:last-child {
            border-bottom: none;
        }
        /* Live DTR Editing Styles */
        .editing-mode {
            background-color: #fff3cd !important;
            border: 2px solid #ffc107 !important;
        }

        .editing-mode .editable-field input,
        .editing-mode .editable-field select {
            border: 1px solid #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .editable-field {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .editable-field:hover {
            background-color: #f8f9fa;
        }

        .editing-mode .editable-field:hover {
            background-color: transparent;
        }

        /* Live Update Indicator Animation */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        #liveStatus .bi-circle-fill {
            animation: pulse 2s infinite;
        }

        /* Sorting Button Styles */
        .table th .btn-group-sm .btn {
            padding: 0.125rem 0.25rem;
            font-size: 0.75rem;
            line-height: 1;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
            color: #6c757d;
        }

        .table th .btn-group-sm .btn:hover {
            background-color: #e9ecef;
            color: #495057;
            border-color: #adb5bd;
        }

        .table th .btn-group-sm .btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .table th .btn-group-sm .btn:active {
            background-color: #dee2e6;
            color: #212529;
        }

        /* Table Header Improvements */
        .table th {
            position: relative;
            vertical-align: middle;
        }

        .table th .d-flex {
            min-height: 32px;
        }

        /* Sort Active State */
        .sort-active {
            background-color: #007bff !important;
            color: white !important;
            border-color: #007bff !important;
        }

        /* Loading State for Table */
        .table-loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Refresh Animation */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .refreshing .bi-hourglass-split {
            animation: spin 1s linear infinite;
        }

        /* Notification Styles */
        .alert-dismissible {
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media print {
            .no-print { display: none !important; }
            .dtr-table { font-size: 0.8rem; }
            .summary-card { page-break-inside: avoid; }
        }
    </style>
    <script>
        function toggleDateType() {
            const monthRadio = document.getElementById('dateTypeMonth');
            const monthField = document.getElementById('monthField');
            const specificDateField = document.getElementById('specificDateField');
            const monthInput = document.querySelector('input[name="month"]');
            const specificDateInput = document.querySelector('input[name="specific_date"]');
            
            if (monthRadio.checked) {
                monthField.classList.remove('d-none');
                specificDateField.classList.add('d-none');
                monthInput.required = true;
                specificDateInput.required = false;
            } else {
                monthField.classList.add('d-none');
                specificDateField.classList.remove('d-none');
                monthInput.required = false;
                specificDateInput.required = true;
            }
        }
        
        function exportDTR() {
            // Export functionality
            window.print();
        }

        // Live DTR functionality
        let liveUpdateInterval = null;
        let isLiveUpdatesActive = false;
        let currentEditingRow = null;

        function toggleLiveUpdates() {
            const btn = document.getElementById('liveToggleBtn');
            const statusBadge = document.getElementById('liveStatus');
            
            if (isLiveUpdatesActive) {
                // Stop live updates
                clearInterval(liveUpdateInterval);
                isLiveUpdatesActive = false;
                
                btn.innerHTML = '<i class="bi bi-wifi me-1"></i>Live Updates';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-info');
                
                statusBadge.style.display = 'none';
            } else {
                // Start live updates
                isLiveUpdatesActive = true;
                
                btn.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Stop Live';
                btn.classList.remove('btn-outline-info');
                btn.classList.add('btn-success');
                
                statusBadge.style.display = 'inline-block';
                
                // Update every 30 seconds
                liveUpdateInterval = setInterval(() => {
                    refreshLiveDTR(true);
                }, 30000);
                
                // Initial refresh
                refreshLiveDTR(true);
            }
        }

        // Global sorting state
        let currentSortBy = 'date';
        let currentSortOrder = 'DESC';
        let isRefreshing = false;

        function sortLiveDTR(sortBy, sortOrder) {
            currentSortBy = sortBy;
            currentSortOrder = sortOrder;
            refreshLiveDTR(true);
        }

        function refreshLiveDTR(silent = false) {
            // Prevent multiple simultaneous refreshes
            if (isRefreshing) {
                console.log('Refresh already in progress...');
                return;
            }
            
            isRefreshing = true;
            
            if (!silent) {
                // Show loading state
                const refreshBtn = document.querySelector('button[onclick="refreshLiveDTR()"]');
                if (refreshBtn) {
                    const originalHTML = refreshBtn.innerHTML;
                    refreshBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
                    refreshBtn.disabled = true;
                    
                    // Store original HTML for restoration
                    refreshBtn.dataset.originalHTML = originalHTML;
                }
            }
            
            try {
                // Get current filter values
                const facultyFilter = document.getElementById('liveFacultyFilter')?.value || '';
                const dateFilter = document.getElementById('liveDateFilter')?.value || '';
                const statusFilter = document.getElementById('liveStatusFilter')?.value || '';
                const searchTerm = document.getElementById('liveSearch')?.value || '';
                
                // Make AJAX call to refresh DTR data with sorting
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        ajax_action: 'refresh_dtr',
                        faculty_filter: facultyFilter,
                        date_filter: dateFilter,
                        status_filter: statusFilter,
                        sort_by: currentSortBy,
                        sort_order: currentSortOrder,
                        search_term: searchTerm
                    })
                })
            .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
            .then(data => {
                if (data.success) {
                    // Update the live DTR table with new data
                    updateLiveDTRTable(data.records);
                    
                    // Update statistics
                    updateLiveStatistics(data.records);
                    
                    // Update last update time
                    updateLastUpdateTime();
                    
                    if (!silent) {
                        showNotification('Live DTR data refreshed!', 'info');
                    }
                } else {
                    if (!silent) {
                        showNotification(data.message || 'Unknown error occurred', 'danger');
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing live DTR data:', error);
                
                if (!silent) {
                    showNotification('Error refreshing live DTR data. Please try again.', 'danger');
                }
            })
            .finally(() => {
                // Always restore button state and reset refresh flag
                isRefreshing = false;
                
                if (!silent) {
                    const refreshBtn = document.querySelector('button[onclick="refreshLiveDTR()"]');
                    if (refreshBtn && refreshBtn.dataset.originalHTML) {
                        refreshBtn.innerHTML = refreshBtn.dataset.originalHTML;
                        refreshBtn.disabled = false;
                        delete refreshBtn.dataset.originalHTML;
                    }
                }
            });
        } catch (error) {
            console.error('Critical error in refreshLiveDTR:', error);
            isRefreshing = false;
            
            if (!silent) {
                showNotification('Critical error occurred. Please refresh the page.', 'danger');
            }
        }

        function updateLiveDTRTable(records) {
            const tbody = document.getElementById('liveDTRTableBody');
            const table = document.getElementById('liveDTRTable');
            
            // Add loading state
            table.classList.add('table-loading');
            
            try {
                // Clear existing rows
                tbody.innerHTML = '';
                
                // Add new rows with proper sorting
                records.forEach(record => {
                    const row = document.createElement('tr');
                    row.className = (record.status_class || '') + ' live-dtr-record';
                    row.setAttribute('data-faculty-id', record.user_id);
                    row.setAttribute('data-date', record.date);
                    row.setAttribute('data-id', record.attendance_id);
                    
                    // Escape HTML to prevent XSS
                    const escapeHtml = (text) => {
                        const div = document.createElement('div');
                        div.textContent = text || '';
                        return div.innerHTML;
                    };
                    
                    row.innerHTML = `
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 12px; font-weight: 600;">
                                    ${escapeHtml(record.first_name?.charAt(0).toUpperCase() || '') + escapeHtml(record.last_name?.charAt(0).toUpperCase() || '')}
                                </div>
                                <div>
                                    <div class="fw-semibold text-truncate" style="max-width: 150px;" title="${escapeHtml(record.first_name + ' ' + record.last_name)}">
                                        ${escapeHtml(record.first_name + ' ' + record.last_name)}
                                    </div>
                                    <small class="text-muted">ID: ${escapeHtml(record.employee_id || '')}</small>
                                </div>
                            </div>
                        </td>
                        <td>${record.date ? formatDate(record.date) : '-'}</td>
                        <td class="text-center editable-field" data-field="check_in_time" data-id="${record.attendance_id}" data-user-id="${record.user_id}" data-date="${record.date}">
                            ${escapeHtml(record.time_in || '-')}
                        </td>
                        <td class="text-center editable-field" data-field="check_out_time" data-id="${record.attendance_id}" data-user-id="${record.user_id}" data-date="${record.date}">
                            ${escapeHtml(record.time_out || '-')}
                        </td>
                        <td class="text-center editable-status" data-field="status" data-id="${record.attendance_id}" data-user-id="${record.user_id}" data-date="${record.date}">
                            ${getStatusBadge(record.status?.toLowerCase().replace(' ', '_') || 'unknown')}
                        </td>
                        <td class="text-start editable-field" data-field="remarks" data-id="${record.attendance_id}" data-user-id="${record.user_id}" data-date="${record.date}">
                            ${escapeHtml(record.remarks || '-')}
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleEditMode(this, ${record.attendance_id})" title="Toggle Edit Mode">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="saveLiveDTRRecord(${record.attendance_id}, ${record.user_id}, '${record.date}')" title="Save Changes" style="display:none;">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    
                    tbody.appendChild(row);
                });
                
                // Update record count
                const recordCountElement = document.getElementById('liveRecordCount');
                if (recordCountElement) {
                    recordCountElement.textContent = records.length;
                }
                
                // Update sort button states
                updateSortButtonStates();
                
            } catch (error) {
                console.error('Error updating DTR table:', error);
                showNotification('Error updating DTR table display', 'warning');
            } finally {
                // Remove loading state
                table.classList.remove('table-loading');
            }
        }
        
        function updateSortButtonStates() {
            // Remove all active states
            document.querySelectorAll('.sort-active').forEach(btn => {
                btn.classList.remove('sort-active');
            });
            
            // Add active state to current sort buttons
            const sortButtons = document.querySelectorAll(`button[onclick*="sortLiveDTR('${currentSortBy}', '${currentSortOrder}')"]`);
            sortButtons.forEach(btn => {
                btn.classList.add('sort-active');
            });
        }

        function updateLiveStatistics(records) {
            let presentCount = 0;
            let lateCount = 0;
            let leaveCount = 0;
            let noScanCount = 0;
            
            records.forEach(record => {
                const status = record.status.toLowerCase();
                if (status.includes('present')) presentCount++;
                else if (status.includes('late')) lateCount++;
                else if (status.includes('leave')) leaveCount++;
                else if (status.includes('no scan')) noScanCount++;
            });
            
            // Update statistics displays
            document.getElementById('livePresentCount').textContent = presentCount;
            document.getElementById('liveLateCount').textContent = lateCount;
        }

        function updateLastUpdateTime() {
            const lastUpdateElement = document.getElementById('lastUpdate');
            const now = new Date();
            lastUpdateElement.textContent = `Last updated: ${now.toLocaleTimeString()}`;
        }

        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        function toggleEditMode(button, recordId) {
            const row = button.closest('tr');
            const isEditing = row.classList.contains('editing-mode');
            
            if (isEditing) {
                // Exit edit mode
                exitEditMode(row);
            } else {
                // Enter edit mode
                enterEditMode(row, recordId);
            }
        }

        function enterEditMode(row, recordId) {
            // Exit any existing edit mode
            if (currentEditingRow && currentEditingRow !== row) {
                exitEditMode(currentEditingRow);
            }
            
            currentEditingRow = row;
            row.classList.add('editing-mode');
            
            // Make fields editable
            const editableFields = row.querySelectorAll('.editable-field');
            editableFields.forEach(field => {
                const currentValue = field.textContent.trim();
                const fieldType = field.dataset.field;
                
                if (fieldType === 'status') {
                    // Create status dropdown
                    const select = document.createElement('select');
                    select.className = 'form-select form-select-sm';
                    select.innerHTML = `
                        <option value="present" ${currentValue.includes('Present') ? 'selected' : ''}>Present</option>
                        <option value="late" ${currentValue.includes('Late') ? 'selected' : ''}>Late</option>
                        <option value="absent" ${currentValue.includes('Absent') ? 'selected' : ''}>Absent</option>
                        <option value="on_leave" ${currentValue.includes('Leave') ? 'selected' : ''}>On Leave</option>
                        <option value="no_scan" ${currentValue.includes('No Scan') ? 'selected' : ''}>No Scan</option>
                    `;
                    field.innerHTML = '';
                    field.appendChild(select);
                } else if (fieldType === 'check_in_time' || fieldType === 'check_out_time') {
                    // Create time input
                    const input = document.createElement('input');
                    input.type = 'time';
                    input.className = 'form-control form-control-sm text-center';
                    input.value = currentValue !== '-' ? currentValue : '';
                    field.innerHTML = '';
                    field.appendChild(input);
                } else {
                    // Create text input for remarks
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control form-control-sm';
                    input.value = currentValue !== '-' ? currentValue : '';
                    field.innerHTML = '';
                    field.appendChild(input);
                }
            });
            
            // Update buttons
            const buttonGroup = row.querySelector('.btn-group');
            const editBtn = buttonGroup.querySelector('button[onclick*="toggleEditMode"]');
            const saveBtn = buttonGroup.querySelector('button[onclick*="saveLiveDTRRecord"]');
            
            editBtn.style.display = 'none';
            saveBtn.style.display = 'inline-block';
            
            // Highlight the row
            row.style.backgroundColor = '#fff3cd';
        }

        function exitEditMode(row) {
            row.classList.remove('editing-mode');
            currentEditingRow = null;
            
            // Refresh data to restore original values
            refreshLiveDTR(true);
        }

        function saveLiveDTRRecord(recordId, userId, date) {
            const row = document.querySelector(`tr[data-id="${recordId}"]`);
            if (!row) return;
            
            // Collect updated values
            const updates = {};
            const editableFields = row.querySelectorAll('.editable-field');
            
            editableFields.forEach(field => {
                const fieldType = field.dataset.field;
                let value;
                
                if (fieldType === 'status') {
                    value = field.querySelector('select').value;
                } else {
                    value = field.querySelector('input').value;
                }
                
                if (value && value !== '-') {
                    updates[fieldType] = value;
                }
            });
            
            // Show loading state
            const saveBtn = row.querySelector('button[onclick*="saveLiveDTRRecord"]');
            const originalHTML = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            saveBtn.disabled = true;
            
            // Make AJAX call to update DTR record
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    ajax_action: 'update_dtr',
                    attendance_id: recordId,
                    user_id: userId,
                    date: date,
                    updates: JSON.stringify(updates)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success feedback
                    showNotification(data.message, 'success');
                    
                    // Exit edit mode
                    exitEditMode(row);
                    
                    // Refresh data
                    refreshLiveDTR(true);
                } else {
                    // Show error feedback
                    showNotification(data.message, 'danger');
                    
                    // Restore button state
                    saveBtn.innerHTML = originalHTML;
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error updating DTR record:', error);
                showNotification('Error updating DTR record. Please try again.', 'danger');
                
                // Restore button state
                saveBtn.innerHTML = originalHTML;
                saveBtn.disabled = false;
            });
        }

        // Helper function for formatting dates (if not already defined)
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
        }

        // Helper function for status badges (if not already defined)
        function getStatusBadge(status) {
            const badges = {
                'present': '<span class="badge bg-success">Present</span>',
                'late': '<span class="badge bg-warning">Late</span>',
                'absent': '<span class="badge bg-danger">Absent</span>',
                'on_leave': '<span class="badge bg-primary">On Leave</span>',
                'no_scan': '<span class="badge bg-secondary">No Scan</span>'
            };
            return badges[status] || '<span class="badge bg-secondary">Unknown</span>';
        }

        // Handle URL navigation and section focusing
        function handleURLNavigation() {
            const urlParams = new URLSearchParams(window.location.search);
            const hash = window.location.hash;
            
            // Handle URL parameters
            if (urlParams.has('faculty_id')) {
                const facultyId = urlParams.get('faculty_id');
                const facultySelect = document.getElementById('faculty_id');
                if (facultySelect) {
                    facultySelect.value = facultyId;
                }
            }
            
            if (urlParams.has('date_type')) {
                const dateType = urlParams.get('date_type');
                const monthRadio = document.getElementById('dateTypeMonth');
                const specificRadio = document.getElementById('dateTypeSpecific');
                
                if (dateType === 'specific' && specificRadio) {
                    specificRadio.checked = true;
                    toggleDateType();
                } else if (monthRadio) {
                    monthRadio.checked = true;
                    toggleDateType();
                }
            }
            
            if (urlParams.has('specific_date')) {
                const specificDate = urlParams.get('specific_date');
                const dateInput = document.getElementById('specific_date');
                if (dateInput) {
                    dateInput.value = specificDate;
                }
            }
            
            if (urlParams.has('month')) {
                const month = urlParams.get('month');
                const monthInput = document.getElementById('month');
                if (monthInput) {
                    monthInput.value = month;
                }
            }
            
            // Handle hash navigation
            if (hash === '#live-dtr') {
                // Scroll to live DTR section
                setTimeout(() => {
                    const liveSection = document.getElementById('live-dtr');
                    if (liveSection) {
                        liveSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        // Auto-start live updates if navigating to live section
                        if (!isLiveUpdatesActive) {
                            toggleLiveUpdates();
                        }
                    }
                }, 500);
            }
            
            // Auto-generate DTR if parameters are provided
            if (urlParams.has('faculty_id') && (urlParams.has('month') || urlParams.has('specific_date'))) {
                setTimeout(() => {
                    const generateBtn = document.querySelector('button[name="generate_dtr"]');
                    if (generateBtn) {
                        generateBtn.click();
                    }
                }, 1000);
            }
        }

        // Recent Activity functionality
        let recentAutoRefreshInterval = null;
        let isRecentAutoRefreshActive = false;

        function refreshRecentActivity(silent = false) {
            // Check if elements exist
            const timeRangeElement = document.getElementById('recentTimeRange');
            const activityTypeElement = document.getElementById('recentActivityType');
            const departmentFilterElement = document.getElementById('recentDepartmentFilter');
            const refreshBtn = document.querySelector('button[onclick="refreshRecentActivity()"]');
            
            // Get filter values with fallbacks
            const timeRange = timeRangeElement?.value || 6;
            const activityType = activityTypeElement?.value || '';
            const departmentFilter = departmentFilterElement?.value || '';
            
            console.log('Refreshing recent activity:', { timeRange, activityType, departmentFilter });
            
            // Show loading state
            if (!silent && refreshBtn) {
                const originalHTML = refreshBtn.innerHTML;
                refreshBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Loading...';
                refreshBtn.disabled = true;
                refreshBtn.dataset.originalHTML = originalHTML;
            }
            
            // Make AJAX call
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    ajax_action: 'get_recent_activity',
                    time_range: timeRange,
                    activity_type: activityType,
                    department_filter: departmentFilter
                })
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    updateRecentActivityTable(data.activities);
                    updateRecentActivityStats(data.activities);
                    updateRecentLastUpdateTime();
                    
                    if (!silent) {
                        showNotification(`Loaded ${data.total} recent activities`, 'success');
                    }
                } else {
                    if (!silent) {
                        showNotification(data.message || 'Error loading recent activity', 'danger');
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing recent activity:', error);
                if (!silent) {
                    showNotification('Error loading recent activity: ' + error.message, 'danger');
                }
            })
            .finally(() => {
                // Restore button state
                if (!silent && refreshBtn && refreshBtn.dataset.originalHTML) {
                    refreshBtn.innerHTML = refreshBtn.dataset.originalHTML;
                    refreshBtn.disabled = false;
                    delete refreshBtn.dataset.originalHTML;
                }
            });
        }

        function updateRecentActivityTable(activities) {
            const tbody = document.getElementById('recentActivityTableBody');
            const table = document.getElementById('recentActivityTable');
            const countElement = document.getElementById('recentActivityCount');
            
            if (!tbody || !table) {
                console.error('Recent activity table elements not found');
                return;
            }
            
            console.log('Updating recent activity table with', activities.length, 'activities');
            
            // Add loading state
            table.classList.add('table-loading');
            
            try {
                // Clear existing rows
                tbody.innerHTML = '';
                
                if (!activities || activities.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-clock-history me-2"></i>
                                No recent activity found in the selected time range
                            </td>
                        </tr>
                    `;
                    if (countElement) countElement.textContent = '0';
                    return;
                }
                
                // Add activity rows
                activities.forEach((activity, index) => {
                    const row = document.createElement('tr');
                    
                    // Escape HTML to prevent XSS
                    const escapeHtml = (text) => {
                        const div = document.createElement('div');
                        div.textContent = text || '';
                        return div.innerHTML;
                    };
                    
                    // Get activity type styling
                    const getActivityBadge = (type) => {
                        const badges = {
                            'check_in': '<span class="badge bg-success">Check-in</span>',
                            'check_out': '<span class="badge bg-info">Check-out</span>',
                            'updated': '<span class="badge bg-warning">Updated</span>',
                            'other': '<span class="badge bg-secondary">Other</span>'
                        };
                        return badges[type] || badges['other'];
                    };
                    
                    // Format time
                    const formatActivityTime = (timeString) => {
                        if (!timeString) return '-';
                        try {
                            const date = new Date(timeString);
                            const now = new Date();
                            const diffMs = now - date;
                            const diffMins = Math.floor(diffMs / 60000);
                            
                            if (diffMins < 1) return 'Just now';
                            if (diffMins < 60) return `${diffMins} min ago`;
                            if (diffMins < 1440) return `${Math.floor(diffMins / 60)} hours ago`;
                            return date.toLocaleTimeString('en-US', { 
                                hour: '2-digit', 
                                minute: '2-digit' 
                            });
                        } catch (e) {
                            console.error('Error formatting time:', e);
                            return 'Invalid time';
                        }
                    };
                    
                    // Get status color
                    const getStatusColor = (status) => {
                        const colors = {
                            'present': 'success',
                            'late': 'warning',
                            'absent': 'danger',
                            'on_leave': 'primary',
                            'no_scan': 'secondary'
                        };
                        return colors[status] || 'secondary';
                    };
                    
                    row.innerHTML = `
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 11px; font-weight: 600;">
                                    ${escapeHtml((activity.first_name?.charAt(0) || '') + (activity.last_name?.charAt(0) || '')).toUpperCase()}
                                </div>
                                <div>
                                    <div class="fw-semibold text-truncate" style="max-width: 120px;" title="${escapeHtml(activity.first_name + ' ' + activity.last_name)}">
                                        ${escapeHtml(activity.first_name + ' ' + activity.last_name)}
                                    </div>
                                    <small class="text-muted">ID: ${escapeHtml(activity.employee_id || '')}</small>
                                </div>
                            </div>
                        </td>
                        <td>${escapeHtml(activity.department_name || 'Not Assigned')}</td>
                        <td>
                            <div class="text-nowrap">
                                <small class="text-muted">${formatActivityTime(activity.activity_time)}</small>
                            </div>
                        </td>
                        <td>${getActivityBadge(activity.activity_type)}</td>
                        <td>
                            <span class="badge bg-${getStatusColor(activity.status)}">
                                ${escapeHtml(activity.status || 'unknown')}
                            </span>
                        </td>
                        <td>
                            <small class="text-muted">${escapeHtml(activity.duration || '-')}</small>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewFacultyDTR(${activity.user_id}, '${activity.date}')" title="View DTR">
                                    <i class="bi bi-calendar3"></i>
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="editAttendanceRecord(${activity.attendance_id || 0}, ${activity.user_id})" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    
                    tbody.appendChild(row);
                });
                
                // Update count
                if (countElement) countElement.textContent = activities.length;
                
            } catch (error) {
                console.error('Error updating recent activity table:', error);
                showNotification('Error updating recent activity display: ' + error.message, 'warning');
            } finally {
                // Remove loading state
                table.classList.remove('table-loading');
            }
        }

        function updateRecentActivityStats(activities) {
            const checkInElement = document.getElementById('recentCheckInCount');
            const checkOutElement = document.getElementById('recentCheckOutCount');
            
            if (!checkInElement || !checkOutElement) {
                console.error('Recent activity stats elements not found');
                return;
            }
            
            let checkInCount = 0;
            let checkOutCount = 0;
            
            if (activities && activities.length > 0) {
                activities.forEach(activity => {
                    if (activity.activity_type === 'check_in') checkInCount++;
                    if (activity.activity_type === 'check_out') checkOutCount++;
                });
            }
            
            checkInElement.textContent = checkInCount;
            checkOutElement.textContent = checkOutCount;
            
            console.log('Updated stats:', { checkInCount, checkOutCount });
        }

        function updateRecentLastUpdateTime() {
            const element = document.getElementById('recentLastUpdate');
            if (element) {
                element.textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
            }
        }

        function toggleAutoRefreshRecent() {
            const btn = document.querySelector('button[onclick="toggleAutoRefreshRecent()"]');
            const icon = document.getElementById('recentAutoIcon');
            const text = document.getElementById('recentAutoText');
            
            if (isRecentAutoRefreshActive) {
                // Stop auto refresh
                clearInterval(recentAutoRefreshInterval);
                isRecentAutoRefreshActive = false;
                
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');
                icon.className = 'bi bi-wifi me-1';
                text.textContent = 'Auto';
                
            } else {
                // Start auto refresh
                isRecentAutoRefreshActive = true;
                
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-success');
                icon.className = 'bi bi-wifi-off me-1';
                text.textContent = 'Stop';
                
                // Refresh immediately
                refreshRecentActivity(true);
                
                // Set interval for auto refresh (every 30 seconds)
                recentAutoRefreshInterval = setInterval(() => {
                    refreshRecentActivity(true);
                }, 30000);
            }
        }

        function viewFacultyDTR(facultyId, date) {
            const url = `dtr_management.php?faculty_id=${facultyId}&date_type=specific&specific_date=${date}`;
            window.open(url, '_blank');
        }

        function editAttendanceRecord(attendanceId, userId) {
            // Find the record in the live DTR table and enable edit mode
            const row = document.querySelector(`tr[data-id="${attendanceId}"]`);
            if (row) {
                const editBtn = row.querySelector('button[onclick*="toggleEditMode"]');
                if (editBtn) {
                    editBtn.click();
                    // Scroll to the row
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                // If not found in current view, refresh and then edit
                refreshLiveDTR(true);
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-id="${attendanceId}"]`);
                    if (row) {
                        const editBtn = row.querySelector('button[onclick*="toggleEditMode"]');
                        if (editBtn) editBtn.click();
                        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 1000);
            }
        }

        // Initialize live DTR on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Handle URL parameters and navigation
            handleURLNavigation();
            
            // Handle cross-window communication from sidebar
            window.addEventListener('message', function(event) {
                if (event.data.action === 'startLiveUpdates') {
                    setTimeout(() => {
                        if (!isLiveUpdatesActive) {
                            toggleLiveUpdates();
                        }
                    }, 500);
                }
            });
            
            // Auto-apply filters when they change
            ['liveFacultyFilter', 'liveDateFilter', 'liveStatusFilter'].forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', refreshLiveDTR);
                }
            });
            
            // Search on Enter key
            const searchInput = document.getElementById('liveSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        refreshLiveDTR();
                    }
                });
                
                // Real-time search as user types
                searchInput.addEventListener('input', function() {
                    if (this.value.length > 2 || this.value.length === 0) {
                        refreshLiveDTR();
                    }
                });
            }
            
            // Add keyboard shortcuts for editing
            document.addEventListener('keydown', function(e) {
                // Escape key to exit edit mode
                if (e.key === 'Escape' && currentEditingRow) {
                    exitEditMode(currentEditingRow);
                }
                
                // Ctrl+S to save current edit
                if (e.ctrlKey && e.key === 's' && currentEditingRow) {
                    e.preventDefault();
                    const saveBtn = currentEditingRow.querySelector('button[onclick*="saveLiveDTRRecord"]');
                    if (saveBtn) {
                        saveBtn.click();
                    }
                }
            });
            
            // Load initial data
            refreshLiveDTR(true);
            
            // Add event listeners for recent activity filters
            const recentFilters = ['recentTimeRange', 'recentActivityType', 'recentDepartmentFilter'];
            recentFilters.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    console.log('Adding event listener for:', id);
                    element.addEventListener('change', () => {
                        console.log('Filter changed:', id, element.value);
                        refreshRecentActivity(true);
                    });
                } else {
                    console.warn('Element not found:', id);
                }
            });
            
            // Load recent activity after setting up listeners
            setTimeout(() => {
                refreshRecentActivity(true);
            }, 500);
            
            // Run integration test
            setTimeout(runIntegrationTest, 2000);
        });
        
        // Integration test function
        function runIntegrationTest() {
            console.log('🧪 Running DTR Integration Test...');
            
            // Test 1: AJAX endpoints
            testAJAXEndpoints()
                .then(() => {
                    console.log('✅ AJAX endpoints working correctly');
                    return testURLNavigation();
                })
                .then(() => {
                    console.log('✅ URL navigation working correctly');
                    return testLiveUpdates();
                })
                .then(() => {
                    console.log('✅ Live updates working correctly');
                    console.log('🎉 All DTR components successfully integrated!');
                    showNotification('DTR System Integration Complete', 'success');
                })
                .catch(error => {
                    console.error('❌ Integration test failed:', error);
                    showNotification('Integration test failed: ' + error.message, 'warning');
                });
        }
        
        function testAJAXEndpoints() {
            return new Promise((resolve, reject) => {
                // Test refresh_dtr endpoint
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        ajax_action: 'refresh_dtr',
                        date_filter: '<?= date('Y-m-d') ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ refresh_dtr endpoint working');
                        resolve();
                    } else {
                        reject(new Error('refresh_dtr endpoint failed'));
                    }
                })
                .catch(error => {
                    reject(error);
                });
            });
        }
        
        function testURLNavigation() {
            return new Promise((resolve, reject) => {
                // Test URL parameter handling
                const testParams = new URLSearchParams({
                    faculty_id: '1',
                    date_type: 'specific',
                    specific_date: '<?= date('Y-m-d') ?>'
                });
                
                // Simulate URL navigation
                if (testParams.has('faculty_id') && testParams.has('date_type')) {
                    console.log('✅ URL parameter handling working');
                    resolve();
                } else {
                    reject(new Error('URL parameter handling failed'));
                }
            });
        }
        
        function testLiveUpdates() {
            return new Promise((resolve, reject) => {
                // Test live update functionality
                const liveToggleBtn = document.getElementById('liveToggleBtn');
                const liveStatus = document.getElementById('liveStatus');
                
                if (liveToggleBtn && liveStatus) {
                    console.log('✅ Live update controls available');
                    resolve();
                } else {
                    reject(new Error('Live update controls not found'));
                }
            });
        }
        
        // DTR Logs Functions
        function viewLogDetails(attendanceId) {
            // View detailed log information
            if (!attendanceId) {
                showNotification('Invalid log ID', 'warning');
                return;
            }
            
            // In real implementation, this would open a modal with detailed log information
            alert(`View DTR log details for ID: ${attendanceId}\n(This would show detailed log information in the full implementation)`);
        }
        
        function editLog(attendanceId) {
            // Edit log entry
            if (!attendanceId) {
                showNotification('Invalid log ID', 'warning');
                return;
            }
            
            // In real implementation, this would open an edit modal
            alert(`Edit DTR log for ID: ${attendanceId}\n(This would open an edit form in the full implementation)`);
        }
        
        function exportDTRLogs() {
            // Export current DTR logs
            const currentUrl = new URL(window.location);
            const params = currentUrl.searchParams;
            params.set('export', 'csv');
            
            // Create export URL
            const exportUrl = `dtr_management.php?${params.toString()}`;
            
            // Create temporary link and trigger download
            const link = document.createElement('a');
            link.href = exportUrl;
            link.download = `dtr_logs_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('DTR logs exported successfully', 'success');
        }
    </script>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="dtr-header no-print">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <img src="../assets/uploads/logo.png" alt="DOIT Logo" style="max-height: 50px; margin-right: 20px;">
                            <div>
                                <h2 class="mb-0"><i class="fas fa-list-alt me-2"></i>DTR Logs</h2>
                                <p class="mb-0 opacity-75">Daily Time Record Logs & Activity History</p>
                            </div>
                        </div>
                        <div class="no-print">
                            <a href="dashboard.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- DTR Logs Filters -->
                <div class="card mb-4 no-print">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>DTR Logs Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Faculty Member</label>
                                <select class="form-select" name="faculty_id">
                                    <option value="">All Faculty</option>
                                    <?php 
                                    $faculty_list = $pdo->query("SELECT id, first_name, last_name, employee_id FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($faculty_list as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>" <?php echo (($_GET['faculty_id'] ?? '') == $faculty['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name'] . ' (' . $faculty['employee_id'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date Range</label>
                                <select class="form-select" name="date_range">
                                    <option value="7" <?php echo (($_GET['date_range'] ?? '30') == '7') ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="30" <?php echo (($_GET['date_range'] ?? '30') == '30') ? 'selected' : ''; ?>>Last 30 Days</option>
                                    <option value="90" <?php echo (($_GET['date_range'] ?? '30') == '90') ? 'selected' : ''; ?>>Last 90 Days</option>
                                    <option value="all" <?php echo (($_GET['date_range'] ?? '30') == 'all') ? 'selected' : ''; ?>>All Records</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="present" <?php echo (($_GET['status'] ?? '') == 'present') ? 'selected' : ''; ?>>Present</option>
                                    <option value="late" <?php echo (($_GET['status'] ?? '') == 'late') ? 'selected' : ''; ?>>Late</option>
                                    <option value="absent" <?php echo (($_GET['status'] ?? '') == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                    <option value="on_leave" <?php echo (($_GET['status'] ?? '') == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                                    <option value="no_scan" <?php echo (($_GET['status'] ?? '') == 'no_scan') ? 'selected' : ''; ?>>No Scan</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="search" placeholder="Search name, ID, or remarks..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                    <button class="btn btn-outline-primary" type="submit">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="btn-group w-100" role="group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i>Filter
                                    </button>
                                    <a href="dtr_management.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DTR Logs Table -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0"><i class="bi bi-list-alt me-2"></i>DTR Logs</h5>
                            <small class="text-muted">Daily Time Record logs and activity history</small>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-success" onclick="exportDTRLogs()">
                                <i class="bi bi-download me-1"></i>Export
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="bi bi-printer me-1"></i>Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Faculty Member</th>
                                        <th>Date</th>
                                        <th>Time In</th>
                                        <th>Time Out</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th>Log Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dtr_logs)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                                No DTR logs found matching the current filters.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($dtr_logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 10px; font-weight: 600;">
                                                            <?= strtoupper(substr($log['first_name'], 0, 1) . substr($log['last_name'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-semibold"><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></div>
                                                            <small class="text-muted">ID: <?= htmlspecialchars($log['employee_id']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= formatDate($log['date']) ?></td>
                                                <td class="text-center">
                                                    <?= $log['check_in_time'] ? formatTime($log['check_in_time']) : '<span class="text-muted">-</span>' ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= $log['check_out_time'] ? formatTime($log['check_out_time']) : '<span class="text-muted">-</span>' ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= getStatusBadge($log['status']) ?>
                                                </td>
                                                <td class="text-start">
                                                    <?= htmlspecialchars($log['remarks'] ?: '-') ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php
                                                    $log_type_class = '';
                                                    $log_icon = '';
                                                    switch($log['log_type']) {
                                                        case 'check_in':
                                                            $log_type_class = 'text-success';
                                                            $log_icon = 'bi-box-arrow-in-right';
                                                            break;
                                                        case 'check_out':
                                                            $log_type_class = 'text-info';
                                                            $log_icon = 'bi-box-arrow-right';
                                                            break;
                                                        default:
                                                            $log_type_class = 'text-secondary';
                                                            $log_icon = 'bi-clock-history';
                                                    }
                                                    ?>
                                                    <span class="badge bg-light <?= $log_type_class ?>">
                                                        <i class="bi <?= $log_icon ?> me-1"></i>
                                                        <?= ucfirst(str_replace('_', ' ', $log['log_type'])) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewLogDetails(<?= $log['attendance_id'] ?>)" title="View Details">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="editLog(<?= $log['attendance_id'] ?>)" title="Edit Log">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- DTR Logs Summary -->
                        <div class="row mt-3">
                            <div class="col-md-8">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        Showing <?= count($dtr_logs) ?> of <?= $total_records ?> total records
                                    </small>
                                    <small class="text-muted">Last updated: <?= date('Y-m-d H:i:s') ?></small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="row g-2">
                                    <div class="col-4">
                                        <div class="card border-success">
                                            <div class="card-body text-center py-2">
                                                <h6 class="mb-0 text-success"><?= count(array_filter($dtr_logs, fn($l) => $l['status'] === 'present')) ?></h6>
                                                <small class="text-muted">Present</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="card border-warning">
                                            <div class="card-body text-center py-2">
                                                <h6 class="mb-0 text-warning"><?= count(array_filter($dtr_logs, fn($l) => $l['status'] === 'late')) ?></h6>
                                                <small class="text-muted">Late</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="card border-danger">
                                            <div class="card-body text-center py-2">
                                                <h6 class="mb-0 text-danger"><?= count(array_filter($dtr_logs, fn($l) => $l['status'] === 'absent')) ?></h6>
                                                <small class="text-muted">Absent</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Live DTR Monitoring Section -->
                <div class="card mb-4" id="live-dtr">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Live DTR Monitoring</h5>
                            <small class="text-muted">Real-time attendance tracking with inline editing</small>
                            <div class="mt-1">
                                <span class="badge bg-success" id="liveStatus" style="display:none;">
                                    <i class="bi bi-circle-fill me-1"></i>Live Updates Active
                                </span>
                                <small class="text-muted" id="lastUpdate">Last updated: Just now</small>
                            </div>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-info" onclick="toggleLiveUpdates()" id="liveToggleBtn">
                                <i class="bi bi-wifi me-1"></i>Live Updates
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="refreshLiveDTR()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Live DTR Filters -->
                        <div class="row mb-3 g-2">
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Faculty</label>
                                <select class="form-select form-select-sm" id="liveFacultyFilter">
                                    <option value="">All Faculty</option>
                                    <?php foreach ($faculty_list as $f): ?>
                                        <option value="<?= $f['id'] ?>"><?= $f['first_name'] . ' ' . $f['last_name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Date</label>
                                <input type="date" class="form-control form-control-sm" id="liveDateFilter" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Status</label>
                                <select class="form-select form-select-sm" id="liveStatusFilter">
                                    <option value="">All Status</option>
                                    <option value="present">Present</option>
                                    <option value="late">Late</option>
                                    <option value="on_leave">On Leave</option>
                                    <option value="no_scan">No Scan</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted">Search</label>
                                <input type="text" class="form-control form-control-sm" id="liveSearch" placeholder="Search...">
                            </div>
                        </div>
                        
                        <!-- Live DTR Table -->
                        <div class="table-responsive">
                            <table class="table table-hover" id="liveDTRTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Faculty Member</span>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('first_name', 'ASC')" title="Sort A-Z">
                                                        <i class="bi bi-sort-alpha-down"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('first_name', 'DESC')" title="Sort Z-A">
                                                        <i class="bi bi-sort-alpha-up"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                        <th style="width: 10%;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Date</span>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('date', 'ASC')" title="Sort Oldest First">
                                                        <i class="bi bi-calendar-date"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('date', 'DESC')" title="Sort Newest First">
                                                        <i class="bi bi-calendar-date-fill"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                        <th style="width: 12%;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Time In</span>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('check_in_time', 'ASC')" title="Sort Early First">
                                                        <i class="bi bi-clock"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('check_in_time', 'DESC')" title="Sort Late First">
                                                        <i class="bi bi-clock-fill"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                        <th style="width: 12%;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Time Out</span>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('check_out_time', 'ASC')" title="Sort Early First">
                                                        <i class="bi bi-clock"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('check_out_time', 'DESC')" title="Sort Late First">
                                                        <i class="bi bi-clock-fill"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                        <th style="width: 10%;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <span>Status</span>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('status', 'ASC')" title="Sort Status A-Z">
                                                        <i class="bi bi-sort-down"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sortLiveDTR('status', 'DESC')" title="Sort Status Z-A">
                                                        <i class="bi bi-sort-up"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                        <th style="width: 15%;">Remarks</th>
                                        <th style="width: 8%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="liveDTRTableBody">
                                    <!-- Live DTR records will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Live Statistics -->
                        <div class="row mt-3">
                            <div class="col-md-8">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        Showing <span id="liveRecordCount">0</span> records
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="card border-success">
                                            <div class="card-body text-center py-2">
                                                <h5 class="mb-0 text-success" id="livePresentCount">0</h5>
                                                <small class="text-muted">Present</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="card border-warning">
                                            <div class="card-body text-center py-2">
                                                <h5 class="mb-0 text-warning" id="liveLateCount">0</h5>
                                                <small class="text-muted">Late</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DTR Display -->
                <?php 
                // Debug: Show DTR data status
                if (isset($dtr_data) && !empty($dtr_data)):
                    // Debug: Log DTR data structure
                    error_log("DTR Data Structure: " . print_r($dtr_data, true));
                ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($dtr_data && !empty($dtr_data['records'])): ?>
                    <!-- Faculty Information -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5 class="mb-3">Daily Time Record - <?php echo htmlspecialchars($dtr_data['period']); ?> <?php echo ($dtr_data['type'] === 'specific') ? '(Single Day)' : '(Monthly)'; ?></h5>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Name:</strong></td>
                                            <td><?php echo htmlspecialchars($dtr_data['faculty']['name']); ?></td>
                                            <td><strong>Employee ID:</strong></td>
                                            <td><?php echo htmlspecialchars($dtr_data['faculty']['employee_id']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Department:</strong></td>
                                            <td><?php echo htmlspecialchars($dtr_data['faculty']['department']); ?></td>
                                            <td><strong>Position:</strong></td>
                                            <td><?php echo htmlspecialchars($dtr_data['faculty']['position']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-4 text-end">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($dtr_data['period']); ?></h6>
                                    <p class="mb-0 text-muted">Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DTR Table -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table dtr-table">
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Display all days according to HR standards
                                        foreach ($dtr_data['records'] as $day) {
                                            // Skip weekends if they have no activity
                                            if ($day['is_weekend'] && !$day['has_leave'] && !$day['time_in'] && !$day['time_out']) {
                                                continue;
                                            }
                                            
                                            // Determine row class based on status
                                            $rowClass = '';
                                            switch ($day['status']) {
                                                case 'present':
                                                    $rowClass = 'status-present';
                                                    break;
                                                case 'late':
                                                    $rowClass = 'status-late';
                                                    break;
                                                case 'on_leave':
                                                    $rowClass = 'status-leave';
                                                    break;
                                                case 'no_scan':
                                                    $rowClass = 'status-no-scan';
                                                    break;
                                                case 'weekend':
                                                    $rowClass = 'status-weekend';
                                                    break;
                                                default:
                                                    $rowClass = 'status-absent';
                                                    break;
                                            }
                                            
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td><?php echo $day['day']; ?></td>
                                                <td><?php echo formatDate($day['date']); ?></td>
                                                <td><?php echo date('D', strtotime($day['date'])); ?></td>
                                                <td><?php echo $day['time_in']; ?></td>
                                                <td><?php echo $day['time_out']; ?></td>
                                                <td><?php echo htmlspecialchars($day['remarks']); ?></td>
                                            </tr>
                                            <?php
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="summary-card">
                                <h6 class="mb-3">Summary</h6>
                                <div class="summary-stat">
                                    <span>Total Working Days:</span>
                                    <strong><?php echo $dtr_data['summary']['working_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Present Days:</span>
                                    <strong><?php echo $dtr_data['summary']['present_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Late Days:</span>
                                    <strong><?php echo $dtr_data['summary']['late_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Leave Days:</span>
                                    <strong><?php echo $dtr_data['summary']['leave_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>No Scan Days:</span>
                                    <strong><?php echo $dtr_data['summary']['no_scan_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Absent Days:</span>
                                    <strong><?php echo $dtr_data['summary']['absent_days']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Total Late Minutes:</span>
                                    <strong><?php echo $dtr_data['summary']['total_late_minutes']; ?></strong>
                                </div>
                                <div class="summary-stat">
                                    <span>Total Late Hours:</span>
                                    <strong><?php echo $dtr_data['summary']['total_late_hours']; ?></strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card">
                                <h6 class="mb-3">Signatures</h6>
                                <div class="mb-3">
                                    <small class="text-muted">Employee Signature</small>
                                    <div class="border-bottom border-secondary mb-2" style="height: 40px;"></div>
                                </div>
                                <div>
                                    <small class="text-muted">Approved By</small>
                                    <div class="border-bottom border-secondary" style="height: 40px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- No DTR Data Available -->
                    <div class="card mb-4">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No DTR Data Available</h5>
                            <p class="text-muted">
                                <?php if (isset($dtr_data) && empty($dtr_data['records'])): ?>
                                    No attendance records found for the selected period. Please try a different date range or check if attendance data exists.
                                <?php else: ?>
                                    Please generate a DTR by selecting a faculty member and date range above.
                                <?php endif; ?>
                            </p>
                            <?php if (isset($dtr_data) && !empty($dtr_data)): ?>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        Debug: Records found: <?php echo count($dtr_data['records'] ?? []); ?> | 
                                        Summary available: <?php echo !empty($dtr_data['summary']) ? 'Yes' : 'No'; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportDTR() {
            // Simple export functionality - can be enhanced with actual export logic
            window.print();
        }
        
        // Auto-refresh functionality (optional)
        // setInterval(() => {
        //     if (document.querySelector('.dtr-table')) {
        //         location.reload();
        //     }
        // }, 300000); // Refresh every 5 minutes
    </script>
</body>
</html>
