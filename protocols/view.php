<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT p.*, us.full_name AS pi_name, us.email AS pi_email, us.phone AS pi_phone
                        FROM protocols p JOIN users us ON us.id = p.pi_id WHERE p.id = ?");
$stmt->execute([$id]);
$protocol = $stmt->fetch();

if (!$protocol) {
    http_response_code(404);
    die('Protocol not found.');
}

// Access control: researchers may only view their own protocols
if ($u['role'] === 'researcher' && (int)$protocol['pi_id'] !== (int)$u['id']) {
    http_response_code(403);
    die('You do not have access to this protocol.');
}
if (in_array($u['role'], ['member','external_consultant'], true)) {
    $chk = $pdo->prepare("SELECT 1 FROM protocol_reviewers WHERE protocol_id = ? AND reviewer_id = ?");
    $chk->execute([$id, $u['id']]);
    if (!$chk->fetch()) {
        http_response_code(403);
        die('This protocol has not been assigned to you for review.');
    }
}
// System Admin does not have access to research/ethics content by design — the role is
// scoped to accounts, infrastructure, and the audit trail, not confidential study data.
if ($u['role'] === 'system_admin') {
    http_response_code(403);
    die('System Administrator accounts do not have access to protocol content. This separation keeps research data visible only to IRB roles.');
}

$pageTitle = $protocol['protocol_code'];
$error = null;

// ---------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // Secretariat/Admin: screening -> set review type & risk level, move to under_review
    if ($action === 'screen' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $reviewType = $_POST['review_type'] ?? null;
        $riskLevel = $_POST['risk_level'] ?? null;
        $stmt = $pdo->prepare("UPDATE protocols SET review_type=?, risk_level=?, status='under_screening' WHERE id=?");
        $stmt->execute([$reviewType, $riskLevel, $id]);
        log_action($pdo, $u['id'], 'Screened protocol', 'protocol', $id);
        flash_set('success', 'Screening details saved.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Secretariat/Chair: verify the Annex 1 submission checklist (SOP/007 step 5.2.3 "Verify
    // Contents of Submitted Package"). Incomplete packages are sent back to the investigator
    // for correction (workflow step 4a), matching the SOP flow chart exactly.
    if ($action === 'verify_checklist' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        $verifiedChecked = $_POST['verified'] ?? [];
        $isComplete = ($_POST['is_complete'] ?? '') === '1';
        $deficiencyNotes = trim($_POST['deficiency_notes'] ?? '');

        $csStmt = $pdo->prepare("SELECT declared_items_json FROM submission_checklists WHERE id=? AND protocol_id=?");
        $csStmt->execute([$checklistId, $id]);
        $cs = $csStmt->fetch();
        if ($cs) {
            $declared = json_decode($cs['declared_items_json'], true) ?: [];
            $verified = [];
            foreach (array_keys($declared) as $key) {
                $verified[$key] = !empty($verifiedChecked[$key]);
            }
            $upd = $pdo->prepare(
                "UPDATE submission_checklists SET verified_items_json=?, is_complete=?, deficiency_notes=?, verified_by=?, verified_at=NOW() WHERE id=?"
            );
            $upd->execute([json_encode($verified), $isComplete ? 1 : 0, $deficiencyNotes, $u['id'], $checklistId]);

            if (!$isComplete) {
                // Workflow step 4a: return to investigator for correction / additional information
                $updP = $pdo->prepare("UPDATE protocols SET status='revision_required' WHERE id=?");
                $updP->execute([$id]);
                flash_set('info', 'Package marked incomplete and returned to the investigator for correction (SOP/007 step 4a).');
            } else {
                flash_set('success', 'Submission package verified complete against Annex 1.');
            }
            log_action($pdo, $u['id'], 'Verified Annex 1 submission checklist', 'protocol', $id);
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Secretariat/Admin: assign reviewers
    if ($action === 'assign_reviewers' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $reviewerIds = $_POST['reviewer_ids'] ?? [];
        $stmt = $pdo->prepare("INSERT IGNORE INTO protocol_reviewers (protocol_id, reviewer_id) VALUES (?,?)");
        foreach ($reviewerIds as $rid) {
            $stmt->execute([$id, (int)$rid]);
        }
        if ($reviewerIds) {
            $upd = $pdo->prepare("UPDATE protocols SET status='under_review' WHERE id=? AND status IN ('submitted','under_screening','resubmitted')");
            $upd->execute([$id]);
        }
        log_action($pdo, $u['id'], 'Assigned reviewers', 'protocol', $id);
        flash_set('success', 'Reviewers assigned.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // IRB Member / External Consultant: submit review
    if ($action === 'submit_review' && in_array($u['role'], ['member','external_consultant'], true)) {
        if ($u['role'] === 'external_consultant' && !has_confidentiality_ack($pdo, $u['id'])) {
            flash_set('error', 'Please acknowledge the confidentiality agreement on your profile before submitting a review.');
            header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
        }
        $rec = $_POST['recommendation'] ?? '';
        $comments = trim($_POST['comments'] ?? '');

        // AWHSC-IRB SOP/008 Annex 1: Study Assessment Form — structured criteria (Yes/No/N-A)
        $assessmentInput = $_POST['assessment'] ?? [];
        $assessment = [];
        foreach (annex1_assessment_categories() as $catKey => $cat) {
            foreach ($cat['items'] as $itemKey => $itemLabel) {
                $assessment[$catKey][$itemKey] = $assessmentInput[$catKey][$itemKey] ?? 'na';
            }
        }

        if (in_array($rec, ['approve','minor_revisions','major_revisions','reject'], true)) {
            $exists = $pdo->prepare("SELECT id FROM reviews WHERE protocol_id=? AND reviewer_id=?");
            $exists->execute([$id, $u['id']]);
            if ($row = $exists->fetch()) {
                $upd = $pdo->prepare("UPDATE reviews SET recommendation=?, assessment_json=?, comments=?, reviewed_at=NOW() WHERE id=?");
                $upd->execute([$rec, json_encode($assessment), $comments, $row['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO reviews (protocol_id, reviewer_id, recommendation, assessment_json, comments) VALUES (?,?,?,?,?)");
                $ins->execute([$id, $u['id'], $rec, json_encode($assessment), $comments]);
            }
            $mark = $pdo->prepare("UPDATE protocol_reviewers SET status='completed' WHERE protocol_id=? AND reviewer_id=?");
            $mark->execute([$id, $u['id']]);
            log_action($pdo, $u['id'], 'Submitted review (Annex 1 assessment)', 'protocol', $id);
            flash_set('success', 'Your review has been recorded.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chairperson: final decision (ethics authority — not delegated to Secretariat or System Admin)
    // Chairperson: final decision, per AWHSC-IRB SOP/008 §5.8 "Record the IRB Decision" (Annex 3)
    // and §5.9 Certificate of Approval (Annex 5) / Notification of Disapproval (Annex 6).
    if ($action === 'record_decision' && $u['role'] === 'chairperson') {
        $decision = $_POST['decision'] ?? '';
        $comments = trim($_POST['decision_comments'] ?? '');
        $certRaw = trim($_POST['certificate_no'] ?? '');
        // Annex 5 numbering convention: "AWHSC-IRB--<sequence>"
        $cert = $certRaw !== '' && stripos($certRaw, 'AWHSC-IRB') !== 0 ? 'AWHSC-IRB-' . $certRaw : $certRaw;

        if (in_array($decision, ['approved','rejected','revision_required','deferred'], true)) {
            $ins = $pdo->prepare("INSERT INTO decisions (protocol_id, decision, decided_by, certificate_no, comments) VALUES (?,?,?,?,?)");
            $ins->execute([$id, $decision, $u['id'], $cert ?: null, $comments]);
            $upd = $pdo->prepare("UPDATE protocols SET status=? WHERE id=?");
            $upd->execute([$decision, $id]);
            log_action($pdo, $u['id'], 'Recorded IRB decision: ' . $decision, 'protocol', $id);
            flash_set('success', 'Decision recorded' . ($decision === 'approved' && $cert ? " — certificate $cert issued." : '.'));
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Researcher: report SAE / adverse event, per AWHSC-IRB SOP/019 Annex 1
    if ($action === 'report_sae' && $u['role'] === 'researcher') {
        $seriousness = $_POST['seriousness'] ?? null;
        $relation = $_POST['relation_to_study'] ?? 'unknown';
        $outcome = $_POST['outcome'] ?? 'ongoing';
        $protocolChange = !empty($_POST['protocol_change_recommended']) ? 1 : 0;
        $icfChange = !empty($_POST['icf_change_recommended']) ? 1 : 0;

        $stmt = $pdo->prepare(
            "INSERT INTO sae_reports (protocol_id, report_date, event_type, severity, description,
                seriousness, relation_to_study, outcome, protocol_change_recommended, icf_change_recommended, reported_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $id,
            $_POST['report_date'] ?? date('Y-m-d'),
            $_POST['event_type'] ?? 'SAE',
            $_POST['severity'] ?? 'moderate',
            trim($_POST['description'] ?? ''),
            $seriousness,
            $relation,
            $outcome,
            $protocolChange,
            $icfChange,
            $u['id'],
        ]);
        log_action($pdo, $u['id'], 'Reported safety event', 'protocol', $id);
        flash_set('success', 'Safety report submitted to the IRB Secretariat.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Secretariat/Chair: record the reviewer decision on a safety report, per Annex 1's
    // "Decision" block (No Further Action / Request Information / Recommend Further Action).
    if ($action === 'review_sae' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $saeId = (int)($_POST['sae_id'] ?? 0);
        $decisionVal = $_POST['reviewer_decision'] ?? '';
        $reviewerComment = trim($_POST['reviewer_comment'] ?? '');
        if (array_key_exists($decisionVal, annex_reviewer_decision_options())) {
            $upd = $pdo->prepare(
                "UPDATE sae_reports SET reviewer_decision=?, reviewer_comment=?, reviewed_by=?, reviewed_at=NOW(), status='closed' WHERE id=? AND protocol_id=?"
            );
            $upd->execute([$decisionVal, $reviewerComment, $u['id'], $saeId, $id]);
            log_action($pdo, $u['id'], 'Reviewed safety report', 'protocol', $id);
            flash_set('success', 'Safety report decision recorded.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Researcher: withdraw protocol
    if ($action === 'withdraw' && $u['role'] === 'researcher') {
        $upd = $pdo->prepare("UPDATE protocols SET status='withdrawn' WHERE id=? AND pi_id=?");
        $upd->execute([$id, $u['id']]);
        log_action($pdo, $u['id'], 'Withdrew protocol', 'protocol', $id);
        flash_set('info', 'Protocol withdrawn.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Researcher: request a protocol amendment (AWHSC-IRB-015)
    if ($action === 'request_amendment' && $u['role'] === 'researcher' && (int)$protocol['pi_id'] === (int)$u['id']) {
        $desc = trim($_POST['description'] ?? '');
        if ($desc !== '') {
            $ins = $pdo->prepare("INSERT INTO amendments (protocol_id, description, submitted_by) VALUES (?,?,?)");
            $ins->execute([$id, $desc, $u['id']]);
            log_action($pdo, $u['id'], 'Requested amendment', 'protocol', $id);
            flash_set('success', 'Amendment request submitted to the IRB Secretariat.');
        } else {
            flash_set('error', 'Please describe the requested amendment.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chair/Admin: decide on an amendment
    if ($action === 'decide_amendment' && $u['role'] === 'chairperson') {
        $amendId = (int)($_POST['amendment_id'] ?? 0);
        $decision = $_POST['amendment_decision'] ?? '';
        $comments = trim($_POST['amendment_comments'] ?? '');
        if (in_array($decision, ['approved','rejected'], true)) {
            $upd = $pdo->prepare("UPDATE amendments SET status=?, decision_comments=?, decided_by=?, decided_at=NOW() WHERE id=? AND protocol_id=?");
            $upd->execute([$decision, $comments, $u['id'], $amendId, $id]);
            log_action($pdo, $u['id'], 'Decided amendment: ' . $decision, 'amendment', $amendId);
            flash_set('success', 'Amendment decision recorded.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chair/Admin: schedule/update a continuing review due date (AWHSC-IRB-014)
    if ($action === 'set_continuing_review' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $due = $_POST['due_date'] ?? '';
        if ($due !== '') {
            $ins = $pdo->prepare("INSERT INTO continuing_reviews (protocol_id, due_date) VALUES (?,?)");
            $ins->execute([$id, $due]);
            log_action($pdo, $u['id'], 'Scheduled continuing review', 'protocol', $id);
            flash_set('success', 'Continuing review deadline set.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Researcher: submit a continuing review report
    if ($action === 'submit_continuing_review' && $u['role'] === 'researcher') {
        $crId = (int)($_POST['cr_id'] ?? 0);
        $report = trim($_POST['cr_report'] ?? '');
        $upd = $pdo->prepare("UPDATE continuing_reviews SET submitted_date=CURDATE(), status='submitted', comments=? WHERE id=? AND protocol_id=?");
        $upd->execute([$report, $crId, $id]);
        log_action($pdo, $u['id'], 'Submitted continuing review report', 'protocol', $id);
        flash_set('success', 'Continuing review report submitted.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chair/Admin: close out a continuing review
    if ($action === 'decide_continuing_review' && $u['role'] === 'chairperson') {
        $crId = (int)($_POST['cr_id'] ?? 0);
        $upd = $pdo->prepare("UPDATE continuing_reviews SET status='reviewed' WHERE id=? AND protocol_id=?");
        $upd->execute([$crId, $id]);
        log_action($pdo, $u['id'], 'Reviewed continuing review', 'protocol', $id);
        flash_set('success', 'Continuing review marked as reviewed.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Researcher: submit end-of-study final report (AWHSC-IRB-016)
    if ($action === 'submit_final_report' && $u['role'] === 'researcher' && (int)$protocol['pi_id'] === (int)$u['id']) {
        $summary = trim($_POST['final_summary'] ?? '');
        if ($summary !== '') {
            $ins = $pdo->prepare("INSERT INTO final_reports (protocol_id, summary, submitted_by) VALUES (?,?,?)");
            $ins->execute([$id, $summary, $u['id']]);
            log_action($pdo, $u['id'], 'Submitted final report', 'protocol', $id);
            flash_set('success', 'Final report submitted for IRB review.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chair/Admin: accept final report and close the study file
    if ($action === 'close_study' && $u['role'] === 'chairperson') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE final_reports SET status='accepted', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND protocol_id=?");
        $upd->execute([$u['id'], $reportId, $id]);
        $upd2 = $pdo->prepare("UPDATE protocols SET status='closed' WHERE id=?");
        $upd2->execute([$id]);
        $pdo->commit();
        log_action($pdo, $u['id'], 'Closed study file', 'protocol', $id);
        flash_set('success', 'Study closed and final report accepted.');
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Chair/Admin: archive the study file (AWHSC-IRB-017 archive & retrieval)
    if ($action === 'archive_protocol' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        if (in_array($protocol['status'], ['approved','closed','rejected','withdrawn'], true)) {
            $upd = $pdo->prepare("UPDATE protocols SET archived=1, archived_at=NOW() WHERE id=?");
            $upd->execute([$id]);
            log_action($pdo, $u['id'], 'Archived study file', 'protocol', $id);
            flash_set('success', 'Study file archived.');
        } else {
            flash_set('error', 'Only approved, closed, rejected, or withdrawn studies can be archived.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Report a non-compliance / violation issue (AWHSC-IRB-020)
    if ($action === 'report_non_compliance' && in_array($u['role'], ['secretariat','chairperson','member','external_consultant'], true)) {
        $desc = trim($_POST['nc_description'] ?? '');
        $severity = $_POST['nc_severity'] ?? 'minor';
        if ($desc !== '' && in_array($severity, ['minor','moderate','serious'], true)) {
            $ins = $pdo->prepare(
                "INSERT INTO non_compliance_reports (protocol_id, reported_by, description, severity) VALUES (?,?,?,?)"
            );
            $ins->execute([$id, $u['id'], $desc, $severity]);
            log_action($pdo, $u['id'], 'Reported non-compliance', 'protocol', $id);
            flash_set('success', 'Non-compliance report logged.');
        } else {
            flash_set('error', 'Please describe the issue and select a severity.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }

    // Secretariat/Chair: update a non-compliance report's status/resolution.
    // Secretariat can move it through investigation but only the Chair can resolve/close
    // (final compliance determinations carry ethical authority, not administrative authority).
    if ($action === 'update_non_compliance' && in_array($u['role'], ['secretariat','chairperson'], true)) {
        $ncId = (int)($_POST['nc_id'] ?? 0);
        $status = $_POST['nc_status'] ?? '';
        $resolution = trim($_POST['nc_resolution'] ?? '');
        $allowedForRole = $u['role'] === 'chairperson'
            ? ['open','under_investigation','resolved','closed']
            : ['open','under_investigation'];

        if (in_array($status, $allowedForRole, true)) {
            if (in_array($status, ['resolved','closed'], true)) {
                $upd = $pdo->prepare(
                    "UPDATE non_compliance_reports SET status=?, resolution=?, resolved_by=?, resolved_at=NOW() WHERE id=? AND protocol_id=?"
                );
                $upd->execute([$status, $resolution, $u['id'], $ncId, $id]);
            } else {
                $upd = $pdo->prepare(
                    "UPDATE non_compliance_reports SET status=?, resolution=? WHERE id=? AND protocol_id=?"
                );
                $upd->execute([$status, $resolution, $ncId, $id]);
            }
            log_action($pdo, $u['id'], 'Updated non-compliance report: ' . $status, 'protocol', $id);
            flash_set('success', 'Non-compliance record updated.');
        } else {
            flash_set('error', 'Only the IRB Chairperson can mark a non-compliance report as resolved or closed.');
        }
        header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $id); exit;
    }
}

// ---------------------------------------------------------------------
// Data for display
// ---------------------------------------------------------------------
$docs = $pdo->prepare("SELECT * FROM protocol_documents WHERE protocol_id=? ORDER BY uploaded_at DESC");
$docs->execute([$id]);
$documents = $docs->fetchAll();

$revStmt = $pdo->prepare(
    "SELECT pr.*, u.full_name, u.specialization FROM protocol_reviewers pr
     JOIN users u ON u.id = pr.reviewer_id WHERE pr.protocol_id=? ORDER BY pr.assigned_at"
);
$revStmt->execute([$id]);
$assignedReviewers = $revStmt->fetchAll();

$reviewsStmt = $pdo->prepare(
    "SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.protocol_id=? ORDER BY r.reviewed_at DESC"
);
$reviewsStmt->execute([$id]);
$reviews = $reviewsStmt->fetchAll();

$decStmt = $pdo->prepare(
    "SELECT d.*, u.full_name FROM decisions d JOIN users u ON u.id = d.decided_by WHERE d.protocol_id=? ORDER BY d.decision_date DESC"
);
$decStmt->execute([$id]);
$decisions = $decStmt->fetchAll();

$saeStmt = $pdo->prepare(
    "SELECT s.*, u.full_name FROM sae_reports s JOIN users u ON u.id = s.reported_by WHERE s.protocol_id=? ORDER BY s.created_at DESC"
);
$saeStmt->execute([$id]);
$saeReports = $saeStmt->fetchAll();

$amendStmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS submitted_by_name, d.full_name AS decided_by_name
     FROM amendments a JOIN users u ON u.id = a.submitted_by
     LEFT JOIN users d ON d.id = a.decided_by
     WHERE a.protocol_id=? ORDER BY a.submitted_at DESC"
);
$amendStmt->execute([$id]);
$amendments = $amendStmt->fetchAll();

$crStmt = $pdo->prepare("SELECT * FROM continuing_reviews WHERE protocol_id=? ORDER BY due_date DESC");
$crStmt->execute([$id]);
$continuingReviews = $crStmt->fetchAll();

$frStmt = $pdo->prepare(
    "SELECT f.*, u.full_name AS submitted_by_name FROM final_reports f
     JOIN users u ON u.id = f.submitted_by WHERE f.protocol_id=? ORDER BY f.submitted_at DESC"
);
$frStmt->execute([$id]);
$finalReports = $frStmt->fetchAll();

$checklistStmt = $pdo->prepare(
    "SELECT sc.*, u.full_name AS submitted_by_name, v.full_name AS verified_by_name
     FROM submission_checklists sc
     JOIN users u ON u.id = sc.submitted_by
     LEFT JOIN users v ON v.id = sc.verified_by
     WHERE sc.protocol_id=? ORDER BY sc.submitted_at DESC LIMIT 1"
);
$checklistStmt->execute([$id]);
$submissionChecklist = $checklistStmt->fetch();

$ncStmt = $pdo->prepare(
    "SELECT nc.*, rb.full_name AS reported_by_name, rv.full_name AS resolved_by_name
     FROM non_compliance_reports nc
     JOIN users rb ON rb.id = nc.reported_by
     LEFT JOIN users rv ON rv.id = nc.resolved_by
     WHERE nc.protocol_id=? ORDER BY nc.created_at DESC"
);
$ncStmt->execute([$id]);
$nonComplianceReports = $ncStmt->fetchAll();

$commStmt = $pdo->prepare(
    "SELECT c.*, s.full_name AS sender_name, r.full_name AS recipient_name
     FROM communications c
     JOIN users s ON s.id = c.sender_id
     JOIN users r ON r.id = c.recipient_id
     WHERE c.protocol_id=? ORDER BY c.sent_at DESC LIMIT 10"
);
$commStmt->execute([$id]);
$protocolMessages = $commStmt->fetchAll();

$myReview = null;
if (in_array($u['role'], ['member','external_consultant'], true)) {
    $mr = $pdo->prepare("SELECT * FROM reviews WHERE protocol_id=? AND reviewer_id=?");
    $mr->execute([$id, $u['id']]);
    $myReview = $mr->fetch();
}

$availableMembers = [];
if (in_array($u['role'], ['secretariat','chairperson'], true)) {
    $availableMembers = $pdo->query("SELECT id, full_name, specialization, role FROM users WHERE role IN ('member','chairperson','external_consultant') AND is_active=1")->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow"><span class="protocol-code"><?= e($protocol['protocol_code']) ?></span></div>
    <h1><?= e($protocol['title']) ?></h1>
  </div>
  <div>
    <?= status_badge($protocol['status']) ?>
    <?php if ($protocol['archived']): ?><span class="badge bg-dark ms-1"><i class="bi bi-archive"></i> Archived</span><?php endif; ?>
  </div>
</div>

<?php flash_render(); ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Study Information</h5>
        <dl class="row mb-0">
          <dt class="col-sm-3">Principal Investigator</dt><dd class="col-sm-9"><?= e($protocol['pi_name']) ?> (<?= e($protocol['pi_email']) ?>)</dd>
          <dt class="col-sm-3">Department</dt><dd class="col-sm-9"><?= e($protocol['department'] ?: '—') ?></dd>
          <dt class="col-sm-3">Study type</dt><dd class="col-sm-9"><?= e($protocol['study_type'] ?: '—') ?></dd>
          <dt class="col-sm-3">Review type</dt><dd class="col-sm-9"><?= $protocol['review_type'] ? e(ucwords(str_replace('_',' ',$protocol['review_type']))) : '<span class="text-muted">Pending screening</span>' ?></dd>
          <dt class="col-sm-3">Risk level</dt><dd class="col-sm-9"><?= $protocol['risk_level'] ? e(ucwords(str_replace('_',' ',$protocol['risk_level']))) : '<span class="text-muted">Pending screening</span>' ?></dd>
          <dt class="col-sm-3">Submitted</dt><dd class="col-sm-9"><?= date('d M Y, H:i', strtotime($protocol['submission_date'])) ?></dd>
        </dl>
        <hr>
        <h6>Summary / Objectives</h6>
        <p class="mb-0" style="white-space:pre-wrap;"><?= e($protocol['summary']) ?></p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Annex 1: Contents of a Submitted Package (Checklist)</h5>
        
        <?php if (!$submissionChecklist): ?>
          <p class="text-muted mb-0">No checklist declaration recorded for this package.</p>
        <?php else: ?>
          <?php
            $declared = json_decode($submissionChecklist['declared_items_json'], true) ?: [];
            $verified = $submissionChecklist['verified_items_json'] ? json_decode($submissionChecklist['verified_items_json'], true) : null;
            $checklistLabels = annex1_submission_checklist($submissionChecklist['package_type']);
          ?>
          <p class="mb-2"><span class="badge bg-secondary"><?= e(annex1_package_label($submissionChecklist['package_type'])) ?></span>
            declared by <?= e($submissionChecklist['submitted_by_name']) ?> on <?= date('d M Y', strtotime($submissionChecklist['submitted_at'])) ?></p>
          <div class="table-responsive">
            <table class="table table-sm mb-2">
              <thead><tr><th>Annex 1 item</th><th class="text-center" style="width:110px;">Declared</th><?php if ($verified !== null): ?><th class="text-center" style="width:110px;">Verified</th><?php endif; ?></tr></thead>
              <tbody>
                <?php foreach ($checklistLabels as $key => $label): ?>
                <tr>
                  <td><?= e($label) ?></td>
                  <td class="text-center"><?= !empty($declared[$key]) ? '<span class="text-success">✓</span>' : '<span class="text-muted">—</span>' ?></td>
                  <?php if ($verified !== null): ?>
                    <td class="text-center"><?= !empty($verified[$key]) ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>' ?></td>
                  <?php endif; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($submissionChecklist['declared_other']): ?>
            <p class="small mb-2"><strong>Others declared:</strong> <?= e($submissionChecklist['declared_other']) ?></p>
          <?php endif; ?>

          <?php if ($submissionChecklist['is_complete'] !== null): ?>
            <div class="alert alert-<?= $submissionChecklist['is_complete'] ? 'success' : 'warning' ?> py-2 mb-0">
              <?= $submissionChecklist['is_complete'] ? 'Verified complete' : 'Verified incomplete — returned to investigator' ?>
              by <?= e($submissionChecklist['verified_by_name']) ?> on <?= date('d M Y', strtotime($submissionChecklist['verified_at'])) ?>
              <?php if ($submissionChecklist['deficiency_notes']): ?><br><span class="small"><?= nl2br(e($submissionChecklist['deficiency_notes'])) ?></span><?php endif; ?>
            </div>
          <?php elseif (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
            <form method="post" class="border-top pt-3 mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="verify_checklist">
              <input type="hidden" name="checklist_id" value="<?= $submissionChecklist['id'] ?>">
              <p class="small mb-2"><strong>Secretariat verification</strong> (SOP/007 step 5.2.3 — Verify Contents of Submitted Package):</p>
              <div class="row row-cols-2 row-cols-md-3 g-2 mb-2">
                <?php foreach ($checklistLabels as $key => $label): ?>
                  <div class="col">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="verified[<?= e($key) ?>]" value="1"
                             id="vf_<?= e($key) ?>" <?= !empty($declared[$key]) ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="vf_<?= e($key) ?>"><?= e($label) ?></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <textarea name="deficiency_notes" class="form-control form-control-sm mb-2" rows="2" placeholder="Deficiency notes (if returning to investigator)"></textarea>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" type="submit" name="is_complete" value="1">Mark complete — proceed to screening</button>
                <button class="btn btn-sm btn-outline-danger" type="submit" name="is_complete" value="0">Incomplete — return to investigator</button>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Documents</h5>
        <?php if (!$documents): ?>
          <p class="text-muted mb-0">No documents uploaded.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($documents as $d): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> <?= e($d['file_name']) ?> <span class="text-muted small">(<?= e($d['doc_type']) ?>)</span></span>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>protocols/download.php?doc_id=<?= $d['id'] ?>">Download</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($reviews): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Reviewer Comments (Annex 1 Study Assessment Form)</h5>
        <ul class="timeline mb-0">
          <?php foreach ($reviews as $r): ?>
            <li>
              <strong><?= e($r['full_name']) ?></strong>
              — <span class="badge bg-<?= $r['recommendation']==='approve'?'success':($r['recommendation']==='reject'?'danger':'warning') ?>">
                <?= e(['approve'=>'Approved','minor_revisions'=>'Approved with Minor Comments','major_revisions'=>'Resubmit','reject'=>'Disapproved'][$r['recommendation']] ?? ucwords(str_replace('_',' ',$r['recommendation']))) ?>
              </span>
              <div class="text-muted small"><?= date('d M Y', strtotime($r['reviewed_at'])) ?></div>
              <?php if (!empty($r['assessment_json'])): $ra = json_decode($r['assessment_json'], true) ?: []; ?>
                <details class="small mt-1">
                  <summary class="text-muted" style="cursor:pointer;">View structured assessment</summary>
                  <?php foreach (annex1_assessment_categories() as $catKey => $cat): ?>
                    <p class="mb-1 mt-2"><strong><?= e($cat['label']) ?></strong></p>
                    <ul class="mb-0">
                      <?php foreach ($cat['items'] as $itemKey => $itemLabel): $ans = $ra[$catKey][$itemKey] ?? 'na'; ?>
                        <li><?= e($itemLabel) ?>:
                          <span class="badge bg-<?= $ans==='yes'?'success':($ans==='no'?'danger':'secondary') ?>"><?= strtoupper($ans) ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endforeach; ?>
                </details>
              <?php endif; ?>
              <?php if ($r['comments']): ?><p class="mb-0 mt-1"><?= nl2br(e($r['comments'])) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if (in_array($u['role'], ['member','external_consultant'], true)): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Submit Your Review<?= $u['role']==='external_consultant' ? ' <span class="badge bg-secondary">External Consultant</span>' : '' ?></h5>
        <p class="text-muted small">AWHSC-IRB SOP/008 — 3.2 Use of Assessment Form (form AWHSC-IRB AF 01-008/01.1)</p>
        <?php if ($u['role'] === 'external_consultant' && !has_confidentiality_ack($pdo, $u['id'])): ?>
          <p class="text-danger mb-2">You must acknowledge the confidentiality agreement before you can submit a review.</p>
          <a href="<?= BASE_URL ?>profile/edit.php" class="btn btn-sm btn-outline-danger">Go to my profile</a>
        <?php else: ?>
          <?php
            $myAssessment = ($myReview && !empty($myReview['assessment_json'])) ? json_decode($myReview['assessment_json'], true) : [];
          ?>
          <?php if ($myReview): ?>
            <p class="small text-muted">You reviewed this on <?= date('d M Y', strtotime($myReview['reviewed_at'])) ?>. Submitting again will update your review.</p>
          <?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_review">

            <?php foreach (annex1_assessment_categories() as $catKey => $cat): ?>
              <h6 class="mt-3"><?= e($cat['label']) ?></h6>
              <div class="table-responsive mb-2">
                <table class="table table-sm mb-0">
                  <tbody>
                    <?php foreach ($cat['items'] as $itemKey => $itemLabel):
                      $current = $myAssessment[$catKey][$itemKey] ?? 'na';
                    ?>
                    <tr>
                      <td><?= e($itemLabel) ?></td>
                      <td style="width:220px;">
                        <?php foreach (['yes'=>'Yes','no'=>'No','na'=>'N/A'] as $val=>$lab): ?>
                          <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="assessment[<?= $catKey ?>][<?= $itemKey ?>]" value="<?= $val ?>" id="<?= $catKey.'_'.$itemKey.'_'.$val ?>" <?= $current===$val?'checked':'' ?>>
                            <label class="form-check-label small" for="<?= $catKey.'_'.$itemKey.'_'.$val ?>"><?= $lab ?></label>
                          </div>
                        <?php endforeach; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>

            <div class="row mt-3">
              <div class="col-md-4 mb-2">
                <label class="form-label small">Recommendation (SOP/008 §5.6)</label>
                <select name="recommendation" class="form-select form-select-sm" required>
                  <option value="">Select…</option>
                  <?php foreach (['approve'=>'Approved','minor_revisions'=>'Approved with Minor Comments','major_revisions'=>'Resubmit','reject'=>'Disapproved'] as $val=>$lab): ?>
                    <option value="<?= $val ?>" <?= ($myReview && $myReview['recommendation']===$val) ? 'selected' : '' ?>><?= $lab ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-8 mb-2">
                <label class="form-label small">Comments / suggestions / reason for disapproval</label>
                <textarea name="comments" class="form-control form-control-sm" rows="2"><?= $myReview ? e($myReview['comments']) : '' ?></textarea>
              </div>
            </div>
            <button class="btn btn-sm btn-primary" type="submit">Submit review</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>


    <?php if ($decisions): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">IRB Decisions</h5>
        <ul class="timeline mb-0">
          <?php foreach ($decisions as $d): ?>
            <li>
              <?= status_badge($d['decision']) ?>
              by <strong><?= e($d['full_name']) ?></strong>
              <div class="text-muted small"><?= date('d M Y', strtotime($d['decision_date'])) ?><?= $d['certificate_no'] ? ' &middot; Certificate: ' . e($d['certificate_no']) : '' ?></div>
              <?php if ($d['comments']): ?><p class="mb-0 mt-1"><?= nl2br(e($d['comments'])) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($saeReports): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Safety / Adverse Event Reports (AWHSC-IRB-019)</h5>
        <p class="text-muted small">AWHSC-IRB SOP/019 Annex 1 (Serious Adverse Event Report) / Annex 2 (Unexpected AE Summary)</p>
        <ul class="timeline mb-0">
          <?php foreach ($saeReports as $s): ?>
            <li>
              <strong><?= e($s['event_type']) ?></strong> — <?= e(ucfirst($s['severity'])) ?>
              <span class="badge bg-<?= $s['status']==='closed'?'dark':'warning' ?>"><?= e(ucwords(str_replace('_',' ',$s['status']))) ?></span>
              <div class="text-muted small"><?= date('d M Y', strtotime($s['report_date'])) ?> &middot; reported by <?= e($s['full_name']) ?></div>
              <p class="mb-0 mt-1"><?= nl2br(e($s['description'])) ?></p>
              <?php if ($s['seriousness'] || $s['relation_to_study']): ?>
                <p class="mb-0 mt-1 small">
                  <?php if ($s['seriousness']): ?><span class="badge bg-light text-dark border me-1"><?= e(annex_sae_seriousness_options()[$s['seriousness']] ?? $s['seriousness']) ?></span><?php endif; ?>
                  <span class="badge bg-light text-dark border me-1">Relation: <?= e(annex_relation_to_study_options()[$s['relation_to_study']] ?? $s['relation_to_study']) ?></span>
                  <span class="badge bg-light text-dark border me-1">Outcome: <?= $s['outcome'] === 'resolved' ? 'Resolved' : 'Ongoing' ?></span>
                  <?php if ($s['protocol_change_recommended']): ?><span class="badge bg-light text-dark border me-1">Protocol change recommended</span><?php endif; ?>
                  <?php if ($s['icf_change_recommended']): ?><span class="badge bg-light text-dark border me-1">ICF change recommended</span><?php endif; ?>
                </p>
              <?php endif; ?>
              <?php if ($s['reviewer_decision']): ?>
                <p class="mb-0 mt-1 small text-muted">
                  Decision: <strong><?= e(annex_reviewer_decision_options()[$s['reviewer_decision']] ?? $s['reviewer_decision']) ?></strong>
                  <?= $s['reviewer_comment'] ? '— ' . nl2br(e($s['reviewer_comment'])) : '' ?>
                </p>
              <?php elseif (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
                <form method="post" class="row g-2 mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="review_sae">
                  <input type="hidden" name="sae_id" value="<?= $s['id'] ?>">
                  <div class="col-auto">
                    <select name="reviewer_decision" class="form-select form-select-sm">
                      <?php foreach (annex_reviewer_decision_options() as $val=>$lab): ?>
                        <option value="<?= $val ?>"><?= e($lab) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto flex-grow-1">
                    <input type="text" name="reviewer_comment" class="form-control form-control-sm" placeholder="Comment / action">
                  </div>
                  <div class="col-auto">
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                  </div>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($amendments): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Amendments (AWHSC-IRB-015)</h5>
        <ul class="timeline mb-0">
          <?php foreach ($amendments as $a): ?>
            <li>
              <span class="badge bg-<?= $a['status']==='approved'?'success':($a['status']==='rejected'?'danger':'warning') ?>"><?= ucfirst($a['status']) ?></span>
              requested by <strong><?= e($a['submitted_by_name']) ?></strong>
              <div class="text-muted small"><?= date('d M Y', strtotime($a['submitted_at'])) ?></div>
              <p class="mb-0 mt-1"><?= nl2br(e($a['description'])) ?></p>
              <?php if ($a['decided_by_name']): ?>
                <p class="mb-0 mt-1 small text-muted">Decided by <?= e($a['decided_by_name']) ?><?= $a['decision_comments'] ? ': ' . nl2br(e($a['decision_comments'])) : '' ?></p>
              <?php endif; ?>
              <?php if ($a['status'] === 'pending' && $u['role'] === 'chairperson'): ?>
                <form method="post" class="row g-2 mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="decide_amendment">
                  <input type="hidden" name="amendment_id" value="<?= $a['id'] ?>">
                  <div class="col-auto">
                    <select name="amendment_decision" class="form-select form-select-sm">
                      <option value="approved">Approve</option>
                      <option value="rejected">Reject</option>
                    </select>
                  </div>
                  <div class="col-auto flex-grow-1">
                    <input type="text" name="amendment_comments" class="form-control form-control-sm" placeholder="Comments">
                  </div>
                  <div class="col-auto">
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                  </div>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($continuingReviews): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Continuing Review (AWHSC-IRB-014)</h5>
        <ul class="timeline mb-0">
          <?php foreach ($continuingReviews as $cr): ?>
            <li>
              Due <strong><?= date('d M Y', strtotime($cr['due_date'])) ?></strong>
              <span class="badge bg-<?= $cr['status']==='reviewed'?'success':($cr['status']==='overdue'?'danger':'secondary') ?>"><?= ucfirst($cr['status']) ?></span>
              <?php if ($cr['comments']): ?><p class="mb-0 mt-1"><?= nl2br(e($cr['comments'])) ?></p><?php endif; ?>
              <?php if ($cr['status'] === 'pending' && $u['role'] === 'researcher' && (int)$protocol['pi_id'] === (int)$u['id']): ?>
                <form method="post" class="mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="submit_continuing_review">
                  <input type="hidden" name="cr_id" value="<?= $cr['id'] ?>">
                  <textarea name="cr_report" class="form-control form-control-sm mb-1" rows="2" placeholder="Progress report" required></textarea>
                  <button class="btn btn-sm btn-primary" type="submit">Submit report</button>
                </form>
              <?php elseif ($cr['status'] === 'submitted' && $u['role'] === 'chairperson'): ?>
                <form method="post" class="mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="decide_continuing_review">
                  <input type="hidden" name="cr_id" value="<?= $cr['id'] ?>">
                  <button class="btn btn-sm btn-outline-success" type="submit">Mark reviewed</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($finalReports): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Final Report / Study Closure (AWHSC-IRB-016)</h5>
        <ul class="timeline mb-0">
          <?php foreach ($finalReports as $f): ?>
            <li>
              <span class="badge bg-<?= $f['status']==='accepted'?'success':'secondary' ?>"><?= ucfirst($f['status']) ?></span>
              by <strong><?= e($f['submitted_by_name']) ?></strong>
              <div class="text-muted small"><?= date('d M Y', strtotime($f['submitted_at'])) ?></div>
              <p class="mb-0 mt-1"><?= nl2br(e($f['summary'])) ?></p>
              <?php if ($f['status'] === 'pending' && $u['role'] === 'chairperson'): ?>
                <form method="post" class="mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="close_study">
                  <input type="hidden" name="report_id" value="<?= $f['id'] ?>">
                  <button class="btn btn-sm btn-gold" type="submit">Accept &amp; close study</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($nonComplianceReports): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Non-Compliance / Violation Reports (AWHSC-IRB-020)</h5>
        <ul class="timeline mb-0">
          <?php foreach ($nonComplianceReports as $nc): ?>
            <li>
              <?php
                $sevColor = ['minor'=>'secondary','moderate'=>'warning','serious'=>'danger'][$nc['severity']] ?? 'secondary';
                $stColor = ['open'=>'danger','under_investigation'=>'warning','resolved'=>'success','closed'=>'dark'][$nc['status']] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $sevColor ?>"><?= ucfirst($nc['severity']) ?></span>
              <span class="badge bg-<?= $stColor ?>"><?= ucwords(str_replace('_',' ',$nc['status'])) ?></span>
              reported by <strong><?= e($nc['reported_by_name']) ?></strong>
              <div class="text-muted small"><?= date('d M Y', strtotime($nc['created_at'])) ?></div>
              <p class="mb-0 mt-1"><?= nl2br(e($nc['description'])) ?></p>
              <?php if ($nc['resolution']): ?>
                <p class="mb-0 mt-1 small text-muted">Resolution by <?= e($nc['resolved_by_name']) ?>: <?= nl2br(e($nc['resolution'])) ?></p>
              <?php endif; ?>
              <?php if (in_array($u['role'], ['secretariat','chairperson'], true) && $nc['status'] !== 'closed'): ?>
                <form method="post" class="row g-2 mt-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_non_compliance">
                  <input type="hidden" name="nc_id" value="<?= $nc['id'] ?>">
                  <div class="col-auto">
                    <select name="nc_status" class="form-select form-select-sm">
                      <option value="open" <?= $nc['status']==='open'?'selected':'' ?>>Open</option>
                      <option value="under_investigation" <?= $nc['status']==='under_investigation'?'selected':'' ?>>Under Investigation</option>
                      <?php if ($u['role'] === 'chairperson'): ?>
                      <option value="resolved" <?= $nc['status']==='resolved'?'selected':'' ?>>Resolved</option>
                      <option value="closed" <?= $nc['status']==='closed'?'selected':'' ?>>Closed</option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-auto flex-grow-1">
                    <input type="text" name="nc_resolution" class="form-control form-control-sm" placeholder="Resolution notes">
                  </div>
                  <div class="col-auto">
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                  </div>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="card-title mb-1">Correspondence</h5>
          <p class="text-muted small mb-0">Messages exchanged about this protocol between the Secretariat, Chair, reviewers, and the investigator.</p>
        </div>
        <a href="<?= BASE_URL ?>communications/create.php?protocol_id=<?= $id ?><?= $u['role']==='researcher' ? '' : '&recipient_id=' . (int)$protocol['pi_id'] ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-envelope-plus"></i> New message
        </a>
      </div>
      <?php if ($protocolMessages): ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>From</th><th>To</th><th>Subject</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($protocolMessages as $m): ?>
            <tr>
              <td><?= e($m['sender_name']) ?></td>
              <td><?= e($m['recipient_name']) ?></td>
              <td><?= e($m['subject']) ?></td>
              <td class="text-nowrap"><?= date('d M Y', strtotime($m['sent_at'])) ?></td>
              <td><a href="<?= BASE_URL ?>communications/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary">Open</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="card-body pt-0"><p class="text-muted small mb-0">No messages linked to this protocol yet.</p></div>
      <?php endif; ?>
    </div>

  </div>

  <div class="col-lg-4">

    <?php if (in_array($u['role'], ['secretariat','chairperson'], true)): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Screening (AWHSC-IRB-008/009)</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="screen">
            <div class="mb-2">
              <label class="form-label small">Review type</label>
              <select name="review_type" class="form-select form-select-sm">
                <?php foreach (['expedited'=>'Expedited','full_board'=>'Full Board','exempt'=>'Exempt'] as $val=>$lab): ?>
                  <option value="<?= $val ?>" <?= $protocol['review_type']===$val?'selected':'' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small">Risk level</label>
              <select name="risk_level" class="form-select form-select-sm">
                <?php foreach (['minimal'=>'Minimal Risk','greater_than_minimal'=>'Greater than Minimal'] as $val=>$lab): ?>
                  <option value="<?= $val ?>" <?= $protocol['risk_level']===$val?'selected':'' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-sm btn-primary w-100" type="submit">Save screening</button>
          </form>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Assign Reviewers (AWHSC-IRB-009)</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_reviewers">
            <div class="mb-2" style="max-height:180px;overflow:auto;">
              <?php foreach ($availableMembers as $m):
                $already = in_array($m['id'], array_column($assignedReviewers, 'reviewer_id'));
              ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="reviewer_ids[]" value="<?= $m['id'] ?>" id="rev<?= $m['id'] ?>" <?= $already ? 'checked disabled' : '' ?>>
                  <label class="form-check-label small" for="rev<?= $m['id'] ?>">
                    <?= e($m['full_name']) ?> <span class="text-muted">(<?= e($m['specialization']) ?><?= $m['role'] === 'external_consultant' ? ' — External Consultant' : '' ?>)</span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-sm btn-primary w-100" type="submit">Assign selected reviewers</button>
          </form>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Continuing Review (AWHSC-IRB-014)</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_continuing_review">
            <label class="form-label small">Next due date</label>
            <input type="date" name="due_date" class="form-control form-control-sm mb-2" required>
            <button class="btn btn-sm btn-outline-primary w-100" type="submit">Set deadline</button>
          </form>
        </div>
      </div>

      <?php if ($protocol['status'] === 'approved' && !$protocol['archived']): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Archive Study File (AWHSC-IRB-017)</h6>
          <p class="small text-muted">Move this study into the archive once it is fully closed out.</p>
          <form method="post" data-confirm="Archive this study file?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archive_protocol">
            <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Archive</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>

      <?php if ($u['role'] === 'chairperson'): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Record IRB Decision</h6>
          <p class="small text-muted">AWHSC-IRB SOP/008 §5.8 (Annex 3 Decision Form) / §5.9 (Annex 5/6 Certificate)</p>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_decision">
            <div class="mb-2">
              <select name="decision" class="form-select form-select-sm" required>
                <option value="">Select decision…</option>
                <option value="approved">Approved (Full Approval)</option>
                <option value="revision_required">Approved with Modifications Required</option>
                <option value="deferred">Deferred (More Information Needed)</option>
                <option value="rejected">Not Approved (Disapproved)</option>
              </select>
            </div>
            <div class="mb-2">
              <div class="input-group input-group-sm">
                <span class="input-group-text">AWHSC-IRB-</span>
                <input type="text" name="certificate_no" class="form-control form-control-sm" placeholder="Certificate no. (if approved)">
              </div>
            </div>
            <div class="mb-2">
              <textarea name="decision_comments" class="form-control form-control-sm" rows="3" placeholder="Comments to PI"></textarea>
            </div>
            <button class="btn btn-sm btn-gold w-100" type="submit">Record decision</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

    <?php if ($u['role'] === 'researcher' && (int)$protocol['pi_id'] === (int)$u['id']): ?>
      <?php if (!in_array($protocol['status'], ['withdrawn','closed','rejected'], true)): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Report a Safety Event (AWHSC-IRB-019)</h6>
          <p class="small text-muted">AWHSC-IRB SOP/019 Annex 1: Serious Adverse Event Report (AF 01-020/01.0)</p>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="report_sae">
            <div class="mb-2">
              <select name="event_type" class="form-select form-select-sm">
                <option>SAE</option>
                <option>Unanticipated Problem</option>
                <option>Protocol Deviation</option>
              </select>
            </div>
            <div class="mb-2">
              <select name="severity" class="form-select form-select-sm">
                <option value="mild">Mild</option>
                <option value="moderate" selected>Moderate</option>
                <option value="severe">Severe</option>
                <option value="fatal">Fatal</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Seriousness</label>
              <select name="seriousness" class="form-select form-select-sm">
                <option value="">Select…</option>
                <?php foreach (annex_sae_seriousness_options() as $val=>$lab): ?>
                  <option value="<?= $val ?>"><?= e($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Relation to study medicine/device</label>
              <select name="relation_to_study" class="form-select form-select-sm">
                <?php foreach (annex_relation_to_study_options() as $val=>$lab): ?>
                  <option value="<?= $val ?>" <?= $val==='unknown'?'selected':'' ?>><?= e($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Outcome</label>
              <select name="outcome" class="form-select form-select-sm">
                <option value="ongoing" selected>On-going</option>
                <option value="resolved">Resolved</option>
              </select>
            </div>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="protocol_change_recommended" value="1" id="pcr">
              <label class="form-check-label small" for="pcr">Changes to the protocol recommended</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="icf_change_recommended" value="1" id="icr">
              <label class="form-check-label small" for="icr">Changes to the informed consent form recommended</label>
            </div>
            <div class="mb-2">
              <input type="date" name="report_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mb-2">
              <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Describe the event, study participant's history, laboratory findings, and treatment" required></textarea>
            </div>
            <button class="btn btn-sm btn-primary w-100" type="submit">Submit report</button>
          </form>
        </div>
      </div>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Request an Amendment (AWHSC-IRB-015)</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_amendment">
            <textarea name="description" class="form-control form-control-sm mb-2" rows="3" placeholder="Describe the requested change" required></textarea>
            <button class="btn btn-sm btn-primary w-100" type="submit">Submit amendment request</button>
          </form>
        </div>
      </div>

      <?php if ($protocol['status'] === 'approved'): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title">Submit Final Report (AWHSC-IRB-016)</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_final_report">
            <textarea name="final_summary" class="form-control form-control-sm mb-2" rows="3" placeholder="End-of-study summary" required></textarea>
            <button class="btn btn-sm btn-primary w-100" type="submit">Submit final report</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-4">
        <div class="card-body">
          <form method="post" data-confirm="Withdraw this protocol? This cannot be undone.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Withdraw protocol</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($u['role'], ['secretariat','chairperson','member','external_consultant'], true)): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h6 class="card-title">Report Non-Compliance (AWHSC-IRB-020)</h6>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="report_non_compliance">
          <select name="nc_severity" class="form-select form-select-sm mb-2">
            <option value="minor">Minor</option>
            <option value="moderate">Moderate</option>
            <option value="serious">Serious</option>
          </select>
          <textarea name="nc_description" class="form-control form-control-sm mb-2" rows="3" placeholder="Describe the compliance issue" required></textarea>
          <button class="btn btn-sm btn-outline-danger w-100" type="submit">Submit report</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <h6 class="card-title">Assigned Reviewers</h6>
        <?php if (!$assignedReviewers): ?>
          <p class="text-muted small mb-0">None assigned yet.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($assignedReviewers as $r): ?>
              <li class="mb-2 d-flex justify-content-between">
                <span><?= e($r['full_name']) ?></span>
                <span class="badge bg-<?= $r['status']==='completed'?'success':'secondary' ?>"><?= ucfirst($r['status']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
