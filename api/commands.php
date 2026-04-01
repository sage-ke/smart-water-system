<?php
/*
 * api/commands.php — SWDS Meru
 * ============================================================
 * THREE modes:
 *
 * GET  ?api_key=X          — device polls for pending commands
 * POST {ack}               — device acknowledges command executed
 * GET  ?status=1&api_key=X — dashboard checks command status
 *
 * ACKNOWLEDGEMENT body (POST from ESP32):
 *   { "api_key":"...", "command_id":5, "status":"acknowledged",
 *     "result":"valve_opened", "valve_pct":100 }
 *
 * STATUS CHECK (GET from dashboard JS):
 *   ?status=1&api_key=X&command_id=5
 *   Returns: { "status":"ok", "command": { "id":5, "status":"acknowledged", "ack_at":"..." } }
 * ============================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../db.php';

// ── Auto-timeout commands stuck on 'sent' for >5 minutes ─────
try {
    $pdo->exec("UPDATE device_commands
        SET status='failed'
        WHERE status='sent'
        AND issued_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
} catch(PDOException $e) {}

// ════════════════════════════════════════════════════════════
//  POST — Device acknowledges command execution
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data       = json_decode(file_get_contents('php://input'), true) ?: [];
    $api_key    = trim($data['api_key']    ?? '');
    $command_id = (int)($data['command_id'] ?? 0);
    $ack_status = $data['status']           ?? 'acknowledged';
    $result_msg = $data['result']           ?? null;
    $valve_pct  = isset($data['valve_pct']) ? (int)$data['valve_pct'] : null;

    // Validate allowed statuses
    if (!in_array($ack_status, ['acknowledged','failed','rejected'])) {
        $ack_status = 'acknowledged';
    }

    if (!$api_key) {
        http_response_code(401);
        echo json_encode(['status'=>'error','message'=>'api_key required']);
        exit;
    }

    $dev = $pdo->prepare("SELECT id, zone_id, device_name FROM hardware_devices WHERE api_key=?");
    $dev->execute([$api_key]);
    $device = $dev->fetch();

    if (!$device) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Invalid API key']);
        exit;
    }

    if (!$command_id) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'command_id required']);
        exit;
    }

    // Update command status
    $pdo->prepare("UPDATE device_commands
        SET status=?, ack_at=NOW()
        WHERE id=? AND device_id=?")
        ->execute([$ack_status, $command_id, $device['id']]);

    // If valve acknowledged — update zone valve_status to reflect reality
    if ($ack_status === 'acknowledged' && $valve_pct !== null && $device['zone_id']) {
        $new_status = $valve_pct > 0 ? 'OPEN' : 'CLOSED';
        $pdo->prepare("UPDATE water_zones SET valve_status=? WHERE id=?")
            ->execute([$new_status, $device['zone_id']]);
    }

    // Log to audit_log
    try {
        $pdo->prepare("INSERT INTO audit_log
            (action, user_name, user_role, entity_label, new_value, result, ip_address)
            VALUES ('command.ack', :dev, 'device', :lbl, :val, :res, :ip)")
            ->execute([
                'dev' => $device['device_name'] ?? 'device_'.$device['id'],
                'lbl' => "command #$command_id",
                'val' => $result_msg ?? $ack_status,
                'res' => $ack_status,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch(PDOException $e) {}

    echo json_encode([
        'status'     => 'ok',
        'message'    => 'Command ' . $ack_status,
        'command_id' => $command_id,
        'ack_status' => $ack_status,
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════
//  GET — Two sub-modes: status check OR command poll
// ════════════════════════════════════════════════════════════
$api_key = trim($_GET['api_key'] ?? '');
if (!$api_key) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'api_key required']);
    exit;
}

$dev = $pdo->prepare("SELECT id, zone_id, device_name FROM hardware_devices WHERE api_key=?");
$dev->execute([$api_key]);
$device = $dev->fetch();

if (!$device) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid API key']);
    exit;
}

// ── Sub-mode: Dashboard status check ─────────────────────────
if (isset($_GET['status']) && isset($_GET['command_id'])) {
    $cmd_id = (int)$_GET['command_id'];
    $cmd = $pdo->prepare("SELECT id, command_type, status, ack_at, issued_at, payload
        FROM device_commands WHERE id=? AND device_id=?");
    $cmd->execute([$cmd_id, $device['id']]);
    $cmd = $cmd->fetch();

    echo json_encode([
        'status'  => 'ok',
        'command' => $cmd ?: null,
    ]);
    exit;
}

// ── Sub-mode: Get recent command history (for dashboard) ──────
if (isset($_GET['history'])) {
    $cmds = $pdo->prepare("
        SELECT dc.id, dc.command_type, dc.status, dc.issued_at, dc.ack_at, dc.payload,
               u.full_name AS issued_by_name
        FROM device_commands dc
        LEFT JOIN users u ON u.id = dc.issued_by
        WHERE dc.device_id = ?
        ORDER BY dc.issued_at DESC LIMIT 20
    ");
    $cmds->execute([$device['id']]);
    echo json_encode(['status'=>'ok','history'=>$cmds->fetchAll()]);
    exit;
}

// ── Main mode: Device polls for pending commands ──────────────

// Update heartbeat
$pdo->prepare("UPDATE hardware_devices SET is_online=1, last_seen=NOW(),
    ip_address=? WHERE id=?")
    ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $device['id']]);

// Get pending commands
$cmds = $pdo->prepare("SELECT id, command_type, payload
    FROM device_commands
    WHERE device_id=? AND status='pending'
    ORDER BY issued_at ASC LIMIT 5");
$cmds->execute([$device['id']]);
$commands = $cmds->fetchAll();

// Mark as sent
if ($commands) {
    $ids = implode(',', array_column($commands, 'id'));
    $pdo->exec("UPDATE device_commands SET status='sent' WHERE id IN ($ids)");
}

echo json_encode([
    'status'   => 'ok',
    'device'   => $device['device_name'],
    'commands' => array_map(fn($c) => [
        'id'      => (int)$c['id'],
        'type'    => $c['command_type'],
        'payload' => json_decode($c['payload'], true)
    ], $commands),
    'timestamp' => date('c'),
]);