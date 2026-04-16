<div class="sidebar">
    <div class="text-center py-4">
        <i class="bi bi-calendar-check fs-1 text-white"></i>
        <h5 class="text-white mt-2">DOIT Attendance</h5>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'faculty.php' ? 'active' : ''; ?>" href="faculty.php">
            <i class="bi bi-people"></i> Faculty Management
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>" href="departments.php">
            <i class="bi bi-building"></i> Departments
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'semesters.php' ? 'active' : ''; ?>" href="semesters.php">
            <i class="bi bi-calendar-range"></i> Semesters
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'active' : ''; ?>" href="schedules.php">
            <i class="bi bi-clock-history"></i> Schedules
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>" href="attendance.php">
            <i class="bi bi-check2-square"></i> Attendance
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leaves.php' ? 'active' : ''; ?>" href="leaves.php">
            <i class="bi bi-envelope-paper"></i> Leave Requests
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
            <i class="bi bi-graph-up"></i> Reports
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'audit-logs.php' ? 'active' : ''; ?>" href="audit-logs.php">
            <i class="bi bi-journal-text"></i> Audit Logs
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="bi bi-gear"></i> Settings
        </a>
        <a class="nav-link" href="../logout.php">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
</div>