<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Continuing Review';

// Auto-flag anything past due as overdue for display purposes
$pdo->exec("UPDATE continuing_reviews SET status='overdue' WHERE status='pending' AND due_date < CURDATE()");

$rows = $pdo->query(
    "SELECT cr.*, p.protocol_code, p.title, p.status AS protocol_status, us.full_name AS pi_name
     FROM continuing_reviews cr
     JOIN protocols p ON p.id = cr.protocol_id
     JOIN users us ON us.id = p.pi_id
     ORDER BY FIELD(cr.status,'overdue','submitted','pending','reviewed'), cr.due_date ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-014</div>
    <h1>Continuing Review</h1>
  </div>
</div>

<?php flash_render(); ?>

<p class="text-muted">Deadlines are set from each protocol's page. This view gives the Secretariat and Chair a single, sortable list of what's due, overdue, or awaiting a decision.</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Protocol</th><th>PI</th><th>Due date</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No continuing review deadlines have been scheduled yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="protocol-code"><?= e($r['protocol_code']) ?></span> <?= e($r['title']) ?></td>
          <td><?= e($r['pi_name']) ?></td>
          <td><?= date('d M Y', strtotime($r['due_date'])) ?></td>
          <td>
            <?php
              $color = ['pending'=>'secondary','submitted'=>'info','reviewed'=>'success','overdue'=>'danger'][$r['status']] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $color ?>"><?= ucfirst($r['status']) ?></span>
          </td>
          <td><?= $r['submitted_date'] ? date('d M Y', strtotime($r['submitted_date'])) : '—' ?></td>
          <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $r['protocol_id'] ?>" class="btn btn-sm btn-outline-primary">Open protocol</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
