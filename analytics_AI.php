<?php
/*
 * analytics_ai.php  ·  SWDS Meru
 * ============================================================
 *  INTELLIGENCE INSIDE:
 *   1. Weighted Moving Average (WMA) — demand forecasting
 *   2. Linear Regression — real least-squares trend line with R²
 *   3. Z-score + IQR anomaly detection (3-layer)
 *   4. Leak detection — pressure-flow divergence algorithm
 *   5. WHO Water Quality Index (WQI)
 *   6. Historical trend charts — 48h hourly with regression lines
 *   7. 7-day forecast chart with confidence bands
 *   8. Prediction vs actual comparison
 *   9. Fail-safe status
 * ============================================================
 */

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) { header('Location: dashboard.php'); exit; }

$user_name    = $_SESSION['user_name'];
$user_email   = $_SESSION['user_email'];
$user_role    = $_SESSION['user_role'];
$current_page = 'analytics';
$page_title   = 'Analytics & AI';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// Load all intelligence functions
require_once __DIR__ . '/analytics/engine.php';

// Load thresholds
$cfg = [];
foreach ($pdo->query("SELECT setting_key,setting_val FROM system_settings") as $r)
    $cfg[$r['setting_key']] = $r['setting_val'];

// Zone selector
$zones   = $pdo->query("SELECT id,zone_name,status,valve_status FROM water_zones ORDER BY id")->fetchAll();
$zone_id = (int)($_GET['zone_id'] ?? ($zones[0]['id'] ?? 1));
$zone    = array_values(array_filter($zones,fn($z)=>(int)$z['id']===$zone_id))[0] ?? $zones[0] ?? [];
$hours   = (int)($_GET['hours'] ?? 48);
$hours   = in_array($hours,[24,48,72,168])?$hours:48;

// Run on manual trigger
$run_msg = '';
if (isset($_GET['run'])) {
    $all_zones = $pdo->query("SELECT id FROM water_zones")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($all_zones as $zid) { save_predictions($zid); save_anomalies($zid); }
    $run_msg = 'Predictions and anomaly scan updated for all zones at '.date('H:i:s');
}

// ── Compute all intelligence for selected zone ────────────────
$stats        = get_zone_statistics($zone_id, 7);
$forecast     = forecast_demand($zone_id, 7);
$trends       = historical_trends($zone_id, $hours);
$anomalies_ai = detect_anomalies($zone_id, 24);
$leak         = detect_leak_probability($zone_id);

// Latest sensor reading
$latest = $pdo->prepare("SELECT * FROM sensor_readings WHERE zone_id=? ORDER BY recorded_at DESC LIMIT 1");
$latest->execute([$zone_id]); $latest = $latest->fetch() ?: [];

// WQI
$wqi = [];
if (!empty($latest)) {
    $wqi = calculate_wqi(
        (float)($latest['ph_level']??7.0),
        (float)($latest['turbidity']??1.0),
        (float)($latest['tds_ppm']??200),
        (float)($latest['temperature']??20)
    );
}

// Linear regression on 30-day daily averages
$daily30 = $pdo->prepare("
    SELECT DATE(recorded_at) d,
           AVG(flow_rate) f, AVG(pressure) p, AVG(water_level) l
    FROM sensor_readings WHERE zone_id=?
      AND recorded_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
    GROUP BY d ORDER BY d ASC LIMIT 30
");
$daily30->execute([$zone_id]); $daily30 = $daily30->fetchAll();
$lr_flow=$lr_pres=$lr_level=['slope'=>0,'r_squared'=>0,'direction'=>'flat'];
if (count($daily30)>=5) {
    $lr_flow  = least_squares(array_map(fn($r)=>(float)$r['f'],$daily30));
    $lr_pres  = least_squares(array_map(fn($r)=>(float)$r['p'],$daily30));
    $lr_level = least_squares(array_map(fn($r)=>(float)$r['l'],$daily30));
}

// ── PHP-detected anomalies ────────────────────────────────────
$hist_anomalies = [];
try {
    $s = $pdo->prepare("SELECT * FROM anomalies WHERE zone_id=? ORDER BY detected_at DESC LIMIT 20");
    $s->execute([$zone_id]); $hist_anomalies = $s->fetchAll();
} catch (PDOException $e) {}

// ── ML-detected anomalies (Python engine → anomaly_log) ───────
$ml_anomalies_db = [];
try {
    $s = $pdo->prepare("
        SELECT id, anomaly_type, expected_value, actual_value,
               deviation_pct, severity_score, ml_confidence,
               is_resolved, detected_at,
               'ml_engine' AS source
        FROM anomaly_log
        WHERE zone_id=?
        ORDER BY detected_at DESC LIMIT 20
    ");
    $s->execute([$zone_id]); $ml_anomalies_db = $s->fetchAll();
} catch (PDOException $e) {}

// ── ML prediction accuracy stats ─────────────────────────────
$ml_accuracy = null;
try {
    $s = $pdo->prepare("
        SELECT
            AVG(ABS(p.predicted_flow - sr.actual_flow) / NULLIF(p.predicted_flow,0) * 100) AS flow_mape,
            COUNT(*) AS days_evaluated
        FROM predictions p
        JOIN (
            SELECT zone_id, DATE(recorded_at) AS d, AVG(flow_rate) AS actual_flow
            FROM sensor_readings WHERE zone_id=? GROUP BY DATE(recorded_at)
        ) sr ON sr.zone_id=p.zone_id AND sr.d=p.predict_date
        WHERE p.zone_id=?
          AND p.predict_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND CURDATE()
    ");
    $s->execute([$zone_id, $zone_id]);
    $ml_accuracy = $s->fetch();
} catch (PDOException $e) {}

// Historical alerts
$hist_alerts = $pdo->prepare("
    SELECT * FROM alerts WHERE zone_id=?
    ORDER BY created_at DESC LIMIT 15
");
$hist_alerts->execute([$zone_id]); $hist_alerts = $hist_alerts->fetchAll();

// Prediction vs actual (last 14 days)
$pred_vs_actual = $pdo->prepare("
    SELECT p.predict_date, p.predicted_flow, p.confidence_pct,
           ROUND(AVG(sr.flow_rate),2) AS actual_flow
    FROM predictions p
    LEFT JOIN sensor_readings sr
           ON sr.zone_id=p.zone_id
          AND DATE(sr.recorded_at)=p.predict_date
    WHERE p.zone_id=?
      AND p.predict_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY)
    GROUP BY p.predict_date, p.predicted_flow, p.confidence_pct
    ORDER BY p.predict_date ASC
");
$pred_vs_actual->execute([$zone_id]); $pred_vs_actual = $pred_vs_actual->fetchAll();

require_once __DIR__ . '/sidebar.php';
?>
<style>
/* ── Metric cards ── */
.kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:.75rem;margin-bottom:1.5rem}
.kpi-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.1rem}
.kpi-lbl{font-size:.63rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
.kpi-val{font-size:1.5rem;font-weight:800;line-height:1.1}
.kpi-sub{font-size:.7rem;color:var(--muted);margin-top:3px}
/* ── Zone tabs ── */
.zone-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.25rem}
.zone-tab{padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:600;
          background:var(--card);border:1px solid var(--border);color:var(--muted);text-decoration:none;white-space:nowrap}
.zone-tab.active,.zone-tab:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
/* ── Chart grid ── */
.chart-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem}
.chart-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem}
@media(max-width:900px){.chart-row,.chart-row-3{grid-template-columns:1fr}}
.chart-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem}
.chart-title{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;
             letter-spacing:.06em;margin-bottom:.85rem;display:flex;align-items:center;gap:6px}
/* ── Section headers ── */
.sec{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;
     margin:1.5rem 0 .85rem;display:flex;align-items:center;gap:8px}
.sec::after{content:'';flex:1;height:1px;background:var(--border)}
/* ── Status pills ── */
.pill{padding:3px 10px;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.pill-ok  {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}
.pill-warn{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.pill-err {background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.pill-blue{background:rgba(14,165,233,.12);color:#0ea5e9;border:1px solid rgba(14,165,233,.25)}
/* ── Tables ── */
.tbl{width:100%;border-collapse:collapse;font-size:.79rem}
.tbl th{padding:7px 10px;text-align:left;color:var(--muted);font-size:.62rem;text-transform:uppercase;background:rgba(255,255,255,.02)}
.tbl td{padding:7px 10px;border-top:1px solid rgba(30,58,95,.4);vertical-align:middle}
/* ── LR stats ── */
.lr-stat{font-size:.78rem;color:var(--muted);display:flex;gap:.4rem;align-items:center}
.lr-arrow-up{color:var(--red)} .lr-arrow-dn{color:var(--green)} .lr-flat{color:var(--muted)}
/* ── Hour selector ── */
.hours-sel{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
.h-btn{padding:5px 12px;border-radius:7px;font-size:.75rem;font-weight:600;text-decoration:none;
       background:var(--card);border:1px solid var(--border);color:var(--muted)}
.h-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
/* ── Run button ── */
.run-btn{padding:8px 18px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;
         border-radius:9px;color:#fff;font-size:.85rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">🧠 Analytics & AI</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:3px">WMA prediction · Linear regression · Z-score + IQR anomaly · Leak detection · WQI</p>
  </div>
  <a href="?zone_id=<?=$zone_id?>&hours=<?=$hours?>&run=1" class="run-btn">▶ Refresh Predictions</a>
</div>

<?php if($run_msg):?>
<div style="padding:10px 16px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--green);border-radius:10px;margin-bottom:1rem;font-size:.83rem">✓ <?=htmlspecialchars($run_msg)?></div>
<?php endif;?>

<!-- Zone tabs -->
<div class="zone-tabs">
  <?php foreach($zones as $z):?>
  <a href="?zone_id=<?=$z['id']?>&hours=<?=$hours?>"
     class="zone-tab <?=(int)$z['id']===$zone_id?'active':''?>">
    <?=htmlspecialchars($z['zone_name'])?>
  </a>
  <?php endforeach;?>
</div>

<!-- KPI cards -->
<div class="kpi">
<?php
$lc=$leak['probability']>=75?'var(--red)':($leak['probability']>=45?'var(--yellow)':'var(--green)');
$wqi_c=$wqi['color']??'var(--muted)';
$kpis=[
  ['Avg Flow (7d)',    ($stats['avg_flow']   ??'—').' L/min', "Max: ".($stats['max_flow']??'—'),'#0ea5e9'],
  ['Avg Pressure',    ($stats['avg_pressure']??'—').' Bar',  "Std: ".($stats['std_flow']??'—'),'#06b6d4'],
  ['Avg Level (7d)',  ($stats['avg_level']  ??'—').'%',     "Readings: ".($stats['readings']??0),'#34d399'],
  ['Avg pH',          $stats['avg_ph']??'—',                "Turbidity: ".($stats['avg_turbidity']??'—').' NTU','#a78bfa'],
  ['Water Quality',   ($wqi['score']??'—').'/100',          $wqi['grade']??'N/A', $wqi_c],
  ['Leak Risk',       ($leak['probability']??0).'%',        $leak['status']??'unknown',$lc],
  ['Open Alerts',     $total_alerts,                        'unresolved','#f87171'],
  ['Anomalies (AI)',  count($anomalies_ai),                 'detected this scan','#fbbf24'],
];
foreach($kpis as[$l,$v,$s,$c]):?>
<div class="kpi-card">
  <div class="kpi-lbl"><?=$l?></div>
  <div class="kpi-val" style="color:<?=$c?>"><?=$v?></div>
  <div class="kpi-sub"><?=$s?></div>
</div>
<?php endforeach;?>
</div>

<!-- Linear Regression Summary -->
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem">
  <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.65rem;font-weight:700">📈 30-day Linear Regression Trends</div>
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem">
    <?php
    $lrs=[
      ['Flow Rate',     $lr_flow,  'L/min'],
      ['Pressure',      $lr_pres,  'Bar'],
      ['Water Level',   $lr_level, '%'],
    ];
    foreach($lrs as[$name,$lr,$unit]):
      $arrow=$lr['direction']==='increasing'?'↑ Increasing':($lr['direction']==='decreasing'?'↓ Decreasing':'→ Flat');
      $ac=$lr['direction']==='increasing'?'var(--green)':($lr['direction']==='decreasing'?'var(--red)':'var(--muted)');
    ?>
    <div>
      <div style="font-size:.78rem;font-weight:700;margin-bottom:3px"><?=$name?></div>
      <div style="color:<?=$ac?>;font-size:.8rem;font-weight:700"><?=$arrow?></div>
      <div style="font-size:.72rem;color:var(--muted)">
        Slope: <?=$lr['slope']?> <?=$unit?>/day &nbsp;|&nbsp; R²: <?=number_format($lr['r_squared'],3)?>
        <?php if($lr['r_squared']>=0.7):?><span style="color:var(--green)"> (strong fit)</span>
        <?php elseif($lr['r_squared']>=0.4):?><span style="color:var(--yellow)"> (moderate)</span>
        <?php else:?><span style="color:var(--muted)"> (weak/noisy)</span><?php endif;?>
      </div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<!-- Hour selector -->
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem;flex-wrap:wrap">
  <span style="font-size:.75rem;color:var(--muted)">Historical window:</span>
  <div class="hours-sel">
    <?php foreach([24=>'24h',48=>'48h',72=>'72h',168=>'7 days'] as $h=>$l):?>
    <a href="?zone_id=<?=$zone_id?>&hours=<?=$h?>" class="h-btn <?=$hours===$h?'active':''?>"><?=$l?></a>
    <?php endforeach;?>
  </div>
  <span style="font-size:.72rem;color:var(--muted)"><?=$trends['data_points']?> data points loaded</span>
</div>

<!-- Historical charts row 1: Flow + Pressure with regression lines -->
<div class="chart-row">
  <div class="chart-card">
    <div class="chart-title">💧 Flow Rate — <?=$hours?>h history + regression</div>
    <canvas id="flowChart" height="200"></canvas>
  </div>
  <div class="chart-card">
    <div class="chart-title">🔵 Pressure — <?=$hours?>h history + regression</div>
    <canvas id="pressChart" height="200"></canvas>
  </div>
</div>

<!-- Historical charts row 2: Level + WQI params -->
<div class="chart-row">
  <div class="chart-card">
    <div class="chart-title">📊 Water Level — <?=$hours?>h history + regression</div>
    <canvas id="levelChart" height="200"></canvas>
  </div>
  <div class="chart-card">
    <div class="chart-title">🧪 pH + Turbidity — <?=$hours?>h history</div>
    <canvas id="qualityChart" height="200"></canvas>
  </div>
</div>

<!-- Forecast chart -->
<div class="sec">🔮 7-Day WMA Forecast</div>
<div class="chart-row">
  <div class="chart-card">
    <div class="chart-title">Flow forecast + confidence band</div>
    <canvas id="forecastFlowChart" height="200"></canvas>
  </div>
  <div class="chart-card">
    <div class="chart-title">Level + pressure forecast</div>
    <canvas id="forecastLevelChart" height="200"></canvas>
  </div>
</div>

<!-- Prediction vs Actual -->
<?php if(!empty($pred_vs_actual)):?>
<div class="sec">📉 Prediction vs Actual (last 14 days)</div>
<div class="chart-card" style="margin-bottom:1.25rem">
  <div class="chart-title">Predicted flow vs measured actual — accuracy check</div>
  <canvas id="predActualChart" height="140"></canvas>
</div>
<?php endif;?>

<!-- Anomaly AI results -->
<div class="sec">🚨 AI Anomaly Scan — Current Reading</div>
<?php if(empty($anomalies_ai)):?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;color:var(--green);font-size:.88rem">
  ✅ No anomalies detected in the latest sensor reading for this zone.
</div>
<?php else:?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.25rem">
<table class="tbl">
  <thead><tr><th>Anomaly Type</th><th>Expected</th><th>Actual</th><th>Deviation</th><th>Severity</th><th>Detection Method</th></tr></thead>
  <tbody>
  <?php foreach($anomalies_ai as $a):
    $sc=$a['severity_score']>=0.7?'pill-err':($a['severity_score']>=0.4?'pill-warn':'pill-blue');?>
  <tr>
    <td style="font-weight:700"><?=htmlspecialchars(strtoupper(str_replace('_',' ',$a['anomaly_type'])))?></td>
    <td><?=$a['expected_value']?></td>
    <td style="font-weight:600"><?=$a['actual_value']?></td>
    <td style="color:var(--yellow)"><?=$a['deviation_pct']?>%</td>
    <td><span class="pill <?=$sc?>"><?=number_format($a['severity_score'],2)?></span></td>
    <td style="color:var(--muted);font-size:.73rem"><?=htmlspecialchars($a['detection_sources'])?></td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>
<?php endif;?>

<!-- Leak detection result -->
<div class="sec">💧 Leak Detection</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.65rem">
    <div style="font-size:1.6rem;font-weight:800;color:<?=$lc?>"><?=$leak['probability']?>%</div>
    <div>
      <div style="font-weight:700"><?=strtoupper(str_replace('_',' ',$leak['status']))?></div>
      <div style="font-size:.78rem;color:var(--muted)">Pressure-flow divergence analysis · last 3 hours</div>
    </div>
  </div>
  <?php if(!empty($leak['indicators'])):?>
  <div style="font-size:.78rem;color:var(--muted)">
    <?php foreach($leak['indicators'] as $ind):?>
    <div style="margin-bottom:3px">⚠ <?=htmlspecialchars($ind)?></div>
    <?php endforeach;?>
  </div>
  <?php else:?>
  <div style="font-size:.8rem;color:var(--green)">✓ No leak signals detected in the current monitoring window.</div>
  <?php endif;?>
  <?php if(!empty($leak['score_breakdown'])):?>
  <div style="margin-top:.65rem;display:flex;gap:1rem;flex-wrap:wrap;font-size:.72rem;color:var(--muted)">
    <?php foreach($leak['score_breakdown'] as $k=>$v):?>
    <span><strong style="color:var(--text)"><?=ucfirst(str_replace('_',' ',$k))?></strong>: +<?=$v?> pts</span>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div>

<!-- WQI detail -->
<?php if(!empty($wqi)):?>
<div class="sec">🧪 Water Quality Index</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;margin-bottom:.65rem">
    <div style="font-size:2rem;font-weight:800;color:<?=$wqi['color']?>"><?=$wqi['score']?><span style="font-size:.9rem">/100</span></div>
    <div>
      <div style="font-weight:700;font-size:1.1rem;color:<?=$wqi['color']?>"><?=$wqi['grade']?></div>
      <div style="font-size:.78rem;color:var(--muted)">pH: <?=$latest['ph_level']??'—'?> · Turbidity: <?=$latest['turbidity']??'—'?> NTU · TDS: <?=$latest['tds_ppm']??'—'?> mg/L · Temp: <?=$latest['temperature']??'—'?>°C</div>
    </div>
  </div>
  <?php if(!empty($wqi['flags'])):?>
  <div style="font-size:.78rem">
    <?php foreach($wqi['flags'] as $flag):?>
    <div style="color:var(--yellow);margin-bottom:2px">⚠ <?=htmlspecialchars($flag)?></div>
    <?php endforeach;?>
  </div>
  <?php else:?>
  <div style="color:var(--green);font-size:.8rem">✓ All water quality parameters within WHO guidelines.</div>
  <?php endif;?>
</div>
<?php endif;?>

<!-- Historical anomalies table -->
<?php if(!empty($hist_anomalies)):?>
<div class="sec">📋 Anomaly History (DB log)</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.25rem">
<div style="overflow-x:auto"><table class="tbl">
  <thead><tr><th>Detected</th><th>Type</th><th>Expected</th><th>Actual</th><th>Dev%</th><th>Severity</th></tr></thead>
  <tbody>
  <?php foreach($hist_anomalies as $a):
    $sc=$a['severity_score']>=0.7?'pill-err':($a['severity_score']>=0.4?'pill-warn':'pill-blue');?>
  <tr>
    <td style="color:var(--muted);font-size:.73rem;white-space:nowrap"><?=date('d M H:i',strtotime($a['detected_at']))?></td>
    <td style="font-weight:600"><?=htmlspecialchars(str_replace('_',' ',ucfirst($a['anomaly_type'])))?></td>
    <td><?=$a['expected_value']?></td>
    <td><?=$a['actual_value']?></td>
    <td style="color:var(--yellow)"><?=$a['deviation_pct']?>%</td>
    <td><span class="pill <?=$sc?>"><?=number_format($a['severity_score'],2)?></span></td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table></div>
</div>
<?php endif;?>

<!-- ── ML Engine Anomalies ────────────────────────────────── -->
<?php if(!empty($ml_anomalies_db)):?>
<div class="sec">🤖 ML Engine Anomalies
    <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:6px">Python Random Forest · anomaly_log table</span>
</div>
<div style="background:var(--card);border:1px solid rgba(251,191,36,.2);border-radius:12px;overflow:hidden;margin-bottom:1.25rem">
<div style="overflow-x:auto"><table class="tbl">
  <thead><tr><th>Detected</th><th>Type</th><th>Expected</th><th>Actual</th><th>Dev%</th><th>ML Confidence</th><th>Severity</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach($ml_anomalies_db as $a):
    $conf = (float)$a['ml_confidence'];
    $conf_c = $conf>=.7?'#34d399':($conf>=.4?'#fbbf24':'#f87171');
    $sc = $a['severity_score']>=0.7?'pill-err':($a['severity_score']>=0.4?'pill-warn':'pill-blue');
  ?>
  <tr>
    <td style="color:var(--muted);font-size:.73rem;white-space:nowrap"><?=date('d M H:i',strtotime($a['detected_at']))?></td>
    <td style="font-weight:700;color:#fbbf24"><?=htmlspecialchars(str_replace('_',' ',ucfirst($a['anomaly_type'])))?></td>
    <td style="color:var(--muted)"><?=number_format((float)$a['expected_value'],2)?></td>
    <td style="font-weight:700;color:#f87171"><?=number_format((float)$a['actual_value'],2)?></td>
    <td style="color:#fbbf24"><?=number_format((float)$a['deviation_pct'],1)?>%</td>
    <td>
      <div style="display:flex;align-items:center;gap:6px;min-width:110px">
        <div style="flex:1;height:8px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden">
          <div style="height:100%;width:<?=round($conf*100)?>%;background:<?=$conf_c?>;border-radius:4px;transition:width .3s"></div>
        </div>
        <span style="font-size:.78rem;font-weight:700;color:#fff;background:<?=$conf_c?>;padding:2px 7px;border-radius:5px;min-width:38px;text-align:center"><?=round($conf*100)?>%</span>
      </div>
    </td>
    <td><span class="pill <?=$sc?>"><?=number_format($a['severity_score'],2)?></span></td>
    <td style="font-size:.75rem;color:<?=$a['is_resolved']?'var(--green)':'var(--red)'?>"><?=$a['is_resolved']?'✓ Resolved':'● Active'?></td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table></div>
</div>
<?php endif;?>

<!-- ── ML Accuracy (Predicted vs Actual) ────────────────────── -->
<?php if(!empty($pred_vs_actual)):?>
<div class="sec">📊 Predicted vs Actual — Last 14 Days
  <?php if($ml_accuracy && $ml_accuracy['days_evaluated']>0): ?>
  <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:6px">
    MAPE: <?=number_format((float)$ml_accuracy['flow_mape'],1)?>% error over <?=$ml_accuracy['days_evaluated']?> days
  </span>
  <?php endif;?>
</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.25rem">
<div style="overflow-x:auto"><table class="tbl">
  <thead><tr>
    <th>Date</th>
    <th>Predicted Flow</th>
    <th>Actual Flow</th>
    <th>Difference</th>
    <th>Accuracy</th>
    <th>Confidence</th>
  </tr></thead>
  <tbody>
  <?php foreach($pred_vs_actual as $r):
    $diff = $r['actual_flow']!==null ? round((float)$r['actual_flow']-(float)$r['predicted_flow'],2) : null;
    $acc  = null;
    if($r['actual_flow']!==null && (float)$r['predicted_flow']>0) {
        $acc = round(100-abs($diff)/(float)$r['predicted_flow']*100,1);
    }
    $diff_c = $diff===null?'var(--muted)':($diff>0?'#34d399':'#f87171');
    $acc_c  = $acc===null?'var(--muted)':($acc>=90?'#34d399':($acc>=70?'#fbbf24':'#f87171'));
    $is_future = strtotime($r['predict_date']) > time();
  ?>
  <tr style="<?=$is_future?'opacity:.6':''?>">
    <td style="color:var(--muted);font-size:.78rem">
      <?=date('D d M',strtotime($r['predict_date']))?>
      <?php if($is_future):?><span style="font-size:.65rem;color:#0ea5e9"> forecast</span><?php endif;?>
    </td>
    <td style="color:#0ea5e9;font-weight:600"><?=number_format((float)$r['predicted_flow'],1)?> <small>L/min</small></td>
    <td style="font-weight:600">
      <?php if($r['actual_flow']!==null): ?>
        <?=number_format((float)$r['actual_flow'],1)?> <small>L/min</small>
      <?php else: ?>
        <span style="color:var(--muted);font-size:.78rem">Awaiting data</span>
      <?php endif;?>
    </td>
    <td style="color:<?=$diff_c?>;font-weight:600">
      <?=$diff!==null?($diff>0?'+':'').number_format($diff,2).' L/min':'—'?>
    </td>
    <td>
      <?php if($acc!==null): ?>
        <span style="color:<?=$acc_c?>;font-weight:700"><?=$acc?>%</span>
      <?php else: ?><span style="color:var(--muted)">—</span><?php endif;?>
    </td>
    <td>
      <?php if(isset($r['confidence_pct'])): $cp=(float)$r['confidence_pct']; $cc=$cp>=70?'#34d399':($cp>=50?'#fbbf24':'#f87171'); ?>
      <span style="font-size:.78rem;font-weight:700;color:#fff;background:<?=$cc?>;padding:2px 8px;border-radius:5px"><?=$cp?>%</span>
      <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table></div>
</div>
<?php endif;?>

<?php if(empty($ml_anomalies_db) && empty($pred_vs_actual)):?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.2rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:center">
  <span style="font-size:1.4rem">🤖</span>
  <div>
    <div style="font-weight:700;font-size:.88rem">No ML data yet for this zone</div>
    <div style="font-size:.78rem;color:var(--muted);margin-top:3px">
      Run the Python ML engine from <a href="prediction_log.php" style="color:var(--blue)">ML Predictions</a> 
      to generate forecasts and anomaly detection. Requires at least 10 sensor readings per zone.
    </div>
  </div>
</div>
<?php endif;?>

<!-- Recent alerts -->
<?php if(!empty($hist_alerts)):?>
<div class="sec">🚨 Recent Alerts</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:2rem">
<div style="overflow-x:auto"><table class="tbl">
  <thead><tr><th>Date</th><th>Type</th><th>Message</th><th>Severity</th><th>Resolved</th></tr></thead>
  <tbody>
  <?php foreach($hist_alerts as $al):
    $sev_c=$al['severity']==='critical'?'pill-err':($al['severity']==='high'?'pill-warn':'pill-blue');?>
  <tr>
    <td style="color:var(--muted);font-size:.73rem;white-space:nowrap"><?=date('d M H:i',strtotime($al['created_at']))?></td>
    <td style="font-weight:600;font-size:.8rem"><?=htmlspecialchars($al['alert_type'])?></td>
    <td style="color:var(--muted);font-size:.76rem;max-width:280px"><?=htmlspecialchars(mb_strimwidth($al['message']??'',0,90,'…'))?></td>
    <td><span class="pill <?=$sev_c?>"><?=$al['severity']?></span></td>
    <td style="color:<?=$al['is_resolved']?'var(--green)':'var(--red)'?>"><?=$al['is_resolved']?'✓ Yes':'✗ Open'?></td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table></div>
</div>
<?php endif;?>

<!-- All Chart.js code -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#7a9bba';
Chart.defaults.borderColor = 'rgba(30,58,95,0.4)';

const labels     = <?=json_encode($trends['labels'])?>;
const flowData   = <?=json_encode($trends['flow'])?>;
const pressData  = <?=json_encode($trends['pressure'])?>;
const levelData  = <?=json_encode($trends['level'])?>;
const phData     = <?=json_encode($trends['ph'])?>;
const turbData   = <?=json_encode($trends['turbidity'])?>;
const flowTrend  = <?=json_encode($trends['flow_trend'])?>;
const pressTrend = <?=json_encode($trends['pressure_trend'])?>;
const levelTrend = <?=json_encode($trends['level_trend'])?>;

// Forecast
const fcDates  = <?=json_encode(array_column($forecast,'date'))?>;
const fcFlow   = <?=json_encode(array_column($forecast,'flow'))?>;
const fcLevel  = <?=json_encode(array_column($forecast,'level'))?>;
const fcPress  = <?=json_encode(array_column($forecast,'pressure'))?>;
const fcConf   = <?=json_encode(array_column($forecast,'confidence'))?>;

// Pred vs actual
const pvaDates  = <?=json_encode(array_column($pred_vs_actual,'predict_date'))?>;
const pvaPred   = <?=json_encode(array_column($pred_vs_actual,'predicted_flow'))?>;
const pvaActual = <?=json_encode(array_column($pred_vs_actual,'actual_flow'))?>;

const chartOpts = (yLabel) => ({
    responsive:true,
    plugins:{ legend:{ labels:{ boxWidth:10, font:{size:10} } }, tooltip:{ mode:'index', intersect:false } },
    scales:{
        x:{ ticks:{ font:{size:9}, maxRotation:45, maxTicksLimit:12 }, grid:{ color:'rgba(30,58,95,.35)' } },
        y:{ title:{ display:true, text:yLabel, color:'#7a9bba', font:{size:10} }, grid:{ color:'rgba(30,58,95,.35)' } }
    }
});

// ── Flow chart with regression trend ──────────────────────────
new Chart('flowChart', {
    type:'line',
    data:{ labels,
        datasets:[
            { label:'Flow Rate (L/min)', data:flowData, borderColor:'#0ea5e9', backgroundColor:'rgba(14,165,233,.08)',
              borderWidth:2, pointRadius:1.5, tension:.4, fill:true },
            { label:'Trend (regression)', data:flowTrend, borderColor:'#34d399', borderWidth:1.5,
              borderDash:[5,5], pointRadius:0, tension:0, fill:false }
        ]},
    options:chartOpts('L/min')
});

// ── Pressure chart ─────────────────────────────────────────────
new Chart('pressChart', {
    type:'line',
    data:{ labels,
        datasets:[
            { label:'Pressure (Bar)', data:pressData, borderColor:'#06b6d4', backgroundColor:'rgba(6,182,212,.08)',
              borderWidth:2, pointRadius:1.5, tension:.4, fill:true },
            { label:'Trend', data:pressTrend, borderColor:'#a78bfa', borderWidth:1.5,
              borderDash:[5,5], pointRadius:0, fill:false }
        ]},
    options:chartOpts('Bar')
});

// ── Level chart ────────────────────────────────────────────────
new Chart('levelChart', {
    type:'line',
    data:{ labels,
        datasets:[
            { label:'Water Level (%)', data:levelData, borderColor:'#34d399', backgroundColor:'rgba(52,211,153,.08)',
              borderWidth:2, pointRadius:1.5, tension:.4, fill:true },
            { label:'Trend', data:levelTrend, borderColor:'#fbbf24', borderWidth:1.5,
              borderDash:[5,5], pointRadius:0, fill:false }
        ]},
    options:chartOpts('%')
});

// ── Quality chart: pH + turbidity ─────────────────────────────
new Chart('qualityChart', {
    type:'line',
    data:{ labels,
        datasets:[
            { label:'pH', data:phData, borderColor:'#a78bfa', backgroundColor:'rgba(167,139,250,.08)',
              borderWidth:2, pointRadius:1.5, tension:.4, fill:false, yAxisID:'yph' },
            { label:'Turbidity (NTU)', data:turbData, borderColor:'#fb923c', backgroundColor:'rgba(251,146,60,.08)',
              borderWidth:2, pointRadius:1.5, tension:.4, fill:false, yAxisID:'yt' },
        ]},
    options:{
        responsive:true,
        plugins:{ legend:{ labels:{ boxWidth:10, font:{size:10} } }, tooltip:{mode:'index',intersect:false} },
        scales:{
            x:{ ticks:{font:{size:9},maxRotation:45,maxTicksLimit:12}, grid:{color:'rgba(30,58,95,.35)'} },
            yph:{ position:'left', title:{display:true,text:'pH',color:'#a78bfa',font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} },
            yt: { position:'right',title:{display:true,text:'NTU',color:'#fb923c',font:{size:10}}, grid:{drawOnChartArea:false} }
        }
    }
});

// ── Forecast flow chart with confidence band ──────────────────
if (fcDates.length > 0) {
    const confHigh = fcFlow.map((v,i) => +(v*(1+fcConf[i]/400)).toFixed(2));
    const confLow  = fcFlow.map((v,i) => +(v*(1-fcConf[i]/400)).toFixed(2));
    new Chart('forecastFlowChart', {
        type:'line',
        data:{ labels:fcDates,
            datasets:[
                { label:'Conf. High', data:confHigh, borderColor:'transparent',
                  backgroundColor:'rgba(14,165,233,.12)', fill:'+1', pointRadius:0, tension:.4 },
                { label:'Predicted Flow', data:fcFlow, borderColor:'#0ea5e9',
                  backgroundColor:'transparent', borderWidth:2, pointRadius:4, tension:.4,
                  pointBackgroundColor:'#0ea5e9' },
                { label:'Conf. Low', data:confLow, borderColor:'transparent',
                  backgroundColor:'rgba(14,165,233,.12)', fill:false, pointRadius:0, tension:.4 },
            ]},
        options:{
            responsive:true,
            plugins:{ legend:{ labels:{ boxWidth:10, font:{size:10},
                filter:(item)=>item.text==='Predicted Flow' } }, tooltip:{mode:'index',intersect:false} },
            scales:{
                x:{ ticks:{font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} },
                y:{ title:{display:true,text:'L/min',color:'#7a9bba',font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} }
            }
        }
    });

    // Forecast level + pressure
    new Chart('forecastLevelChart', {
        type:'line',
        data:{ labels:fcDates,
            datasets:[
                { label:'Level (%)', data:fcLevel, borderColor:'#34d399', borderWidth:2, pointRadius:4, tension:.4 },
                { label:'Pressure (Bar)', data:fcPress, borderColor:'#06b6d4', borderWidth:2, pointRadius:4, tension:.4, yAxisID:'yp' },
            ]},
        options:{
            responsive:true,
            plugins:{ legend:{ labels:{boxWidth:10,font:{size:10}} }, tooltip:{mode:'index',intersect:false} },
            scales:{
                x:{ ticks:{font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} },
                y:{ title:{display:true,text:'%',color:'#34d399',font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} },
                yp:{ position:'right', title:{display:true,text:'Bar',color:'#06b6d4',font:{size:10}}, grid:{drawOnChartArea:false} }
            }
        }
    });
}

// ── Prediction vs actual ───────────────────────────────────────
if (pvaDates.length > 0) {
    new Chart('predActualChart', {
        type:'bar',
        data:{ labels:pvaDates,
            datasets:[
                { label:'Predicted Flow', data:pvaPred,   backgroundColor:'rgba(14,165,233,.5)', borderColor:'#0ea5e9', borderWidth:1.5 },
                { label:'Actual Flow',    data:pvaActual, backgroundColor:'rgba(52,211,153,.5)', borderColor:'#34d399', borderWidth:1.5 },
            ]},
        options:{
            responsive:true,
            plugins:{ legend:{labels:{boxWidth:10,font:{size:10}}}, tooltip:{mode:'index',intersect:false} },
            scales:{
                x:{ ticks:{font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} },
                y:{ title:{display:true,text:'L/min',color:'#7a9bba',font:{size:10}}, grid:{color:'rgba(30,58,95,.35)'} }
            }
        }
    });
}
</script>
</main></body></html>