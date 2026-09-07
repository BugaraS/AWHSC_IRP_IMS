<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson']);

$u = current_user();
$pageTitle = 'Record Monitoring Visit';
$error = null;

$protocols = $pdo->query(
    "SELECT id, protocol_code, title FROM protocols WHERE status IN ('approved') ORDER BY submission_date DESC"
)->fetchAll();

$monitors = $pdo->query(
    "SELECT id, full_name FROM users WHERE role IN ('secretariat','chairperson','member') AND is_active=1 ORDER BY full_name"
)->fetchAll();

function yn(?string $key): ?int {
    if ($key === '1') return 1;
    if ($key === '0') return 0;
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $protocolId = (int)($_POST['protocol_id'] ?? 0);
    $visitDate = $_POST['visit_date'] ?? '';
    $monitorId = (int)($_POST['monitor_id'] ?? 0);
    $decision = $_POST['decision'] ?? 'no_action';

    // Derive the overall compliance_status summary from the checklist answers, so the
    // existing non-compliance/monitoring dashboards keep working unchanged.
    $nonCompliance = yn($_POST['protocol_noncompliance_found'] ?? null);
    $rating = $_POST['participant_protection_rating'] ?? null;
    if ($nonCompliance === 1 && $decision === 'recommend_further_action') {
        $compliance = 'non_compliant';
    } elseif ($nonCompliance === 1 || $rating === 'not_good') {
        $compliance = 'major_findings';
    } elseif ($decision === 'request_information' || $rating === 'fair') {
        $compliance = 'minor_findings';
    } else {
        $compliance = 'compliant';
    }

    $allowedDecisions = array_keys(annex_reviewer_decision_options());
    $allowedRatings = ['good','fair','not_good'];

    if (!$protocolId || $visitDate === '' || !$monitorId) {
        $error = 'Please select the protocol, visit date, and monitor.';
    } elseif (!in_array($decision, $allowedDecisions, true)) {
        $error = 'Please select a valid decision.';
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO monitoring_visits (
                protocol_id, visit_date, monitor_id,
                total_expected_subjects, total_enrolled_subjects,
                site_facilities_appropriate, site_facilities_comment,
                informed_consents_recent, informed_consents_comment,
                adverse_events_found, adverse_events_comment,
                protocol_noncompliance_found, protocol_noncompliance_comment,
                case_record_forms_updated, case_record_forms_comment,
                storage_secured, storage_secured_comment,
                participant_protection_rating,
                outstanding_tasks, outstanding_tasks_detail,
                visit_duration_hours, visit_start_time, visit_end_time,
                decision, compliance_status, findings, action_required
            ) VALUES (?,?,?, ?,?, ?,?, ?,?, ?,?, ?,?, ?,?, ?,?, ?, ?,?, ?,?,?, ?,?,?,?)"
        );
        $ins->execute([
            $protocolId, $visitDate, $monitorId,
            $_POST['total_expected_subjects'] !== '' ? (int)$_POST['total_expected_subjects'] : null,
            $_POST['total_enrolled_subjects'] !== '' ? (int)$_POST['total_enrolled_subjects'] : null,
            yn($_POST['site_facilities_appropriate'] ?? null), trim($_POST['site_facilities_comment'] ?? ''),
            yn($_POST['informed_consents_recent'] ?? null), trim($_POST['informed_consents_comment'] ?? ''),
            yn($_POST['adverse_events_found'] ?? null), trim($_POST['adverse_events_comment'] ?? ''),
            $nonCompliance, trim($_POST['protocol_noncompliance_comment'] ?? ''),
            yn($_POST['case_record_forms_updated'] ?? null), trim($_POST['case_record_forms_comment'] ?? ''),
            yn($_POST['storage_secured'] ?? null), trim($_POST['storage_secured_comment'] ?? ''),
            in_array($rating, $allowedRatings, true) ? $rating : null,
            yn($_POST['outstanding_tasks'] ?? null), trim($_POST['outstanding_tasks_detail'] ?? ''),
            $_POST['visit_duration_hours'] !== '' ? (float)$_POST['visit_duration_hours'] : null,
            $_POST['visit_start_time'] ?: null,
            $_POST['visit_end_time'] ?: null,
            $decision, $compliance,
            trim($_POST['findings'] ?? ''), trim($_POST['action_required'] ?? ''),
        ]);
        $visitId = (int)$pdo->lastInsertId();

        // If non-compliance was found on the visit, automatically open a non-compliance
        // record too, so it appears in the Non-Compliance queue for follow-up (AWHSC-IRB-020).
        if ($nonCompliance === 1) {
            $desc = 'Identified during monitoring visit on ' . date('d M Y', strtotime($visitDate)) . '. '
                  . trim($_POST['protocol_noncompliance_comment'] ?? '');
            $sev = $compliance === 'non_compliant' ? 'serious' : 'moderate';
            $ncIns = $pdo->prepare(
                "INSERT INTO non_compliance_reports (protocol_id, reported_by, description, severity) VALUES (?,?,?,?)"
            );
            $ncIns->execute([$protocolId, $u['id'], $desc, $sev]);
        }

        log_action($pdo, $u['id'], 'Recorded monitoring visit (Annex 1 checklist)', 'protocol', $protocolId);
        flash_set('success', 'Monitoring visit recorded.' . ($nonCompliance === 1 ? ' A non-compliance report was also opened for follow-up.' : ''));
        header('Location: ' . BASE_URL . 'monitoring/list.php');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">AWHSC-IRB-018</div>
    <h1>Record a Monitoring Visit</h1>
  </div>
  <a href="<?= BASE_URL ?>monitoring/list.php" class="btn btn-outline-primary btn-sm">Back to monitoring</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <p class="text-muted small">AWHSC-IRB SOP/020 — 7.1 Site Monitoring Visits, Annex 1: Checklist of a Monitoring Visit (AWHSC-IRB F 01-021/02.0)</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Protocol</label>
        <select name="protocol_id" class="form-select" required>
          <option value="">Select a protocol…</option>
          <?php foreach ($protocols as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['protocol_code']) ?> — <?= e($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Only currently approved (active) studies are eligible for a site visit.</div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Date of the visit</label>
          <input type="date" name="visit_date" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Monitor / IRB representative</label>
          <select name="monitor_id" class="form-select" required>
            <option value="">Select a monitor…</option>
            <?php foreach ($monitors as $m): ?>
              <option value="<?= $m['id'] ?>" <?= (int)$m['id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Total expected subjects</label>
          <input type="number" min="0" name="total_expected_subjects" class="form-control">
        </div>
        <div class="col-md-2 mb-3">
          <label class="form-label">Total enrolled</label>
          <input type="number" min="0" name="total_enrolled_subjects" class="form-control">
        </div>
      </div>

      <hr>
      <h6 class="mb-3">Annex 1 checklist</h6>

      <?php
      $checks = [
          'site_facilities_appropriate'  => 'Are site facilities appropriate?',
          'informed_consents_recent'     => 'Are Informed Consents recent?',
          'adverse_events_found'         => 'Any adverse events found?',
          'protocol_noncompliance_found' => 'Any protocol non-compliance / violation?',
          'case_record_forms_updated'    => 'Are all Case Record Forms up to date?',
          'storage_secured'              => 'Are storage of data and investigating products locked?',
      ];
      foreach ($checks as $key => $label):
      ?>
      <div class="row align-items-center mb-2">
        <div class="col-md-5"><?= e($label) ?></div>
        <div class="col-md-2">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="<?= $key ?>" id="<?= $key ?>_y" value="1">
            <label class="form-check-label" for="<?= $key ?>_y">Yes</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="<?= $key ?>" id="<?= $key ?>_n" value="0" checked>
            <label class="form-check-label" for="<?= $key ?>_n">No</label>
          </div>
        </div>
        <div class="col-md-5">
          <input type="text" name="<?= $key ?>_comment" class="form-control form-control-sm" placeholder="Comment">
        </div>
      </div>
      <?php endforeach; ?>

      <div class="row align-items-center mb-3 mt-2">
        <div class="col-md-5">How well are participants protected?</div>
        <div class="col-md-7">
          <select name="participant_protection_rating" class="form-select form-select-sm" style="max-width:220px;">
            <option value="good">Good</option>
            <option value="fair">Fair</option>
            <option value="not_good">Not good</option>
          </select>
        </div>
      </div>

      <div class="row align-items-center mb-3">
        <div class="col-md-5">Any outstanding tasks or results of visit?</div>
        <div class="col-md-2">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="outstanding_tasks" id="ot_y" value="1">
            <label class="form-check-label" for="ot_y">Yes</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="outstanding_tasks" id="ot_n" value="0" checked>
            <label class="form-check-label" for="ot_n">No</label>
          </div>
        </div>
        <div class="col-md-5">
          <input type="text" name="outstanding_tasks_detail" class="form-control form-control-sm" placeholder="Give details">
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-3">
          <label class="form-label small">Duration of visit (hours)</label>
          <input type="number" step="0.5" min="0" name="visit_duration_hours" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Starting from</label>
          <input type="time" name="visit_start_time" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Finish</label>
          <input type="time" name="visit_end_time" class="form-control form-control-sm">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Decision</label>
        <select name="decision" class="form-select">
          <?php foreach (annex_reviewer_decision_options() as $val=>$lab): ?>
            <option value="<?= $val ?>"><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Overall findings / notes</label>
        <textarea name="findings" class="form-control" rows="3" placeholder="Summary of what was observed"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Action required (if any)</label>
        <textarea name="action_required" class="form-control" rows="2"></textarea>
      </div>
      <p class="text-muted small">If "protocol non-compliance / violation" is marked Yes, a non-compliance report is opened automatically for the Secretariat/Chair to track (AWHSC-IRB-020).</p>
      <button type="submit" class="btn btn-primary">Save monitoring record</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
