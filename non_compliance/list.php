<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Non-Compliance';

$rows = $pdo->query(
    "SELECT nc.*, p.protocol_code, p.title, rb.full_name AS reported_by_name
     FROM non_compliance_reports nc
     JOIN protocols p ON p.id = nc.protocol_id
     JOIN users rb ON rb.id = nc.reported_by
     ORDER BY FIELD(nc.status,'open','under_investigation','resolved','closed'),
              FIELD(nc.severity,'serious','moderate','minor'), nc.created_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-020</div>
    <h1>Non-Compliance &amp; Violation Management</h1>
  </div>
</div>

<?php flash_render(); ?>

<p class="text-muted">Reports are logged from a protocol's page by the Secretariat, Chair, or an assigned reviewer. Update the status and resolution from the protocol page this is a queue of everything by severity and status.</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Protocol</th><th>Reported by</th><th>Issue</th><th>Severity</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No non-compliance reports logged.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="protocol-code"><?= e($r['protocol_code']) ?></span> <?= e($r['title']) ?></td>
          <td><?= e($r['reported_by_name']) ?></td>
          <td><?= e(mb_strimwidth($r['description'], 0, 70, '…')) ?></td>
          <td>
            <?php $sc = ['minor'=>'secondary','moderate'=>'warning','serious'=>'danger'][$r['severity']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $sc ?>"><?= ucfirst($r['severity']) ?></span>
          </td>
          <td>
            <?php $stc = ['open'=>'danger','under_investigation'=>'warning','resolved'=>'success','closed'=>'dark'][$r['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $stc ?>"><?= ucwords(str_replace('_',' ',$r['status'])) ?></span>
          </td>
          <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $r['protocol_id'] ?>" class="btn btn-sm btn-outline-primary">Open protocol</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
