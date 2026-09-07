<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['secretariat','chairperson','system_admin']);

$u = current_user();
$type = $_GET['type'] ?? 'protocols';

if ($type === 'audit') {
    // Only System Admin exports the audit trail (system-level oversight).
    require_role(['system_admin']);
    $rows = $pdo->query(
        "SELECT al.created_at, u.full_name, al.action, al.entity, al.entity_id
         FROM audit_log al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC"
    )->fetchAll();
    $filename = 'awhsc-irb-audit-log-' . date('Ymd-His') . '.csv';
    $header = ['Date/Time','User','Action','Entity','Entity ID'];
} else {
    // Only Secretariat/Chairperson export the protocol register — System Admin does not
    // have access to confidential research content, per the same separation of duties
    // that keeps protocols/*.php off-limits to that role.
    require_role(['secretariat','chairperson']);
    $rows = $pdo->query(
        "SELECT p.protocol_code, p.title, us.full_name AS pi_name, p.department, p.study_type,
                p.review_type, p.risk_level, p.status, p.archived, p.submission_date
         FROM protocols p JOIN users us ON us.id = p.pi_id ORDER BY p.submission_date DESC"
    )->fetchAll();
    $filename = 'awhsc-irb-protocol-register-' . date('Ymd-His') . '.csv';
    $header = ['Protocol Code','Title','PI','Department','Study Type','Review Type','Risk Level','Status','Archived','Submitted'];
}

log_action($pdo, $u['id'], 'Exported report: ' . $type);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, $header);
foreach ($rows as $r) {
    fputcsv($out, array_values($r));
}
fclose($out);
exit;
