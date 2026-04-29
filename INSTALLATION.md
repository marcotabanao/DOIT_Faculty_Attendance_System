# DOIT Faculty Attendance System - Installation Guide

## System Requirements

- **PHP**: 8.1 or higher
- **Database**: MySQL 5.7+ or MariaDB 10.2+
- **Web Server**: Apache 2.4+ (with mod_rewrite enabled)
- **Extensions**: PDO, PDO_MySQL, GD, Fileinfo, OpenSSL, Mbstring

## Quick Installation

### Step 1: Database Setup

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Create database: `doit_attendance`
3. Import the schema file: `database/schema.sql`

### Step 2: System Configuration

1. Ensure Apache and MySQL are running in XAMPP
2. Verify file permissions for uploads directory
3. Check PHP extensions are enabled

### Step 3: Access the System

1. Navigate to: `http://localhost/DOIT_FULL_SYSTEM/`
2. You'll be redirected to the login page
3. Use the credentials below to log in

## Login Credentials

### Administrator Account
- **Username**: `admin`
- **Password**: `Admin@1234`

### Faculty Test Account
- **Username**: `FAC001`
- **Password**: `Faculty@1234`

## Directory Structure

```
DOIT_FULL_SYSTEM/
|-- admin/                    # Admin portal modules
|   |-- dashboard.php         # Admin dashboard
|   |-- faculty.php           # Faculty management
|   |-- departments.php       # Department management
|   |-- semesters.php         # Semester management
|   |-- schedules.php         # Schedule management
|   |-- attendance.php        # Attendance tracking
|   |-- leaves.php            # Leave management
|   |-- reports.php           # Reports and analytics
|   |-- settings.php          # System settings
|
|-- faculty/                  # Faculty portal modules
|   |-- dashboard.php         # Faculty dashboard
|   |-- profile.php           # Profile management
|   |-- schedule.php         # Schedule viewing
|   |-- attendance.php        # Attendance history
|   |-- leaves.php            # Leave requests
|   |-- notifications.php     # Notifications
|
|-- config/                   # Configuration files
|   |-- config.php            # System settings
|   |-- database.php          # Database connection
|
|-- includes/                 # Helper functions
|   |-- functions.php         # Utility functions
|   |-- security.php          # Security functions
|   |-- header.php            # Common header
|   |-- footer.php            # Common footer
|
|-- database/                 # Database files
|   |-- schema.sql            # Complete database schema
|
|-- uploads/                  # File uploads
|   |-- profiles/             # Profile photos
|   |-- leaves/               # Leave attachments
|   |-- documents/            # Other documents
|
|-- assets/                   # Static assets
|   |-- css/                  # Stylesheets
|   |-- js/                   # JavaScript
|   |-- images/               # Images
|
|-- install.php               # Installation wizard
|-- login.php                 # Login page
|-- logout.php                # Logout handler
|-- index.php                 # Entry point
|-- README.md                 # Documentation
```

## Features Overview

### Admin Panel Features
- **Dashboard**: Real-time statistics and overview
- **Faculty Management**: Complete CRUD operations with bulk import
- **Department Management**: Organize faculty by departments
- **Semester Management**: Academic year and semester configuration
- **Schedule Management**: Weekly scheduling with calendar view
- **Attendance Tracking**: Manual and bulk attendance entry
- **Leave Management**: Approval workflow for leave requests
- **Reports**: Comprehensive analytics with export functionality
- **Settings**: System configuration and maintenance

### Faculty Portal Features
- **Dashboard**: Personal overview and quick access
- **Profile Management**: Update personal information and password
- **Schedule Viewing**: View assigned schedules in list or calendar format
- **Attendance History**: View personal attendance records
- **Leave Requests**: Submit and track leave requests
- **Notifications**: View system notifications

## Security Features

- **Authentication**: Secure login with password hashing
- **CSRF Protection**: All forms include CSRF tokens
- **Session Management**: Secure session handling with timeout
- **Input Validation**: All inputs are sanitized and validated
- **SQL Injection Prevention**: Prepared statements used throughout
- **File Upload Security**: Secure file handling with validation
- **Audit Logging**: Complete activity tracking

## Troubleshooting

### Common Issues

#### 404 Errors
- Ensure Apache mod_rewrite is enabled
- Check .htaccess file permissions
- Verify file paths in configuration

#### Database Connection Errors
- Verify MySQL service is running
- Check database credentials in config.php
- Ensure database exists and schema is imported

#### Blank Pages
- Check PHP error logs
- Verify file permissions
- Ensure all required PHP extensions are enabled

#### File Upload Issues
- Check uploads directory permissions
- Verify file size limits in php.ini
- Ensure allowed file types are configured

### Error Logs

- **Apache Error Log**: `C:\xampp\apache\logs\error.log`
- **PHP Error Log**: `C:\xampp\php\logs\php_error_log`
- **System Logs**: Available in admin panel under Settings

## Configuration Options

### Database Configuration (config/config.php)
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'doit_attendance');
```

### Security Configuration
```php
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
```

### File Upload Configuration
```php
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_PATH', 'uploads/');
```

## Maintenance

### Regular Tasks
- **Database Backups**: Weekly via admin panel
- **Log Cleanup**: Monthly via admin panel
- **System Updates**: As needed
- **Security Audits**: Quarterly

### Performance Optimization
- Enable PHP OPcache
- Use database indexes
- Optimize file uploads
- Monitor database size

## Support

For technical support:
1. Check error logs
2. Verify configuration
3. Test with sample data
4. Review documentation

## Version Information

- **Current Version**: 1.0.0
- **Release Date**: April 2026
- **Compatibility**: PHP 8.1+, MySQL 5.7+
- **Framework**: Custom PHP with Bootstrap 5

---

## Installation Complete!

After following these steps, your DOIT Faculty Attendance System should be fully operational. The system is designed to be intuitive and user-friendly for both administrators and faculty members.

### Next Steps
1. Log in as admin to configure departments and semesters
2. Add faculty members and their schedules
3. Set up leave types and credits
4. Begin tracking attendance and managing leaves

For detailed usage instructions, refer to the user guide or explore the system directly.
