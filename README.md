# School Assessment Management System

A comprehensive PHP-based school management system for handling student assessments, subject alternatives, and administrative tasks.

## Features

- **User Management**: Admin, Teacher, and Student roles with secure authentication
- **Assessment System**: Create, manage, and track student assessments
- **Subject Alternatives**: Configure mutually exclusive subjects with automatic enrollment management
- **Class Management**: Organize students into classes and programs
- **Role-Based Access Control**: Secure access based on user roles
- **Audit Trail**: Complete logging of system activities

## System Architecture

### Core Structure
```
/school_system/
├── config/          # Configuration files
├── includes/        # Core system files and templates
├── models/          # Data models
├── admin/           # Admin dashboard and management
├── teacher/         # Teacher interface
├── student/         # Student interface
└── api/             # AJAX API endpoints
```

### Key Features
- **Singleton Database Connection**: Centralized PDO connection management
- **Session Management**: Comprehensive security with automatic cleanup
- **Subject Alternatives System**: Flexible N-way alternative groups with mutual exclusion
- **Performance Caching**: File-based caching for improved responsiveness
- **Security**: CSRF protection, XSS prevention, SQL injection protection

## Requirements

- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Web Server**: Apache/Nginx with mod_rewrite
- **Composer**: For dependency management

## Installation

1. Clone the repository:
   ```bash
   git clone <repository-url>
   cd school_system
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Configure your web server to point to the project directory

4. Set up your database configuration in `config/config.php`

5. Import the database structure (contact admin for schema)

6. Set proper permissions:
   ```bash
   chmod 755 cache/ logs/ uploads/
   ```

## Configuration

### Database Setup
Update the database configuration in `config/config.php`:
```php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### Environment Detection
The system automatically detects development vs production environments based on hostname patterns.

## Usage

### Default Access
- **Admin**: Full system access and user management
- **Teacher**: Assessment creation and student management
- **Student**: View subjects, take assessments, track progress

### Subject Alternatives
The system supports flexible subject alternative groups where students must choose between mutually exclusive subjects (e.g., Computing OR Biology).

## Development

### Code Structure
- **MVC Pattern**: Clear separation of models, views, and controllers
- **Security First**: All inputs sanitized, prepared statements used
- **Responsive Design**: Mobile-friendly Bootstrap-based interface
- **Professional Theme**: Black and gold color scheme throughout

### Contributing
1. Follow PSR-4 autoloading standards
2. Use prepared statements for all database queries
3. Implement proper error handling and logging
4. Maintain consistent code style and documentation

## Security Features

- Account locking after failed login attempts
- Password strength validation
- Session security with HTTPS detection
- CSRF token protection
- XSS prevention through input sanitization
- SQL injection protection via prepared statements
- Comprehensive audit logging

## License

This project is proprietary software. All rights reserved.

## Support

For support and maintenance, contact the development team.