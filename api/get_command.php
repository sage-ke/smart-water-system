<?php
/*
 * api/get_command.php — SWDS Meru
 * ESP32 / virtual_esp32 polls this every 10s for valve commands.
 * Works with actual device_commands table columns:
 *   id, device_id, command_type, payload, issued_by, status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../db.php';

// ── Validate API key ──────────────────────────────────────────
$api_key = trim($_GET['api_key'] ?? '');
if (!$api_key) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'api_key required']);
    exit;
}

try {
    $dev = $pdo->prepare("SELECT id, zone_id, device_name
        FROM hardware_devices
        WHERE api_key = ?
        LIMIT 1");
    $dev->execute([$api_key]);
    $device = $dev->fetch();
} catch(PDOException $e) {
    error_log('[get_command] Device lookup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Database error during auth']);
    exit;
}

if (!$device) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid API key']);
    exit;
}

// ── Update heartbeat ──────────────────────────────────────────
try {
    $pdo->prepare("UPDATE hardware_devices
        SET is_online=1, last_seen=NOW() WHERE id=?")
        ->execute([$device['id']]);
} catch(PDOException $e) {
    error_log('[get_command] Heartbeat: ' . $e->getMessage());
}

// ── Auto-timeout sent commands >5 min ────────────────────────
try {
    $pdo->prepare("UPDATE device_commands
        SET status='failed'
        WHERE device_id=? AND status='sent'
        AND issued_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")
        ->execute([$device['id']]);
} catch(PDOException $e) {
    error_log('[get_command] Timeout: ' . $e->getMessage());
}

// ── Fetch pending commands ────────────────────────────────────
try {
    $cmds = $pdo->prepare("
        SELECT id, device_id, command_type, payload, status
        FROM device_commands
        WHERE device_id = ?
        AND status = 'pending'
        ORDER BY id ASC
        LIMIT 5
    ");
    $cmds->execute([$device['id']]);
    $raw = $cmds->fetchAll();
} catch(PDOException $e) {
    error_log('[get_command] Fetch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Failed to fetch commands']);
    exit;
}

// ── Parse payload into command + valve_pct ────────────────────
$commands = [];
foreach ($raw as $row) {
    $payload   = json_decode($row['payload'] ?? '{}', true) ?: [];
    $valve_pct = (int)($payload['valve_pct'] ?? 0);

    // Derive open/close from command_type and valve_pct
    if ($row['command_type'] === 'set_valve') {
        $command = $valve_pct > 0 ? 'open' : 'close';
    } else {
        $command = $row['command_type'];
    }

    // Get zone_id from payload first, fallback to device zone_id
    $zone_id_cmd = (int)($payload['zone_id'] ?? $device['zone_id'] ?? 1);

    $commands[] = [
        'id'        => (int)$row['id'],
        'zone_id'   => $zone_id_cmd,
        'command'   => $command,
        'valve_pct' => $valve_pct,
    ];
}

// ── Mark as sent ──────────────────────────────────────────────
if (!empty($commands)) {
    $ids          = array_column($commands, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("UPDATE device_commands
            SET status='sent', issued_at=NOW()
            WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    } catch(PDOException $e) {
        error_log('[get_command] Mark sent: ' . $e->getMessage());
    }
}

// ── Return ────────────────────────────────────────────────────
echo json_encode([
    'status'    => 'ok',
    'device'    => $device['device_name'],
    'commands'  => $commands,
    'timestamp' => date('c'),
]);