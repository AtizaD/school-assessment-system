# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Environment

This is a PHP-based school management system running on XAMPP with a MySQL database. The system uses vanilla PHP with PDO for database operations.

### Required Setup
- XAMPP/WAMP with PHP 7.4+
- MySQL database named `bass_shs_test`
- Composer for dependency management

## Common Commands

### Database Setup
```bash
# Start XAMPP services (from XAMPP Control Panel or CLI)
# Import database structure from:
database/bass_shs_test_structure.sql
# Or the full database from:
database/bass_shs_test.sql
```

### Composer Dependencies
```bash
# Install PHP dependencies
composer install

# Update dependencies
composer update
```

### File Operations
```bash
# Set proper permissions for cache and logs directories
chmod 755 cache/ logs/ uploads/
```

## System Architecture

### Core Structure
```
/school_system/
├── config/          # Configuration files
│   ├── config.php   # Main system configuration and constants
│   └── database.php # Database connection singleton
├── includes/        # Core system files
│   ├── functions.php # Utility functions, pagination, validation
│   ├── auth.php     # Authentication and authorization
│   └── bass/        # Base templates (header/footer)
├── models/          # Data models
│   ├── User.php     # User management with audit trails
│   ├── Assessment.php # Assessment and question management
│   ├── Student.php  # Student-specific operations
│   └── Teacher.php  # Teacher-specific operations
├── admin/           # Admin dashboard and management
├── teacher/         # Teacher interface
├── student/         # Student interface
└── api/             # AJAX API endpoints
```

### Key Design Patterns

**Singleton Database Connection**: `DatabaseConfig` class provides centralized PDO connection management.

**Role-Based Access Control**: Three primary roles (admin, teacher, student) with corresponding directory structure and access controls.

**Session Management**: Comprehensive session handling with security headers, HTTPS detection, and automatic cleanup.

**Audit Trail System**: All user operations are logged with user context, IP addresses, and timestamps.

**Security Features**:
- Account locking after failed login attempts
- Password strength validation
- CSRF protection
- XSS prevention through input sanitization
- SQL injection protection via prepared statements

### Database Integration
- Uses PDO with prepared statements throughout
- Automatic audit information setting before database operations
- Transaction support for complex operations
- Comprehensive logging to `SystemLogs` and `AuthLogs` tables

### File Structure Conventions
- Each role has dedicated directories with corresponding functionality
- API endpoints in `/api/` for AJAX operations
- Shared assets in `/assets/` with organized CSS, JS, and image subdirectories
- Math rendering support via KaTeX library for assessment questions

### Authentication Flow
1. Login attempts are tracked and validated against account lock status
2. Successful authentication creates session with role-based redirects
3. First-time users and password change requirements are handled automatically
4. Session cleanup and logout procedures maintain security

### Performance Considerations
- Performance caching system in `/cache/` directory
- Dropdown data caching for improved UI responsiveness
- File-based logging with rotation considerations

## Development Notes

### Environment Detection
The system automatically detects development vs production environments based on hostname patterns (localhost, 127.0.0.1, ngrok domains).

### HTTPS Handling
Smart HTTPS detection that works with reverse proxies, ngrok, and various hosting environments.

### Error Handling
Comprehensive error and exception handling with both file-based logging and database logging systems.

### File Uploads
Secure file upload system with type validation, size limits, and organized storage in `/assets/assessment_images/`.