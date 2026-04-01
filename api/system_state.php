<?php
// ================================================================
//  api/system_state.php  ·  SWDS Meru
//  ----------------------------------------------------------------
//  GET-only endpoint. Returns a complete, real-time snapshot of
//  the entire system in a single response. Used by:
//    • Admin dashboard (polling every 30 s for live updates)
//    • Resident dashboard (polling every 60 s)
//    • External monitoring tools
//    • ESP32 gateway (for system-wide awareness)
//
//  REQUEST:
//    GET /api/system_state.php
//    GET /api/system_state.php?zone_id=2        ← single zone
//    GET /api/system_state.php?api_key=DEVICE   ← device auth
//
//  RESPONSE SHAPE:
//  {
//    "status": "ok",
//    "system": {
//      "name":        "SWDS Meru",
//      "health":      "normal" | "degraded" | "critical",
//      "zones_total": 5,
//      "zones_online": 4,
//      "zones_offline": 1,
//      "zones_anomaly": 1,
//      "open_alerts":  3,
//      "critical_alerts": 1,
//      "avg_water_level": 72.4,
//      "total_flow":   182.5,       ← sum L/min across all zones
//      "unread_commands": 2,
//      "generated_at": "2024-..."
//    },
//    "zones": [
//      {
//        "zone_id", "zone_name", "valve_status", "sys_state",
//        "online", "seconds_since",
//        "reading": { flow_rate, pressure, water_level, ... },
//        "anomaly": { detected, type, description },
//        "reliability": { score, label },
//        "quality": { ph_ok, turbidity_ok, pressure_ok }
//      }, ...
//    ],
//    "alerts":   [ { id, alert_type, message, severity, zone_name, created_at }, ... ],
//    "commands": [ { id, zone_name, command_type, payload, status, issued_at }, ... ],
//    "settings": { water_rate_kes, alert_pressure_min, ... }
//  }
// ================================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../sensor_data.php';   // get_zone_data(), get_all_zones()

only('GET');

// ── Auth: session user OR device api_key ─────────────────────
$api_key = trim($_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
if ($api_key) {
    auth_device($pdo);
} else {
    auth_session(['admin', 'operator', 'viewer']);
}

// ── Optional single-zone mode ─────────────────────────────────
$zone_id = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;

if ($zone_id) {
    // Single zone — return just that zone's data + active alerts for it
    $zdata = get_zone_data($pdo, $zone_id);
    if (isset($zdata['error'])) api_err($zdata['error'], 404);

    $zone_alerts = $pdo->prepare("
        SELECT a.id, a.alert_type, a.message, a.severity, a.created_at
        FROM   alerts a
        WHERE  a.zone_id = ? AND a.is_resolved = 0
        ORDER  BY FIELD(a.severity,'critical','high','medium','low'), a.created_at DESC
        LIMIT  10
    ");
    $zone_alerts->execute([$zone_id]);
    $zdata['alerts']       = $zone_alerts->fetchAll();
    $zdata['generated_at'] = date('c');

    api_ok($zdata);
}

// ================================================================
//  FULL SYSTEM SNAPSHOT
// ================================================================

// ── All zones with sensor data ────────────────────────────────
$zones = get_all_zones($pdo);

// ── Aggregate system metrics ──────────────────────────────────
$total_zones   = count($zones);
$online_zones  = 0;
$offline_zones = 0;
$anomaly_zones = 0;
$total_flow    = 0.0;
$level_sum     = 0.0;
$level_count   = 0;

foreach ($zones as $z) {
    if ($z['online']) {
        $online_zones++;
        if ($z['reading']) {
            $total_flow += (float)$z['reading']['flow_rate'];
            if ($z['reading']['water_level'] > 0) {
                $level_sum += (float)$z['reading']['water_level'];
                $level_count++;
            }
        }
    } else {
        $offline_zones++;
    }
    if ($z['anomaly']['detected']) $anomaly_zones++;
}

$avg_water_level = $level_count > 0 ? round($level_sum / $level_count, 1) : 0;

// ── Alert counts ──────────────────────────────────────────────
$alert_counts = $pdo->query("
    SELECT
        COUNT(*)                                          AS total,
        SUM(severity = 'critical')                        AS critical,
        SUM(severity = 'high')                            AS high,
        SUM(severity IN ('medium','low'))                 AS medium_low
    FROM alerts WHERE is_resolved = 0
")->fetch();

$open_alerts     = (int)($alert_counts['total']    ?? 0);
$critical_alerts = (int)($alert_counts['critical'] ?? 0);

// ── Unacknowledged commands ───────────────────────────────────
$pending_cmds = (int)$pdo->query(
    "SELECT COUNT(*) FROM device_commands WHERE status IN ('pending','sent')"
)->fetchColumn();

// ── System health: derived from all signals ───────────────────
if ($critical_alerts > 0 || $offline_zones >= $total_zones / 2) {
    $health = 'critical';
} elseif ($open_alerts > 0 || $offline_zones > 0 || $anomaly_zones > 0) {
    $health = 'degraded';
} else {
    $health = 'normal';
}

// ── Active alerts (last 20) ───────────────────────────────────
$alerts = $pdo->query("
    SELECT a.id, a.alert_type, a.message, a.severity, a.created_at,
           wz.zone_name,
           hd.device_code
    FROM   alerts a
    LEFT JOIN water_zones      wz ON wz.id = a.zone_id
    LEFT JOIN hardware_devices hd ON hd.id = a.device_id
    WHERE  a.is_resolved = 0
    ORDER  BY FIELD(a.severity,'critical','high','medium','low'), a.created_at DESC
    LIMIT  20
")->fetchAll();

// ── Pending / recent commands (last 20) ──────────────────────
$commands = $pdo->query("
    SELECT dc.id, dc.command_type, dc.payload, dc.status, dc.issued_at, dc.ack_at,
           wz.zone_name,
           hd.device_code,
           u.full_name AS issued_by_name
    FROM   device_commands dc
    LEFT JOIN hardware_devices hd ON hd.id = dc.device_id
    LEFT JOIN water_zones      wz ON wz.id = hd.zone_id
    LEFT JOIN users             u ON u.id  = dc.issued_by
    ORDER  BY dc.issued_at DESC
    LIMIT  20
")->fetchAll();

// Decode JSON payload for each command
foreach ($commands as &$cmd) {
    $cmd['payload'] = json_decode($cmd['payload'] ?? '{}', true);
}
unset($cmd);

// ── Relevant system settings (non-sensitive subset) ───────────
$setting_keys = [
    'water_rate_kes','alert_pressure_min','alert_flow_min',
    'alert_level_min','alert_ph_min','alert_ph_max',
    'alert_turbidity_max','valve_timeout_sec','anomaly_iqr_factor',
];
$settings_rows = $pdo->query("
    SELECT setting_key, setting_val FROM system_settings
    WHERE setting_key IN ('" . implode("','", $setting_keys) . "')
")->fetchAll();
$settings = [];
foreach ($settings_rows as $s) $settings[$s['setting_key']] = $s['setting_val'];

// ── System name from settings ─────────────────────────────────
$sys_name = $pdo->query(
    "SELECT setting_val FROM system_settings WHERE setting_key='system_name' LIMIT 1"
)->fetchColumn() ?: 'SWDS Meru';

// ── Assemble and return ───────────────────────────────────────
api_ok([
    'system' => [
        'name'             => $sys_name,
        'health'           => $health,
        'zones_total'      => $total_zones,
        'zones_online'     => $online_zones,
        'zones_offline'    => $offline_zones,
        'zones_anomaly'    => $anomaly_zones,
        'open_alerts'      => $open_alerts,
        'critical_alerts'  => $critical_alerts,
        'avg_water_level'  => $avg_water_level,
        'total_flow_lpm'   => round($total_flow, 2),
        'unacked_commands' => $pending_cmds,
        'generated_at'     => date('c'),
    ],
    'zones'    => $zones,
    'alerts'   => $alerts,
    'commands' => $commands,
    'settings' => $settings,
]);