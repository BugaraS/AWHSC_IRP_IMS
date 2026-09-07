<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$u = current_user();
$pageTitle = 'Dashboard';

// ---- Build role-specific stats ----
$stats = [];

if ($u['role'] === 'researcher') {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM protocols WHERE pi_id = ? GROUP BY status");
    $stmt->execute([$u['id']]);
    $byStatus = $stmt->fetchAll();
    $total = array_sum(array_column($byStatus, 'c'));
    $approved = 0; $pending = 0;
    foreach ($byStatus as $r) {
        if ($r['status'] === 'approved') $approved = $r['c'];
        if (in_array($r['status'], ['submitted','under_screening','under_review','revision_required','deferred','resubmitted'], true)) $pending += $r['c'];
    }
    $stats = [
        ['label' => 'My Protocols', 'value' => $total],
        ['label' => 'Approved', 'value' => $approved],
        ['label' => 'Pending Review', 'value' => $pending],
        ['label' => 'Unread Messages', 'value' => unread_message_count($pdo, $u['id'])],
    ];

    $stmt = $pdo->prepare("SELECT * FROM protocols WHERE pi_id = ? ORDER BY submission_date DESC LIMIT 8");
    $stmt->execute([$u['id']]);
    $recentProtocols = $stmt->fetchAll();

} elseif (in_array($u['role'], ['member','external_consultant'], true)) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM protocol_reviewers WHERE reviewer_id = ? AND status = 'pending'"
    );
    $stmt->execute([$u['id']]);
    $pendingReviews = $stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM reviews WHERE reviewer_id = ?");
    $stmt->execute([$u['id']]);
    $completedReviews = $stmt->fetch()['c'];

    $stmt = $pdo->query("SELECT COUNT(*) c FROM meetings WHERE status='scheduled' AND meeting_date >= NOW()");
    $upcomingMeetings = $stmt->fetch()['c'];

    $stats = [
        ['label' => 'Assigned to Me (pending)', 'value' => $pendingReviews],
        ['label' => 'Reviews Completed', 'value' => $completedReviews],
        ['label' => 'Upcoming Meetings', 'value' => $upcomingMeetings],
        ['label' => 'Unread Messages', 'value' => unread_message_count($pdo, $u['id'])],
    ];

    $stmt = $pdo->prepare(
        "SELECT p.*, pr.status AS review_status FROM protocol_reviewers pr
         JOIN protocols p ON p.id = pr.protocol_id
         WHERE pr.reviewer_id = ? ORDER BY pr.assigned_at DESC LIMIT 8"
    );
    $stmt->execute([$u['id']]);
    $myAssignments = $stmt->fetchAll();

} elseif (in_array($u['role'], ['secretariat','chairperson'], true)) { // organisation-wide operational view
    $stmt = $pdo->query("SELECT status, COUNT(*) c FROM protocols GROUP BY status");
    $byStatus = $stmt->fetchAll();
    $total = array_sum(array_column($byStatus, 'c'));
    $approved = 0; $underReview = 0; $needsAction = 0;
    foreach ($byStatus as $r) {
        if ($r['status'] === 'approved') $approved = $r['c'];
        if (in_array($r['status'], ['under_review','under_screening'], true)) $underReview += $r['c'];
        if (in_array($r['status'], ['submitted','revision_required','deferred','resubmitted'], true)) $needsAction += $r['c'];
    }
    $stmt = $pdo->query("SELECT COUNT(*) c FROM meetings WHERE status='scheduled' AND meeting_date >= NOW()");
    $upcomingMeetings = $stmt->fetch()['c'];
    $stmt = $pdo->query("SELECT COUNT(*) c FROM sae_reports WHERE status != 'closed'");
    $openSae = $stmt->fetch()['c'];
    $pdo->exec("UPDATE continuing_reviews SET status='overdue' WHERE status='pending' AND due_date < CURDATE()");
    $stmt = $pdo->query("SELECT COUNT(*) c FROM continuing_reviews WHERE status='overdue'");
    $overdueCR = $stmt->fetch()['c'];
    $stmt = $pdo->query("SELECT COUNT(*) c FROM amendments WHERE status='pending'");
    $pendingAmendments = $stmt->fetch()['c'];
    $stmt = $pdo->query("SELECT COUNT(*) c FROM non_compliance_reports WHERE status IN ('open','under_investigation')");
    $openNonCompliance = $stmt->fetch()['c'];

    $stats = [
        ['label' => 'Total Protocols', 'value' => $total],
        ['label' => 'Needs Action', 'value' => $needsAction],
        ['label' => 'Under Review', 'value' => $underReview],
        ['label' => 'Approved', 'value' => $approved],
        ['label' => 'Open Safety Reports', 'value' => $openSae],
        ['label' => 'Overdue Continuing Reviews', 'value' => $overdueCR],
        ['label' => 'Pending Amendments', 'value' => $pendingAmendments],
        ['label' => 'Open Non-Compliance', 'value' => $openNonCompliance],
        ['label' => 'Upcoming Meetings', 'value' => $upcomingMeetings],
        ['label' => 'Unread Messages', 'value' => unread_message_count($pdo, $u['id'])],
    ];

    $stmt = $pdo->query(
        "SELECT p.*, u.full_name AS pi_name FROM protocols p
         JOIN users u ON u.id = p.pi_id
         ORDER BY p.submission_date DESC LIMIT 10"
    );
    $recentAll = $stmt->fetchAll();

} else { // system_admin - accounts & system health only; no access to protocol content
    $stmt = $pdo->query("SELECT role, COUNT(*) c FROM users WHERE is_active=1 GROUP BY role");
    $byRole = array_column($stmt->fetchAll(), 'c', 'role');
    $stmt = $pdo->query("SELECT COUNT(*) c FROM users WHERE is_active=0");
    $inactiveUsers = $stmt->fetch()['c'];
    $stmt = $pdo->query("SELECT COUNT(*) c FROM audit_log WHERE created_at >= CURDATE()");
    $auditToday = $stmt->fetch()['c'];
    $stmt = $pdo->query("SELECT COUNT(*) c FROM audit_log");
    $auditTotal = $stmt->fetch()['c'];

    $stats = [
        ['label' => 'Active Users', 'value' => array_sum($byRole)],
        ['label' => 'Deactivated Accounts', 'value' => $inactiveUsers],
        ['label' => 'IRB Members', 'value' => ($byRole['member'] ?? 0) + ($byRole['external_consultant'] ?? 0)],
        ['label' => 'Researchers', 'value' => $byRole['researcher'] ?? 0],
        ['label' => 'Audit Entries Today', 'value' => $auditToday],
        ['label' => 'Audit Entries (All Time)', 'value' => $auditTotal],
    ];

    $stmt = $pdo->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 10");
    $recentAuditRaw = $stmt->fetchAll();
    $recentAudit = [];
    foreach ($recentAuditRaw as $a) {
        if ($a['user_id']) {
            $us = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
            $us->execute([$a['user_id']]);
            $a['full_name'] = $us->fetch()['full_name'] ?? 'Unknown';
        } else {
            $a['full_name'] = 'System';
        }
        $recentAudit[] = $a;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">Welcome back</div>
    <h1><?= e($u['name']) ?></h1>
  </div>
  <div><?= role_label($u['role']) ?></div>
</div>

<?php flash_render(); ?>

<div class="row g-3 mb-4">
  <?php foreach ($stats as $s): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card card-stat h-100">
      <div class="card-body">
        <div class="stat-number"><?= (int)$s['value'] ?></div>
        <div class="stat-label"><?= e($s['label']) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($u['role'] === 'researcher'): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">My recent protocols</h5>
    <a href="<?= BASE_URL ?>protocols/submit.php" class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i> Submit new protocol</a>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Code</th><th>Title</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          <?php if (!$recentProtocols): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">You haven't submitted any protocols yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($recentProtocols as $p): ?>
          <tr>
            <td><span class="protocol-code"><?= e($p['protocol_code']) ?></span></td>
            <td><?= e($p['title']) ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td><?= date('d M Y', strtotime($p['submission_date'])) ?></td>
            <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif (in_array($u['role'], ['member','external_consultant'], true)): ?>
  <?php if ($u['role'] === 'external_consultant' && !has_confidentiality_ack($pdo, $u['id'])): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
      <span>Please acknowledge the confidentiality agreement before reviewing any assigned protocol.</span>
      <a href="<?= BASE_URL ?>profile/edit.php" class="btn btn-sm btn-warning">Go to my profile</a>
    </div>
  <?php endif; ?>
  <h5 class="mb-3">My review assignments</h5>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Code</th><th>Title</th><th>Protocol Status</th><th>My Review</th><th></th></tr></thead>
        <tbody>
          <?php if (!$myAssignments): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No protocols assigned to you yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($myAssignments as $p): ?>
          <tr>
            <td><span class="protocol-code"><?= e($p['protocol_code']) ?></span></td>
            <td><?= e($p['title']) ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td><?= $p['review_status'] === 'completed' ? '<span class="badge bg-success">Completed</span>' : '<span class="badge bg-warning">Pending</span>' ?></td>
            <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Review</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Recently submitted protocols</h5>
    <a href="<?= BASE_URL ?>protocols/list.php" class="btn btn-outline-primary btn-sm">View all protocols</a>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Code</th><th>Title</th><th>PI</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          <?php if (!$recentAll): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No protocols submitted yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($recentAll as $p): ?>
          <tr>
            <td><span class="protocol-code"><?= e($p['protocol_code']) ?></span></td>
            <td><?= e($p['title']) ?></td>
            <td><?= e($p['pi_name']) ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td><?= date('d M Y', strtotime($p['submission_date'])) ?></td>
            <td><a href="<?= BASE_URL ?>protocols/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: // system_admin ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Recent system activity</h5>
    <a href="<?= BASE_URL ?>audit/list.php" class="btn btn-outline-primary btn-sm">View full audit log</a>
  </div>
  <div class="card mb-4">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Date/Time</th><th>User</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!$recentAudit): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No activity recorded yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($recentAudit as $a): ?>
          <tr>
            <td class="text-nowrap"><?= date('d M Y, H:i', strtotime($a['created_at'])) ?></td>
            <td><?= e($a['full_name']) ?></td>
            <td><?= e($a['action']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <a href="<?= BASE_URL ?>users/list.php" class="btn btn-gold btn-sm"><i class="bi bi-person-gear"></i> Manage user accounts</a>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
