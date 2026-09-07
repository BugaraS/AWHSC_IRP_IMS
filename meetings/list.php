<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson','member']);

$u = current_user();
$pageTitle = 'Meetings';

$meetings = $pdo->query(
    "SELECT m.*, u.full_name AS created_by_name,
     (SELECT COUNT(*) FROM meeting_agenda_items WHERE meeting_id = m.id) AS agenda_count
     FROM meetings m JOIN users u ON u.id = m.created_by
     ORDER BY m.meeting_date DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-021 / 022</div>
    <h1>IRB Meetings</h1>
  </div>
  <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
    <a href="<?= BASE_URL ?>meetings/create.php" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Schedule meeting</a>
  <?php endif; ?>
</div>

<?php flash_render(); ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Type</th><th>Venue</th><th>Agenda Items</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$meetings): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No meetings scheduled yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($meetings as $m): ?>
        <tr>
          <td><?= date('d M Y, H:i', strtotime($m['meeting_date'])) ?></td>
          <td><?= $m['meeting_type']==='emergency' ? '<span class="badge bg-danger">Emergency</span>' : '<span class="badge bg-secondary">Regular</span>' ?></td>
          <td><?= e($m['venue'] ?: '—') ?></td>
          <td><?= (int)$m['agenda_count'] ?></td>
          <td><span class="badge bg-<?= $m['status']==='completed'?'success':($m['status']==='cancelled'?'dark':'primary') ?>"><?= ucfirst($m['status']) ?></span></td>
          <td><a href="<?= BASE_URL ?>meetings/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
