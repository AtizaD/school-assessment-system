
<?php
// index.php
define('BASEPATH', dirname(__FILE__));
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Require login for this page
requireLogin();

// Get user's role and redirect if not already on correct dashboard
$role = $_SESSION['user_role'];
$currentPage = $_SERVER['PHP_SELF'];

if ($currentPage === '/index.php') {
    switch ($role) {
        case 'admin':
            redirectTo('/admin/index.php');
            break;
        case 'teacher':
            redirectTo('/teacher/index.php');
            break;
        case 'student':
            redirectTo('/student/index.php');
            break;
    }
}

// Include header
$pageTitle = 'Dashboard';
require_once INCLUDES_PATH . '/bass/base_header.php';

?>

<main class="main-content">
    <h1>Welcome to <?php echo SYSTEM_NAME; ?></h1>
    <p>Please use the navigation menu to access your features.</p>
</main>

<?php
require_once INCLUDES_PATH . '/bass/base_footer.php';
?>a