<?php
// maintenance.php
define('BASEPATH', dirname(__FILE__));
session_start();

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// If user is admin, redirect to admin dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

// If maintenance mode is disabled, redirect to appropriate dashboard
if (!isMaintenanceMode()) {
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
        $role = $_SESSION['user_role'];
        header('Location: ' . BASE_URL . "/{$role}/index.php");
    } else {
        header('Location: ' . BASE_URL . '/login.php');
    }
    exit;
}

$pageTitle = 'System Under Maintenance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SYSTEM_NAME; ?></title>
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-yellow: #ffd700;
            --dark-yellow: #ccac00;
            --light-yellow: #fff7cc;
            --black: #000000;
            --white: #ffffff;
        }

        body {
            background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 50%, var(--dark-yellow) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .maintenance-container {
            text-align: center;
            color: white;
            max-width: 600px;
            padding: 2rem;
        }
        
        .maintenance-icon {
            font-size: 5rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            color: var(--primary-yellow);
            position: relative;
            display: inline-block;
        }
        
        .gear-container {
            position: relative;
            display: inline-block;
            margin-bottom: 2rem;
        }
        
        .gear {
            display: inline-block;
            color: var(--primary-yellow);
            animation: rotate 4s linear infinite;
        }
        
        .gear-1 {
            font-size: 4rem;
            position: relative;
            z-index: 2;
        }
        
        .gear-2 {
            font-size: 2.5rem;
            position: absolute;
            top: -10px;
            right: -20px;
            animation: rotate-reverse 3s linear infinite;
            opacity: 0.8;
        }
        
        .gear-3 {
            font-size: 1.8rem;
            position: absolute;
            bottom: -5px;
            left: -15px;
            animation: rotate 5s linear infinite;
            opacity: 0.7;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes rotate-reverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }
        
        .pulse-dot {
            width: 8px;
            height: 8px;
            background: var(--primary-yellow);
            border-radius: 50%;
            display: inline-block;
            margin: 0 3px;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        .pulse-dot:nth-child(2) {
            animation-delay: 0.3s;
        }
        
        .pulse-dot:nth-child(3) {
            animation-delay: 0.6s;
        }
        
        @keyframes pulse {
            0%, 80%, 100% {
                opacity: 0.3;
                transform: scale(1);
            }
            40% {
                opacity: 1;
                transform: scale(1.3);
            }
        }
        
        .working-indicator {
            margin-top: 1rem;
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .maintenance-title {
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 1rem;
            color: white;
        }
        
        .maintenance-message {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 2rem;
            opacity: 0.9;
            color: white;
        }
        
        .maintenance-info {
            background: rgba(255, 215, 0, 0.1);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }
        
        .maintenance-info h4 {
            color: var(--primary-yellow);
            margin-bottom: 1rem;
        }
        
        .btn-home {
            background: transparent;
            border: 2px solid var(--primary-yellow);
            color: var(--primary-yellow);
            padding: 0.75rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-home:hover {
            background: var(--primary-yellow);
            color: var(--black);
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
        }
        
        .system-name {
            font-weight: 600;
            color: var(--primary-yellow);
        }
        
        @media (max-width: 768px) {
            .maintenance-container {
                padding: 1rem;
            }
            
            .maintenance-icon {
                font-size: 3.5rem;
            }
            
            .maintenance-title {
                font-size: 2rem;
            }
            
            .maintenance-message {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="gear-container">
            <i class="fas fa-cog gear gear-1"></i>
            <i class="fas fa-cog gear gear-2"></i>
            <i class="fas fa-cog gear gear-3"></i>
        </div>
        
        <div class="working-indicator">
            <span class="pulse-dot"></span>
            <span class="pulse-dot"></span>
            <span class="pulse-dot"></span>
        </div>
        
        <h1 class="maintenance-title">System Under Maintenance</h1>
        
        <div class="maintenance-message">
            <p>We're currently performing scheduled maintenance on <span class="system-name"><?php echo SYSTEM_NAME; ?></span> to improve your experience.</p>
        </div>
        
        <div class="maintenance-info">
            <h4><i class="fas fa-info-circle"></i> What's happening?</h4>
            <p class="mb-0">Our technical team is working to enhance the system's performance and security. We apologize for any inconvenience this may cause.</p>
        </div>
        
        <div class="maintenance-info">
            <h4><i class="fas fa-clock"></i> When will it be back?</h4>
            <p class="mb-0">We expect the system to be fully operational shortly. Please check back in a few minutes.</p>
        </div>
        
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="mt-4">
            <a href="<?php echo BASE_URL; ?>/includes/bass/logout.php" class="btn-home">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
        <?php else: ?>
        <div class="mt-4">
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn-home">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
        </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <small class="text-white-50">
                <i class="fas fa-shield-alt"></i>
                Only administrators can access the system during maintenance
            </small>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.bundle.min.js"></script>
    
    <!-- Auto-refresh every 30 seconds to check if maintenance is over -->
    <script>
    setTimeout(function() {
        window.location.reload();
    }, 30000);
    </script>
</body>
</html>