<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    log_action($pdo, $_SESSION['user_id'], 'Logged out');
}
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . 'login.php');
exit;
