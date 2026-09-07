<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['system_admin']);

$u = current_user();
$pageTitle = 'Users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'toggle_active' && $targetId !== $u['id']) {
        $stmt = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?");
        $stmt->execute([$targetId]);
        log_action($pdo, $u['id'], 'Toggled user active state', 'user', $targetId);
        flash_set('success', 'User status updated.');
    }

    if ($action === 'change_role' && $targetId !== $u['id']) {
        $role = $_POST['role'] ?? 'researcher';
        if (in_array($role, ['system_admin','secretariat','chairperson','member','external_consultant','researcher'], true)) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $targetId]);
            log_action($pdo, $u['id'], 'Changed user role to ' . $role, 'user', $targetId);
            flash_set('success', 'Role updated.');
        }
    }

    if ($action === 'create_user') {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'researcher';
        $dept = trim($_POST['department'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($name && $email && $pass && in_array($role, ['system_admin','secretariat','chairperson','member','external_consultant','researcher'], true)) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                flash_set('error', 'A user with this email already exists.');
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO users (full_name, email, password, role, department) VALUES (?,?,?,?,?)");
                $ins->execute([$name, $email, $hash, $role, $dept]);
                log_action($pdo, $u['id'], 'Created user', 'user', (int)$pdo->lastInsertId());
                flash_set('success', 'User created.');
            }
        } else {
            flash_set('error', 'Please fill in all required fields.');
        }
    }

    header('Location: ' . BASE_URL . 'users/list.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">Administration</div>
    <h1>User Accounts</h1>
  </div>
  <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#newUserModal"><i class="bi bi-plus-lg"></i> Add user</button>
</div>

<?php flash_render(); ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th></th><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th style="width:260px;"></th></tr></thead>
      <tbody>
        <?php foreach ($users as $row): ?>
        <tr>
          <td><img src="<?= avatar_url($row['photo_path'], $row['full_name']) ?>" class="rounded-circle" width="32" height="32" alt=""></td>
          <td><?= e($row['full_name']) ?></td>
          <td><?= e($row['email']) ?></td>
          <td>
            <?php if ((int)$row['id'] !== (int)$u['id']): ?>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
              <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach (['system_admin','secretariat','chairperson','member','external_consultant','researcher'] as $r): ?>
                  <option value="<?= $r ?>" <?= $row['role']===$r?'selected':'' ?>><?= role_label($r) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
              <?= role_label($row['role']) ?> <span class="text-muted small">(you)</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['department'] ?: '—') ?></td>
          <td><?= $row['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></td>
          <td>
            <?php if ((int)$row['id'] !== (int)$u['id']): ?>
            <form method="post" data-confirm="Change active status for this user?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><?= $row['is_active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New user modal -->
<div class="modal fade" id="newUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <div class="modal-header">
          <h5 class="modal-title">Add a new user</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Full name</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option value="researcher">Researcher (PI)</option>
              <option value="member">IRB Member</option>
              <option value="external_consultant">External Consultant (ad-hoc reviewer)</option>
              <option value="chairperson">IRB Chairperson</option>
              <option value="secretariat">IRB Secretariat</option>
              <option value="system_admin">System Administrator</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Department</label>
            <input type="text" name="department" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label">Temporary password</label>
            <input type="text" name="password" class="form-control" required minlength="8">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Create user</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
