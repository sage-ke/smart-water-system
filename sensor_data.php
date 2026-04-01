<?php
/*
 * sensor_data.php — SWDS Meru
 * ============================================================
 * Dual-mode file:
 *   1. When included via require_once → provides helper functions
 *      get_all_zones($pdo)  — used by valve_control.php
 *      get_history($pdo, $zone_id, $hours) — used by chart lazy-loader
 *
 *   2. When called directly via browser/AJAX → serves JSON or HTML page
 *      ?all=1              → JSON: all zones with latest readings
 *      ?zone_id=X&history=H → JSON: historical data for SVG charts
 *      (no params)         → HTML page: sensor readings table
 * ============================================================
 */

// ── FUNCTION: get_all_zones ───────────────────────────────────
// Returns array of zones with latest reading, valve status, anomaly flag
if (!function_exists('get_all_zones')) {
function get_all_zones(PDO $pdo): array {
    // Fetch zones with latest reading (flat) + valve device + pump device
    $zones = $pdo->query("
        SELECT wz.*,
               sr.flow_rate, sr.pressure, sr.water_level,
               sr.temperature, sr.ph_level, sr.turbidity,
               sr.pump_status, sr.recorded_at,
               -- valve/sensor device
               vc.id          AS valve_device_id,
               vc.device_name AS valve_device_name,
               vc.is_online   AS valve_online,
               -- pump device
               pc.id          AS pump_device_id,
               pc.device_name AS pump_device_name,
               pc.is_online   AS pump_online
        FROM water_zones wz
        LEFT JOIN sensor_readings sr ON sr.id = (
            SELECT id FROM sensor_readings WHERE zone_id=wz.id ORDER BY recorded_at DESC LIMIT 1
        )
        LEFT JOIN hardware_devices vc ON vc.id = (
            SELECT id FROM hardware_devices WHERE zone_id=wz.id
            AND device_type IN ('valve_controller','sensor_node')
            ORDER BY is_online DESC, id ASC LIMIT 1
        )
        LEFT JOIN hardware_devices pc ON pc.id = (
            SELECT id FROM hardware_devices WHERE zone_id=wz.id
            AND device_type='pump_controller'
            ORDER BY is_online DESC, id ASC LIMIT 1
        )
        ORDER BY wz.zone_name ASC
    ")->fetchAll();

    $result = [];
    foreach ($zones as $z) {
        // Anomaly detection
        $anomaly = ['detected'=>false,'type'=>null,'description'=>null,'methods'=>[]];
        $f = (float)($z['flow_rate'] ?? 0);
        $p = (float)($z['pressure'] ?? 0);
        $l = (float)($z['water_level'] ?? 100);
        if ($p > 4.0 && $f < 5 && $p > 0) {
            $anomaly = ['detected'=>true,'type'=>'pressure_no_flow',
                'description'=>'High pressure with near-zero flow — possible blockage',
                'methods'=>['threshold','divergence']];
        } elseif ($f > 80) {
            $anomaly = ['detected'=>true,'type'=>'critical_spike',
                'description'=>'Flow critically high — possible pipe burst',
                'methods'=>['threshold','zscore','iqr']];
        } elseif ($l < 10 && $z['recorded_at']) {
            $anomaly = ['detected'=>true,'type'=>'low_level',
                'description'=>'Tank level critically low','methods'=>['threshold']];
        }

        $quality = [
            'pressure_ok' => ($p >= 1.5 && $p <= 6.0),
            'flow_ok'     => ($f >= 5),
            'level_ok'    => ($l >= 20),
        ];

        // Flat structure — matches what valve_control.php template expects
        $result[] = [
            // Zone core
            'id'              => (int)$z['id'],
            'zone_id'         => (int)$z['id'],
            'zone_name'       => $z['zone_name'],
            'zone_status'     => $z['status'] ?? 'active',
            'status'          => $z['status'] ?? 'active',
            'valve_status'    => strtoupper($z['valve_status'] ?? 'CLOSED'),
            'location'        => $z['location'] ?? '',
            'population'      => (int)($z['population'] ?? 0),
            // Sensor readings (flat)
            'flow_rate'       => $z['flow_rate'] !== null ? round((float)$z['flow_rate'],2) : null,
            'pressure'        => $z['pressure']  !== null ? round((float)$z['pressure'],2)  : null,
            'water_level'     => $z['water_level']!== null ? round((float)$z['water_level'],1): null,
            'temperature'     => $z['temperature']!== null ? round((float)$z['temperature'],1): null,
            'ph_level'        => $z['ph_level']  !== null ? round((float)$z['ph_level'],2)  : null,
            'turbidity'       => $z['turbidity'] !== null ? round((float)$z['turbidity'],2) : null,
            'pump_status'     => $z['pump_status'] ?? 0,
            'recorded_at'     => $z['recorded_at'],
            // Device info (flat — for template)
            'valve_device_id' => $z['valve_device_id'],
            'valve_device_name'=> $z['valve_device_name'],
            'valve_online'    => (bool)($z['valve_online'] ?? false),
            'pump_device_id'  => $z['pump_device_id'],
            'pump_device_name'=> $z['pump_device_name'],
            'pump_online'     => (bool)($z['pump_online'] ?? false),
            'online'          => (bool)($z['valve_online'] ?? $z['pump_online'] ?? false),
            // For v3 AJAX charts
            'reading'         => $z['flow_rate'] !== null ? [
                'flow_rate'     => round((float)$z['flow_rate'],2),
                'pressure'      => round((float)$z['pressure'],2),
                'water_level'   => round((float)$z['water_level'],1),
                'valve_open_pct'=> strtoupper($z['valve_status']??'') === 'OPEN' ? 100 : 0,
                'pump_status'   => $z['pump_status'],
                'recorded_at'   => $z['recorded_at'],
            ] : null,
            'anomaly'         => $anomaly,
            'quality'         => $quality,
        ];
    }
    return $result;
}
}

// ── FUNCTION: get_history ─────────────────────────────────────
// Returns time-bucketed readings for SVG charts
if (!function_exists('get_history')) {
function get_history(PDO $pdo, int $zone_id, int $hours = 24): array {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(
                DATE_SUB(recorded_at, INTERVAL MOD(MINUTE(recorded_at),5) MINUTE),
                '%Y-%m-%d %H:%i:00'
            ) AS bucket,
            ROUND(AVG(flow_rate),  2) AS flow_rate,
            ROUND(AVG(pressure),   2) AS pressure,
            ROUND(AVG(water_level),1) AS water_level,
            ROUND(AVG(ph_level),   2) AS ph_level,
            ROUND(AVG(turbidity),  2) AS turbidity
        FROM sensor_readings
        WHERE zone_id=?
          AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY bucket
        ORDER BY bucket ASC
        LIMIT 500
    ");
    $stmt->execute([$zone_id, $hours]);
    return $stmt->fetchAll();
}
}

// ── DIRECT REQUEST HANDLING ───────────────────────────────────
// Only run page/API logic when this file is accessed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {

    session_start();
    require_once __DIR__ . '/db.php';

    // ── JSON API mode ─────────────────────────────────────────
    if (isset($_GET['all']) || isset($_GET['history'])) {

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error'=>'Not authenticated']);
            exit;
        }

        header('Content-Type: application/json');

        // All zones (for live polling)
        if (isset($_GET['all'])) {
            $zones = get_all_zones($pdo);
            $etag  = md5(json_encode(array_column($zones,'reading')));
            header("ETag: $etag");
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                http_response_code(304); exit;
            }
            echo json_encode(['zones'=>$zones,'ts'=>date('c')]);
            exit;
        }

        // Historical data for chart
        if (isset($_GET['zone_id'])) {
            $zid   = (int)$_GET['zone_id'];
            $hours = (int)($_GET['history'] ?? 24);
            $hours = in_array($hours,[6,12,24,48,72,168]) ? $hours : 24;
            echo json_encode(['history'=>get_history($pdo,$zid,$hours)]);
            exit;
        }
    }

    // ── HTML page mode ────────────────────────────────────────
    if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

    $user_name  = $_SESSION['user_name'];
    $user_email = $_SESSION['user_email'];
    $user_role  = $_SESSION['user_role'];
    $current_page = 'sensors';
    $page_title   = 'Sensor Data';
    $total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
    $msg = ''; $msg_type = '';

    // Handle ADD reading
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_reading') {
        $zone_id     = (int)($_POST['zone_id']    ?? 0);
        $flow_rate   = (float)($_POST['flow_rate']  ?? 0);
        $pressure    = (float)($_POST['pressure']   ?? 0);
        $water_level = (float)($_POST['water_level']?? 0);
        $temperature = (float)($_POST['temperature']?? 0);
        $ph_level    = (float)($_POST['ph_level']   ?? 7.0);
        $turbidity   = (float)($_POST['turbidity']  ?? 1.0);
        if ($zone_id) {
            $pdo->prepare("INSERT INTO sensor_readings
                (zone_id,flow_rate,pressure,water_level,temperature,ph_level,turbidity)
                VALUES (?,?,?,?,?,?,?)")
                ->execute([$zone_id,$flow_rate,$pressure,$water_level,$temperature,$ph_level,$turbidity]);
            $msg = "✅ Sensor reading recorded."; $msg_type = 'success';
        } else { $msg = "Please select a zone."; $msg_type = 'error'; }
    }

    $zones = $pdo->query("SELECT id,zone_name FROM water_zones ORDER BY zone_name")->fetchAll();
    $readings = $pdo->query("
        SELECT sr.*, wz.zone_name FROM sensor_readings sr
        LEFT JOIN water_zones wz ON wz.id=sr.zone_id
        ORDER BY sr.recorded_at DESC LIMIT 50
    ")->fetchAll();
    $stats = $pdo->query("
        SELECT AVG(flow_rate) AS avg_flow, AVG(pressure) AS avg_pressure,
               AVG(water_level) AS avg_level, AVG(temperature) AS avg_temp,
               MAX(flow_rate) AS max_flow, MIN(water_level) AS min_level
        FROM sensor_readings
    ")->fetch();

    require_once __DIR__ . '/sidebar.php';
?>

<?php if ($msg): ?>
    <div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom:2rem">
    <div class="stat-card"><div class="stat-icon">💧</div><div class="stat-label">Avg Flow Rate</div><div class="stat-value c-blue"><?= round($stats['avg_flow']??0,1) ?><span style="font-size:1rem"> L/m</span></div></div>
    <div class="stat-card"><div class="stat-icon">⚡</div><div class="stat-label">Avg Pressure</div><div class="stat-value c-teal"><?= round($stats['avg_pressure']??0,1) ?><span style="font-size:1rem"> Bar</span></div></div>
    <div class="stat-card"><div class="stat-icon">🪣</div><div class="stat-label">Avg Water Level</div><div class="stat-value c-green"><?= round($stats['avg_level']??0,1) ?><span style="font-size:1rem">%</span></div></div>
    <div class="stat-card"><div class="stat-icon">🌡️</div><div class="stat-label">Avg Temperature</div><div class="stat-value c-yellow"><?= round($stats['avg_temp']??0,1) ?><span style="font-size:1rem">°C</span></div></div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="section-title" style="margin-bottom:0">📡 Recent Sensor Readings</div>
    <button class="btn-primary btn-sm" onclick="document.getElementById('addModal').classList.add('open')">+ Log Reading</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Zone</th><th>Flow</th><th>Pressure</th><th>Level</th><th>pH</th><th>Turbidity</th><th>Temp</th><th>Recorded At</th></tr></thead>
            <tbody>
            <?php if (empty($readings)): ?>
                <tr><td colspan="9"><div class="empty-state"><div class="icon">📡</div>No sensor readings yet.</div></td></tr>
            <?php else: foreach ($readings as $r): ?>
            <tr>
                <td style="color:var(--muted)"><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['zone_name']??'—') ?></td>
                <td><?= $r['flow_rate'] ?> <span style="color:var(--muted);font-size:.75rem">L/m</span></td>
                <td><?= $r['pressure'] ?> <span style="color:var(--muted);font-size:.75rem">Bar</span></td>
                <td><?php $l=(float)$r['water_level']; $c=$l<30?'c-red':($l<60?'c-yellow':'c-green'); ?><span class="<?=$c?>"><?=$l?>%</span></td>
                <td><?= $r['ph_level']??'—' ?></td>
                <td><?= $r['turbidity']??'—' ?> <span style="color:var(--muted);font-size:.75rem">NTU</span></td>
                <td><?= $r['temperature'] ?><span style="color:var(--muted);font-size:.75rem">°C</span></td>
                <td style="color:var(--muted);font-size:.82rem"><?= date('d M Y H:i',strtotime($r['recorded_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD READING MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title">📡 Log Sensor Reading</div>
        <form method="post">
            <input type="hidden" name="action" value="add_reading">
            <div class="form-group">
                <label class="form-label">Zone *</label>
                <select name="zone_id" class="form-control" required>
                    <option value="">— Select Zone —</option>
                    <?php foreach($zones as $z): ?>
                    <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['zone_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid-2">
                <div class="form-group"><label class="form-label">Flow Rate (L/min)</label><input type="number" step="0.01" name="flow_rate" class="form-control" value="0"></div>
                <div class="form-group"><label class="form-label">Pressure (Bar)</label><input type="number" step="0.01" name="pressure" class="form-control" value="0"></div>
                <div class="form-group"><label class="form-label">Water Level (%)</label><input type="number" step="0.1" min="0" max="100" name="water_level" class="form-control" value="0"></div>
                <div class="form-group"><label class="form-label">Temperature (°C)</label><input type="number" step="0.1" name="temperature" class="form-control" value="25"></div>
                <div class="form-group"><label class="form-label">pH Level</label><input type="number" step="0.01" min="0" max="14" name="ph_level" class="form-control" value="7.0"></div>
                <div class="form-group"><label class="form-label">Turbidity (NTU)</label><input type="number" step="0.01" name="turbidity" class="form-control" value="1.0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn-primary">Save Reading</button>
            </div>
        </form>
    </div>
</div>
</main></body></html>
<?php } // end direct-access block