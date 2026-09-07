<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$pageTitle = 'Compose Message';
$error = null;

// Recipients: everyone except yourself. Researchers typically write to the Secretariat/Chair.
$recipients = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id != ? AND is_active = 1 ORDER BY role, full_name");
$recipients->execute([$u['id']]);
$recipients = $recipients->fetchAll();

// Protocols this user is allowed to attach a message to
if ($u['role'] === 'researcher') {
    $stmt = $pdo->prepare("SELECT id, protocol_code, title FROM protocols WHERE pi_id = ? ORDER BY submission_date DESC");
    $stmt->execute([$u['id']]);
} elseif (in_array($u['role'], ['member','external_consultant'], true)) {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.protocol_code, p.title FROM protocols p
         JOIN protocol_reviewers pr ON pr.protocol_id = p.id
         WHERE pr.reviewer_id = ? ORDER BY p.submission_date DESC"
    );
    $stmt->execute([$u['id']]);
} else {
    $stmt = $pdo->query("SELECT id, protocol_code, title FROM protocols ORDER BY submission_date DESC");
}
$protocols = $stmt->fetchAll();

$prefillProtocol = (int)($_GET['protocol_id'] ?? 0);
$prefillRecipient = (int)($_GET['recipient_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $protocolId = (int)($_POST['protocol_id'] ?? 0) ?: null;
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$recipientId || $subject === '' || $message === '') {
        $error = 'Please choose a recipient and fill in both the subject and message.';
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO communications (protocol_id, sender_id, recipient_id, subject, message) VALUES (?,?,?,?,?)"
        );
        $ins->execute([$protocolId, $u['id'], $recipientId, $subject, $message]);
        log_action($pdo, $u['id'], 'Sent message', 'communication', (int)$pdo->lastInsertId());
        flash_set('success', 'Message sent.');
        header('Location: ' . BASE_URL . 'communications/list.php?tab=sent');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">New message</div>
    <h1>Compose a Message</h1>
  </div>
  <a href="<?= BASE_URL ?>communications/list.php" class="btn btn-outline-primary btn-sm">Back to messages</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">To</label>
        <select name="recipient_id" class="form-select" required>
          <option value="">Select a recipient…</option>
          <?php foreach ($recipients as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $prefillRecipient === (int)$r['id'] ? 'selected' : '' ?>>
              <?= e($r['full_name']) ?> (<?= role_label($r['role']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Related protocol (optional)</label>
        <select name="protocol_id" class="form-select">
          <option value="">Not linked to a specific protocol</option>
          <?php foreach ($protocols as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $prefillProtocol === (int)$p['id'] ? 'selected' : '' ?>>
              <?= e($p['protocol_code']) ?> — <?= e($p['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Subject</label>
        <input type="text" name="subject" class="form-control" required value="<?= e($_POST['subject'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Message</label>
        <textarea name="message" class="form-control" rows="6" required><?= e($_POST['message'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Send message</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
