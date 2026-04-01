<?php
/*
 * api/ack_command.php — SWDS Meru
 * ============================================================
 * ESP32 calls this after executing a valve command.
 * Closes the control loop by updating command status.
 *
 * CALLED BY: master_node.ino postAck()
 *
 * POST body (JSON):
 * {
 *   "api_key":    "device_api_key",
 *   "command_id": 5,
 *   "zone_id":    1,
 *   "status":     "acknowledged",   or "failed"
 *   "valve_pct":  100               (100=open, 0=closed)
 * }
 *
 * RETURNS:
 * {"status": "ok", "message": "Command acknowledged"}
 *
 * FULL LOOP:
 *   Dashboard → pending
 *       ↓ get_command.php
 *   ESP32 receives → sent
 *       ↓ LoRa to slave
 *   Slave executes valve
 *       ↓ LoRa ACK back
 *   ESP32 receives ACK
 *       ↓ POST ack_command.php
 *   Database → acknowledged  ✅
 *   Dashboard badge updates  ✅
 *   Zone valve_status updates ✅
 * ============================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

require_once __DIR__ . '/../db.php';

// ── Only accept POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST required']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    // Try form data fallback
    $data = $_POST;
}
if (!$data) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON body']);
    exit;
}

$api_key    = trim($data['api_key']    ?? '');
$command_id = (int)($data['command_id'] ?? 0);
$zone_id    = (int)($data['zone_id']    ?? 0);
$ack_status = trim($data['status']     ?? 'acknowledged');
$valve_pct  = isset($data['valve_pct']) ? (int)$data['valve_pct'] : null;

// ── Validate status value ─────────────────────────────────────
if (!in_array($ack_status, ['acknowledged','failed','rejected'])) {
    $ack_status = 'acknowledged';
}

// ── Validate API key ──────────────────────────────────────────
if (!$api_key) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'api_key required']);
    exit;
}

$dev = $pdo->prepare("SELECT id, zone_id, device_name
    FROM hardware_devices WHERE api_key=? LIMIT 1");
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

// ── Update command status → acknowledged or failed ────────────
try {
    $updated = $pdo->prepare("
        UPDATE device_commands
        SET status    = ?,
            ack_at    = NOW(),
            valve_pct = COALESCE(?, valve_pct)
        WHERE id = ?
    ")->execute([$ack_status, $valve_pct, $command_id]);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'DB error: '.$e->getMessage()]);
    exit;
}

// ── Update zone valve_status to reflect reality ───────────────
// zone_id comes from POST body — master sends it with every ACK
if ($ack_status === 'acknowledged' && $valve_pct !== null) {
    // Use zone_id from POST body first, then device zone_id as fallback
    $real_zone = (int)($data['zone_id'] ?? 0) ?: (int)($device['zone_id'] ?? 0);
    if ($real_zone) {
        $new_valve = $valve_pct > 0 ? 'OPEN' : 'CLOSED';
        try {
            $pdo->prepare("UPDATE water_zones
                SET valve_status = ?,
                    last_reading_at = NOW()
                WHERE id = ?")
                ->execute([$new_valve, $real_zone]);
        } catch(PDOException $e) {
    error_log('[ack_command] ' . $e->getMessage());
}
    }
}

// ── Update valve_command_log if exists ────────────────────────
try {
    $pdo->prepare("UPDATE valve_command_log
        SET status = ?, executed_at = NOW()
        WHERE command_id = ?")
        ->execute([$ack_status, $command_id]);
} catch(PDOException $e) {
    error_log('[ack_command] ' . $e->getMessage());
}

// ── Audit log ─────────────────────────────────────────────────
try {
    $pdo->prepare("INSERT INTO audit_log
        (action, user_name, user_role, entity_label,
         new_value, result, ip_address, created_at)
        VALUES ('command.ack', ?, 'device', ?, ?, ?, ?, NOW())")
        ->execute([
            $device['device_name'],
            'command_' . $command_id,
            $ack_status,
            $ack_status,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
} catch(PDOException $e) {
    error_log('[ack_command] ' . $e->getMessage());
}

// ── Update device heartbeat ───────────────────────────────────
try {
    $pdo->prepare("UPDATE hardware_devices
        SET is_online=1, last_seen=NOW() WHERE id=?")
        ->execute([$device['id']]);
} catch(PDOException $e) {
    error_log('[ack_command] ' . $e->getMessage());
}

// ── Return success ────────────────────────────────────────────
echo json_encode([
    'status'     => 'ok',
    'message'    => 'Command ' . $ack_status,
    'command_id' => $command_id,
    'ack_status' => $ack_status,
    'valve_pct'  => $valve_pct,
    'timestamp'  => date('c'),
]);