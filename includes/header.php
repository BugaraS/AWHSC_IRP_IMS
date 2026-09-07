<?php
/**
 * Shared page header. Expects $pageTitle to be set before include.
 * Must be included AFTER config/db.php and includes/functions.php.
 */
$pageTitle = $pageTitle ?? 'AWHSC-IRB MIS';
$u = current_user();

// Baseline security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | AWHSC-IRB MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php if ($u): ?>
<nav class="navbar navbar-expand-lg navbar-dark topbar sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>dashboard.php">
      <span class="brand-mark">IRB</span>
      <span class="d-none d-sm-inline">AWHSC&nbsp;Institutional Review Board</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <?php if ($u['role'] !== 'system_admin'): ?>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>protocols/list.php"><i class="bi bi-file-earmark-text"></i> Protocols</a></li>
        <?php endif; ?>
        <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>continuing_review/list.php"><i class="bi bi-arrow-repeat"></i> Continuing Review</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>amendments/list.php"><i class="bi bi-pencil-square"></i> Amendments</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>monitoring/list.php"><i class="bi bi-clipboard-check"></i> Monitoring</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>non_compliance/list.php"><i class="bi bi-exclamation-triangle"></i> Non-Compliance</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>reports/export.php?type=protocols"><i class="bi bi-download"></i> Export</a></li>
        <?php endif; ?>
        <?php if (in_array($u['role'], ['secretariat','chairperson','member'], true)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>meetings/list.php"><i class="bi bi-people"></i> Meetings</a></li>
        <?php endif; ?>
        <?php if ($u['role'] === 'system_admin'): ?>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>users/list.php"><i class="bi bi-person-gear"></i> Users</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>audit/list.php"><i class="bi bi-shield-lock"></i> Audit Log</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>reports/export.php?type=audit"><i class="bi bi-download"></i> Export Audit Log</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php $unread = unread_message_count($pdo, $u['id']); ?>
        <li class="nav-item">
          <a class="nav-link" href="<?= BASE_URL ?>communications/list.php">
            <i class="bi bi-envelope"></i> Messages
            <?php if ($unread > 0): ?><span class="badge bg-danger rounded-pill"><?= $unread ?></span><?php endif; ?>
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
            <img src="<?= avatar_url($u['photo'], $u['name']) ?>" class="rounded-circle" width="26" height="26" alt="">
            <?= e($u['name']) ?>
            <span class="badge bg-light text-dark role-chip"><?= e(role_label($u['role'])) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>profile/edit.php"><i class="bi bi-person-gear"></i> My profile</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>

<main class="container-fluid px-3 px-md-4 py-4">
