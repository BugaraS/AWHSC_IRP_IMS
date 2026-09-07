<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();

// System Admin manages accounts/infrastructure, not confidential research content.
if ($u['role'] === 'system_admin') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;max-width:600px;margin:80px auto;text-align:center;">
            <h2>403 - Not applicable to this role</h2>
            <p>System Administrator accounts do not have access to the protocol registry. This keeps research and ethics content visible only to IRB roles (Secretariat, Chairperson, Members, External Consultants, Researchers).</p>
            <a href="' . BASE_URL . 'dashboard.php">Return to dashboard</a>
         </div>');
}

$pageTitle = 'Protocols';

$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$archivedFilter = $_GET['archived'] ?? '';

$where = [];
$params = [];

if ($u['role'] === 'researcher') {
    $where[] = 'p.pi_id = ?';
    $params[] = $u['id'];
} elseif (in_array($u['role'], ['member','external_consultant'], true)) {
    $where[] = 'pr.reviewer_id = ?';
    $params[] = $u['id'];
}

if ($statusFilter !== '') {
    $where[] = 'p.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.protocol_code LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($archivedFilter === 'archived') {
    $where[] = 'p.archived = 1';
} elseif ($archivedFilter !== 'all') {
    $where[] = 'p.archived = 0';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$joinReviewer = in_array($u['role'], ['member','external_consultant'], true) ? 'JOIN protocol_reviewers pr ON pr.protocol_id = p.id' : '';

$sql = "SELECT DISTINCT p.*, us.full_name AS pi_name
        FROM protocols p
        JOIN users us ON us.id = p.pi_id
        $joinReviewer
        $whereSql
        ORDER BY p.submission_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$protocols = $stmt->fetchAll();

$statuses = ['submitted','under_screening','under_review','revision_required','deferred','resubmitted','approved','rejected','withdrawn','closed'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">Registry</div>
    <h1>Protocols</h1>
  </div>
  <?php if ($u['role'] === 'researcher'): ?>
    <a href="<?= BASE_URL ?>protocols/submit.php" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Submit new protocol</a>
  <?php endif; ?>
</div>

<?php flash_render(); ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4">
    <input type="text" class="form-control" name="q" placeholder="Search by title or code" value="<?= e($search) ?>">
  </div>
  <div class="col-md-3">
    <select class="form-select" name="status">
      <option value="">All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <select class="form-select" name="archived">
      <option value="active" <?= $archivedFilter !== 'archived' && $archivedFilter !== 'all' ? 'selected' : '' ?>>Active studies</option>
      <option value="archived" <?= $archivedFilter === 'archived' ? 'selected' : '' ?>>Archived studies</option>
      <option value="all" <?= $archivedFilter === 'all' ? 'selected' : '' ?>>All studies</option>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Code</th><th>Title</th><th>PI</th><th>Review Type</th><th>Status</th><th>Submitted</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$protocols): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No protocols found.</td></tr>
        <?php endif; ?>
        <?php foreach ($protocols as $p): ?>
        <tr>
          <td><span class="protocol-code"><?= e($p['protocol_code']) ?></span></td>
          <td><?= e($p['title']) ?></td>
          <td><?= e($p['pi_name']) ?></td>
          <td><?= $p['review_type'] ? e(ucwords(str_replace('_',' ',$p['review_type']))) : '<span class="text-muted">Not set</span>' ?></td>
          <td><?= status_badge($p['status']) ?><?php if ($p['archived']): ?> <span class="badge bg-dark">Archived</span><?php endif; ?></td>
          <td><?= date('d M Y', strtotime($p['submission_date'])) ?></td>
          <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
