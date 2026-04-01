<?php
/*
 * api/valve_control.php — SWDS Meru
 * ============================================================
 * AJAX endpoint called by valve_control.php JS (sendCmd)
 *
 * POST JSON body:
 *   { zone_id: int, action: "open"|"close"|"set_pct",
 *     valve_pct: int, reason: string }
 *
 * Returns JSON:
 *   { status:"ok", zone_name, valve_pct, device_online }
 *   { status:"error", code:"CSRF_INVALID"|"RBAC_DENIED"|..., message }
 * ============================================================
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','code'=>'UNAUTHENTICATED','message'=>'Not logged in']);
    exit;
}

$user_role = $_SESSION['user_role'] ?? 'viewer';
$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';

// ── CSRF check — bypassed for demo/development ───────────────
// In production: uncomment verify_csrf() check below
// if (!verify_csrf()) {
//     http_response_code(403);
//     echo json_encode(['status'=>'error','message'=>'CSRF invalid']);
//     exit;
// }

// ── Parse body ────────────────────────────────────────────────
$raw_input = file_get_contents('php://input');
$body     = json_decode($raw_input, true) ?? [];
$zone_id  = (int)($body['zone_id']  ?? 0);
$action   = trim($body['action']    ?? '');
$valve_pct= max(0, min(100, (int)($body['valve_pct'] ?? 0)));
$reason   = trim($body['reason']    ?? '');

// ── LOGGING ───────────────────────────────────────────────────
$log_file = __DIR__ . '/../valve_control.log';
$log_entry = date('Y-m-d H:i:s') . " | role=$user_role | zone=$zone_id | action=$action | pct=$valve_pct | raw=" . substr($raw_input,0,200) . "
";
file_put_contents($log_file, $log_entry, FILE_APPEND);

if (!$zone_id || !in_array($action, ['open','close','set_pct'], true)) {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " | REJECTED: bad_request zone=$zone_id action=$action
", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['status'=>'error','code'=>'BAD_REQUEST','message'=>'zone_id and action required']);
    exit;
}

// ── Map action → permission ───────────────────────────────────
$perm_map = ['open'=>'valve.open', 'close'=>'valve.close', 'set_pct'=>'valve.set_pct'];
$required_perm = $perm_map[$action];

if (!can_do($pdo, $user_role, $required_perm)) {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " | REJECTED: rbac_denied role=$user_role perm=$required_perm
", FILE_APPEND);
    http_response_code(403);
    echo json_encode(['status'=>'error','code'=>'RBAC_DENIED',
        'permission'=>$required_perm,
        'message'=>"Your role ($user_role) cannot perform: $required_perm"]);
    exit;
}

// ── Resolve valve_pct from action ─────────────────────────────
if ($action === 'open')  $valve_pct = 100;
if ($action === 'close') $valve_pct = 0;
// set_pct uses the value from body

// ── Load zone ─────────────────────────────────────────────────
$zone = $pdo->prepare("SELECT * FROM water_zones WHERE id=? LIMIT 1");
$zone->execute([$zone_id]);
$zone = $zone->fetch();

if (!$zone) {
    http_response_code(404);
    echo json_encode(['status'=>'error','code'=>'ZONE_NOT_FOUND','message'=>"Zone $zone_id not found"]);
    exit;
}

// ── Always use master node for commands ───────────────────────
// Master node relays commands to slaves via LoRa
// Slave devices never receive direct HTTP commands
$device = $pdo->query("
    SELECT * FROM hardware_devices
    WHERE device_type='master_node'
    ORDER BY is_online DESC, id ASC
    LIMIT 1
")->fetch();
$device_online = $device && $device['is_online'];

// ── Queue the command ─────────────────────────────────────────
$cmd_id = null;
if ($device) {
    // Use 'command' column so get_command.php / virtual_esp32.py can read it
    $cmd_text = $valve_pct > 0 ? 'open' : 'close';
    // Use actual device_commands columns: device_id, command_type, payload, issued_by, status
    $payload_json = json_encode(['valve_pct' => $valve_pct, 'zone_id' => $zone_id]);
    file_put_contents($log_file, date('Y-m-d H:i:s') . " | Inserting cmd: device_id=" . ($device['id']??'NULL') . " zone=$zone_id pct=$valve_pct
", FILE_APPEND);
    $ins = $pdo->prepare("
        INSERT INTO device_commands
            (device_id, command_type, payload, issued_by, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $ins->execute([$device['id'], 'set_valve', $payload_json, $user_id]);
    $cmd_id = (int)$pdo->lastInsertId();
    file_put_contents($log_file, date('Y-m-d H:i:s') . " | Inserted cmd_id=$cmd_id device_id=" . ($device['id']??'NULL') . "
", FILE_APPEND);
}

// ── Update zone valve_status immediately ──────────────────────
$new_status = $valve_pct > 0 ? 'OPEN' : 'CLOSED';
$pdo->prepare("UPDATE water_zones SET valve_status=? WHERE id=?")
    ->execute([$new_status, $zone_id]);

// ── Log to valve_command_log ──────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO valve_command_log
            (zone_id, command_type, valve_pct, reason, requested_by, command_id, queued_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$zone_id, $action, $valve_pct, $reason ?: null, $user_id, $cmd_id]);
} catch (\PDOException $e) {
    // Table may not exist yet — create it silently
    $pdo->exec("CREATE TABLE IF NOT EXISTS valve_command_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        zone_id       INT,
        command_type  VARCHAR(50),
        valve_pct     INT DEFAULT 0,
        reason        TEXT,
        requested_by  INT,
        command_id    INT,
        status        VARCHAR(30) DEFAULT 'queued',
        queued_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vcl_zone (zone_id),
        INDEX idx_vcl_time (queued_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->prepare("
        INSERT INTO valve_command_log
            (zone_id, command_type, valve_pct, reason, requested_by, command_id, queued_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$zone_id, $action, $valve_pct, $reason ?: null, $user_id, $cmd_id]);
}

// ── Log to audit_log ──────────────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO audit_log
            (action, user_name, user_role, entity_label, new_value, reason, result, ip_address)
        VALUES ('valve.".$action."', ?, ?, ?, ?, ?, 'ok', ?)
    ")->execute([
        $user_name,
        $user_role,
        $zone['zone_name'],
        json_encode(['valve_pct' => $valve_pct]),
        $reason ?: null,
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
} catch (\PDOException $e) {
    // audit_log table doesn't exist yet — create it
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        action       VARCHAR(100),
        user_name    VARCHAR(200),
        user_role    VARCHAR(50),
        entity_label VARCHAR(200),
        new_value    TEXT,
        reason       TEXT,
        result       VARCHAR(30) DEFAULT 'ok',
        ip_address   VARCHAR(45),
        INDEX idx_audit_action (action),
        INDEX idx_audit_time   (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $pdo->prepare("
            INSERT INTO audit_log
                (action, user_name, user_role, entity_label, new_value, reason, result, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, 'ok', ?)
        ")->execute([
            'valve.'.$action, $user_name, $user_role,
            $zone['zone_name'],
            json_encode(['valve_pct' => $valve_pct]),
            $reason ?: null,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
    } catch(PDOException $e) {
        // Audit log failure must never block the command
        error_log('[valve_control] audit_log: ' . $e->getMessage());
    }
}

// ── Fire alert if emergency close ─────────────────────────────
if ($action === 'close' && $valve_pct === 0) {
    try {
        $pdo->prepare("INSERT INTO alerts (zone_id,alert_type,message,severity)
                       VALUES (?,'VALVE_CLOSED',?,?)")
            ->execute([$zone_id,
                "Valve closed for {$zone['zone_name']} by {$user_name}. Reason: ".($reason ?: 'none'),
                'high']);
    } catch (\PDOException $e) {}
}

// ── Respond ───────────────────────────────────────────────────
echo json_encode([
    'status'        => 'ok',
    'zone_name'     => $zone['zone_name'],
    'zone_id'       => $zone_id,
    'action'        => $action,
    'valve_pct'     => $valve_pct,
    'new_status'    => $new_status,
    'device_online' => $device_online,
    'command_id'    => $cmd_id,
    'message'       => "Valve $new_status for {$zone['zone_name']}",
]);