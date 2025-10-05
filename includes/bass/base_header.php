<?php if (!defined('BASEPATH')) exit('No direct script access allowed'); ?>
<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

// Check for password change requirement
if (
    isset($_SESSION['password_change_required']) &&
    $_SESSION['password_change_required'] &&
    basename($_SERVER['PHP_SELF']) !== 'change-password.php'
) {
    logSystemActivity(
        'Security',
        'User redirected to password change page - password change required',
        'INFO',
        $_SESSION['user_id'] ?? null
    );
    header('Location: ' . BASE_URL . '/change-password.php');
    exit;
}

// Check for first login
if (
    isset($_SESSION['first_login']) &&
    $_SESSION['first_login'] &&
    basename($_SERVER['PHP_SELF']) !== 'change-password.php'
) {
    logSystemActivity(
        'Security',
        'User redirected to password change page - first login',
        'INFO',
        $_SESSION['user_id'] ?? null
    );
    header('Location: ' . BASE_URL . '/change-password.php');
    exit;
}

// Check maintenance mode (skip for maintenance.php itself)
if (basename($_SERVER['PHP_SELF']) !== 'maintenance.php') {
    enforceMaintenanceMode();
}

// Verify current session if exists
if (isset($_SESSION['user_id'])) {
    try {
        $db = DatabaseConfig::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as active_session 
             FROM UserSessions 
             WHERE user_id = ? 
             AND session_id = ? 
             AND is_active = TRUE 
             AND expire_timestamp > CURRENT_TIMESTAMP"
        );
        $stmt->execute([$_SESSION['user_id'], session_id()]);
        $result = $stmt->fetch();

        if ($result['active_session'] == 0) {
            $_SESSION = array();
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION = array();
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
        logError("Session verification error in header: " . $e->getMessage());
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
} else {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// Load semester data for teachers
$currentSemester = null;
$allSemesters = null;
if ($userRole === 'teacher') {
    require_once INCLUDES_PATH . '/teacher_semester_selector.php';
    try {
        $currentSemester = getCurrentSemesterForTeacher($db, $_SESSION['user_id']);
        $allSemesters = getAllSemestersForTeacher($db);
    } catch (Exception $e) {
        // Continue without semester data if there's an issue
        logError("Could not load semester data in header: " . $e->getMessage());
    }
}

$sidebarItems = [
    'admin' => [
        ['url' => '/admin/index.php', 'icon' => 'fa-dashboard', 'text' => 'Dashboard'],
        ['url' => '/admin/users.php', 'icon' => 'fa-users', 'text' => 'Users'],
        ['url' => '/admin/programs.php', 'icon' => 'fa-graduation-cap', 'text' => 'Programs'],
        ['url' => '/admin/classes.php', 'icon' => 'fa-chalkboard', 'text' => 'Classes'],
        ['url' => '/admin/subjects.php', 'icon' => 'fa-book', 'text' => 'Subjects'],
        ['url' => '/admin/subject_alternatives_config.php', 'icon' => 'fa-random', 'text' => 'Subject Alternatives'],
        ['url' => '/admin/semesters.php', 'icon' => 'fa-calendar', 'text' => 'Semesters'],
        ['url' => '/admin/assessment_types.php', 'icon' => 'fa-tasks', 'text' => 'Assessment Types'],
        
        ['url' => '/admin/performance_dashboard.php', 'icon' => 'fa-chart-bar', 'text' => 'Students Performance'],
        ['url' => '/admin/report_cards.php', 'icon' => 'fa-file-alt', 'text' => 'Report Cards'],
        ['url' => '/admin/generate_results.php', 'icon' => 'fa-chart-bar', 'text' => 'Get Result'],
        ['url' => '/admin/payment-dashboard.php', 'icon' => 'fa-credit-card', 'text' => 'Payment Management'],
        ['url' => '/admin/reset_passwords.php', 'icon' => 'fas fa-key', 'text' => 'Manage Password'],
        ['url' => '/admin/promote_students.php', 'icon' => 'fa-level-up-alt', 'text' => 'Promote Students'],
        ['url' => '/admin/view_archived_students.php', 'icon' => 'fa-archive', 'text' => 'Archived Students'],
        ['url' => '/admin/audit-logs.php', 'icon' => 'fa-history', 'text' => 'Audit Logs', 'category' => 'System']
    ],
    'teacher' => [
        ['url' => '/teacher/index.php', 'icon' => 'fa-dashboard', 'text' => 'Dashboard'],
        ['url' => '/teacher/assessments.php', 'icon' => 'fa-tasks', 'text' => 'Assessments'],
        ['url' => '/teacher/students.php', 'icon' => 'fa-user-graduate', 'text' => 'Students'],
        ['url' => '/teacher/results.php', 'icon' => 'fa-chart-bar', 'text' => 'Results'],
        ['url' => '/teacher/subjects.php', 'icon' => 'fa-book', 'text' => 'My Subjects'],
        ['url' => '/teacher/question-bank.php', 'icon' => 'fa-folder', 'text' => 'Question Bank'],
        ['url' => '/teacher/generate_results_pdf.php', 'icon' => 'fa-chart-line', 'text' => 'Generate results'],
        ['url' => '/teacher/reports.php', 'icon' => 'fa-chart-line', 'text' => 'Reports']
    ],
    'student' => [
        ['url' => '/student/index.php', 'icon' => 'fa-dashboard', 'text' => 'Dashboard'],
        ['url' => '/student/assessments.php', 'icon' => 'fa-tasks', 'text' => 'Assessments'],
        ['url' => '/student/results.php', 'icon' => 'fa-chart-bar', 'text' => 'Results'],
        ['url' => '/student/report_card.php', 'icon' => 'fa-file-alt', 'text' => 'Report Card'],
        ['url' => '/student/subjects.php', 'icon' => 'fa-book', 'text' => 'My Subjects'],
        ['url' => '/student/schedule.php', 'icon' => 'fa-calendar', 'text' => 'Assessment Schedule'],
        ['url' => '/student/progress.php', 'icon' => 'fa-chart-line', 'text' => 'Progress Report'],
        ['url' => '/includes/bass/logout.php', 'icon' => 'fas fa-sign-out-alt', 'text' => 'Log-Out']
    ]
];

$roleTitle = [
    'admin' => 'ClassTest Admin',
    'teacher' => 'ClassTest Teacher',
    'student' => 'ClassTest Student'
][$userRole] ?? 'ClassTest';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="300">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : ''; ?><?php echo SYSTEM_NAME; ?></title>

    <!-- PWA Meta Tags -->
    <meta name="description" content="School Assessment Management System - Take assessments, view results, and manage your academic progress">
    <meta name="theme-color" content="#ffd700">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Assessment">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/manifest.json">

    <!-- App Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo BASE_URL; ?>/assets/images/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo BASE_URL; ?>/assets/images/icon-512x512.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/images/icon-192x192.png">

    <!-- Load KaTeX CSS first -->
    <link href="<?php echo BASE_URL; ?>/assets/maths/katex.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/fontawesome/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mathquill/mathquill.css">
    
    <style>
        :root {
            --primary-yellow: #ffd700;
            --dark-yellow: #ccac00;
            --light-yellow: #fff7cc;
            --sidebar-width: 250px;
            --header-height: 60px;
            --transition-speed: 0.3s;
        }

        body {
            overflow-x: hidden;
            background-color: #f8f9fa;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            height: 100vh;
            width: var(--sidebar-width);
            position: fixed;
            left: 0;
            top: 0;
            background: linear-gradient(to bottom, #000000, #1a1a1a);
            transition: transform var(--transition-speed);
            z-index: 1040;
            display: flex;
            flex-direction: column;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: #1a1a1a;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: var(--primary-yellow);
            border-radius: 2px;
        }

        .list-group-flush {
            height: 100%;
        }

        .sidebar-heading {
            padding: 1rem;
            font-size: 1.2rem;
            background: linear-gradient(90deg, #000000, var(--primary-yellow));
            color: white;
            height: var(--header-height);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .list-group-item {
            background: transparent;
            color: #ffffff;
            border: none;
            padding: 1rem 1.2rem;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
            font-size: 1.0rem;
            line-height: 1.4;
            margin: 4px 0;
        }

        .list-group-item:hover {
            background: rgba(255, 215, 0, 0.1);
            color: var(--primary-yellow);
        }

        .list-group-item.active {
            background: var(--primary-yellow);
            color: #000000;
            font-weight: 500;
        }

        .list-group-item i {
            width: 20px;
            text-align: center;
        }

        /* Page Content Wrapper */
        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin var(--transition-speed);
        }

        /* Hamburger menu button styles - for all screen sizes */
        #menu-toggle {
            background: transparent;
            border: none;
            color: white;
            padding: 0.5rem;
            font-size: 1.2rem;
            cursor: pointer;
            transition: color 0.2s;
            display: block;
        }

        #menu-toggle:hover {
            color: var(--primary-yellow);
        }

        /* Navbar Styles */
        .navbar {
            height: var(--header-height);
            background: linear-gradient(90deg, #000000, var(--primary-yellow)) !important;
            padding: 0 1rem;
            position: fixed;
            top: 0;
            right: 0;
            left: var(--sidebar-width);
            z-index: 1030;
            transition: left var(--transition-speed);
        }

        .navbar-brand {
            color: white !important;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link {
            color: white !important;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.2s;
        }

        .nav-link:hover {
            color: var(--primary-yellow) !important;
        }

        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-top: 8px;
            min-width: 200px;
        }

        .dropdown-item {
            padding: 0.7rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }

        .dropdown-item:hover {
            background: var(--light-yellow);
        }

        /* Navbar Semester Selector */
        .navbar-semester-selector {
            margin: 0 1rem;
            display: flex;
            align-items: center;
        }

        .navbar-semester-selector select {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: white !important;
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
            min-width: 200px;
        }

        .navbar-semester-selector select:focus {
            background: rgba(255, 255, 255, 0.2) !important;
            border-color: var(--primary-yellow) !important;
            box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25) !important;
            color: white !important;
        }

        .navbar-semester-selector select option {
            background: #333333 !important;
            color: white !important;
        }

        /* Mobile Styles */
        @media (max-width: 991px) {
            #sidebar-wrapper {
                transform: translateX(-100%);
                width: 80%;
                max-width: var(--sidebar-width);
            }

            #page-content-wrapper {
                margin-left: 0;
            }

            .navbar {
                left: 0;
            }

            .toggled #sidebar-wrapper {
                transform: translateX(0);
            }

            .navbar-collapse {
                background: linear-gradient(90deg, #000000, var(--primary-yellow));
                position: absolute;
                top: var(--header-height);
                left: 0;
                right: 0;
                padding: 1rem;
                z-index: 1030;
                border-radius: 0 0 8px 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }

            .dropdown-menu {
                background: white !important;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
                padding: 0.5rem 0;
                display: none;
                position: absolute;
                transform: none !important;
                float: none;
                min-width: 200px;
                margin-top: 0;
            }

            .dropdown-menu.show {
                display: block !important;
            }

            .dropdown-item {
                color: #000000 !important;
                padding: 0.8rem 1.2rem !important;
            }

            .dropdown-item:hover {
                background: var(--light-yellow) !important;
                color: #000000 !important;
            }

            .navbar-toggler {
                padding: 0.5rem;
                color: white !important;
                border: 1px solid rgba(255, 255, 255, 0.2);
                margin-left: 0.5rem;
            }

            .navbar-toggler:focus {
                box-shadow: none;
                border-color: var(--primary-yellow);
            }

            .nav-item {
                margin: 0.5rem 0;
            }

            .navbar-toggler {
                border: none;
                color: white;
                padding: 0.5rem;
            }

            .navbar-toggler:focus {
                box-shadow: none;
            }

            .dropdown-menu {
                background: white;
                margin-top: 0;
                border-radius: 4px;
                position: static !important;
                transform: none !important;
                box-shadow: none;
            }

            .toggled::after {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1035;
                opacity: 1;
                transition: opacity var(--transition-speed);
            }
        }

        /* Desktop Styles */
        @media (min-width: 992px) {
            /* By default, show the sidebar on desktop */
            #sidebar-wrapper {
                transform: translateX(0);
            }
            
            #page-content-wrapper {
                margin-left: var(--sidebar-width);
            }
            
            /* When toggled, hide the sidebar */
            .toggled #sidebar-wrapper {
                transform: translateX(-100%);
            }
            
            .toggled #page-content-wrapper {
                margin-left: 0;
            }

            .toggled .navbar {
                left: 0;
            }
        }

        /* Tablet Styles */
        @media (min-width: 992px) and (max-width: 1024px) {
            :root {
                --sidebar-width: 200px;
            }

            .sidebar-heading {
                font-size: 1rem;
            }

            .list-group-item {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
        }
    </style>

    <!-- PWA Install Prompt -->
    <script src="<?php echo BASE_URL; ?>/assets/js/pwa-install.js" defer></script>
</head>

<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <i class="fas fa-graduation-cap"></i>
                <span><?php echo $roleTitle; ?></span>
            </div>
            <div class="sidebar-content">
                <div class="list-group list-group-flush">
                    <?php if (isset($sidebarItems[$userRole])): ?>
                        <?php
                        $currentCategory = '';
                        foreach ($sidebarItems[$userRole] as $item):
                            if (isset($item['category']) && $item['category'] !== $currentCategory):
                                $currentCategory = $item['category'];
                        ?>
                                <div class="sidebar-category"><?php echo htmlspecialchars($currentCategory); ?></div>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL . $item['url']; ?>"
                                class="list-group-item list-group-item-action <?php echo basename($item['url']) == $currentPage ? 'active' : ''; ?>">
                                <i class="fas <?php echo $item['icon']; ?>"></i>
                                <span><?php echo $item['text']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg">
                <div class="container-fluid">
                    <!-- Hamburger menu button visible on all screen sizes -->
                    <button id="menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <a class="navbar-brand" href="#">
                        <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <small>(<?php echo ucfirst($userRole); ?>)</small>
                    </a>
                    
                    <!-- Semester Selector for Teachers -->
                    <?php if ($userRole === 'teacher' && $currentSemester && $allSemesters): ?>
                    <div class="navbar-semester-selector">
                        <select id="globalSemesterSelect" class="form-select form-select-sm" onchange="changeGlobalSemester(this.value)">
                            <?php foreach ($allSemesters as $semester): ?>
                                <?php 
                                $selected = $semester['semester_id'] == $currentSemester['semester_id'] ? 'selected' : '';
                                $currentLabel = $semester['is_current'] ? ' (Current)' : '';
                                $trackLabel = $semester['is_double_track'] ? ' - Double Track' : '';
                                ?>
                                <option value="<?php echo $semester['semester_id']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($semester['semester_name']) . $currentLabel . $trackLabel; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#navbarSupportedContent" aria-expanded="false">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="navbar-nav ms-auto">
                            <?php if (isset($sidebarItems[$userRole])): ?>
                                <?php foreach (array_slice($sidebarItems[$userRole], 0, 3) as $item): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" href="<?php echo BASE_URL . $item['url']; ?>">
                                            <i class="fas <?php echo $item['icon']; ?>"></i>
                                            <span><?php echo $item['text']; ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-user"></i>
                                    <span>Profile</span>
                                </a>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item" href="<?php echo BASE_URL . '/' . $userRole; ?>/settings.php">
                                        <i class="fas fa-cog"></i>
                                        <span>Settings</span>
                                    </a>
                                    <a class="dropdown-item" href="<?php echo BASE_URL; ?>/change-password.php">
                                        <i class="fas fa-key"></i>
                                        <span>Change Password</span>
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="<?php echo BASE_URL; ?>/includes/bass/logout.php">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <span>Logout</span>
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="container-fluid" style="padding-top: calc(var(--header-height) + 1rem); margin-top: 0;">
                <!-- Main content goes here -->
         

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const wrapper = document.getElementById('wrapper');
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const pageContent = document.getElementById('page-content-wrapper');

        // Toggle sidebar for all screen sizes
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            wrapper.classList.toggle('toggled');
            
            // For desktop views, adjust the content margin and navbar position
            if (window.innerWidth > 991) {
                const navbar = document.querySelector('.navbar');
                if (wrapper.classList.contains('toggled')) {
                    // Sidebar is hidden
                    pageContent.style.marginLeft = '0';
                    navbar.style.left = '0';
                } else {
                    // Sidebar is visible
                    pageContent.style.marginLeft = 'var(--sidebar-width)';
                    navbar.style.left = 'var(--sidebar-width)';
                }
            }
        });

        // Existing touch and mobile handling code
        let touchStartX = 0;
        let touchEndX = 0;

        // Handle touch swipe for mobile
        document.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, false);

        document.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, false);

        function handleSwipe() {
            const swipeDistance = Math.abs(touchEndX - touchStartX);
            const SWIPE_THRESHOLD = 100;

            if (swipeDistance > SWIPE_THRESHOLD) {
                if (touchEndX > touchStartX) { // Right swipe
                    wrapper.classList.add('toggled');
                } else { // Left swipe
                    wrapper.classList.remove('toggled');
                }
            }
        }

        // Close sidebar when clicking outside on mobile/tablet
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 991 &&
                !sidebar.contains(e.target) &&
                !menuToggle.contains(e.target) &&
                wrapper.classList.contains('toggled')) {
                wrapper.classList.remove('toggled');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const navbar = document.querySelector('.navbar');
            
            if (window.innerWidth > 768) {
                wrapper.classList.remove('toggled');
                
                // Reset sidebar styles on larger screens
                if (window.innerWidth > 991) {
                    sidebar.style.transform = 'translateX(0)';
                    pageContent.style.marginLeft = 'var(--sidebar-width)';
                    navbar.style.left = 'var(--sidebar-width)';
                } else {
                    navbar.style.left = '0';
                }
            }
        });

        // Simple approach: Let Bootstrap handle all dropdowns naturally
        // No custom mobile handling - Bootstrap 5 handles responsive dropdowns well
    });

    // Global semester switching function for teachers
    function changeGlobalSemester(semesterId) {
        // Get current URL and update semester parameter
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('semester', semesterId);

        // Redirect to updated URL
        window.location.href = currentUrl.toString();
    }

    // PWA Service Worker Registration
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            const swPath = '<?php echo BASE_URL; ?>/sw.js';
            console.log('[PWA] Attempting to register service worker at:', swPath);

            navigator.serviceWorker.register(swPath)
                .then(registration => {
                    console.log('[PWA] Service Worker registered successfully:', registration.scope);

                    // Check for updates
                    registration.addEventListener('updatefound', () => {
                        const newWorker = registration.installing;
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                // New version available
                                if (confirm('A new version is available. Reload to update?')) {
                                    newWorker.postMessage({ type: 'SKIP_WAITING' });
                                    window.location.reload();
                                }
                            }
                        });
                    });
                })
                .catch(error => {
                    console.error('Service Worker registration failed:', error);
                });

            // Handle service worker messages
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data.type === 'SYNC_ANSWERS') {
                    // Trigger answer sync if offline manager is available
                    if (window.offlineManager) {
                        window.offlineManager.syncAllPendingAnswers();
                    }
                }
            });
        });
    }
    </script>