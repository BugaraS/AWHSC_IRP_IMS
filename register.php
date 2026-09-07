<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$error = null;
$values = ['full_name' => '', 'email' => '', 'department' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $values = [
        'full_name'  => trim($_POST['full_name'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'department' => trim($_POST['department'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
    ];
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($values['full_name'] === '' || $values['email'] === '' || $password === '') {
        $error = 'Full name, email, and password are required.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$values['email']]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, password, role, department, phone) VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([$values['full_name'], $values['email'], $hash, 'researcher', $values['department'], $values['phone']]);
            log_action($pdo, (int)$pdo->lastInsertId(), 'Researcher self-registered');
            flash_set('success', 'Account created. You can now sign in.');
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | AWHSC-IRB MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-wrap">
<div class="auth-card" style="max-width:520px;">
  <div class="auth-seal">IRB</div>
  <h4 class="text-center display-font mb-1">Researcher Registration</h4>
  <p class="text-center text-eyebrow mb-4">Submit and track protocols online</p>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Full name</label>
      <input type="text" name="full_name" class="form-control" required value="<?= e($values['full_name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Email address</label>
      <input type="email" name="email" class="form-control" required value="<?= e($values['email']) ?>">
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Department / School</label>
        <input type="text" name="department" class="form-control" value="<?= e($values['department']) ?>">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= e($values['phone']) ?>">
      </div>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required minlength="8">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Confirm password</label>
        <input type="password" name="confirm" class="form-control" required minlength="8">
      </div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Create account</button>
  </form>

  <p class="text-center mt-3 mb-0"><small>Already have an account? <a href="<?= BASE_URL ?>login.php">Sign in</a></small></p>
</div>
</body>
</html>
