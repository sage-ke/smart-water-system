<?php
/*
 * api/register_device.php — Device Self-Registration
 * ============================================================
 * Allows an ESP32 to register itself and receive an API key
 * WITHOUT requiring manual phpMyAdmin entry.
 *
 * HOW THE ESP32 CALLS THIS (once on first boot):
 *   POST http://SERVER_IP/smart_water/api/register_device.php
 *   Body (JSON):
 *   {
 *     "register_token": "SWDS_REG_2024",   ← shared secret
 *     "device_name":    "Master Node 1",
 *     "device_type":    "master",           ← master|slave|sensor
 *     "hardware_id":    "ESP32-ABC123",     ← unique chip ID
 *     "zone_id":        1,                  ← optional
 *     "slave_id":       0,                  ← 0 for master
 *     "firmware_ver":   "1.0.0"
 *   }
 *
 * RETURNS:
 *   { "status":"registered", "api_key":"abc123...", "device_id":5 }
 *   OR if already registered:
 *   { "status":"exists", "api_key":"abc123...", "device_id":5 }
 *
 * SECURITY:
 *   - register_token must match REGISTER_SECRET below
 *   - Change REGISTER_SECRET before deploying
 *   - After all devices are registered you can disable this endpoint
 * ============================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST only']);
    exit;
}

require_once __DIR__ . '/../db.php';

// ── Shared registration secret ────────────────────────────────
// Change this in production — must match what's in the firmware
define('REGISTER_SECRET', 'SWDS_REG_2024');

$data = json_decode(file_get_contents('php://input'), true);

// ── Validate token ────────────────────────────────────────────
if (($data['register_token'] ?? '') !== REGISTER_SECRET) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid registration token']);
    exit;
}

// ── Extract fields ─────────────────────────────────────────────
$device_name  = trim($data['device_name']  ?? '');
$device_type  = trim($data['device_type']  ?? 'sensor');
$hardware_id  = trim($data['hardware_id']  ?? '');
$zone_id      = isset($data['zone_id'])  ? (int)$data['zone_id']  : null;
$slave_id     = isset($data['slave_id']) ? (int)$data['slave_id'] : 0;
$firmware_ver = trim($data['firmware_ver'] ?? '');
$ip_address   = $_SERVER['REMOTE_ADDR'] ?? null;

if (!$device_name || !$hardware_id) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'device_name and hardware_id required']);
    exit;
}

// Validate device_type
if (!in_array($device_type, ['master','slave','sensor','pump'])) {
    $device_type = 'sensor';
}

// ── Check if already registered (by hardware_id) ─────────────
try {
    // Ensure hardware_id column exists
    $pdo->exec("ALTER TABLE hardware_devices
        ADD COLUMN IF NOT EXISTS hardware_id  VARCHAR(64)  DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS firmware_ver VARCHAR(20)  DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS slave_id     INT          DEFAULT 0,
        ADD COLUMN IF NOT EXISTS ip_address   VARCHAR(45)  DEFAULT NULL
    ");
} catch(PDOException $e) {} // ignore if already exists

$existing = $pdo->prepare("SELECT id, api_key FROM hardware_devices WHERE hardware_id=?");
$existing->execute([$hardware_id]);
$device = $existing->fetch();

if ($device) {
    // Already registered — update last_seen and return existing key
    $pdo->prepare("UPDATE hardware_devices
        SET is_online=1, last_seen=NOW(), ip_address=?,
            firmware_ver=?, zone_id=COALESCE(?,zone_id)
        WHERE id=?")
        ->execute([$ip_address, $firmware_ver ?: null, $zone_id, $device['id']]);

    echo json_encode([
        'status'    => 'exists',
        'message'   => 'Device already registered',
        'api_key'   => $device['api_key'],
        'device_id' => (int)$device['id'],
    ]);
    exit;
}

// ── New registration — generate API key ───────────────────────
$api_key = bin2hex(random_bytes(20)); // 40 char hex key

$pdo->prepare("INSERT INTO hardware_devices
    (device_name, device_type, hardware_id, api_key, zone_id, slave_id,
     firmware_ver, ip_address, is_online, last_seen, status)
    VALUES (?,?,?,?,?,?,?,?,1,NOW(),'active')")
    ->execute([
        $device_name, $device_type, $hardware_id, $api_key,
        $zone_id, $slave_id, $firmware_ver ?: null, $ip_address,
    ]);

$new_id = (int)$pdo->lastInsertId();

// ── Log to audit_log ──────────────────────────────────────────
try {
    $pdo->prepare("INSERT INTO audit_log
        (action, user_name, entity_label, new_value, result, ip_address)
        VALUES ('device.register','system',:name,:hw,'success',:ip)")
        ->execute([
            'name' => $device_name,
            'hw'   => $hardware_id,
            'ip'   => $ip_address,
        ]);
} catch(PDOException $e) {}

// ── Notify admin via user_notifications ───────────────────────
try {
    $admins = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 3")->fetchAll();
    foreach ($admins as $admin) {
        $pdo->prepare("INSERT INTO user_notifications
            (user_id, type, title, body, is_read)
            VALUES (?, 'device', 'New Device Registered', ?, 0)")
            ->execute([
                $admin['id'],
                "Device '$device_name' (ID: $hardware_id) registered from $ip_address"
            ]);
    }
} catch(PDOException $e) {}

echo json_encode([
    'status'    => 'registered',
    'message'   => 'Device registered successfully',
    'api_key'   => $api_key,
    'device_id' => $new_id,
]);