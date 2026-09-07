<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['system_admin']);

$u = current_user();
$pageTitle = 'Audit Log';

$userFilter = (int)($_GET['user_id'] ?? 0);
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($userFilter) { $where[] = 'al.user_id = ?'; $params[] = $userFilter; }
if ($search !== '') { $where[] = '(al.action LIKE ? OR al.entity LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) c FROM audit_log al $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];

$sql = "SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
        $whereSql ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
$totalPages = max(1, (int)ceil($total / $perPage));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-023 / 026</div>
    <h1>Audit Log</h1>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4">
    <input type="text" class="form-control" name="q" placeholder="Search action or entity" value="<?= e($search) ?>">
  </div>
  <div class="col-md-3">
    <select class="form-select" name="user_id">
      <option value="">All users</option>
      <?php foreach ($users as $us): ?>
        <option value="<?= $us['id'] ?>" <?= $userFilter === (int)$us['id'] ? 'selected' : '' ?>><?= e($us['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
  </div>
  <div class="col-md-3 text-md-end">
    <a href="<?= BASE_URL ?>reports/export.php?type=audit" class="btn btn-outline-secondary w-100"><i class="bi bi-download"></i> Export CSV</a>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Entity</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No matching audit entries.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= date('d M Y, H:i:s', strtotime($r['created_at'])) ?></td>
          <td><?= e($r['full_name'] ?? 'System') ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= $r['entity'] ? e($r['entity']) . ' #' . (int)$r['entity_id'] : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&user_id=<?= $userFilter ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
