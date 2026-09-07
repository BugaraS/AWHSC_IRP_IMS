<?php
/**
 * AWHSC-IRB MIS - Database Connection
 * XAMPP default MySQL settings: host=localhost, user=root, password=""
 * Edit these four constants only if your XAMPP setup differs.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'awhsc_irb_mis');
define('DB_USER', 'root');
define('DB_PASS', '');

// Base URL of the app - used for links/redirects. Adjust if you rename the folder.
define('BASE_URL', '/awhsc_irb_mis/');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die(
        '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;' .
        'border:1px solid #e2a03f;background:#fff8ec;border-radius:8px;color:#7a4a00;">' .
        '<h2 style="margin-top:0;">Database connection failed</h2>' .
        '<p>Could not connect to MySQL/phpMyAdmin. In XAMPP:</p>' .
        '<ol><li>Start <strong>Apache</strong> and <strong>MySQL</strong> in the XAMPP Control Panel.</li>' .
        '<li>Open phpMyAdmin and import <code>sql/awhsc_irb.sql</code>.</li>' .
        '<li>Check the DB_HOST / DB_USER / DB_PASS values in <code>config/db.php</code>.</li></ol>' .
        '<p style="color:#a33;">Technical detail: ' . htmlspecialchars($e->getMessage()) . '</p></div>'
    );
}
