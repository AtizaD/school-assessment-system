<?php
// student/payment.php - Redirect to main payment page
// This is a compatibility redirect for users accessing payment through student directory

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__));
}
require_once BASEPATH . '/config/config.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Ensure user is logged in
requireLogin();

// Redirect to the main payment page
redirectTo('payment.php');
?>