<?php
/*
 * dashboard.php — Admin/Operator Main Dashboard
 */
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name']  ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$user_role  = $_SESSION['user_role']  ?? 'viewer';
$current_page = 'dashboard';
$page_title   = 'Dashboard';

// Redirect residents away from admin dashboard
if (!in_array($user_role, ['admin','operator'])) {
    header("Location: user_dashboard.php"); exit;
}

// ── Valve toggle ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_zone'])) {
    $zoneId = (int)$_POST['toggle_zone'];
    $pdo->prepare("UPDATE water_zones SET valve_status=IF(valve_status='OPEN','CLOSED','OPEN') WHERE id=?")
        ->execute([$zoneId]);
    header("Location: dashboard.php"); exit;
}

// ── Metrics ───────────────────────────────────────────────────
$total_zones  = (int)$pdo->query("SELECT COUNT(*) FROM water_zones")->fetchColumn();

$alert_cols   = array_column($pdo->query("SHOW COLUMNS FROM alerts")->fetchAll(),'Field');
$has_resolved = in_array('is_resolved',$alert_cols);
$has_severity = in_array('severity',$alert_cols);

$total_alerts = (int)$pdo->query(
    $has_resolved ? "SELECT COUNT(*) FROM alerts WHERE is_resolved=0" : "SELECT COUNT(*) FROM alerts"
)->fetchColumn();

$high_alerts = ($has_severity && $has_resolved)
    ? (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0 AND severity IN ('high','critical')")->fetchColumn()
    : 0;

$usageToday = (float)$pdo->query("SELECT COALESCE(SUM(flow_rate),0) FROM sensor_readings WHERE DATE(recorded_at)=CURDATE()")->fetchColumn();
$avgFlow    = (float)$pdo->query("SELECT COALESCE(AVG(flow_rate),0) FROM sensor_readings")->fetchColumn();

// ── Real anomaly threshold: use Z-score std dev, not naive *2 ──
$flow_stats = $pdo->query("SELECT AVG(flow_rate) as mean, STDDEV(flow_rate) as std FROM sensor_readings WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();
$anomalyThreshold = ($flow_stats['mean'] > 0 && $flow_stats['std'] > 0)
    ? round((float)$flow_stats['mean'] + 2.5 * (float)$flow_stats['std'], 1)
    : ($avgFlow > 0 ? $avgFlow * 2 : 999);

// ── ML anomalies from Python engine ───────────────────────────
$ml_anomaly_count = 0;
try {
    $ml_anomaly_count = (int)$pdo->query("SELECT COUNT(*) FROM anomaly_log WHERE is_resolved=0 AND detected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
} catch (PDOException $e) {}

// ── Today's ML predictions (for dashboard widget) ─────────────
$ml_predictions = [];
try {
    $ml_predictions = $pdo->query("
        SELECT p.predict_date, p.predicted_flow, p.predicted_level,
               p.predicted_demand, p.confidence_pct, wz.zone_name
        FROM predictions p
        JOIN water_zones wz ON wz.id = p.zone_id
        WHERE p.predict_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        ORDER BY p.zone_id, p.predict_date ASC
        LIMIT 15
    ")->fetchAll();
} catch (PDOException $e) {}

// ── Recent ML anomalies for dashboard panel ────────────────────
$ml_anomalies = [];
try {
    $ml_anomalies = $pdo->query("
        SELECT al.*, wz.zone_name
        FROM anomaly_log al
        LEFT JOIN water_zones wz ON wz.id = al.zone_id
        WHERE al.is_resolved = 0
        ORDER BY al.detected_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {}

$avg_level = (float)$pdo->query("SELECT COALESCE(AVG(water_level),0) FROM sensor_readings WHERE recorded_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();

// ── Zone table ────────────────────────────────────────────────
$zones = $pdo->query("
    SELECT wz.id, wz.zone_name, wz.location, wz.status, wz.valve_status,
           sr.flow_rate, sr.pressure, sr.water_level, sr.temperature, sr.recorded_at
    FROM water_zones wz
    LEFT JOIN sensor_readings sr ON sr.id=(
        SELECT id FROM sensor_readings WHERE zone_id=wz.id ORDER BY recorded_at DESC LIMIT 1
    )
    ORDER BY wz.id ASC
")->fetchAll();

$active_zones = count(array_filter($zones, fn($z) => $z['status']==='active'));

// ── Alerts ────────────────────────────────────────────────────
$alerts_where = $has_resolved ? "WHERE a.is_resolved=0" : "";
$alerts_order = $has_severity ? "ORDER BY FIELD(a.severity,'critical','high','medium','low'), a.created_at DESC" : "ORDER BY a.created_at DESC";
$alerts = $pdo->query("
    SELECT a.*, wz.zone_name FROM alerts a
    LEFT JOIN water_zones wz ON wz.id=a.zone_id
    $alerts_where $alerts_order LIMIT 10
")->fetchAll();

// ── Unread user reports badge ─────────────────────────────────
$unread_reports = 0;
try {
    $unread_reports = (int)$pdo->query("SELECT COUNT(*) FROM emergency_messages WHERE status='open'")->fetchColumn();
} catch(PDOException $e) {}

// ── Chart data — last 14 days daily avg flow per zone ─────────
$chart_daily = [];
try {
    $rows = $pdo->query("
        SELECT DATE(recorded_at) AS d,
               ROUND(AVG(flow_rate),2) AS avg_flow,
               ROUND(SUM(flow_rate),1) AS total_flow,
               ROUND(AVG(water_level),1) AS avg_level
        FROM sensor_readings
        WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(recorded_at)
        ORDER BY d ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        $chart_daily[] = [
            'date'       => date('d M', strtotime($r['d'])),
            'avg_flow'   => (float)$r['avg_flow'],
            'total_flow' => (float)$r['total_flow'],
            'avg_level'  => (float)$r['avg_level'],
        ];
    }
} catch(PDOException $e) {}

// ── Chart data — zone comparison (last 24h avg) ───────────────
$chart_zones = [];
try {
    $rows = $pdo->query("
        SELECT wz.zone_name,
               ROUND(AVG(sr.flow_rate),2)   AS avg_flow,
               ROUND(AVG(sr.water_level),1) AS avg_level,
               ROUND(AVG(sr.pressure),2)    AS avg_pressure
        FROM water_zones wz
        LEFT JOIN sensor_readings sr ON sr.zone_id=wz.id
        GROUP BY wz.id, wz.zone_name
        ORDER BY wz.id ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        // Skip zones with no sensor data (null avg = no readings)
        if ($r['avg_flow'] === null) continue;
        $chart_zones[] = [
            'zone'     => $r['zone_name'],
            'flow'     => round((float)$r['avg_flow'], 2),
            'level'    => round((float)$r['avg_level'], 1),
            'pressure' => round((float)$r['avg_pressure'], 2),
        ];
    }
} catch(PDOException $e) {}

// ── Chart data — predicted vs actual (last 7 days) ────────────
$chart_pva = [];
try {
    $rows = $pdo->query("
        SELECT p.predict_date,
               ROUND(AVG(p.predicted_flow),2) AS predicted,
               ROUND(AVG(sr.flow_rate),2)     AS actual
        FROM predictions p
        LEFT JOIN sensor_readings sr ON sr.zone_id=p.zone_id
            AND DATE(sr.recorded_at)=p.predict_date
        WHERE p.predict_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 7 DAY)
              AND DATE_ADD(CURDATE(),INTERVAL 3 DAY)
        GROUP BY p.predict_date
        ORDER BY p.predict_date ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        $chart_pva[] = [
            'date'      => date('d M', strtotime($r['predict_date'])),
            'predicted' => (float)$r['predicted'],
            'actual'    => $r['actual'] !== null ? (float)$r['actual'] : null,
        ];
    }
} catch(PDOException $e) {}

// ── Payments summary ──────────────────────────────────────────
$payments_today  = 0; $payments_month = 0; $payments_pending = 0;
try {
    $payments_today  = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM billing WHERE DATE(payment_date)=CURDATE()")->fetchColumn();
    $payments_month  = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM billing WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
    $payments_pending= (int)$pdo->query("SELECT COUNT(*) FROM billing WHERE payment_status='pending'")->fetchColumn();
} catch(PDOException $e) {}

require_once __DIR__ . '/sidebar.php';
?>

<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.2rem;position:relative;overflow:hidden}
.stat-label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.stat-value{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800}
.stat-sub{font-size:.78rem;color:var(--muted);margin-top:4px}
.stat-icon{position:absolute;top:1rem;right:1rem;font-size:1.6rem;opacity:.25}
.c-blue{color:var(--blue)}.c-green{color:var(--green)}.c-yellow{color:var(--yellow)}
.c-red{color:var(--red)}.c-teal{color:var(--teal)}

.welcome-banner{background:linear-gradient(135deg,rgba(14,165,233,.12),rgba(6,182,212,.08));
  border:1px solid rgba(14,165,233,.2);border-radius:16px;padding:1.25rem 1.5rem;
  margin-bottom:2rem;display:flex;align-items:center;gap:1rem}
.welcome-avatar{width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--teal));
  display:grid;place-items:center;font-size:1.2rem;flex-shrink:0}

.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;text-transform:uppercase}
.valve-open{background:rgba(52,211,153,.15);color:var(--green)}
.valve-closed{background:rgba(248,113,113,.15);color:var(--red)}
.sev-high,.sev-critical{background:rgba(248,113,113,.2);color:var(--red)}
.sev-medium{background:rgba(251,191,36,.15);color:var(--yellow)}
.sev-low{background:rgba(52,211,153,.15);color:var(--green)}

.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle}
.dot-online{background:var(--green);box-shadow:0 0 6px var(--green);animation:pulse 2s infinite}
.dot-offline{background:var(--red)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

.anomaly-flag{display:inline-flex;align-items:center;gap:3px;background:rgba(248,113,113,.12);
  border:1px solid rgba(248,113,113,.3);color:var(--red);border-radius:6px;
  padding:2px 7px;font-size:.7rem;font-weight:600;margin-left:5px}

.bar-wrap{display:flex;align-items:center;gap:8px;min-width:110px}
.bar-track{flex:1;height:5px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden}
.bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--teal))}
.bar-fill.low{background:var(--red)}.bar-fill.medium{background:var(--yellow)}

.btn-toggle{padding:5px 14px;border-radius:8px;font-size:.78rem;font-weight:600;cursor:pointer;
  font-family:'DM Sans',sans-serif;border:none;transition:all .2s;white-space:nowrap}
.btn-toggle.is-open{background:rgba(248,113,113,.15);color:var(--red);border:1px solid rgba(248,113,113,.3)}
.btn-toggle.is-open:hover{background:rgba(248,113,113,.28)}
.btn-toggle.is-closed{background:rgba(52,211,153,.15);color:var(--green);border:1px solid rgba(52,211,153,.3)}
.btn-toggle.is-closed:hover{background:rgba(52,211,153,.28)}
.empty-state{text-align:center;padding:2.5rem 1rem;color:var(--muted);font-size:.9rem}
</style>

<!-- Welcome -->
<div class="welcome-banner">
    <div class="welcome-avatar">👤</div>
    <div>
        <h3 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700">
            Welcome back, <?= htmlspecialchars($user_name) ?>!
        </h3>
        <p style="font-size:.82rem;color:var(--muted);margin-top:2px">
            Smart Water Distribution System — Meru County &nbsp;·&nbsp;
            <span style="color:var(--blue)"><?= ucfirst($user_role) ?></span>
        </p>
    </div>
    <div style="margin-left:auto;font-size:.78rem;color:var(--muted)" id="live-date"></div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">🗺️</div>
        <div class="stat-label">Total Zones</div>
        <div class="stat-value c-blue"><?= $total_zones ?></div>
        <div class="stat-sub"><?= $active_zones ?> active</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💧</div>
        <div class="stat-label">Usage Today</div>
        <div class="stat-value c-teal"><?= number_format($usageToday/1000,1) ?><span style="font-size:1rem"> kL</span></div>
        <div class="stat-sub"><?= number_format($usageToday,0) ?> L total</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-label">Avg Flow Rate</div>
        <div class="stat-value c-blue"><?= number_format($avgFlow,1) ?><span style="font-size:1rem"> L/m</span></div>
        <div class="stat-sub">⚠ Anomaly &gt; <?= number_format($anomalyThreshold,1) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🪣</div>
        <div class="stat-label">Avg Water Level</div>
        <div class="stat-value <?= $avg_level<30?'c-red':($avg_level<60?'c-yellow':'c-green') ?>">
            <?= number_format($avg_level,1) ?>%
        </div>
        <div class="stat-sub">Last 24 hours</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🚨</div>
        <div class="stat-label">Active Alerts</div>
        <div class="stat-value <?= $total_alerts>0?'c-red':'c-green' ?>"><?= $total_alerts ?></div>
        <div class="stat-sub"><?= $high_alerts ?> high / critical</div>
    </div>
    <?php if ($unread_reports > 0): ?>
    <div class="stat-card" style="border-color:rgba(251,191,36,.3)">
        <div class="stat-icon">📬</div>
        <div class="stat-label">Resident Reports</div>
        <div class="stat-value c-yellow"><?= $unread_reports ?></div>
        <div class="stat-sub"><a href="user_reports.php" style="color:var(--blue);text-decoration:none">View inbox →</a></div>
    </div>
    <?php endif; ?>
</div>

<!-- Zone table -->
<div class="section-title">🗺️ Zone Monitoring & Valve Control</div>
<div class="card">
    <div class="card-header">
        <span class="card-title">Live Zone Status</span>
        <span style="font-size:.75rem;color:var(--muted)">
            Anomaly &gt; <strong style="color:var(--yellow)"><?= number_format($anomalyThreshold,1) ?> L/min</strong>
            &nbsp;|&nbsp; Offline after 120s
        </span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Zone</th><th>Sensor</th><th>Water Level</th><th>Flow Rate</th><th>Pressure</th><th>Valve</th><th>Last Reading</th><th>Control</th></tr>
            </thead>
            <tbody>
            <?php foreach ($zones as $z):
                $timestamp    = $z['recorded_at'];
                $sensorOnline = $timestamp && (time()-strtotime($timestamp))<120;
                $flowRate     = $z['flow_rate'];
                $isAnomaly    = ($flowRate!==null && $anomalyThreshold>0 && (float)$flowRate>$anomalyThreshold);
                $waterLevel   = (float)($z['water_level']??0);
                $barClass     = $waterLevel<30?'low':($waterLevel<60?'medium':'');
                $levelColor   = $waterLevel<30?'var(--red)':($waterLevel<60?'var(--yellow)':'var(--green)');
                $valve        = strtoupper($z['valve_status']??'UNKNOWN');
                $valveOpen    = ($valve==='OPEN');
            ?>
            <tr>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($z['zone_name']) ?></div>
                    <?php if (!empty($z['location'])): ?>
                    <div style="font-size:.72rem;color:var(--muted)">📍 <?= htmlspecialchars($z['location']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="dot <?= $sensorOnline?'dot-online':'dot-offline' ?>"></span>
                    <span style="font-size:.82rem;color:<?= $sensorOnline?'var(--green)':'var(--red)' ?>">
                        <?= $sensorOnline?'Online':'Offline' ?>
                    </span>
                </td>
                <td>
                    <?php if ($z['water_level']!==null): ?>
                    <div class="bar-wrap">
                        <div class="bar-track">
                            <div class="bar-fill <?= $barClass ?>" style="width:<?= min(100,$waterLevel) ?>%"></div>
                        </div>
                        <span style="font-size:.82rem;font-weight:600;color:<?= $levelColor ?>"><?= number_format($waterLevel,1) ?>%</span>
                    </div>
                    <?php else: ?><span style="color:var(--muted)">No data</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($flowRate!==null): ?>
                    <span style="font-weight:<?= $isAnomaly?'700':'400' ?>;color:<?= $isAnomaly?'var(--red)':'inherit' ?>">
                        <?= number_format((float)$flowRate,2) ?> L/min
                    </span>
                    <?php if ($isAnomaly): ?><span class="anomaly-flag">⚠ Anomaly</span><?php endif; ?>
                    <?php else: ?><span style="color:var(--muted)">No data</span><?php endif; ?>
                </td>
                <td style="color:var(--muted)"><?= $z['pressure']!==null?number_format((float)$z['pressure'],2).' Bar':'—' ?></td>
                <td><span class="badge <?= $valveOpen?'valve-open':'valve-closed' ?>"><?= $valveOpen?'🟢 OPEN':'🔴 CLOSED' ?></span></td>
                <td style="font-size:.8rem;color:var(--muted)"><?= $timestamp?date('d M, H:i:s',strtotime($timestamp)):'—' ?></td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="toggle_zone" value="<?= $z['id'] ?>">
                        <button type="submit" class="btn-toggle <?= $valveOpen?'is-open':'is-closed' ?>"
                            onclick="return confirm('<?= $valveOpen?'Close':'Open' ?> valve for &quot;<?= htmlspecialchars(addslashes($z['zone_name'])) ?>&quot;?')">
                            <?= $valveOpen?'🔴 Close Valve':'🟢 Open Valve' ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Alerts -->
<div class="section-title">🚨 Active Alerts</div>
<div class="card">
    <div class="card-header">
        <span class="card-title">Unresolved Alerts</span>
        <a href="alerts.php" style="font-size:.78rem;color:var(--blue);text-decoration:none">Manage all →</a>
    </div>
    <?php if (empty($alerts)): ?>
        <div class="empty-state">✅ No active alerts — all systems running normally.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Zone</th><th>Type</th><th>Message</th><th>Severity</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($alerts as $alert):
                $sev = $alert['severity']??'medium';
            ?>
            <tr>
                <td><?= htmlspecialchars($alert['zone_name']??'—') ?></td>
                <td style="font-weight:500"><?= htmlspecialchars($alert['alert_type']??'Alert') ?></td>
                <td style="color:var(--muted);font-size:.82rem;max-width:220px"><?= htmlspecialchars($alert['message']??'') ?></td>
                <td><span class="badge sev-<?= $sev ?>"><?= ucfirst($sev) ?></span></td>
                <td style="color:var(--muted);font-size:.8rem"><?= date('d M Y',strtotime($alert['created_at'])) ?></td>
                <td><a href="alerts.php?resolve=<?= $alert['id'] ?>" style="font-size:.78rem;color:var(--green);text-decoration:none">✅ Resolve</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>


<!-- ── ML ANOMALIES PANEL ─────────────────────────────────── -->
<?php if (!empty($ml_anomalies)): ?>
<div class="section-title" style="margin-top:1.5rem">
    🤖 ML Anomaly Detection
    <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:8px">Python engine · last 24h · unresolved</span>
    <a href="prediction_log.php" style="font-size:.75rem;color:var(--blue);text-decoration:none;float:right">View full ML report →</a>
</div>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Zone</th><th>Anomaly Type</th><th>Expected</th><th>Actual</th><th>Deviation</th><th>Confidence</th><th>Detected</th></tr></thead>
            <tbody>
            <?php foreach ($ml_anomalies as $a):
                $sev = $a['severity_score'] >= 0.7 ? 'critical' : ($a['severity_score'] >= 0.4 ? 'high' : 'medium');
            ?>
            <tr>
                <td style="font-weight:600"><?= htmlspecialchars($a['zone_name'] ?? '—') ?></td>
                <td><span class="badge sev-<?= $sev ?>"><?= htmlspecialchars(str_replace('_',' ',ucfirst($a['anomaly_type']))) ?></span></td>
                <td style="color:var(--muted)"><?= number_format((float)$a['expected_value'],2) ?></td>
                <td style="font-weight:700;color:var(--red)"><?= number_format((float)$a['actual_value'],2) ?></td>
                <td style="color:var(--yellow)"><?= number_format((float)$a['deviation_pct'],1) ?>%</td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <div style="height:5px;width:60px;background:rgba(255,255,255,.08);border-radius:3px">
                            <div style="height:100%;width:<?= round($a['ml_confidence']*100) ?>%;background:<?= $a['ml_confidence']>=.7?'var(--green)':($a['ml_confidence']>=.4?'var(--yellow)':'var(--red)') ?>;border-radius:3px"></div>
                        </div>
                        <span style="font-size:.75rem"><?= round($a['ml_confidence']*100) ?>%</span>
                    </div>
                </td>
                <td style="color:var(--muted);font-size:.78rem"><?= date('d M H:i', strtotime($a['detected_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── ML PREDICTIONS PANEL ───────────────────────────────── -->
<?php if (!empty($ml_predictions)): ?>
<div class="section-title" style="margin-top:1.5rem">
    📈 ML Forecasts — Next 3 Days
    <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:8px">Random Forest predictions per zone</span>
    <a href="prediction_log.php" style="font-size:.75rem;color:var(--blue);text-decoration:none;float:right">Full forecast →</a>
</div>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Zone</th><th>Date</th><th>Predicted Flow</th><th>Predicted Level</th><th>Demand</th><th>Confidence</th></tr></thead>
            <tbody>
            <?php foreach ($ml_predictions as $p): ?>
            <tr>
                <td style="font-weight:600"><?= htmlspecialchars($p['zone_name']) ?></td>
                <td style="color:var(--muted);font-size:.82rem"><?= date('D d M', strtotime($p['predict_date'])) ?></td>
                <td style="color:var(--blue);font-weight:600"><?= number_format((float)$p['predicted_flow'],1) ?> <span style="font-size:.72rem;color:var(--muted)">L/min</span></td>
                <td>
                    <?php $lvl=(float)$p['predicted_level']; $lc=$lvl<30?'var(--red)':($lvl<60?'var(--yellow)':'var(--green)'); ?>
                    <span style="color:<?= $lc ?>;font-weight:600"><?= number_format($lvl,1) ?>%</span>
                </td>
                <td style="color:var(--muted)"><?= number_format((float)$p['predicted_demand'],1) ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <div style="height:5px;width:60px;background:rgba(255,255,255,.08);border-radius:3px">
                            <div style="height:100%;width:<?= $p['confidence_pct'] ?>%;background:<?= $p['confidence_pct']>=70?'var(--green)':($p['confidence_pct']>=40?'var(--yellow)':'var(--red)') ?>;border-radius:3px"></div>
                        </div>
                        <span style="font-size:.75rem"><?= $p['confidence_pct'] ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- No ML data notice -->
<?php if (empty($ml_anomalies) && empty($ml_predictions)): ?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.2rem 1.5rem;margin-top:1.5rem;display:flex;align-items:center;gap:1rem">
    <span style="font-size:1.5rem">🤖</span>
    <div>
        <div style="font-weight:700;font-size:.88rem">ML Engine Not Yet Running</div>
        <div style="font-size:.78rem;color:var(--muted);margin-top:3px">
            No predictions or anomalies from the Python ML engine yet.
            <a href="prediction_log.php" style="color:var(--blue)">Go to ML Predictions →</a> to run it manually,
            or add sensor readings so it has data to train on.
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════ -->
<!--  PAYMENTS PANEL                                           -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="section-title" style="margin-top:1.5rem">
    💳 Payments Overview
    <a href="reports.php" style="font-size:.75rem;color:var(--blue);text-decoration:none;float:right">Full report →</a>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-label">Today's Revenue</div>
        <div class="stat-value c-green">KES <?= number_format($payments_today,0) ?></div>
        <div class="stat-sub">payments received today</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">This Month</div>
        <div class="stat-value c-blue">KES <?= number_format($payments_month,0) ?></div>
        <div class="stat-sub"><?= date('F Y') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Pending Bills</div>
        <div class="stat-value c-yellow"><?= $payments_pending ?></div>
        <div class="stat-sub">unpaid invoices</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  CHART.JS CHARTS                                          -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="section-title" style="margin-top:1.5rem">📊 Analytics Charts</div>

<!-- Row 1: Daily usage + Zone comparison -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

    <!-- Daily Water Usage -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem">
        <div style="font-weight:700;font-size:.88rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
            📈 Daily Water Usage — Last 14 Days
            <span style="font-size:.7rem;color:var(--muted);font-weight:400">avg flow L/min</span>
        </div>
        <canvas id="chartDaily" height="160"></canvas>
    </div>

    <!-- Zone Comparison -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem">
        <div style="font-weight:700;font-size:.88rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
            🗺️ Zone Comparison — Last 24h
            <span style="font-size:.7rem;color:var(--muted);font-weight:400">avg flow L/min · last 7 days</span>
        </div>
        <canvas id="chartZones" height="160"></canvas>
    </div>
</div>

<!-- Row 2: Predicted vs Actual + Water Level trend -->
<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:1rem;margin-bottom:2rem">

    <!-- Predicted vs Actual -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem">
        <div style="font-weight:700;font-size:.88rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
            🤖 Predicted vs Actual Flow
            <span style="font-size:.7rem;color:var(--muted);font-weight:400">ML forecast accuracy</span>
        </div>
        <?php if(empty($chart_pva)): ?>
        <div style="text-align:center;padding:2rem;color:var(--muted);font-size:.82rem">
            No ML predictions yet — run the Python engine first
        </div>
        <?php else: ?>
        <canvas id="chartPva" height="120"></canvas>
        <?php endif; ?>
    </div>

    <!-- Water Level Donut per zone -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem">
        <div style="font-weight:700;font-size:.88rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
            💧 Avg Water Level by Zone
            <span style="font-size:.7rem;color:var(--muted);font-weight:400">last 7 days</span>
        </div>
        <canvas id="chartLevel" height="160"></canvas>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ── Shared chart defaults ─────────────────────────────────────
Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = 'rgba(30,58,95,.4)';
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 11;

const gridColor = 'rgba(30,58,95,.35)';
const tooltipStyle = {
    backgroundColor: '#0f1f35',
    borderColor: 'rgba(14,165,233,.3)',
    borderWidth: 1,
    padding: 10,
    titleColor: '#e2e8f0',
    bodyColor: '#94a3b8',
};

// ── 1. Daily Usage Chart ──────────────────────────────────────
<?php if(!empty($chart_daily)): ?>
new Chart(document.getElementById('chartDaily'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($chart_daily,'date')) ?>,
        datasets: [{
            label: 'Avg Flow (L/min)',
            data:  <?= json_encode(array_column($chart_daily,'avg_flow')) ?>,
            borderColor: '#0ea5e9',
            backgroundColor: 'rgba(14,165,233,.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
            fill: true,
            tension: 0.4,
        },{
            label: 'Avg Level (%)',
            data:  <?= json_encode(array_column($chart_daily,'avg_level')) ?>,
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,.05)',
            borderWidth: 2,
            pointRadius: 3,
            fill: true,
            tension: 0.4,
            yAxisID: 'y2',
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true,
        interaction: { mode:'index', intersect:false },
        plugins: { legend:{ position:'bottom', labels:{boxWidth:10,padding:15} }, tooltip: tooltipStyle },
        scales: {
            x: { grid:{color:gridColor} },
            y: { grid:{color:gridColor}, title:{display:true,text:'Flow L/min'} },
            y2:{ position:'right', grid:{display:false}, title:{display:true,text:'Level %'},
                 ticks:{color:'#34d399'} }
        }
    }
});
<?php else: ?>
document.getElementById('chartDaily').parentElement.innerHTML +=
    '<p style="text-align:center;color:#64748b;font-size:.8rem;padding:2rem">No sensor data yet</p>';
document.getElementById('chartDaily').style.display='none';
<?php endif; ?>

// ── 2. Zone Comparison Bar Chart ──────────────────────────────
<?php if(!empty($chart_zones)): ?>
(function(){
    const zoneFlows    = <?= json_encode(array_column($chart_zones,'flow')) ?>;
    const zonePressure = <?= json_encode(array_column($chart_zones,'pressure')) ?>;
    const zoneLabels   = <?= json_encode(array_column($chart_zones,'zone')) ?>;
    const minFlow = Math.max(0, Math.min(...zoneFlows) - 5);

    new Chart(document.getElementById('chartZones'), {
        type: 'bar',
        data: {
            labels: zoneLabels,
            datasets: [{
                label: 'Avg Flow (L/min)',
                data: zoneFlows,
                backgroundColor: [
                    'rgba(14,165,233,.85)','rgba(52,211,153,.85)',
                    'rgba(251,191,36,.85)','rgba(248,113,113,.85)','rgba(167,139,250,.85)'
                ],
                borderColor: ['#0ea5e9','#34d399','#fbbf24','#f87171','#a78bfa'],
                borderWidth: 1.5,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive:true, maintainAspectRatio:true,
            plugins: {
                legend:{display:false},
                tooltip:{ ...tooltipStyle,
                    callbacks:{ label: ctx => ` Flow: ${ctx.parsed.y.toFixed(1)} L/min` }
                }
            },
            scales: {
                x: { grid:{display:false} },
                y: { grid:{color:gridColor}, min: minFlow,
                     title:{display:true,text:'Flow L/min'} }
            }
        }
    });
})();
<?php else: ?>
document.getElementById('chartZones').parentElement.innerHTML +=
    '<p style="text-align:center;color:#64748b;font-size:.8rem;padding:2rem">No sensor data yet</p>';
document.getElementById('chartZones').style.display='none';
<?php endif; ?>

// ── 3. Predicted vs Actual Line Chart ─────────────────────────
<?php if(!empty($chart_pva)): ?>
new Chart(document.getElementById('chartPva'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($chart_pva,'date')) ?>,
        datasets: [{
            label: 'ML Predicted',
            data:  <?= json_encode(array_column($chart_pva,'predicted')) ?>,
            borderColor: '#a78bfa',
            backgroundColor: 'rgba(167,139,250,.08)',
            borderWidth: 2,
            borderDash: [5,3],
            pointRadius: 3,
            fill: true,
            tension: 0.4,
        },{
            label: 'Actual',
            data:  <?= json_encode(array_column($chart_pva,'actual')) ?>,
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,.08)',
            borderWidth: 2.5,
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.3,
            spanGaps: true,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true,
        interaction: { mode:'index', intersect:false },
        plugins: { legend:{position:'bottom',labels:{boxWidth:10,padding:15}}, tooltip: tooltipStyle },
        scales: {
            x: { grid:{color:gridColor} },
            y: { grid:{color:gridColor}, beginAtZero:true,
                 title:{display:true,text:'Flow L/min'} }
        }
    }
});
<?php endif; ?>

// ── 4. Water Level Horizontal Bar ─────────────────────────────
<?php if(!empty($chart_zones)): ?>
(function(){
    const levelData = <?= json_encode(array_column($chart_zones,'level')) ?>;
    const minLevel  = Math.max(0, Math.min(...levelData) - 5);
    new Chart(document.getElementById('chartLevel'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($chart_zones,'zone')) ?>,
            datasets: [{
                label: 'Water Level %',
                data: levelData,
                backgroundColor: <?= json_encode(array_map(fn($z) =>
                    $z['level'] < 30 ? 'rgba(248,113,113,.85)' :
                    ($z['level'] < 60 ? 'rgba(251,191,36,.85)' : 'rgba(52,211,153,.85)'),
                    $chart_zones)) ?>,
                borderColor: <?= json_encode(array_map(fn($z) =>
                    $z['level'] < 30 ? '#f87171' :
                    ($z['level'] < 60 ? '#fbbf24' : '#34d399'),
                    $chart_zones)) ?>,
                borderWidth: 1,
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive:true, maintainAspectRatio:true,
            plugins: { legend:{display:false}, tooltip:{
                ...tooltipStyle,
                callbacks:{ label: ctx => ` Level: ${ctx.parsed.x.toFixed(1)}%` }
            }},
            scales: {
                x: { grid:{color:gridColor},
                     min: minLevel, max:100,
                     title:{display:true,text:'Level %'},
                     ticks:{ callback: v => v+'%' } },
                y: { grid:{display:false} }
            }
        }
    });
})();
<?php endif; ?>

// ── Clock + auto reload ───────────────────────────────────────
function ud(){
    const n=new Date();
    const el=document.getElementById('live-date');
    if(el) el.textContent=n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric'})
        +' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
ud(); setInterval(ud,1000);
setTimeout(()=>location.reload(),60000);
</script>

</main></body></html>