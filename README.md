# DOIT Faculty Attendance System

A complete web-based attendance management system for Davao Oriental International Technology College.

## Features

- **Authentication**: Secure login for Admin and Faculty with password reset
- **Admin Dashboard**: Statistics, charts, quick actions
- **Faculty Management**: CRUD, search, filters, bulk import (CSV)
- **Department & Semester Management**
- **Schedule Management**: Weekly calendar view
- **Attendance Tracking**: Manual entry and self check-in/out with late detection
- **Leave Management**: Request, approval, credit deduction
- **Notifications**: In-app alerts
- **Audit Logs**: Track all admin actions
- **Reports**: Export to PDF/CSV
- **System Settings**: Site name, logo, session timeout, etc.

## Tech Stack

- PHP 8.1+ (PDO, password_hash)
- MySQL 5.7+
- Bootstrap 5, Chart.js, FullCalendar.js (optional)
- HTML5, CSS3, JavaScript

## Installation Guide

### Requirements
- XAMPP/WAMP/MAMP with PHP 8.1+ and MySQL
- Web browser (Chrome/Firefox)

### Steps

1. **Clone/Download** the project folder `DOIT_Faculty_Attendance_System` into your web server root:
   - XAMPP: `C:\xampp\htdocs\`
   - WAMP: `C:\wamp\www\`

2. **Start Apache & MySQL** from XAMPP control panel.

3. **Create Database**:
   - Open phpMyAdmin: `http://localhost/phpmyadmin`
   - Create a new database named `doit_attendance`
   - Import the file `database.sql` (located in the project root)

4. **Configure Database Connection**:
   - Open `config/database.php`
   - Update credentials if needed (default: `root` with empty password)

5. **Set Upload Directories Permissions** (on Linux/Mac):
   ```bash
   chmod -R 777 assets/uploads/