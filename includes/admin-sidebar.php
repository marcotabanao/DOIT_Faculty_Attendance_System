<?php
// Ensure constants are loaded (especially PROFILE_PHOTO_PATH)
if (!defined('PROFILE_PHOTO_PATH')) {
    require_once __DIR__ . '/../config/constants.php';
}
// Fetch logo path from database
$logo_path = getSetting($pdo, 'logo_path');
?>
<div class="sidebar">
    <div class="text-center py-4">
        <img src="../assets/uploads/logo.png" alt="DOIT Logo" style="max-height: 70px; margin-bottom: 10px;">
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'faculty.php' ? 'active' : '' ?>" href="faculty.php">
            <i class="bi bi-people"></i> Faculty
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : '' ?>" href="departments.php">
            <i class="bi bi-building"></i> Departments
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'semesters.php' ? 'active' : '' ?>" href="semesters.php">
            <i class="bi bi-calendar-range"></i> Semesters
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'schedules.php' ? 'active' : '' ?>" href="schedules.php">
            <i class="bi bi-clock-history"></i> Schedules
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : '' ?>" href="attendance.php">
            <i class="bi bi-check2-square"></i> Attendance
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'leaves.php' ? 'active' : '' ?>" href="leaves.php">
            <i class="bi bi-envelope-paper"></i> Leave Requests
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : '' ?>" href="reports.php">
            <i class="bi bi-graph-up"></i> Reports
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'audit-logs.php' ? 'active' : '' ?>" href="audit-logs.php">
            <i class="bi bi-journal-text"></i> Audit Logs
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'faculty_scanner.php' ? 'active' : '' ?>" href="faculty_scanner.php">
            <i class="bi bi-person-badge"></i> Faculty Scanner
        </a>
        <a class="nav-link" href="dtr_management.php">
            <i class="bi bi-file-earmark-text"></i> DTR Management
        </a>
        <a class="nav-link" href="../kiosk_dtr.php" target="_blank">
            <i class="bi bi-file-earmark-text"></i> DTR Generator
        </a>
        <a class="nav-link" href="../kiosk.php" target="_blank">
            <i class="bi bi-upc-scan"></i> Attendance Kiosk
        </a>
    </nav>
    
    <!-- Sidebar DTR Interface -->
    <div class="sidebar-dtr-panel mt-3">
        <div class="dtr-panel-header" onclick="toggleSidebarDTR()">
            <div class="d-flex justify-content-between align-items-center px-3 py-2">
                <span class="text-white small fw-semibold">
                    <i class="bi bi-clock-history me-2"></i>Quick DTR
                </span>
                <i class="bi bi-chevron-down text-white small" id="sidebarDTRToggle"></i>
            </div>
        </div>
        <div class="dtr-panel-content" id="sidebarDTRPanel" style="display: none;">
            <div class="px-3 py-2">
                <!-- Quick DTR Actions -->
                <div class="mb-3">
                    <label class="text-white small">Today's DTR</label>
                    <div class="d-grid gap-2 mt-2">
                        <button class="btn btn-sm btn-outline-light" onclick="quickDTRAction('today')">
                            <i class="bi bi-calendar-day me-1"></i>
                            <small>View Today</small>
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="quickDTRAction('live')">
                            <i class="bi bi-wifi me-1"></i>
                            <small>Live Monitor</small>
                        </button>
                    </div>
                </div>
                
                <!-- Faculty Quick Select -->
                <div class="mb-3">
                    <label class="text-white small">Faculty DTR</label>
                    <select class="form-select form-select-sm" id="sidebarFacultySelect">
                        <option value="">Select Faculty</option>
                        <?php
                        // Get faculty list for quick DTR access
                        $faculty_list = $pdo->query("SELECT id, first_name, last_name, employee_id FROM users WHERE role = 'faculty' AND status = 'active' ORDER BY first_name, last_name LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($faculty_list as $f):
                        ?>
                        <option value="<?= $f['id'] ?>"><?= $f['first_name'] . ' ' . $f['last_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="d-grid gap-1 mt-2">
                        <button class="btn btn-sm btn-outline-info" onclick="quickFacultyDTR('today')">
                            <small>Today</small>
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="quickFacultyDTR('month')">
                            <small>This Month</small>
                        </button>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="mb-3">
                    <label class="text-white small">Today's Summary</label>
                    <div class="d-flex justify-content-between mt-2">
                        <div class="text-center">
                            <div class="text-white fw-bold" id="sidebarPresentCount">0</div>
                            <small class="text-white-50">Present</small>
                        </div>
                        <div class="text-center">
                            <div class="text-white fw-bold" id="sidebarLateCount">0</div>
                            <small class="text-white-50">Late</small>
                        </div>
                        <div class="text-center">
                            <div class="text-white fw-bold" id="sidebarAbsentCount">0</div>
                            <small class="text-white-50">Absent</small>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Date Picker -->
                <div class="mb-3">
                    <label class="text-white small">Quick Date</label>
                    <input type="date" class="form-control form-control-sm" id="sidebarQuickDate" value="<?= date('Y-m-d') ?>">
                    <button class="btn btn-sm btn-outline-success w-100 mt-2" onclick="quickDateDTR()">
                        <i class="bi bi-search me-1"></i>
                        <small>View DTR</small>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .sidebar-dtr-panel {
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }
        
        .dtr-panel-header {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .dtr-panel-header:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .dtr-panel-content {
            max-height: 400px;
            overflow-y: auto;
            background-color: rgba(0,0,0,0.2);
        }
        
        .dtr-panel-content::-webkit-scrollbar {
            width: 4px;
        }
        
        .dtr-panel-content::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .dtr-panel-content::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
        }
        
        .form-select-sm, .form-control-sm {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.2);
        }
        
        #sidebarDTRToggle {
            transition: transform 0.2s;
        }
        
        #sidebarDTRToggle.rotated {
            transform: rotate(180deg);
        }
    </style>
    
    <script>
        function toggleSidebarDTR() {
            const panel = document.getElementById('sidebarDTRPanel');
            const toggle = document.getElementById('sidebarDTRToggle');
            
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                toggle.classList.add('rotated');
                // Load quick stats when panel opens
                loadSidebarQuickStats();
            } else {
                panel.style.display = 'none';
                toggle.classList.remove('rotated');
            }
        }
        
        function quickDTRAction(action) {
            if (action === 'today') {
                const url = 'dtr_management.php?date_type=specific&specific_date=<?= date('Y-m-d') ?>&auto_generate=1';
                window.open(url, '_blank');
            } else if (action === 'live') {
                const url = 'dtr_management.php#live-dtr';
                const newWindow = window.open(url, '_blank');
                // Add delay to ensure the new window loads before starting live updates
                setTimeout(() => {
                    try {
                        newWindow.postMessage({ action: 'startLiveUpdates' }, '*');
                    } catch (e) {
                        console.log('New window not ready for communication');
                    }
                }, 1000);
            }
        }
        
        function quickFacultyDTR(period) {
            const facultyId = document.getElementById('sidebarFacultySelect').value;
            if (!facultyId) {
                alert('Please select a faculty member');
                return;
            }
            
            let url = 'dtr_management.php?faculty_id=' + facultyId;
            if (period === 'today') {
                url += '&date_type=specific&specific_date=<?= date('Y-m-d') ?>';
            } else {
                url += '&date_type=month&month=<?= date('Y-m') ?>';
            }
            
            window.open(url, '_blank');
        }
        
        function quickDateDTR() {
            const date = document.getElementById('sidebarQuickDate').value;
            window.open('dtr_management.php?date_type=specific&specific_date=' + date, '_blank');
        }
        
        function loadSidebarQuickStats() {
            // Load today's attendance summary
            fetch('dtr_management.php', {
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
                    let presentCount = 0;
                    let lateCount = 0;
                    let absentCount = 0;
                    
                    data.records.forEach(record => {
                        const status = record.status.toLowerCase();
                        if (status.includes('present')) presentCount++;
                        else if (status.includes('late')) lateCount++;
                        else if (status.includes('absent')) absentCount++;
                    });
                    
                    document.getElementById('sidebarPresentCount').textContent = presentCount;
                    document.getElementById('sidebarLateCount').textContent = lateCount;
                    document.getElementById('sidebarAbsentCount').textContent = absentCount;
                }
            })
            .catch(error => {
                console.error('Error loading sidebar stats:', error);
            });
        }
        
        // Auto-refresh stats every 5 minutes when panel is open
        setInterval(() => {
            const panel = document.getElementById('sidebarDTRPanel');
            if (panel && panel.style.display !== 'none') {
                loadSidebarQuickStats();
            }
        }, 300000);
    </script>
</div>