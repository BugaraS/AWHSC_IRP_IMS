<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson','member']);

$u = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT m.*, u.full_name AS created_by_name FROM meetings m JOIN users u ON u.id = m.created_by WHERE m.id=?");
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if (!$meeting) { http_response_code(404); die('Meeting not found.'); }

$pageTitle = 'Meeting Detail';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_minutes' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $text = trim($_POST['minutes_text'] ?? '');
        $exists = $pdo->prepare("SELECT id FROM meeting_minutes WHERE meeting_id=?");
        $exists->execute([$id]);
        if ($row = $exists->fetch()) {
            $upd = $pdo->prepare("UPDATE meeting_minutes SET minutes_text=?, recorded_by=?, recorded_at=NOW() WHERE id=?");
            $upd->execute([$text, $u['id'], $row['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO meeting_minutes (meeting_id, minutes_text, recorded_by) VALUES (?,?,?)");
            $ins->execute([$id, $text, $u['id']]);
        }
        log_action($pdo, $u['id'], 'Saved meeting minutes', 'meeting', $id);
        flash_set('success', 'Minutes saved.');
        header('Location: ' . BASE_URL . 'meetings/view.php?id=' . $id); exit;
    }

    if ($action === 'mark_attendance' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $present = $_POST['present'] ?? [];
        $all = $pdo->prepare("SELECT user_id FROM meeting_attendees WHERE meeting_id=?");
        $all->execute([$id]);
        foreach ($all->fetchAll() as $row) {
            $isPresent = in_array($row['user_id'], $present) ? 1 : 0;
            $upd = $pdo->prepare("UPDATE meeting_attendees SET present=? WHERE meeting_id=? AND user_id=?");
            $upd->execute([$isPresent, $id, $row['user_id']]);
        }
        flash_set('success', 'Attendance updated.');
        header('Location: ' . BASE_URL . 'meetings/view.php?id=' . $id); exit;
    }

    if ($action === 'update_status' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $status = $_POST['status'] ?? 'scheduled';
        if (in_array($status, ['scheduled','completed','cancelled'], true)) {
            $upd = $pdo->prepare("UPDATE meetings SET status=? WHERE id=?");
            $upd->execute([$status, $id]);
            flash_set('success', 'Meeting status updated.');
        }
        header('Location: ' . BASE_URL . 'meetings/view.php?id=' . $id); exit;
    }

    // Member/Chairperson: declare a conflict of interest for an agenda protocol,
    // per AWHSC-IRB SOP/021 Annex 3 (Conflict of Interest Declaration Form).
    if ($action === 'declare_coi' && in_array($u['role'], ['member','chairperson'], true)) {
        $protocolId = (int)($_POST['protocol_id'] ?? 0);
        $reason = trim($_POST['coi_reason'] ?? '');
        if ($protocolId && $reason !== '') {
            $ins = $pdo->prepare(
                "INSERT INTO meeting_coi_declarations (meeting_id, member_id, protocol_id, reason)
                 VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), declared_at=NOW()"
            );
            $ins->execute([$id, $u['id'], $protocolId, $reason]);
            log_action($pdo, $u['id'], 'Declared conflict of interest', 'meeting', $id);
            flash_set('success', 'Conflict of interest declared. You should recuse yourself from discussion and voting on this protocol.');
        }
        header('Location: ' . BASE_URL . 'meetings/view.php?id=' . $id); exit;
    }
}

$agenda = $pdo->prepare(
    "SELECT a.*, p.protocol_code, p.title, p.status FROM meeting_agenda_items a
     LEFT JOIN protocols p ON p.id = a.protocol_id WHERE a.meeting_id=? ORDER BY a.item_order"
);
$agenda->execute([$id]);
$agendaItems = $agenda->fetchAll();

$att = $pdo->prepare(
    "SELECT ma.*, u.full_name, u.role FROM meeting_attendees ma JOIN users u ON u.id = ma.user_id WHERE ma.meeting_id=?"
);
$att->execute([$id]);
$attendees = $att->fetchAll();

$min = $pdo->prepare("SELECT mm.*, u.full_name FROM meeting_minutes mm JOIN users u ON u.id = mm.recorded_by WHERE mm.meeting_id=?");
$min->execute([$id]);
$minutes = $min->fetch();

$coiStmt = $pdo->prepare(
    "SELECT c.*, u.full_name AS member_name FROM meeting_coi_declarations c
     JOIN users u ON u.id = c.member_id WHERE c.meeting_id=?"
);
$coiStmt->execute([$id]);
$coiDeclarations = $coiStmt->fetchAll();
$coiByProtocol = [];
foreach ($coiDeclarations as $c) { $coiByProtocol[$c['protocol_id']][] = $c; }

$myCoiProtocolIds = [];
foreach ($coiDeclarations as $c) {
    if ((int)$c['member_id'] === (int)$u['id']) $myCoiProtocolIds[] = (int)$c['protocol_id'];
}

$categoryLabels = [
    'initial_review'    => 'Initial Review',
    'resubmitted'       => 'Resubmitted Protocols',
    'amendment'         => 'Amendments',
    'pending'           => 'Pending / Continued Discussion',
    'sae_report'        => 'Safety / Adverse Event Reports',
    'expedited_review'  => 'Expedited Review',
    'other'             => 'Other Business',
];
$agendaByCategory = [];
foreach ($agendaItems as $a) { $agendaByCategory[$a['category'] ?? 'other'][] = $a; }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow"><?= $meeting['meeting_type']==='emergency' ? 'Emergency Meeting' : 'Ordinary Meeting' ?></div>
    <h1><?= date('d M Y, H:i', strtotime($meeting['meeting_date'])) ?></h1>
  </div>
  <div>
    <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
    <form method="post" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status">
      <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
        <?php foreach (['scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled'] as $val=>$lab): ?>
          <option value="<?= $val ?>" <?= $meeting['status']===$val?'selected':'' ?>><?= $lab ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php else: ?>
      <span class="badge bg-primary"><?= ucfirst($meeting['status']) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php flash_render(); ?>

<?php if (in_array($u['role'], ['member','chairperson'], true)): ?>
<div class="card mb-4">
  <div class="card-body">
    <h6 class="card-title">Declare a Conflict of Interest</h6>
    <p class="text-muted small mb-2">AWHSC-IRB SOP/021 Annex 3 — declare before discussion/voting begins on any protocol where you have a conflict, then recuse yourself.</p>
    <?php $protocolsOnAgenda = array_filter($agendaItems, fn($a) => $a['protocol_id']); ?>
    <?php if (!$protocolsOnAgenda): ?>
      <p class="text-muted small mb-0">No protocols on this agenda.</p>
    <?php else: ?>
      <form method="post" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="declare_coi">
        <div class="col-md-5">
          <select name="protocol_id" class="form-select form-select-sm" required>
            <option value="">Select protocol…</option>
            <?php foreach ($protocolsOnAgenda as $a): ?>
              <option value="<?= $a['protocol_id'] ?>" <?= in_array((int)$a['protocol_id'], $myCoiProtocolIds, true) ? 'disabled' : '' ?>>
                <?= e($a['protocol_code']) ?><?= in_array((int)$a['protocol_id'], $myCoiProtocolIds, true) ? ' (already declared)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <input type="text" name="coi_reason" class="form-control form-control-sm" placeholder="Nature of the conflict" required>
        </div>
        <div class="col-md-2">
          <button class="btn btn-sm btn-outline-danger w-100" type="submit">Declare</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Agenda</h5>
        <p class="text-muted small">AWHSC-IRB SOP/021 Annex 1: Meeting Agenda Format</p>
        <?php if (!$agendaItems): ?>
          <p class="text-muted mb-0">No agenda items.</p>
        <?php else: ?>
          <ol class="mb-2"><li>Adoption of the Agenda</li><li>Announcement of Conflict of Interest</li><li>Approval of Last Meeting's Minutes</li></ol>
          <?php $n = 4; foreach ($categoryLabels as $catKey => $catLabel): if (empty($agendaByCategory[$catKey])) continue; ?>
            <p class="mb-1"><strong><?= $n++ ?>. <?= e($catLabel) ?></strong></p>
            <ul class="mb-3">
              <?php foreach ($agendaByCategory[$catKey] as $a): ?>
                <li class="mb-1">
                  <?php if ($a['protocol_id']): ?>
                    <span class="protocol-code"><?= e($a['protocol_code']) ?></span> <?= e($a['title']) ?>
                    <?= status_badge($a['status']) ?>
                    <?php if (!empty($coiByProtocol[$a['protocol_id']])): ?>
                      <span class="badge bg-danger" title="<?php foreach ($coiByProtocol[$a['protocol_id']] as $c) echo e($c['member_name']) . ': ' . e($c['reason']) . ' &#10;'; ?>">
                        <i class="bi bi-exclamation-triangle"></i> COI declared
                      </span>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>protocols/view.php?id=<?= $a['protocol_id'] ?>" class="small d-block">Open protocol &rarr;</a>
                  <?php else: ?>
                    <?= e($a['topic'] ?: 'General discussion') ?>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
          <p class="mb-0 small text-muted"><?= $n++ ?>. Issues Requiring Continuing Education &nbsp;|&nbsp; <?= $n++ ?>. Reportable Issues &nbsp;|&nbsp; <?= $n ?>. Any Other Business</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Minutes (AWHSC-IRB-021)</h5>
        <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_minutes">
            <textarea name="minutes_text" class="form-control mb-2" rows="8" placeholder="Record discussion, decisions, and action items..."><?= e($minutes['minutes_text'] ?? '') ?></textarea>
            <button class="btn btn-primary btn-sm" type="submit">Save minutes</button>
          </form>
        <?php else: ?>
          <p style="white-space:pre-wrap;" class="mb-0"><?= $minutes ? e($minutes['minutes_text']) : '<span class="text-muted">Minutes have not been recorded yet.</span>' ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Attendance</h5>
        <form method="post">
          <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_attendance">
          <ul class="list-unstyled mb-2">
            <?php foreach ($attendees as $a): ?>
              <li class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="present[]" value="<?= $a['user_id'] ?>" id="pr<?= $a['user_id'] ?>"
                  <?= $a['present'] ? 'checked' : '' ?>
                  <?= in_array($u['role'], ['secretariat','chairperson'], true) ? '' : 'disabled' ?>>
                <label class="form-check-label" for="pr<?= $a['user_id'] ?>"><?= e($a['full_name']) ?> <span class="text-muted small">(<?= role_label($a['role']) ?>)</span></label>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
            <button class="btn btn-sm btn-outline-primary w-100" type="submit">Save attendance</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
