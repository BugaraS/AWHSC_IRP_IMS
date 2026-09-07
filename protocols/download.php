<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$docId = (int)($_GET['doc_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT d.*, p.pi_id FROM protocol_documents d JOIN protocols p ON p.id = d.protocol_id WHERE d.id = ?"
);
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('File not found.');
}

// Access control
if ($u['role'] === 'system_admin') {
    http_response_code(403);
    die('System Administrator accounts do not have access to protocol documents.');
}
if ($u['role'] === 'researcher' && (int)$doc['pi_id'] !== (int)$u['id']) {
    http_response_code(403);
    die('Access denied.');
}
if (in_array($u['role'], ['member','external_consultant'], true)) {
    $chk = $pdo->prepare("SELECT 1 FROM protocol_reviewers WHERE protocol_id=? AND reviewer_id=?");
    $chk->execute([$doc['protocol_id'], $u['id']]);
    if (!$chk->fetch()) {
        http_response_code(403);
        die('Access denied.');
    }
}

$fullPath = __DIR__ . '/../' . $doc['file_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    die('File is missing from the server.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($doc['file_name']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
