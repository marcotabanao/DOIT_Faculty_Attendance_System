<?php
/**
 * DOIT Faculty Attendance System - Faculty Schedule
 * Schedule viewing for faculty members
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
$semesterFilter = (int)($_GET['semester'] ?? 0);
$view = sanitizeInput($_GET['view'] ?? 'list');

// Get data
try {
    // Get active semester if not filtered
    if ($semesterFilter === 0) {
        $activeSemester = getActiveSemester();
        $semesterFilter = $activeSemester ? $activeSemester['id'] : 0;
    }
    
    // Get semesters
    $stmt = $pdo->query("SELECT id, name, academic_year FROM semesters ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
    
    // Get faculty schedule
    if ($semesterFilter > 0) {
        $stmt = $pdo->prepare("
            SELECT s.*, lt.name as leave_type_name
            FROM schedules s
            WHERE s.faculty_id = ? AND s.semester_id = ? AND s.is_active = 1
            ORDER BY s.day_of_week, s.time_in
        ");
        $stmt->execute([$_SESSION['faculty_id'], $semesterFilter]);
        $schedules = $stmt->fetchAll();
    } else {
        $schedules = [];
    }
    
    // Group schedules by day for calendar view
    $schedulesByDay = [];
    if ($view === 'calendar') {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        foreach ($days as $day) {
            $schedulesByDay[$day] = array_filter($schedules, fn($s) => $s['day_of_week'] === $day);
        }
    }
    
} catch (PDOException $e) {
    error_log("Faculty schedule error: " . $e->getMessage());
    $semesters = [];
    $schedules = [];
    $schedulesByDay = [];
}

// Get flash message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - <?php echo SYSTEM_NAME; ?></title>
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
        
        .schedule-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .time-badge {
            background: #6c757d;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            text-align: center;
            font-weight: bold;
        }
        
        .calendar-time-slot {
            background: #f8f9fa;
            padding: 0.5rem;
            text-align: center;
            font-size: 0.875rem;
            border-right: 1px solid #dee2e6;
        }
        
        .calendar-day {
            background: white;
            min-height: 60px;
            position: relative;
        }
        
        .schedule-block {
            position: absolute;
            left: 2px;
            right: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2px 4px;
            border-radius: 4px;
            font-size: 0.75rem;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        
        .day-schedule {
            border-left: 3px solid #667eea;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        
        .subject-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: bold;
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
                        <a class="nav-link active" href="schedule.php">
                            <i class="fas fa-clock me-2"></i> My Schedule
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance.php">
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
                    <h2>My Schedule</h2>
                    <div>
                        <button class="btn btn-outline-success" onclick="printSchedule()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="card schedule-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Semester</label>
                                <select class="form-select" name="semester">
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?php echo $semester['id']; ?>" <?php echo $semesterFilter == $semester['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($semester['name'] . ' (' . $semester['academic_year'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">View</label>
                                <select class="form-select" name="view" onchange="this.form.submit()">
                                    <option value="list" <?php echo $view === 'list' ? 'selected' : ''; ?>>List View</option>
                                    <option value="calendar" <?php echo $view === 'calendar' ? 'selected' : ''; ?>>Calendar View</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if ($view === 'calendar'): ?>
                    <!-- Calendar View -->
                    <div class="card schedule-card">
                        <div class="card-header">
                            <h5 class="mb-0">Weekly Calendar</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="calendar-grid">
                                <!-- Headers -->
                                <div class="calendar-header">Time</div>
                                <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                                    <div class="calendar-header"><?php echo substr($day, 0, 3); ?></div>
                                <?php endforeach; ?>
                                
                                <!-- Time slots -->
                                <?php 
                                $timeSlots = [];
                                for ($hour = 6; $hour <= 21; $hour++) {
                                    $timeSlots[] = sprintf("%02d:00", $hour);
                                    $timeSlots[] = sprintf("%02d:30", $hour);
                                }
                                
                                foreach ($timeSlots as $time): ?>
                                    <div class="calendar-time-slot"><?php echo $time; ?></div>
                                    <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                                        <div class="calendar-day">
                                            <?php 
                                            $daySchedules = $schedulesByDay[$day] ?? [];
                                            foreach ($daySchedules as $schedule):
                                                $startTime = strtotime($schedule['time_in']);
                                                $endTime = strtotime($schedule['time_out']);
                                                $currentTime = strtotime($time);
                                                
                                                if ($currentTime >= $startTime && $currentTime < $endTime):
                                                    $duration = ($endTime - $startTime) / 60; // in minutes
                                                    $height = ($duration / 30) * 60; // 60px per 30 minutes
                                                    $top = (($startTime - strtotime(date('Y-m-d 06:00'))) / 60 / 30) * 60;
                                            ?>
                                                    <div class="schedule-block" style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;">
                                                        <div class="fw-bold"><?php echo htmlspecialchars($schedule['subject_code']); ?></div>
                                                        <div><?php echo htmlspecialchars($schedule['subject_name'] ?? ''); ?></div>
                                                        <div><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($schedule['room']); ?></div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- List View -->
                    <div class="card schedule-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Schedule List</h5>
                            <span class="badge bg-primary"><?php echo count($schedules); ?> Classes</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($schedules)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No schedules found for the selected semester.</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php 
                                    $schedulesByDayList = [];
                                    foreach ($schedules as $schedule) {
                                        $schedulesByDayList[$schedule['day_of_week']][] = $schedule;
                                    }
                                    
                                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                                    foreach ($days as $day): ?>
                                        <div class="col-lg-6 mb-4">
                                            <div class="card h-100">
                                                <div class="card-header bg-primary text-white">
                                                    <h6 class="mb-0"><?php echo $day; ?></h6>
                                                </div>
                                                <div class="card-body">
                                                    <?php if (isset($schedulesByDayList[$day]) && !empty($schedulesByDayList[$day])): ?>
                                                        <?php foreach ($schedulesByDayList[$day] as $schedule): ?>
                                                            <div class="day-schedule">
                                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                                    <div>
                                                                        <div class="subject-badge mb-2">
                                                                            <?php echo htmlspecialchars($schedule['subject_code']); ?>
                                                                        </div>
                                                                        <?php if ($schedule['subject_name']): ?>
                                                                            <div class="fw-bold"><?php echo htmlspecialchars($schedule['subject_name']); ?></div>
                                                                        <?php endif; ?>
                                                                        <div class="text-muted">
                                                                            <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($schedule['room']); ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="text-end">
                                                                        <div class="time-badge">
                                                                            <?php echo formatTime($schedule['time_in']); ?> - <?php echo formatTime($schedule['time_out']); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="text-center py-3">
                                                            <small class="text-muted">No classes scheduled</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Summary Statistics -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php echo count($schedules); ?></h3>
                                <p class="mb-0">Total Classes</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <h3 class="text-success">
                                    <?php 
                                    $totalHours = 0;
                                    foreach ($schedules as $schedule) {
                                        $start = strtotime($schedule['time_in']);
                                        $end = strtotime($schedule['time_out']);
                                        $totalHours += ($end - $start) / 3600;
                                    }
                                    echo number_format($totalHours, 1); 
                                    ?>
                                </h3>
                                <p class="mb-0">Weekly Hours</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h3 class="text-info">
                                    <?php echo count(array_unique(array_column($schedules, 'day_of_week'))); ?>
                                </h3>
                                <p class="mb-0">Days per Week</p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printSchedule() {
            window.print();
        }
    </script>
</body>
</html>
