<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Schedule Meeting';
$error = null;

// Protocols eligible for agenda (under review / under screening)
$eligibleProtocols = $pdo->query(
    "SELECT id, protocol_code, title FROM protocols WHERE status IN ('submitted','under_screening','under_review','deferred','resubmitted') ORDER BY submission_date"
)->fetchAll();

$members = $pdo->query("SELECT id, full_name, role FROM users WHERE role IN ('chairperson','member','secretariat') AND is_active=1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $meetingDate = $_POST['meeting_date'] ?? '';
    $meetingType = $_POST['meeting_type'] ?? 'regular';
    $venue = trim($_POST['venue'] ?? '');
    $agendaProtocols = $_POST['agenda_protocols'] ?? [];
    $attendees = $_POST['attendees'] ?? [];

    if ($meetingDate === '') {
        $error = 'Please choose a meeting date and time.';
    } else {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO meetings (meeting_date, meeting_type, venue, created_by) VALUES (?,?,?,?)");
        $stmt->execute([$meetingDate, $meetingType, $venue, $u['id']]);
        $meetingId = (int)$pdo->lastInsertId();

        $order = 1;
        foreach ($agendaProtocols as $pid) {
            $pid = (int)$pid;
            // Auto-categorize per AWHSC-IRB SOP/021 Annex 1 agenda structure (item 5: Protocol
            // Presentations, Review, Discussion and Voting), based on the protocol's current status.
            $stStmt = $pdo->prepare("SELECT status FROM protocols WHERE id=?");
            $stStmt->execute([$pid]);
            $pStatus = $stStmt->fetch()['status'] ?? null;
            $category = match ($pStatus) {
                'resubmitted' => 'resubmitted',
                'under_review', 'deferred' => 'pending',
                default => 'initial_review',
            };
            $ins = $pdo->prepare("INSERT INTO meeting_agenda_items (meeting_id, protocol_id, item_order, category) VALUES (?,?,?,?)");
            $ins->execute([$meetingId, $pid, $order++, $category]);
        }
        foreach ($attendees as $uid) {
            $ins = $pdo->prepare("INSERT IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES (?,?)");
            $ins->execute([$meetingId, (int)$uid]);
        }
        log_action($pdo, $u['id'], 'Scheduled meeting', 'meeting', $meetingId);
        $pdo->commit();

        flash_set('success', 'Meeting scheduled.');
        header('Location: ' . BASE_URL . 'meetings/view.php?id=' . $meetingId);
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">New meeting</div>
    <h1>Schedule an IRB Meeting</h1>
  </div>
  <a href="<?= BASE_URL ?>meetings/list.php" class="btn btn-outline-primary btn-sm">Back to meetings</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Date &amp; time</label>
          <input type="datetime-local" name="meeting_date" class="form-control" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Type</label>
          <select name="meeting_type" class="form-select">
            <option value="regular">Ordinary</option>
            <option value="emergency">Emergency</option>
          </select>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Venue</label>
          <input type="text" name="venue" class="form-control" placeholder="e.g. IRB Board Room">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Agenda: protocols to review</label>
        <div class="border rounded p-2" style="max-height:220px;overflow:auto;">
          <?php if (!$eligibleProtocols): ?>
            <p class="text-muted small mb-0">No protocols currently awaiting review.</p>
          <?php endif; ?>
          <?php foreach ($eligibleProtocols as $p): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="agenda_protocols[]" value="<?= $p['id'] ?>" id="ap<?= $p['id'] ?>">
              <label class="form-check-label small" for="ap<?= $p['id'] ?>">
                <span class="protocol-code"><?= e($p['protocol_code']) ?></span> <?= e($p['title']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Invite attendees</label>
        <div class="border rounded p-2" style="max-height:180px;overflow:auto;">
          <?php foreach ($members as $m): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="attendees[]" value="<?= $m['id'] ?>" id="att<?= $m['id'] ?>" checked>
              <label class="form-check-label small" for="att<?= $m['id'] ?>"><?= e($m['full_name']) ?> <span class="text-muted">(<?= role_label($m['role']) ?>)</span></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Schedule meeting</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
