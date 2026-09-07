<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in again.']);
    exit;
}

$u = current_user();

$stmt = $pdo->prepare("SELECT role, content, created_at FROM chat_messages WHERE user_id = ? ORDER BY id ASC LIMIT 100");
$stmt->execute([$u['id']]);
echo json_encode(['messages' => $stmt->fetchAll()]);
