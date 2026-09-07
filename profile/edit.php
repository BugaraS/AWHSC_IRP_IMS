<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$pageTitle = 'My Profile';
$error = null;
$success = null;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$u['id']]);
$account = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_details') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');

        if ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users SET full_name=?, phone=?, department=?, specialization=? WHERE id=?"
            );
            $stmt->execute([$fullName, $phone, $department, $specialization, $u['id']]);
            $_SESSION['user_name'] = $fullName;
            log_action($pdo, $u['id'], 'Updated own profile', 'user', $u['id']);
            flash_set('success', 'Profile updated.');
            header('Location: ' . BASE_URL . 'profile/edit.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $account['password'])) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([$hash, $u['id']]);
            log_action($pdo, $u['id'], 'Changed own password', 'user', $u['id']);
            flash_set('success', 'Password changed successfully.');
            header('Location: ' . BASE_URL . 'profile/edit.php');
            exit;
        }
    }

    if ($action === 'upload_photo') {
        if (empty($_FILES['photo']['name'])) {
            $error = 'Please choose an image to upload.';
        } else {
            $allowed = ['jpg','jpeg','png'];
            $maxBytes = 2 * 1024 * 1024; // 2 MB
            if (!is_allowed_upload($_FILES['photo']['tmp_name'], $_FILES['photo']['name'], $allowed, $maxBytes, $ext)) {
                $error = 'Please upload a JPG or PNG image up to 2 MB.';
            } else {
                $dir = __DIR__ . '/../uploads/avatars';
                if (!is_dir($dir)) { mkdir($dir, 0775, true); }

                // Remove any previous photo for this user before saving the new one
                if (!empty($account['photo_path'])) {
                    $old = __DIR__ . '/../' . $account['photo_path'];
                    if (is_file($old)) { @unlink($old); }
                }

                $safeName = 'user' . $u['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dest = $dir . '/' . $safeName;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                    $rel = 'uploads/avatars/' . $safeName;
                    $stmt = $pdo->prepare("UPDATE users SET photo_path=? WHERE id=?");
                    $stmt->execute([$rel, $u['id']]);
                    $_SESSION['user_photo'] = $rel;
                    log_action($pdo, $u['id'], 'Updated profile photo', 'user', $u['id']);
                    flash_set('success', 'Profile photo updated.');
                    header('Location: ' . BASE_URL . 'profile/edit.php');
                    exit;
                } else {
                    $error = 'Could not save the uploaded photo. Please try again.';
                }
            }
        }
    }

    if ($action === 'remove_photo' && !empty($account['photo_path'])) {
        $old = __DIR__ . '/../' . $account['photo_path'];
        if (is_file($old)) { @unlink($old); }
        $stmt = $pdo->prepare("UPDATE users SET photo_path=NULL WHERE id=?");
        $stmt->execute([$u['id']]);
        $_SESSION['user_photo'] = null;
        log_action($pdo, $u['id'], 'Removed profile photo', 'user', $u['id']);
        flash_set('success', 'Profile photo removed.');
        header('Location: ' . BASE_URL . 'profile/edit.php');
        exit;
    }

    if ($action === 'acknowledge_confidentiality' && $u['role'] === 'external_consultant') {
        $stmt = $pdo->prepare("UPDATE users SET confidentiality_ack_at = NOW() WHERE id = ?");
        $stmt->execute([$u['id']]);
        log_action($pdo, $u['id'], 'Acknowledged confidentiality agreement', 'user', $u['id']);
        flash_set('success', 'Thank you — you can now review assigned protocols.');
        header('Location: ' . BASE_URL . 'profile/edit.php');
        exit;
    }

    // Refresh in case of validation error above
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$u['id']]);
    $account = $stmt->fetch();
}

// Recent activity for this user (their own audit trail)
$activity = $pdo->prepare("SELECT * FROM audit_log WHERE user_id=? ORDER BY created_at DESC LIMIT 15");
$activity->execute([$u['id']]);
$myActivity = $activity->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">Account</div>
    <h1>My Profile</h1>
  </div>
  <div><?= role_label($account['role']) ?></div>
</div>

<?php flash_render(); ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<p class="text-muted"><?= e(role_description($account['role'])) ?></p>

<?php if ($account['role'] === 'external_consultant' && empty($account['confidentiality_ack_at'])): ?>
<div class="card mb-4 border-danger">
  <div class="card-body">
    <h5 class="card-title text-danger">Confidentiality Agreement</h5>
    <p class="mb-3">As an External Consultant, you will see confidential research protocols that are not
       yet public. By acknowledging below, you agree to keep all protocol content, applicant information,
       and committee discussions strictly confidential, and to use them only for the purpose of the review
       you have been asked to complete.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="acknowledge_confidentiality">
      <button type="submit" class="btn btn-danger">I acknowledge and agree</button>
    </form>
  </div>
</div>
<?php elseif ($account['role'] === 'external_consultant'): ?>
<div class="alert alert-success">Confidentiality agreement acknowledged on <?= date('d M Y', strtotime($account['confidentiality_ack_at'])) ?>.</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card mb-4">
      <div class="card-body text-center">
        <h5 class="card-title text-start">Profile photo</h5>
        <img src="<?= avatar_url($account['photo_path'], $account['full_name']) ?>" class="rounded-circle mb-3" width="120" height="120" alt="Profile photo">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 justify-content-center flex-wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_photo">
          <input type="file" name="photo" accept=".jpg,.jpeg,.png" class="form-control form-control-sm" style="max-width:220px;" required>
          <button type="submit" class="btn btn-sm btn-primary">Upload</button>
        </form>
        <?php if (!empty($account['photo_path'])): ?>
          <form method="post" class="mt-2" data-confirm="Remove your profile photo?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_photo">
            <button type="submit" class="btn btn-sm btn-outline-danger">Remove photo</button>
          </form>
        <?php endif; ?>
        <p class="text-muted small mt-2 mb-0">JPG or PNG, up to 2 MB.</p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Profile details</h5>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_details">
          <div class="mb-3">
            <label class="form-label">Full name</label>
            <input type="text" name="full_name" class="form-control" required value="<?= e($account['full_name']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Email address</label>
            <input type="email" class="form-control" value="<?= e($account['email']) ?>" disabled>
            <div class="form-text">Contact the Secretariat/Admin to change your email address.</div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?= e($account['phone']) ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label"><?= $account['role'] === 'external_consultant' ? 'Affiliation (institution/organization)' : 'Department / School' ?></label>
              <input type="text" name="department" class="form-control" value="<?= e($account['department']) ?>">
            </div>
          </div>
          <?php if (in_array($account['role'], ['member','chairperson','external_consultant'], true)): ?>
          <div class="mb-3">
            <label class="form-label">Specialization</label>
            <input type="text" name="specialization" class="form-control" value="<?= e($account['specialization']) ?>">
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Change password</h5>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3">
            <label class="form-label">Current password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">New password</label>
              <input type="password" name="new_password" class="form-control" required minlength="8">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Confirm new password</label>
              <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>
          </div>
          <button type="submit" class="btn btn-outline-primary">Change password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">My recent activity</h5>
        <?php if (!$myActivity): ?>
          <p class="text-muted mb-0">No activity recorded yet.</p>
        <?php else: ?>
          <ul class="timeline mb-0">
            <?php foreach ($myActivity as $a): ?>
              <li>
                <?= e($a['action']) ?>
                <?php if ($a['entity']): ?><span class="text-muted small"> — <?= e($a['entity']) ?> #<?= (int)$a['entity_id'] ?></span><?php endif; ?>
                <div class="text-muted small"><?= date('d M Y, H:i', strtotime($a['created_at'])) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
