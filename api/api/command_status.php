<?php
/*
 * api/command_status.php — Live command status checker
 * ============================================================
 * Called by valve_control.php JS every 3 seconds.
 * Returns current status + ack_at for a list of command IDs.
 *
 * GET ?ids=1,2,3
 * Returns: [ {id, status, ack_at}, ... ]
 * ============================================================
 */
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Auth — must be logged in admin/operator
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$raw = trim($_GET['ids'] ?? '');
if (!$raw) {
    echo json_encode([]);
    exit;
}

// Sanitise: only integers
$ids = array_filter(
    array_map('intval', explode(',', $raw)),
    fn($id) => $id > 0
);

if (empty($ids)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$rows = $pdo->prepare("
    SELECT id, status,
           DATE_FORMAT(ack_at, '%H:%i:%s') AS ack_at
    FROM device_commands
    WHERE id IN ($placeholders)
");
$rows->execute(array_values($ids));

echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));