<?php
/*
 * sensors.php — Sensor Readings
 * View all sensor readings; add new readings manually.
 */
session_start(); require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'sensors';
$page_title   = 'Sensor Data';

$total_alerts = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

$msg = ''; $msg_type = '';

// ── Handle ADD reading ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_reading') {
    $zone_id     = (int)($_POST['zone_id']    ?? 0);
    $flow_rate   = (float)($_POST['flow_rate']  ?? 0);
    $pressure    = (float)($_POST['pressure']   ?? 0);
    $water_level = (float)($_POST['water_level']?? 0);
    $temperature = (float)($_POST['temperature']?? 0);
    if ($zone_id) {
        $pdo->prepare("INSERT INTO sensor_readings (zone_id,flow_rate,pressure,water_level,temperature) VALUES (?,?,?,?,?)")
            ->execute([$zone_id,$flow_rate,$pressure,$water_level,$temperature]);
        $msg = "✅ Sensor reading recorded."; $msg_type = 'success';
    } else { $msg = "Please select a zone."; $msg_type = 'error'; }
}

// ── Fetch zones for dropdown ─────────────────────────────────
$zones = $pdo->query("SELECT id,zone_name FROM water_zones ORDER BY zone_name")->fetchAll();

// ── Fetch latest 50 readings ─────────────────────────────────
$readings = $pdo->query("
    SELECT sr.*, wz.zone_name
    FROM sensor_readings sr
    LEFT JOIN water_zones wz ON wz.id=sr.zone_id
    ORDER BY sr.recorded_at DESC
    LIMIT 50
")->fetchAll();

// ── Summary stats ────────────────────────────────────────────
$stats = $pdo->query("
    SELECT
        AVG(flow_rate)   AS avg_flow,
        AVG(pressure)    AS avg_pressure,
        AVG(water_level) AS avg_level,
        AVG(temperature) AS avg_temp,
        MAX(flow_rate)   AS max_flow,
        MIN(water_level) AS min_level
    FROM sensor_readings
")->fetch();

require_once __DIR__ . '/sidebar.php';
?>

<?php if ($msg): ?>
    <div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="stats-grid" style="margin-bottom:2rem;">
    <div class="stat-card"><div class="stat-icon">💧</div><div class="stat-label">Avg Flow Rate</div><div class="stat-value c-blue"><?= round($stats['avg_flow']??0,1) ?><span style="font-size:1rem"> L/m</span></div></div>
    <div class="stat-card"><div class="stat-icon">⚡</div><div class="stat-label">Avg Pressure</div><div class="stat-value c-teal"><?= round($stats['avg_pressure']??0,1) ?><span style="font-size:1rem"> Bar</span></div></div>
    <div class="stat-card"><div class="stat-icon">🪣</div><div class="stat-label">Avg Water Level</div><div class="stat-value c-green"><?= round($stats['avg_level']??0,1) ?><span style="font-size:1rem">%</span></div></div>
    <div class="stat-card"><div class="stat-icon">🌡️</div><div class="stat-label">Avg Temperature</div><div class="stat-value c-yellow"><?= round($stats['avg_temp']??0,1) ?><span style="font-size:1rem">°C</span></div></div>
</div>

<!-- Add reading + table header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div class="section-title" style="margin-bottom:0;">📡 Recent Sensor Readings</div>
    <button class="btn-primary btn-sm" onclick="document.getElementById('addModal').classList.add('open')">+ Log Reading</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Zone</th>
                    <th>Flow Rate</th>
                    <th>Pressure</th>
                    <th>Water Level</th>
                    <th>Temperature</th>
                    <th>Recorded At</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($readings)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="icon">📡</div>No sensor readings yet.</div></td></tr>
            <?php else: ?>
                <?php foreach ($readings as $r): ?>
                <tr>
                    <td style="color:var(--muted)"><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['zone_name']??'—') ?></td>
                    <td><?= $r['flow_rate'] ?> <span style="color:var(--muted);font-size:0.75rem">L/min</span></td>
                    <td><?= $r['pressure'] ?> <span style="color:var(--muted);font-size:0.75rem">Bar</span></td>
                    <td>
                        <?php $lvl=(float)$r['water_level']; $bc=$lvl<30?'c-red':($lvl<60?'c-yellow':'c-green'); ?>
                        <span class="<?= $bc ?>"><?= $lvl ?>%</span>
                    </td>
                    <td><?= $r['temperature'] ?> <span style="color:var(--muted);font-size:0.75rem">°C</span></td>
                    <td style="color:var(--muted);font-size:0.82rem"><?= date('d M Y H:i', strtotime($r['recorded_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD READING MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title">📡 Log Sensor Reading</div>
        <form method="post" action="sensors.php">
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
                <div class="form-group">
                    <label class="form-label">Flow Rate (L/min)</label>
                    <input type="number" step="0.01" name="flow_rate" class="form-control" placeholder="0.00" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Pressure (Bar)</label>
                    <input type="number" step="0.01" name="pressure" class="form-control" placeholder="0.00" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Water Level (%)</label>
                    <input type="number" step="0.1" min="0" max="100" name="water_level" class="form-control" placeholder="0.0" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Temperature (°C)</label>
                    <input type="number" step="0.1" name="temperature" class="form-control" placeholder="0.0" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn-primary">Save Reading</button>
            </div>
        </form>
    </div>
</div>

</main></body></html>