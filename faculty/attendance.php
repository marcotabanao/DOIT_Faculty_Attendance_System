<?php
/**
 * DOIT Faculty Attendance System - Faculty Attendance
 * Attendance viewing for faculty members
 */

// Define system constant for security
define('DOIT_SYSTEM', true);

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

// Require faculty authentication
requireAuth();
if (!isFaculty()) {
    header('Location: ../unauthorized.php');
    exit;
}

// Check session timeout
checkSessionTimeout();

// Get filters
$monthFilter = sanitizeInput($_GET['month'] ?? date('Y-m'));
$yearFilter = sanitizeInput($_GET['year'] ?? date('Y'));

// Get attendance data
try {
    // Get attendance records for the faculty member
    $stmt = $pdo->prepare("
        SELECT a.*, 
               TIMESTAMPDIFF(HOUR, a.time_in, a.time_out) as hours_worked
        FROM attendance a 
        WHERE a.faculty_id = ? 
        AND DATE_FORMAT(a.date, '%Y-%m') = ?
        ORDER BY a.date DESC
    ");
    $stmt->execute([$_SESSION['faculty_id'], $monthFilter]);
    $attendanceRecords = $stmt->fetchAll();
    
    // Get monthly statistics
    $stmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, time_in, time_out) ELSE 0 END) as total_hours
        FROM attendance 
        WHERE faculty_id = ? 
        AND DATE_FORMAT(date, '%Y-%m') = ?
        GROUP BY status
    ");
    $stmt->execute([$_SESSION['faculty_id'], $monthFilter]);
    $monthlyStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get leave credits
    $leaveCredits = getFacultyLeaveCredits($_SESSION['faculty_id']);
    
} catch (PDOException $e) {
    error_log("Faculty attendance error: " . $e->getMessage());
    $attendanceRecords = [];
    $monthlyStats = [];
    $leaveCredits = [];
}

// Get flash message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            margin: 0.25rem 0;
            transition: all 0.3s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .attendance-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .attendance-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .stats-card {
            border: none;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-3px);
        }
        
        .calendar-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem;
            text-align: center;
            font-weight: bold;
            font-size: 0.875rem;
        }
        
        .calendar-day {
            background: white;
            min-height: 80px;
            padding: 0.5rem;
            position: relative;
        }
        
        .calendar-day-number {
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        
        .attendance-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 2px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center mb-4">
                    <h4><i class="fas fa-graduation-cap"></i> DOIT</h4>
                    <small>Faculty Portal</small>
                </div>
                
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-2"></i> My Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <i class="fas fa-clock me-2"></i> My Schedule
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="attendance.php">
                            <i class="fas fa-check-circle me-2"></i> My Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="leaves.php">
                            <i class="fas fa-file-medical me-2"></i> Leave Requests
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="notifications.php">
                            <i class="fas fa-bell me-2"></i> Notifications
                        </a>
                    </li>
                </ul>
                
                <div class="mt-auto pt-4">
                    <div class="text-center">
                        <small class="d-block">Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?></small>
                        <a href="../logout.php" class="btn btn-sm btn-outline-light mt-2">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </nav>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content p-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>My Attendance</h2>
                    <div>
                        <button class="btn btn-outline-success" onclick="exportAttendance()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Monthly Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card border-success">
                            <div class="card-body">
                                <h3 class="text-success"><?php echo $monthlyStats['Present'] ?? 0; ?></h3>
                                <p class="mb-0">Present</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-warning">
                            <div class="card-body">
                                <h3 class="text-warning"><?php echo $monthlyStats['Late'] ?? 0; ?></h3>
                                <p class="mb-0">Late</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-danger">
                            <div class="card-body">
                                <h3 class="text-danger"><?php echo $monthlyStats['Absent'] ?? 0; ?></h3>
                                <p class="mb-0">Absent</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card border-info">
                            <div class="card-body">
                                <h3 class="text-info"><?php echo ($monthlyStats['Half_Day'] ?? 0) + ($monthlyStats['On_Leave'] ?? 0); ?></h3>
                                <p class="mb-0">Half Day/Leave</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="card attendance-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Month</label>
                                <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($monthFilter); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-filter"></i> Apply Filter
                                </button>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Attendance Records -->
                <div class="card attendance-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Attendance Records</h5>
                        <span class="badge bg-primary"><?php echo count($attendanceRecords); ?> Records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($attendanceRecords)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No attendance records found for the selected period.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Hours Worked</th>
                                            <th>Status</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendanceRecords as $record): ?>
                                            <tr>
                                                <td><?php echo formatDate($record['date']); ?></td>
                                                <td><?php echo $record['time_in'] ? formatTime($record['time_in']) : '-'; ?></td>
                                                <td><?php echo $record['time_out'] ? formatTime($record['time_out']) : '-'; ?></td>
                                                <td>
                                                    <?php if ($record['time_in'] && $record['time_out']): ?>
                                                        <?php echo number_format($record['hours_worked'], 2); ?> hrs
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge badge bg-<?php 
                                                        echo $record['status'] === 'Present' ? 'success' : 
                                                             ($record['status'] === 'Late' ? 'warning' : 
                                                             ($record['status'] === 'Absent' ? 'danger' : 'info')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($record['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($record['remarks'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Calendar View -->
                <div class="card attendance-card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Calendar View</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get calendar data
                        $calendarData = [];
                        foreach ($attendanceRecords as $record) {
                            $date = date('Y-m-d', strtotime($record['date']));
                            $calendarData[$date] = $record['status'];
                        }
                        
                        // Get first and last day of month
                        $firstDay = date('Y-m-01', strtotime($monthFilter));
                        $lastDay = date('Y-m-t', strtotime($monthFilter));
                        $daysInMonth = date('t', strtotime($monthFilter));
                        ?>
                        
                        <div class="calendar-view">
                            <?php
                            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($dayNames as $day): ?>
                                <div class="calendar-header"><?php echo $day; ?></div>
                            <?php endforeach; ?>
                            
                            <?php
                            $currentDay = 1;
                            $startDayOfWeek = date('w', strtotime($firstDay));
                            
                            // Empty cells for days before month starts
                            for ($i = 0; $i < $startDayOfWeek; $i++): ?>
                                <div class="calendar-day"></div>
                            <?php endfor; ?>
                            
                            // Days of the month
                            while ($currentDay <= $daysInMonth):
                                $date = date('Y-m-d', strtotime("$firstDay + " . ($currentDay - 1) . " days"));
                                $status = $calendarData[$date] ?? null;
                                $isToday = $date === date('Y-m-d');
                                ?>
                                <div class="calendar-day <?php echo $isToday ? 'bg-light' : ''; ?>">
                                    <div class="calendar-day-number <?php echo $isToday ? 'text-primary fw-bold' : ''; ?>">
                                        <?php echo $currentDay; ?>
                                    </div>
                                    <?php if ($status): ?>
                                        <div class="attendance-indicator bg-<?php 
                                            echo $status === 'Present' ? 'success' : 
                                                 ($status === 'Late' ? 'warning' : 
                                                 ($status === 'Absent' ? 'danger' : 'info'); 
                                        ?>" title="<?php echo htmlspecialchars($status); ?>"></div>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $currentDay++;
                            endwhile;
                            
                            // Empty cells for days after month ends
                            $totalCells = $startDayOfWeek + $daysInMonth;
                            $remainingCells = 42 - $totalCells; // 6 weeks * 7 days
                            for ($i = 0; $i < $remainingCells; $i++): ?>
                                <div class="calendar-day"></div>
                            <?php endfor; ?>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <span class="attendance-indicator bg-success"></span> Present
                                <span class="attendance-indicator bg-warning ms-2"></span> Late
                                <span class="attendance-indicator bg-danger ms-2"></span> Absent
                                <span class="attendance-indicator bg-info ms-2"></span> Other
                            </small>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetFilters() {
            const currentMonth = new Date().toISOString().slice(0, 7);
            window.location.href = 'attendance.php?month=' + currentMonth;
        }
        
        function exportAttendance() {
            const month = '<?php echo $monthFilter; ?>';
            window.location.href = 'attendance_export.php?month=' + month;
        }
    </script>
</body>
</html>
