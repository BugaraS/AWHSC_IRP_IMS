<?php
/**
 * ONE-TIME SETUP SCRIPT
 * Run this once in your browser after importing sql/awhsc_irb.sql:
 *   http://localhost/awhsc_irb_mis/setup.php
 *
 * It creates demo accounts for every role using PHP's own password_hash(),
 * then writes a lock file so it can't be run again by accident.
 * You can safely delete this file after running it.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$lockFile = __DIR__ . '/setup.lock';

$demoAccounts = [
    ['System Administrator', 'sysadmin@awhsc-irb.dbu.edu.et', 'system_admin', 'IT / MIS Directorate', 'Systems Administration'],
    ['Ato Getachew Molla', 'secretariat@awhsc-irb.dbu.edu.et', 'secretariat', 'IRB Secretariat', 'Research Administration'],
    ['Dr. Awraris Hailu', 'chair@awhsc-irb.dbu.edu.et', 'chairperson', 'IRB', 'Public Health'],
    ['Dr. Ermyas Endwnetu', 'member1@awhsc-irb.dbu.edu.et', 'member', 'Internal Medicine', 'Internal Medicine'],
    ['Dr. Tizazu Zenebe', 'member2@awhsc-irb.dbu.edu.et', 'member', 'Medical Microbiology', 'Medical Microbiology'],
    ['Dr. Selamawit Bekele (External)', 'consultant@example-university.edu', 'external_consultant', 'Partner University', 'Biostatistics'],
    ['Test Researcher', 'researcher@dbu.edu.et', 'researcher', 'School of Nursing', 'Maternal Health'],
];
$demoPassword = 'Passw0rd!';

$done = false;
$error = null;

if (file_exists($lockFile)) {
    $error = 'Setup has already been run. Delete setup.lock (and this file) if you need to re-run it manually.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $hash = password_hash($demoPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, role, department, specialization)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE password = VALUES(password)"
        );
        foreach ($demoAccounts as [$name, $email, $role, $dept, $spec]) {
            $stmt->execute([$name, $email, $hash, $role, $dept, $spec]);
        }
        file_put_contents($lockFile, 'Setup completed on ' . date('c'));
        $done = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup | AWHSC-IRB MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-wrap">
<div class="auth-card">
  <div class="auth-seal">IRB</div>
  <h4 class="text-center mb-3">First-time setup</h4>

  <?php if ($done): ?>
    <div class="alert alert-success">Demo accounts created successfully.</div>
    <p class="mb-1"><strong>Password for all demo accounts:</strong> <code><?= htmlspecialchars($demoPassword) ?></code></p>
    <table class="table table-sm mt-2">
      <thead><tr><th>Role</th><th>Email</th></tr></thead>
      <tbody>
        <?php foreach ($demoAccounts as [$name,$email,$role,$dept,$spec]): ?>
        <tr><td><?= htmlspecialchars(role_label($role)) ?></td><td><code><?= htmlspecialchars($email) ?></code></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a class="btn btn-primary w-100 mt-2" href="login.php">Go to login</a>
    <p class="text-muted small mt-3 mb-0">For security, delete <code>setup.php</code> and <code>setup.lock</code> once you're done exploring.</p>
  <?php elseif ($error): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($error) ?></div>
    <a class="btn btn-outline-primary w-100" href="login.php">Go to login</a>
  <?php else: ?>
    <p class="text-muted">This will create one demo account for each of the six roles (System Administrator, Secretariat, Chairperson, two Members, an External Consultant, and a Researcher) with the password <code><?= htmlspecialchars($demoPassword) ?></code>, using PHP's password_hash() so it matches your server exactly.</p>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-primary w-100" type="submit">Create demo accounts</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
