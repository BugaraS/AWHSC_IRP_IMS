<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
header('Location: ' . BASE_URL . (is_logged_in() ? 'dashboard.php' : 'login.php'));
exit;
