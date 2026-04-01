<?php
/*
 * reports.php — Summary Reports
 */
session_start(); require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'reports';
$page_title   = 'Reports';

$total_alerts = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// ── Zone performance summary ──────────────────────────────────
$zone_report = $pdo->query("
    SELECT wz.zone_name, wz.status, wz.location,
        COUNT(sr.id)     AS reading_count,
        AVG(sr.flow_rate)    AS avg_flow,
        AVG(sr.pressure)     AS avg_pressure,
        AVG(sr.water_level)  AS avg_level,
        MIN(sr.water_level)  AS min_level,
        MAX(sr.water_level)  AS max_level,
        COUNT(a.id)          AS alert_count
    FROM water_zones wz
    LEFT JOIN sensor_readings sr ON sr.zone_id=wz.id
    LEFT JOIN alerts a ON a.zone_id=wz.id AND a.is_resolved=0
    GROUP BY wz.id
    ORDER BY wz.id
")->fetchAll();

// ── Monthly reading counts (last 6 months) ───────────────────
$monthly = $pdo->query("
    SELECT DATE_FORMAT(recorded_at,'%b %Y') AS month_label,
           DATE_FORMAT(recorded_at,'%Y-%m') AS month_sort,
           COUNT(*) AS total
    FROM sensor_readings
    WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_sort, month_label
    ORDER BY month_sort ASC
")->fetchAll();

// ── Alert breakdown ───────────────────────────────────────────
$alert_types = $pdo->query("
    SELECT alert_type, COUNT(*) AS cnt FROM alerts GROUP BY alert_type ORDER BY cnt DESC LIMIT 8
")->fetchAll();

// ── Overall totals ────────────────────────────────────────────
$totals = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM water_zones)                     AS zones,
        (SELECT COUNT(*) FROM sensor_readings)                 AS readings,
        (SELECT COUNT(*) FROM alerts)                          AS alerts,
        (SELECT COUNT(*) FROM alerts WHERE is_resolved=0)      AS open_alerts,
        (SELECT AVG(water_level) FROM sensor_readings)         AS avg_level
")->fetch();

require_once __DIR__ . '/sidebar.php';
?>

<!-- Overview stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon">🗺️</div><div class="stat-label">Total Zones</div><div class="stat-value c-blue"><?= $totals['zones'] ?></div></div>
    <div class="stat-card"><div class="stat-icon">📡</div><div class="stat-label">Total Readings</div><div class="stat-value c-teal"><?= number_format($totals['readings']) ?></div></div>
    <div class="stat-card"><div class="stat-icon">🚨</div><div class="stat-label">Total Alerts</div><div class="stat-value c-yellow"><?= $totals['alerts'] ?></div></div>
    <div class="stat-card"><div class="stat-icon">💧</div><div class="stat-label">Avg Water Level</div><div class="stat-value c-green"><?= round($totals['avg_level']??0,1) ?>%</div></div>
</div>

<!-- Zone Performance Table -->
<div class="section-title">📊 Zone Performance Summary</div>
<div class="card" style="margin-bottom:2rem;">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Zone</th><th>Status</th><th>Readings</th><th>Avg Flow</th><th>Avg Pressure</th><th>Avg Level</th><th>Level Range</th><th>Open Alerts</th></tr>
            </thead>
            <tbody>
            <?php foreach($zone_report as $z): ?>
            <tr>
                <td>
                    <div style="font-weight:500"><?= htmlspecialchars($z['zone_name']) ?></div>
                    <div style="font-size:0.72rem;color:var(--muted)"><?= htmlspecialchars($z['location']) ?></div>
                </td>
                <td><span class="badge badge-<?= $z['status'] ?>"><?= ucfirst($z['status']) ?></span></td>
                <td><?= $z['reading_count'] ?></td>
                <td><?= $z['reading_count']>0 ? round($z['avg_flow'],1).' L/min' : '—' ?></td>
                <td><?= $z['reading_count']>0 ? round($z['avg_pressure'],2).' Bar' : '—' ?></td>
                <td>
                    <?php $lvl=round($z['avg_level']??0,1); $c=$lvl<30?'c-red':($lvl<60?'c-yellow':'c-green'); ?>
                    <span class="<?= $c ?>"><?= $z['reading_count']>0 ? $lvl.'%' : '—' ?></span>
                </td>
                <td style="font-size:0.82rem;color:var(--muted)">
                    <?= $z['reading_count']>0 ? round($z['min_level'],0).'% – '.round($z['max_level'],0).'%' : '—' ?>
                </td>
                <td>
                    <?php if ($z['alert_count']>0): ?>
                        <span class="badge badge-high"><?= $z['alert_count'] ?></span>
                    <?php else: ?>
                        <span style="color:var(--green)">✅ None</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Two column layout: monthly readings + alert types -->
<div class="grid-2">
    <!-- Monthly Readings -->
    <div>
        <div class="section-title">📅 Monthly Sensor Readings (Last 6 Months)</div>
        <div class="card">
            <div class="card-body">
            <?php if (empty($monthly)): ?>
                <div class="empty-state"><div class="icon">📅</div>No data yet.</div>
            <?php else: ?>
                <?php $max = max(array_column($monthly,'total')); ?>
                <?php foreach($monthly as $m): ?>
                    <div style="margin-bottom:1rem;">
                        <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:5px;">
                            <span><?= htmlspecialchars($m['month_label']) ?></span>
                            <span style="color:var(--blue);font-weight:600"><?= $m['total'] ?> readings</span>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= round(($m['total']/$max)*100) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alert Types -->
    <div>
        <div class="section-title">🚨 Top Alert Types</div>
        <div class="card">
            <div class="card-body">
            <?php if (empty($alert_types)): ?>
                <div class="empty-state"><div class="icon">✅</div>No alerts recorded.</div>
            <?php else: ?>
                <?php $max2 = max(array_column($alert_types,'cnt')); ?>
                <?php foreach($alert_types as $a): ?>
                    <div style="margin-bottom:1rem;">
                        <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:5px;">
                            <span><?= htmlspecialchars($a['alert_type']) ?></span>
                            <span style="color:var(--red);font-weight:600"><?= $a['cnt'] ?></span>
                        </div>
                        <div class="bar-track">
                            <div class="bar-fill low" style="width:<?= round(($a['cnt']/$max2)*100) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</main></body></html>