<?php
/*
 * api/insert_sensor.php — SWDS Meru
 * ============================================================
 * Direct sensor insert endpoint.
 * Accepts a single reading, validates fields, inserts to DB.
 * Also triggers anomaly check against ML anomaly_log.
 *
 * POST body (JSON):
 *   { "api_key":"...", "flow_rate":45.2, "pressure":3.1,
 *     "water_level":72, "temperature":22, "ph_level":7.2,
 *     "turbidity":1.1, "pump_status":1, "valve_open_pct":100 }
 *
 * Response:
 *   { "status":"ok", "reading_id":123, "anomaly_detected":false,
 *     "ml_anomaly": null, "commands":[] }
 * ============================================================
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['status'=>'error','message'=>'POST only']); exit;
}

require_once __DIR__ . '/../db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;
if (!$data) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'No data']); exit; }

// ── Auth ──────────────────────────────────────────────────────
$api_key = trim($data['api_key'] ?? '');
if (!$api_key) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'api_key required']); exit; }
$device = $pdo->prepare("SELECT * FROM hardware_devices WHERE api_key=?");
$device->execute([$api_key]);
$device = $device->fetch();
if (!$device) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'Invalid API key']); exit; }

// ── Heartbeat ─────────────────────────────────────────────────
$pdo->prepare("UPDATE hardware_devices SET is_online=1, last_seen=NOW(), ip_address=? WHERE id=?")
    ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $device['id']]);

// ── Ensure columns exist ──────────────────────────────────────
try {
    foreach (['total_litres FLOAT','slave_id TINYINT','rssi SMALLINT'] as $col) {
        $pdo->exec("ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS $col DEFAULT NULL");
    }
} catch (PDOException $e) {}

// ── Validate numeric fields ───────────────────────────────────
$flow    = isset($data['flow_rate'])      ? (float)$data['flow_rate']      : null;
$pres    = isset($data['pressure'])       ? (float)$data['pressure']       : null;
$level   = isset($data['water_level'])    ? (float)$data['water_level']    : null;
$temp    = isset($data['temperature'])    ? (float)$data['temperature']    : null;
$turb    = isset($data['turbidity'])      ? (float)$data['turbidity']      : null;
$ph      = isset($data['ph_level'])       ? (float)$data['ph_level']       : null;
$tds     = isset($data['tds_ppm'])        ? (int)$data['tds_ppm']          : null;
$vopen   = isset($data['valve_open_pct']) ? (int)$data['valve_open_pct']   : 100;
$pump    = isset($data['pump_status'])    ? (int)$data['pump_status']      : 0;
$litres  = isset($data['total_litres'])   ? (float)$data['total_litres']   : null;
$sid     = isset($data['slave_id'])       ? (int)$data['slave_id']         : null;
$rssi    = isset($data['rssi'])           ? (int)$data['rssi']             : null;

// ── Insert ────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO sensor_readings
    (device_id,zone_id,flow_rate,pressure,water_level,temperature,
     turbidity,ph_level,tds_ppm,valve_open_pct,pump_status,
     total_litres,slave_id,rssi)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
->execute([$device['id'],$device['zone_id'],$flow,$pres,$level,$temp,
           $turb,$ph,$tds,$vopen,$pump,$litres,$sid,$rssi]);
$rid = (int)$pdo->lastInsertId();

// ── Check if ML engine flagged this zone recently ─────────────
$ml_anomaly = null;
try {
    $ml = $pdo->prepare("
        SELECT anomaly_type, expected_value, actual_value,
               deviation_pct, severity_score, ml_confidence, detected_at
        FROM anomaly_log
        WHERE zone_id = ? AND is_resolved = 0
          AND detected_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY detected_at DESC LIMIT 1
    ");
    $ml->execute([$device['zone_id']]);
    $ml_anomaly = $ml->fetch() ?: null;
} catch (PDOException $e) {}

// ── Simple threshold anomaly check (fallback) ─────────────────
$anomaly_detected = false;
$anomaly_msg = null;
if ($flow !== null) {
    $stats = $pdo->prepare("SELECT AVG(flow_rate) m, STDDEV(flow_rate) s FROM sensor_readings
        WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats->execute([$device['zone_id']]);
    $st = $stats->fetch();
    if ($st['m'] > 0 && $st['s'] > 0) {
        $z = abs($flow - $st['m']) / $st['s'];
        if ($z > 2.5) {
            $anomaly_detected = true;
            $anomaly_msg = "Z-score $z — flow $flow deviates significantly from 7-day mean {$st['m']}";
            // Push to alerts table
            $pdo->prepare("INSERT INTO alerts (zone_id,device_id,alert_type,message,severity) VALUES (?,?,?,?,?)")
                ->execute([$device['zone_id'],$device['id'],'flow_anomaly',$anomaly_msg,'high']);
        }
    }
}

// ── Pending commands ──────────────────────────────────────────
$cmds = $pdo->prepare("SELECT * FROM device_commands WHERE device_id=? AND status='pending' ORDER BY issued_at ASC LIMIT 5");
$cmds->execute([$device['id']]);
$pending = $cmds->fetchAll();
if ($pending) {
    $ids = implode(',', array_column($pending,'id'));
    $pdo->exec("UPDATE device_commands SET status='sent' WHERE id IN ($ids)");
}

echo json_encode([
    'status'           => 'ok',
    'reading_id'       => $rid,
    'zone_id'          => (int)$device['zone_id'],
    'anomaly_detected' => $anomaly_detected,
    'anomaly_msg'      => $anomaly_msg,
    'ml_anomaly'       => $ml_anomaly,  // ← ML engine result passed back to device
    'commands'         => array_map(fn($c)=>[
        'id'      => $c['id'],
        'type'    => $c['command_type'],
        'payload' => json_decode($c['payload'],true)
    ], $pending)
]);