<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Monitoring';

$rows = $pdo->query(
    "SELECT mv.*, p.protocol_code, p.title, m.full_name AS monitor_name
     FROM monitoring_visits mv
     JOIN protocols p ON p.id = mv.protocol_id
     JOIN users m ON m.id = mv.monitor_id
     ORDER BY mv.visit_date DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-018</div>
    <h1>Protocol Implementation Monitoring</h1>
  </div>
  <a href="<?= BASE_URL ?>monitoring/create.php" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Record a visit</a>
</div>

<?php flash_render(); ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Protocol</th><th>Visit date</th><th>Monitor</th><th>Compliance</th><th>Findings</th><th></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No monitoring visits recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="protocol-code"><?= e($r['protocol_code']) ?></span> <?= e($r['title']) ?></td>
          <td><?= date('d M Y', strtotime($r['visit_date'])) ?></td>
          <td><?= e($r['monitor_name']) ?></td>
          <td>
            <?php
              $color = [
                'compliant' => 'success',
                'minor_findings' => 'warning',
                'major_findings' => 'danger',
                'non_compliant' => 'dark',
              ][$r['compliance_status']] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $color ?>"><?= ucwords(str_replace('_',' ',$r['compliance_status'])) ?></span>
          </td>
          <td><?= e(mb_strimwidth($r['findings'] ?? '', 0, 60, '…')) ?></td>
          <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $r['protocol_id'] ?>" class="btn btn-sm btn-outline-primary">Open protocol</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
