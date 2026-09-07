<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT c.*, s.full_name AS sender_name, s.role AS sender_role,
            r.full_name AS recipient_name, r.role AS recipient_role,
            p.protocol_code, p.title AS protocol_title
     FROM communications c
     JOIN users s ON s.id = c.sender_id
     JOIN users r ON r.id = c.recipient_id
     LEFT JOIN protocols p ON p.id = c.protocol_id
     WHERE c.id = ?"
);
$stmt->execute([$id]);
$message = $stmt->fetch();

if (!$message || ((int)$message['sender_id'] !== (int)$u['id'] && (int)$message['recipient_id'] !== (int)$u['id'])) {
    http_response_code(404);
    die('Message not found.');
}

// Mark as read the moment the recipient opens it
if ((int)$message['recipient_id'] === (int)$u['id'] && !$message['read_at']) {
    $pdo->prepare("UPDATE communications SET read_at = NOW() WHERE id = ?")->execute([$id]);
    $message['read_at'] = date('Y-m-d H:i:s');
}

$pageTitle = 'Message';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $reply = trim($_POST['reply'] ?? '');
    if ($reply === '') {
        $error = 'Please write a reply before sending.';
    } else {
        $toId = (int)$message['sender_id'] === (int)$u['id'] ? $message['recipient_id'] : $message['sender_id'];
        $subject = (stripos($message['subject'], 'Re:') === 0) ? $message['subject'] : ('Re: ' . $message['subject']);
        $ins = $pdo->prepare(
            "INSERT INTO communications (protocol_id, sender_id, recipient_id, subject, message) VALUES (?,?,?,?,?)"
        );
        $ins->execute([$message['protocol_id'], $u['id'], $toId, $subject, $reply]);
        log_action($pdo, $u['id'], 'Replied to message', 'communication', (int)$pdo->lastInsertId());
        flash_set('success', 'Reply sent.');
        header('Location: ' . BASE_URL . 'communications/list.php?tab=sent');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">Message</div>
    <h1><?= e($message['subject']) ?></h1>
  </div>
  <a href="<?= BASE_URL ?>communications/list.php" class="btn btn-outline-primary btn-sm">Back to messages</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <p class="mb-1"><strong>From:</strong> <?= e($message['sender_name']) ?> (<?= role_label($message['sender_role']) ?>)</p>
    <p class="mb-1"><strong>To:</strong> <?= e($message['recipient_name']) ?> (<?= role_label($message['recipient_role']) ?>)</p>
    <?php if ($message['protocol_code']): ?>
      <p class="mb-1"><strong>Protocol:</strong>
        <a href="<?= BASE_URL ?>protocols/view.php?id=<?= $message['protocol_id'] ?>"><span class="protocol-code"><?= e($message['protocol_code']) ?></span> <?= e($message['protocol_title']) ?></a>
      </p>
    <?php endif; ?>
    <p class="text-muted small mb-3">Sent <?= date('d M Y, H:i', strtotime($message['sent_at'])) ?></p>
    <hr>
    <p style="white-space:pre-wrap;"><?= e($message['message']) ?></p>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h6 class="card-title">Reply</h6>
    <form method="post">
      <?= csrf_field() ?>
      <textarea name="reply" class="form-control mb-2" rows="4" placeholder="Write a reply…" required></textarea>
      <button type="submit" class="btn btn-primary btn-sm">Send reply</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
