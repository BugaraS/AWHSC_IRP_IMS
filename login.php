<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $error = login_throttle_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($error) {
        // throttled - fall through and show message
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            login_throttle_hit();
            $error = 'Invalid email or password.';
        } elseif (!$user['is_active']) {
            $error = 'This account has been deactivated. Contact the IRB Secretariat.';
        } else {
            login_throttle_reset();
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_photo'] = $user['photo_path'];
            log_action($pdo, $user['id'], 'Logged in');
            header('Location: ' . BASE_URL . 'dashboard.php');
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
<title>Sign in | AWHSC-IRB MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-wrap">
<div class="auth-card">
  <div class="auth-seal">IRB</div>
  <h4 class="text-center display-font mb-1">AWHSC-IRB</h4>
  <p class="text-center text-eyebrow mb-4">Management Information System</p>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Email address</label>
      <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>
  </form>

  <p class="text-center mt-3 mb-0">
    <small>New researcher? <a href="<?= BASE_URL ?>register.php">Create an account</a></small>
  </p>
  <p class="text-center mt-1 mb-0">
    <small class="text-muted">First time running this app? <a href="<?= BASE_URL ?>setup.php">Create demo accounts</a></small>
  </p>
</div>
</body>
</html>
