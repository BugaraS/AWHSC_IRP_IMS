<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['researcher']);

$u = current_user();
$pageTitle = 'Submit Protocol';
$error = null;
$values = ['title' => '', 'department' => '', 'study_type' => '', 'summary' => ''];

// AWHSC-IRB SOP/007 Annex 1: Contents of a Submitted Package (Checklist) — Initial Review package
$checklistItems = annex1_submission_checklist('initial');
$checkedItems = $_POST['checklist'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $values = [
        'title'      => trim($_POST['title'] ?? ''),
        'department' => trim($_POST['department'] ?? ''),
        'study_type' => trim($_POST['study_type'] ?? ''),
        'summary'    => trim($_POST['summary'] ?? ''),
    ];
    $otherItemText = trim($_POST['checklist_other'] ?? '');

    $missingRequired = [];
    foreach (['summary_sheet','application_form','informed_consent'] as $requiredKey) {
        if (empty($checkedItems[$requiredKey])) {
            $missingRequired[] = $checklistItems[$requiredKey] ?? $requiredKey;
        }
    }

    if ($values['title'] === '' || $values['summary'] === '') {
        $error = 'Title and study summary are required.';
    } elseif ($missingRequired) {
        $error = 'Per AWHSC-IRB SOP/007 Annex 1, the submission package must at minimum include: '
               . implode('; ', $missingRequired) . '. Please confirm these items before submitting.';
    } else {
        try {
            $pdo->beginTransaction();

            $code = generate_protocol_code($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO protocols (protocol_code, title, pi_id, department, study_type, summary, status)
                 VALUES (?,?,?,?,?,?, 'submitted')"
            );
            $stmt->execute([$code, $values['title'], $u['id'], $values['department'], $values['study_type'], $values['summary']]);
            $protocolId = (int)$pdo->lastInsertId();

            // Record the researcher's Annex 1 checklist declaration
            $declared = [];
            foreach ($checklistItems as $key => $label) {
                $declared[$key] = !empty($checkedItems[$key]);
            }
            $ins = $pdo->prepare(
                "INSERT INTO submission_checklists (protocol_id, package_type, declared_items_json, declared_other, submitted_by)
                 VALUES (?, 'initial', ?, ?, ?)"
            );
            $ins->execute([$protocolId, json_encode($declared), $otherItemText, $u['id']]);

            // Handle per-item file uploads (each Annex 1 item gets its own upload slot)
            $uploadErrors = [];
            $dir = protocol_upload_dir($protocolId);
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            $maxBytes = 15 * 1024 * 1024; // 15 MB per file

            if (!empty($_FILES['item_files'])) {
                foreach ($_FILES['item_files']['name'] as $key => $name) {
                    if ($name === '' || $_FILES['item_files']['error'][$key] !== UPLOAD_ERR_OK) continue;
                    $tmp = $_FILES['item_files']['tmp_name'][$key];
                    if (!is_allowed_upload($tmp, $name, $allowed, $maxBytes, $ext)) {
                        $uploadErrors[] = $name . ' was skipped (unsupported or oversized file).';
                        continue;
                    }
                    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                    $dest = $dir . '/' . $safeName;
                    if (move_uploaded_file($tmp, $dest)) {
                        $label = $checklistItems[$key] ?? 'General';
                        $rel = 'uploads/protocols/' . $protocolId . '/' . $safeName;
                        $insDoc = $pdo->prepare("INSERT INTO protocol_documents (protocol_id, doc_type, file_name, file_path) VALUES (?,?,?,?)");
                        $insDoc->execute([$protocolId, $label, $name, $rel]);
                    }
                }
            }
            // "Others" file(s)
            if (!empty($_FILES['other_files']['name'][0])) {
                foreach ($_FILES['other_files']['name'] as $i => $name) {
                    if ($_FILES['other_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $tmp = $_FILES['other_files']['tmp_name'][$i];
                    if (!is_allowed_upload($tmp, $name, $allowed, $maxBytes, $ext)) {
                        $uploadErrors[] = $name . ' was skipped (unsupported or oversized file).';
                        continue;
                    }
                    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                    $dest = $dir . '/' . $safeName;
                    if (move_uploaded_file($tmp, $dest)) {
                        $rel = 'uploads/protocols/' . $protocolId . '/' . $safeName;
                        $insDoc = $pdo->prepare("INSERT INTO protocol_documents (protocol_id, doc_type, file_name, file_path) VALUES (?,?,?,?)");
                        $insDoc->execute([$protocolId, $otherItemText !== '' ? $otherItemText : 'Other', $name, $rel]);
                    }
                }
            }

            log_action($pdo, $u['id'], 'Submitted protocol (Annex 1 package)', 'protocol', $protocolId);
            $pdo->commit();

            foreach ($uploadErrors as $msg) { flash_set('error', $msg); }
            flash_set('success', "Protocol $code submitted successfully with its Annex 1 checklist declaration.");
            header('Location: ' . BASE_URL . 'protocols/view.php?id=' . $protocolId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Submission failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="page-eyebrow">New submission — Initial Review</div>
    <h1>Submit a Research Protocol</h1>
  </div>
  <a href="<?= BASE_URL ?>protocols/list.php" class="btn btn-outline-primary btn-sm">Back to my protocols</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <h5 class="mb-3">1. Protocol details</h5>
      <div class="mb-3">
        <label class="form-label">Protocol title</label>
        <input type="text" name="title" class="form-control" required value="<?= e($values['title']) ?>">
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Department / School</label>
          <input type="text" name="department" class="form-control" value="<?= e($values['department']) ?>">
        </div>
        <div class="col-md-6 mb-3">
         <label class="form-label">Study type</label> <select name="study_type" class="form-select" required> 
<option value="" selected disabled>Select Study Type</option> <?php foreach (['Observational','Interventional','Retrospective Chart Review','Survey/Questionnaire','Clinical Trial','Other'] as $opt): ?> <option value="<?= htmlspecialchars($opt) ?>">
              <option <?= $values['study_type'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label">Study summary / objectives</label>
        <textarea name="summary" class="form-control" rows="6" required><?= e($values['summary']) ?></textarea>
      </div>

      <hr class="mb-4">

      <h5 class="mb-1">2. Annex 1: Contents of a Submitted Package (Checklist)</h5>
    
      <div class="table-responsive mb-2">
        <table class="table table-sm align-middle">
          <thead>
            <tr><th style="width:36px;"></th><th>Annex 1 item</th><th style="width:280px;">Attach file (optional)</th></tr>
          </thead>
          <tbody>
            <?php
            $requiredKeys = ['summary_sheet','application_form','informed_consent'];
            foreach ($checklistItems as $key => $label):
            ?>
            <tr>
              <td>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="checklist[<?= e($key) ?>]" value="1"
                         id="chk_<?= e($key) ?>" <?= !empty($checkedItems[$key]) ? 'checked' : '' ?>>
                </div>
              </td>
              <td>
                <label for="chk_<?= e($key) ?>" class="form-check-label mb-0">
                  <?= e($label) ?><?= in_array($key, $requiredKeys, true) ? ' <span class="text-danger">*</span>' : '' ?>
                </label>
              </td>
              <td><input type="file" name="item_files[<?= e($key) ?>]" class="form-control form-control-sm"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row align-items-end mb-4">
        <div class="col-md-8 mb-2">
          <label class="form-label small">Others (specify)</label>
          <input type="text" name="checklist_other" class="form-control form-control-sm" placeholder="e.g. Ethical clearance from another institution" value="<?= e($_POST['checklist_other'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-2">
          <label class="form-label small">Attach file(s) for "Others"</label>
          <input type="file" name="other_files[]" class="form-control form-control-sm" multiple>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Submit protocol for review</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
