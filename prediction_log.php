<?php
/*
 * prediction_log.php — SWDS Meru
 * ============================================================
 * Shows:
 *   - Last N ML engine runs (prediction_log table)
 *   - Current 7-day forecasts per zone (predictions table)
 *   - Recent ML anomalies (anomaly_log table)
 *   - Button to manually trigger prediction_engine.py
 * ============================================================
 */
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
    header('Location: dashboard.php'); exit;
}

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'prediction_log';

// ── Trigger ML engine run ─────────────────────────────────────
$ml_run_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='run_ml') {
    require_once __DIR__ . '/db.php';
    if (!file_exists(__DIR__ . '/prediction_engine.py')) {
        $ml_run_msg = 'error|prediction_engine.py not found in ' . __DIR__;
    } else {
        $python = 'python';
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'prediction_engine.py';
        $logdir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
        $logfile= $logdir . DIRECTORY_SEPARATOR . 'ml_engine.log';
        if (!is_dir($logdir)) mkdir($logdir, 0755, true);
        $cmd  = escapeshellcmd("$python $script") . " 2>&1";
        $out  = []; $code = 0;
        exec($cmd, $out, $code);
        file_put_contents($logfile, date('[Y-m-d H:i:s]') . " code=$code
" . implode("
",$out)."
", FILE_APPEND);
        $ml_run_msg = $code === 0
            ? 'success|ML engine ran successfully. ' . count($out) . ' output lines.'
            : 'error|ML engine exited with code ' . $code . '. Check logs/ml_engine.log.';
    }
}
$page_title   = 'ML Prediction Log';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// ── Manual trigger ────────────────────────────────────────────
$run_msg = '';
if (isset($_GET['run_ml'])) {
    // Look for prediction_engine.py in ml/ subfolder first, then root
    $script = realpath(__DIR__ . '/ml/prediction_engine.py');
    if (!$script) $script = realpath(__DIR__ . '/prediction_engine.py');
    if ($script && file_exists($script)) {
        $cmd = "start /B python \"$script\" > NUL 2>&1";
        pclose(popen($cmd, "r"));
        $run_msg = "✅ ML engine triggered — check back in 30 seconds.";
    } else {
        $run_msg = "⚠️ prediction_engine.py not found. Place it in smart_water/ml/ folder.";
    }
}

// ── Load data ──────────────────────────────────────────────────
// 1. Run history
$run_log = [];
try {
    $run_log = $pdo->query("
        SELECT * FROM prediction_log
        ORDER BY run_at DESC LIMIT 30
    ")->fetchAll();
} catch (PDOException $e) {}

// 2. Latest forecasts per zone
$forecasts = [];
try {
    $forecasts = $pdo->query("
        SELECT p.*, wz.zone_name
        FROM predictions p
        JOIN water_zones wz ON wz.id = p.zone_id
        WHERE p.predict_date >= CURDATE()
        ORDER BY p.zone_id, p.predict_date ASC
    ")->fetchAll();
} catch (PDOException $e) {}

// Group forecasts by zone
$by_zone = [];
foreach ($forecasts as $f) {
    $by_zone[$f['zone_name']][] = $f;
}

// 3. Recent anomalies
$anomalies = [];
try {
    $anomalies = $pdo->query("
        SELECT al.*, wz.zone_name
        FROM anomaly_log al
        LEFT JOIN water_zones wz ON wz.id = al.zone_id
        ORDER BY al.detected_at DESC LIMIT 50
    ")->fetchAll();
} catch (PDOException $e) {}

// 4. Stats
$stats = [];
try {
    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM prediction_log) AS total_runs,
            (SELECT COUNT(*) FROM prediction_log WHERE status='success') AS success_runs,
            (SELECT COUNT(*) FROM predictions WHERE predict_date >= CURDATE()) AS active_forecasts,
            (SELECT COUNT(*) FROM anomaly_log WHERE is_resolved=0) AS open_anomalies,
            (SELECT MAX(run_at) FROM prediction_log WHERE status='success') AS last_success
    ")->fetch();
} catch (PDOException $e) {}

require_once __DIR__ . '/sidebar.php';
?>

<style>
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.7rem;margin-bottom:1.5rem}
.sc{background:var(--card);border:1px solid var(--border);border-radius:11px;padding:.85rem 1rem}
.sl{font-size:.62rem;color:var(--muted);text-transform:uppercase;margin-bottom:3px}
.sv{font-size:1.4rem;font-weight:800}

.tab-header{display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap}
.tab{padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:600;
     border:1px solid var(--border);color:var(--muted);cursor:pointer;background:transparent}
.tab.on{border-color:var(--blue);color:var(--blue);background:rgba(14,165,233,.07)}
.tab-body{display:none}.tab-body.on{display:block}

.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:9px 12px;font-size:.67rem;font-weight:700;text-transform:uppercase;
         letter-spacing:.07em;color:var(--muted);border-bottom:1px solid var(--border);text-align:left}
.tbl td{padding:9px 12px;font-size:.82rem;border-bottom:1px solid rgba(30,58,95,.4)}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.02)}

.bdg{padding:2px 8px;border-radius:5px;font-size:.62rem;font-weight:700}
.b-ok {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}
.b-err{background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-skip{background:rgba(122,155,186,.1);color:#7a9bba;border:1px solid rgba(122,155,186,.2)}
.b-hi {background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-med{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.b-lo {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}

.fore-zone{background:var(--card);border:1px solid var(--border);border-radius:12px;
            padding:1rem 1.2rem;margin-bottom:1rem}
.fore-zone-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;margin-bottom:.75rem}
.fore-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.4rem}
.fore-day{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;
           padding:.6rem .5rem;text-align:center}
.fore-date{font-size:.6rem;color:var(--muted);text-transform:uppercase;margin-bottom:4px}
.fore-val{font-size:.78rem;font-weight:700}
.fore-sub{font-size:.6rem;color:var(--muted);margin-top:2px}
.conf-bar{height:3px;border-radius:2px;margin-top:4px;
           background:linear-gradient(90deg,var(--blue),var(--teal))}

.run-btn{padding:9px 18px;background:linear-gradient(135deg,var(--blue),var(--teal));
          border:none;border-radius:9px;color:#fff;font-size:.82rem;font-weight:700;
          cursor:pointer;text-decoration:none;display:inline-block}
.flash{border-radius:9px;padding:10px 14px;margin-bottom:1rem;font-size:.83rem}
.flash.ok {background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:#34d399}
.flash.warn{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);color:#fbbf24}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800">🧠 ML Prediction Log</h1>
    <p style="color:var(--muted);font-size:.83rem;margin-top:2px">
      Random Forest forecasts · Isolation Forest anomaly detection
    </p>
  </div>
  <a href="?run_ml=1" class="run-btn">▶ Run ML Engine Now</a>
</div>

<?php if ($run_msg): ?>
<div class="flash <?= str_starts_with($run_msg,'✅') ? 'ok' : 'warn' ?>"><?= htmlspecialchars($run_msg) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="sg">
  <?php
  $last = $stats['last_success'] ?? null;
  $last_str = $last ? date('d M H:i', strtotime($last)) : 'Never';
  foreach ([
    ['Total Runs',   $stats['total_runs']       ?? 0, '#0ea5e9'],
    ['Successful',   $stats['success_runs']      ?? 0, '#34d399'],
    ['Forecasts',    $stats['active_forecasts']  ?? 0, '#06b6d4'],
    ['Open Anomalies',$stats['open_anomalies']   ?? 0, '#f87171'],
    ['Last Run',     $last_str,                        '#7a9bba'],
  ] as [$l,$v,$c]): ?>
  <div class="sc">
    <div class="sl"><?= $l ?></div>
    <div class="sv" style="color:<?= $c ?>;font-size:<?= is_string($v)&&strlen($v)>5?'.9rem':'1.4rem' ?>"><?= $v ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="tab-header">
  <div class="tab on"  onclick="showTab('forecasts',this)">📈 7-Day Forecasts</div>
  <div class="tab"     onclick="showTab('anomalies',this)">🔍 ML Anomalies</div>
  <div class="tab"     onclick="showTab('runlog',this)">📋 Run Log</div>
  <div class="tab"     onclick="showTab('setup',this)">⚙️ Setup Guide</div>
</div>

<!-- Tab: Forecasts -->
<div class="tab-body on" id="tab-forecasts">
  <?php if (empty($by_zone)): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;
              padding:2.5rem;text-align:center;color:var(--muted)">
    <div style="font-size:2rem;margin-bottom:.75rem">📭</div>
    <div style="font-weight:700;margin-bottom:.4rem">No forecasts yet</div>
    <p style="font-size:.83rem">Click <b>▶ Run ML Engine Now</b> above (or run <code>python ml/prediction_engine.py</code> in your terminal) to generate the first predictions.</p>
  </div>
  <?php else: ?>
  <?php foreach ($by_zone as $zname => $rows): ?>
  <div class="fore-zone">
    <div class="fore-zone-name">📍 <?= htmlspecialchars($zname) ?></div>
    <div class="fore-grid">
      <?php foreach ($rows as $r): ?>
      <div class="fore-day">
        <div class="fore-date"><?= date('D d', strtotime($r['predict_date'])) ?></div>
        <div class="fore-val" style="color:var(--blue)">
          <?= $r['predicted_flow'] !== null ? round($r['predicted_flow'],1).'<small style="font-size:.55rem;font-weight:400"> L/m</small>' : '—' ?>
        </div>
        <div class="fore-sub">
          Level: <?= $r['predicted_level'] !== null ? round($r['predicted_level'],1).'%' : '—' ?>
        </div>
        <div class="conf-bar" style="width:<?= round($r['confidence_pct']) ?>%"></div>
        <div style="font-size:.55rem;color:var(--muted);margin-top:2px"><?= round($r['confidence_pct']) ?>% conf</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Tab: Anomalies -->
<div class="tab-body" id="tab-anomalies">
  <?php if (empty($anomalies)): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:2rem;text-align:center;color:var(--muted)">
    No ML anomalies logged yet. Run the engine first.
  </div>
  <?php else: ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden">
    <table class="tbl">
      <thead>
        <tr><th>Zone</th><th>Type</th><th>Actual</th><th>Expected</th><th>Deviation</th><th>Confidence</th><th>Detected</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($anomalies as $a):
        $conf = (float)($a['ml_confidence'] ?? $a['severity_score'] ?? 0);
        $bc = $conf >= 80 ? 'b-hi' : ($conf >= 50 ? 'b-med' : 'b-lo');
      ?>
      <tr>
        <td><?= htmlspecialchars($a['zone_name'] ?? '—') ?></td>
        <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($a['anomaly_type']) ?></td>
        <td style="color:var(--red);font-weight:600"><?= round((float)$a['actual_value'],2) ?></td>
        <td><?= round((float)$a['expected_value'],2) ?></td>
        <td><?= round((float)$a['deviation_pct'],1) ?>%</td>
        <td><span class="bdg <?= $bc ?>"><?= round($conf) ?>%</span></td>
        <td style="color:var(--muted);font-size:.75rem"><?= date('d M H:i', strtotime($a['detected_at'])) ?></td>
        <td><span class="bdg <?= $a['is_resolved'] ? 'b-ok' : 'b-err' ?>"><?= $a['is_resolved'] ? 'Resolved' : 'Open' ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Tab: Run Log -->
<div class="tab-body" id="tab-runlog">
  <?php if (empty($run_log)): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:2rem;text-align:center;color:var(--muted)">
    No runs logged yet.
  </div>
  <?php else: ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden">
    <table class="tbl">
      <thead>
        <tr><th>Run At</th><th>Zone</th><th>Rows</th><th>Forecasts</th><th>Anomalies</th><th>MAE Flow</th><th>MAE Level</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($run_log as $r):
        $bc = $r['status']==='success' ? 'b-ok' : ($r['status']==='skipped' ? 'b-skip' : 'b-err');
      ?>
      <tr>
        <td style="color:var(--muted);font-size:.75rem"><?= date('d M Y H:i', strtotime($r['run_at'])) ?></td>
        <td><?= htmlspecialchars($r['zone_name'] ?? '—') ?></td>
        <td><?= $r['rows_trained'] ?></td>
        <td><?= $r['forecast_days'] ?></td>
        <td><?= $r['anomalies_found'] ?></td>
        <td><?= $r['mae_flow'] !== null ? round($r['mae_flow'],3) : '—' ?></td>
        <td><?= $r['mae_level'] !== null ? round($r['mae_level'],3) : '—' ?></td>
        <td>
          <span class="bdg <?= $bc ?>"><?= strtoupper($r['status']) ?></span>
          <?php if ($r['error_msg']): ?>
          <span style="font-size:.65rem;color:var(--red);display:block;margin-top:2px">
            <?= htmlspecialchars(substr($r['error_msg'],0,80)) ?>
          </span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Tab: Setup -->
<div class="tab-body" id="tab-setup">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.5rem">
    <h3 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:1rem">⚙️ Setup & Scheduling</h3>
    <div style="font-size:.85rem;line-height:1.9;color:var(--text)">

      <b style="color:var(--blue)">1. Files location</b><br>
      Place <code>ml/prediction_engine.py</code> inside your smart_water folder:<br>
      <code style="color:#34d399">C:\xampp_new\htdocs\smart_water\ml\prediction_engine.py</code>
      <br><br>

      <b style="color:var(--blue)">2. Test manually</b><br>
      Open a command prompt (Win+R → cmd):<br>
      <code style="color:#34d399">cd C:\xampp_new\htdocs\smart_water\ml<br>python prediction_engine.py</code>
      <br>
      It will print each zone and create tables in your DB.<br><br>

      <b style="color:var(--blue)">3. Schedule automatically (every 15 minutes)</b><br>
      In an Administrator command prompt, paste this one line:<br>
      <code style="color:#34d399;word-break:break-all">
        schtasks /create /tn "SWDS_ML" /sc minute /mo 15 /tr "python C:\xampp_new\htdocs\smart_water\ml\prediction_engine.py" /f
      </code>
      <br><br>

      <b style="color:var(--blue)">4. Check the log file</b><br>
      After running, check: <code>C:\xampp_new\htdocs\smart_water\ml\ml_engine.log</code><br>
      This shows every zone processed, MAE scores, and any errors.<br><br>

      <b style="color:var(--blue)">5. Minimum data needed</b><br>
      Each zone needs at least <b>10 sensor readings</b> in <code>sensor_readings</code> before the ML model will train.
      The more data you have (especially 90+ days), the more accurate the forecasts.<br><br>

      <b style="color:var(--blue)">6. DB config</b><br>
      Edit the <code>DB_CONFIG</code> dict at the top of <code>prediction_engine.py</code> if your
      MySQL password or database name is different from the defaults.
    </div>
  </div>
</div>

<script>
function showTab(name, el) {
    document.querySelectorAll('.tab-body').forEach(t => t.classList.remove('on'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
    document.getElementById('tab-' + name).classList.add('on');
    el.classList.add('on');
}
</script>

</main>
</body>
</html>