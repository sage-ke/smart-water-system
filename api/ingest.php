<?php
/*
 * api/ingest.php — Hardware Data Ingestion Endpoint
 * ============================================================
 * IoT sensor nodes (Arduino/ESP32/Raspberry Pi) POST their
 * readings to this URL with their API key for authentication.
 *
 * HOW HARDWARE CALLS THIS:
 *   POST http://your-server/smart_water/api/ingest.php
 *   Headers: Content-Type: application/json
 *   Body: {
 *     "api_key": "device-secret-key",
 *     "flow_rate": 45.2,
 *     "pressure": 3.5,
 *     "water_level": 78.0,
 *     "temperature": 22.5,
 *     "turbidity": 1.2,
 *     "ph_level": 7.1,
 *     "tds_ppm": 320,
 *     "valve_open_pct": 100,
 *     "pump_status": 1,
 *     "battery_pct": 87,
 *     "signal_strength": -65
 *   }
 *
 * RETURNS JSON:
 *   {"status":"ok","message":"Reading saved","reading_id":123,"commands":[...]}
 *
 * ARDUINO/ESP32 EXAMPLE CODE (paste into your sketch):
 *   #include <WiFi.h>
 *   #include <HTTPClient.h>
 *   #include <ArduinoJson.h>
 *   const char* serverUrl = "http://YOUR_PC_IP/smart_water/api/ingest.php";
 *   const char* apiKey = "YOUR_DEVICE_API_KEY";
 *   void sendReading(float flow, float pressure, float level) {
 *     HTTPClient http;
 *     http.begin(serverUrl);
 *     http.addHeader("Content-Type","application/json");
 *     String body = "{\"api_key\":\""+String(apiKey)+"\","
 *                   "\"flow_rate\":"+String(flow)+","
 *                   "\"pressure\":"+String(pressure)+","
 *                   "\"water_level\":"+String(level)+"}";
 *     int code = http.POST(body);
 *     String response = http.getString();
 *     http.end();
 *   }
 * ============================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow hardware from any IP

require_once __DIR__ . '/../db.php';

// ── Only accept POST requests ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'Only POST allowed']);
    exit;
}

// ── Read JSON body ───────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON body']);
    exit;
}

// ── Authenticate device by API key ───────────────────────────
$api_key = trim($data['api_key'] ?? '');
if (!$api_key) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'api_key required']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM hardware_devices WHERE api_key = ?");
$stmt->execute([$api_key]);
$device = $stmt->fetch();

if (!$device) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid API key']);
    exit;
}

// ── Update device heartbeat ──────────────────────────────────
$pdo->prepare("UPDATE hardware_devices SET is_online=1, last_seen=NOW(),
    ip_address=?, battery_pct=?, signal_strength=? WHERE id=?")
    ->execute([
        $_SERVER['REMOTE_ADDR'] ?? null,
        (int)($data['battery_pct']     ?? $device['battery_pct']),
        (int)($data['signal_strength'] ?? $device['signal_strength']),
        $device['id']
    ]);

// ── Save sensor reading ───────────────────────────────────────
// Add total_litres column if it doesn't exist yet
try {
    $pdo->exec("ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS total_litres FLOAT DEFAULT NULL");
    $pdo->exec("ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS slave_id TINYINT DEFAULT NULL");
    $pdo->exec("ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS rssi SMALLINT DEFAULT NULL");
} catch (\PDOException $e) {}

$stmt = $pdo->prepare("
    INSERT INTO sensor_readings
        (device_id, zone_id, flow_rate, pressure, water_level, temperature,
         turbidity, ph_level, tds_ppm, valve_open_pct, pump_status,
         total_litres, slave_id, rssi)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$stmt->execute([
    $device['id'],
    $device['zone_id'],
    isset($data['flow_rate'])      ? (float)$data['flow_rate']      : null,
    isset($data['pressure'])       ? (float)$data['pressure']       : null,
    isset($data['water_level'])    ? (float)$data['water_level']    : null,
    isset($data['temperature'])    ? (float)$data['temperature']    : null,
    isset($data['turbidity'])      ? (float)$data['turbidity']      : null,
    isset($data['ph_level'])       ? (float)$data['ph_level']       : null,
    isset($data['tds_ppm'])        ? (int)$data['tds_ppm']          : null,
    isset($data['valve_open_pct']) ? (int)$data['valve_open_pct']   : 100,
    isset($data['pump_status'])    ? (int)$data['pump_status']      : 0,
    isset($data['total_litres'])   ? (float)$data['total_litres']   : null,
    isset($data['slave_id'])       ? (int)$data['slave_id']         : null,
    isset($data['rssi'])           ? (int)$data['rssi']             : null,
]);
$reading_id = $pdo->lastInsertId();

// ── Auto-generate alerts based on thresholds ─────────────────
$settings = [];
foreach ($pdo->query("SELECT setting_key, setting_val FROM system_settings")->fetchAll() as $s) {
    $settings[$s['setting_key']] = $s['setting_val'];
}

$auto_alerts = [];

if (isset($data['pressure']) && (float)$data['pressure'] < (float)($settings['alert_pressure_min']??2.5)) {
    $auto_alerts[] = ['Low Pressure', "Pressure {$data['pressure']} Bar below minimum threshold in device {$device['device_code']}", 'high'];
}
if (isset($data['water_level']) && (float)$data['water_level'] < (float)($settings['alert_level_min']??20)) {
    $auto_alerts[] = ['Critical Low Level', "Water level {$data['water_level']}% critically low in device {$device['device_code']}", 'critical'];
}
if (isset($data['ph_level'])) {
    $ph = (float)$data['ph_level'];
    if ($ph < (float)($settings['alert_ph_min']??6.5) || $ph > (float)($settings['alert_ph_max']??8.5)) {
        $auto_alerts[] = ['pH Out of Range', "pH level $ph is outside safe range 6.5–8.5 in device {$device['device_code']}", 'high'];
    }
}
if (isset($data['turbidity']) && (float)$data['turbidity'] > (float)($settings['alert_turbidity_max']??4.0)) {
    $auto_alerts[] = ['High Turbidity', "Turbidity {$data['turbidity']} NTU exceeds safe limit in device {$device['device_code']}", 'medium'];
}
if (isset($data['flow_rate']) && (float)$data['flow_rate'] < (float)($settings['alert_flow_min']??10.0) && (int)($data['pump_status']??0) === 1) {
    $auto_alerts[] = ['Low Flow', "Abnormally low flow {$data['flow_rate']} L/min despite pump running in {$device['device_code']}", 'high'];
}

require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/mailer.php';
foreach ($auto_alerts as [$type, $msg, $sev]) {
    $pdo->prepare("INSERT INTO alerts (zone_id,device_id,alert_type,message,severity) VALUES (?,?,?,?,?)")
        ->execute([$device['zone_id'], $device['id'], $type, $msg, $sev]);
    // Auto SMS + Email for high/critical alerts
    send_sms_alert($pdo, "[$type] $msg", $sev);
    send_email_alert($pdo, "[$type] $msg", $sev, $device['zone_name'] ?? '');
}

// ── Return pending commands to device ────────────────────────
$cmds = $pdo->prepare("SELECT * FROM device_commands WHERE device_id=? AND status='pending' ORDER BY issued_at ASC LIMIT 5");
$cmds->execute([$device['id']]);
$pending_commands = $cmds->fetchAll();

// Mark them as sent
if ($pending_commands) {
    $ids = implode(',', array_column($pending_commands, 'id'));
    $pdo->exec("UPDATE device_commands SET status='sent' WHERE id IN ($ids)");
}

// ── Respond ───────────────────────────────────────────────────
echo json_encode([
    'status'     => 'ok',
    'message'    => 'Reading saved',
    'reading_id' => (int)$reading_id,
    'zone_id'    => (int)$device['zone_id'],
    'alerts_created' => count($auto_alerts),
    'commands'   => array_map(fn($c) => [
        'id'      => $c['id'],
        'type'    => $c['command_type'],
        'payload' => json_decode($c['payload'], true)
    ], $pending_commands)
]);