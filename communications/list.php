<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$pageTitle = 'Messages';
$tab = $_GET['tab'] ?? 'inbox';

if ($tab === 'sent') {
    $stmt = $pdo->prepare(
        "SELECT c.*, r.full_name AS other_name, p.protocol_code
         FROM communications c
         JOIN users r ON r.id = c.recipient_id
         LEFT JOIN protocols p ON p.id = c.protocol_id
         WHERE c.sender_id = ? ORDER BY c.sent_at DESC"
    );
} else {
    $tab = 'inbox';
    $stmt = $pdo->prepare(
        "SELECT c.*, s.full_name AS other_name, p.protocol_code
         FROM communications c
         JOIN users s ON s.id = c.sender_id
         LEFT JOIN protocols p ON p.id = c.protocol_id
         WHERE c.recipient_id = ? ORDER BY c.sent_at DESC"
    );
}
$stmt->execute([$u['id']]);
$messages = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB communication records</div>
    <h1>Messages</h1>
  </div>
  <a href="<?= BASE_URL ?>communications/create.php" class="btn btn-gold btn-sm"><i class="bi bi-pencil"></i> Compose</a>
</div>

<?php flash_render(); ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='inbox'?'active':'' ?>" href="?tab=inbox">Inbox</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='sent'?'active':'' ?>" href="?tab=sent">Sent</a></li>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th><?= $tab === 'sent' ? 'To' : 'From' ?></th>
          <th>Subject</th>
          <th>Protocol</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$messages): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No messages here yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($messages as $m): ?>
        <tr class="<?= ($tab==='inbox' && !$m['read_at']) ? 'fw-bold' : '' ?>">
          <td><?= e($m['other_name']) ?></td>
          <td><?= e($m['subject']) ?></td>
          <td><?= $m['protocol_code'] ? '<span class="protocol-code">' . e($m['protocol_code']) . '</span>' : '—' ?></td>
          <td><?= date('d M Y, H:i', strtotime($m['sent_at'])) ?></td>
          <td><a href="<?= BASE_URL ?>communications/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
