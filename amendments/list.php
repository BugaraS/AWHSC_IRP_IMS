<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Amendments';

$rows = $pdo->query(
    "SELECT a.*, p.protocol_code, p.title, us.full_name AS submitted_by_name
     FROM amendments a
     JOIN protocols p ON p.id = a.protocol_id
     JOIN users us ON us.id = a.submitted_by
     ORDER BY FIELD(a.status,'pending','approved','rejected'), a.submitted_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-015</div>
    <h1>Protocol Amendments</h1>
  </div>
</div>

<?php flash_render(); ?>

<p class="text-muted">Researchers submit amendment requests from a protocol's page.</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Protocol</th><th>Requested by</th><th>Summary</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No amendment requests yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="protocol-code"><?= e($r['protocol_code']) ?></span> <?= e($r['title']) ?></td>
          <td><?= e($r['submitted_by_name']) ?></td>
          <td><?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?></td>
          <td>
            <?php $color = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$r['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $color ?>"><?= ucfirst($r['status']) ?></span>
          </td>
          <td><?= date('d M Y', strtotime($r['submitted_at'])) ?></td>
          <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $r['protocol_id'] ?>" class="btn btn-sm btn-outline-primary">Open protocol</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
