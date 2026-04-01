<?php
// ================================================================
//  user_dashboard.php — Resident Water Portal
//  Architecture: PRG pattern · safe query guards · full analytics
// ================================================================
session_start();
require_once __DIR__ . '/db.php';

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['user_name'] ?? 'Resident';
$role = $_SESSION['user_role'] ?? 'user';
if (in_array($role, ['admin','operator'])) { header('Location: dashboard.php'); exit; }

// ================================================================
//  HELPERS
// ================================================================
function db_val(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn() ?? $default;
    } catch (PDOException $e) { return $default; }
}
function db_row(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch() ?: [];
    } catch (PDOException $e) { return []; }
}
function db_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll() ?: [];
    } catch (PDOException $e) { return []; }
}
function has_col(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col";
    if (!isset($cache[$key])) {
        $cols = array_column(db_rows($pdo,"SHOW COLUMNS FROM `$table`"), 'Field');
        foreach ($cols as $c) $cache["$table.$c"] = true;
    }
    return $cache[$key] ?? false;
}
function fmt_num(float $n, int $dec = 0): string {
    return number_format($n, $dec);
}

// ── Water rate ────────────────────────────────────────────────
$RATE = (float)(db_val($pdo, "SELECT setting_val FROM system_settings WHERE setting_key='water_rate_kes' LIMIT 1") ?: 0.05);

// ================================================================
//  PRG: HANDLE POST ACTIONS — then redirect
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── BUY WATER ───────────────────────────────────────────────
    if ($action === 'buy_water') {
        $litres = (float)($_POST['litres'] ?? 0);
        $method = in_array($_POST['method']??'',['M-Pesa','Cash','Bank','Airtel Money'])
                  ? $_POST['method'] : 'M-Pesa';
        $mpesa_ref = preg_replace('/[^A-Z0-9]/i','', strtoupper(trim($_POST['mpesa_ref']??'')));

        if ($litres < 100) {
            $_SESSION['flash'] = ['err','Minimum purchase is 100 litres.'];
        } else {
            $amount     = round($litres * $RATE, 2);
            $invoice_no = 'INV-'.strtoupper(substr(md5(uniqid($uid,true)),0,8));

            try {
                $pdo->beginTransaction();

                // Add to balance
                $pdo->prepare("UPDATE users SET water_balance = water_balance + ? WHERE id=?")->execute([$litres, $uid]);

                // Insert into billing (primary billing table)
                try {
                    $pdo->prepare("
                        INSERT INTO billing (user_id,invoice_no,litres,rate_per_litre,amount_kes,payment_method,mpesa_ref,status)
                        VALUES (?,?,?,?,?,?,?,'paid')
                    ")->execute([$uid,$invoice_no,$litres,$RATE,$amount,$method,$mpesa_ref?:null]);
                } catch (PDOException $e) {
                    // billing table doesn't exist yet — fall back to transactions
                    $pdo->prepare("INSERT INTO transactions (user_id,litres,amount_kes) VALUES (?,?,?)")
                        ->execute([$uid,$litres,$amount]);
                }

                $pdo->commit();
                $_SESSION['flash'] = ['ok',"✅ Purchased ".fmt_num($litres)." L · Invoice $invoice_no · KES ".fmt_num($amount,2)];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash'] = ['err','Purchase failed. Please try again.'];
            }
        }
    }

    // ── SEND REPORT ─────────────────────────────────────────────
    elseif ($action === 'send_report') {
        $message    = trim($_POST['message'] ?? '');
        $issue_type = trim($_POST['issue_type'] ?? 'Other');
        $severity   = in_array($_POST['severity']??'',['info','warning','critical']) ? $_POST['severity'] : 'warning';
        $zone_nm    = trim($_POST['zone_name'] ?? '');
        $gps_lat    = is_numeric($_POST['gps_lat'] ?? '') ? (float)$_POST['gps_lat'] : null;
        $gps_lng    = is_numeric($_POST['gps_lng'] ?? '') ? (float)$_POST['gps_lng'] : null;

        if (empty($message)) {
            $_SESSION['flash'] = ['err','Please describe the problem.'];
        } elseif (empty($zone_nm)) {
            $_SESSION['flash'] = ['err','Please select your zone.'];
        } else {
            try {
                // Ensure table + all columns exist before inserting
                $pdo->exec("CREATE TABLE IF NOT EXISTS emergency_messages (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    user_id      INT NOT NULL,
                    message      TEXT NOT NULL,
                    severity     VARCHAR(20)  DEFAULT 'warning',
                    issue_type   VARCHAR(100) DEFAULT 'Other',
                    status       VARCHAR(30)  DEFAULT 'open',
                    zone_name    VARCHAR(100),
                    gps_lat      DECIMAL(10,7),
                    gps_lng      DECIMAL(10,7),
                    admin_response TEXT,
                    responded_by INT DEFAULT NULL,
                    responded_at TIMESTAMP NULL,
                    is_read      TINYINT(1) DEFAULT 0,
                    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                foreach ([
                    "ALTER TABLE emergency_messages ADD COLUMN severity VARCHAR(20) DEFAULT 'warning'",
                    "ALTER TABLE emergency_messages ADD COLUMN issue_type VARCHAR(100) DEFAULT 'Other'",
                    "ALTER TABLE emergency_messages ADD COLUMN status VARCHAR(30) DEFAULT 'open'",
                    "ALTER TABLE emergency_messages ADD COLUMN zone_name VARCHAR(100)",
                    "ALTER TABLE emergency_messages ADD COLUMN gps_lat DECIMAL(10,7)",
                    "ALTER TABLE emergency_messages ADD COLUMN gps_lng DECIMAL(10,7)",
                    "ALTER TABLE emergency_messages ADD COLUMN admin_response TEXT",
                    "ALTER TABLE emergency_messages ADD COLUMN responded_by INT DEFAULT NULL",
                    "ALTER TABLE emergency_messages ADD COLUMN responded_at TIMESTAMP NULL",
                    "ALTER TABLE emergency_messages ADD COLUMN is_read TINYINT(1) DEFAULT 0",
                ] as $s) { try { $pdo->exec($s); } catch(PDOException $e){} }

                // Direct insert — no has_col guesswork
                $pdo->prepare("INSERT INTO emergency_messages
                    (user_id, message, severity, issue_type, status, zone_name, gps_lat, gps_lng)
                    VALUES (?, ?, ?, ?, 'open', ?, ?, ?)")
                    ->execute([$uid, $message, $severity, $issue_type,
                               $zone_nm ?: null, $gps_lat, $gps_lng]);

                $_SESSION['flash'] = ['ok','✅ Report sent. The admin has been notified.'];
            } catch (PDOException $e) {
                $_SESSION['flash'] = ['err','Could not submit report: ' . $e->getMessage()];
            }
        }
    }

    // ── MARK NOTIFICATION READ ──────────────────────────────────
    elseif ($action === 'mark_read') {
        $nid = (int)($_POST['nid'] ?? 0);
        try {
            $pdo->prepare("UPDATE user_notifications SET is_read=1 WHERE id=? AND (user_id=? OR user_id IS NULL)")->execute([$nid,$uid]);
        } catch (PDOException $e) {}
    }

    // PRG redirect — prevents double-submit on F5
    header('Location: user_dashboard.php');
    exit;
}

// ── Read and clear flash ──────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ================================================================
//  DATA LAYER — all queries wrapped in helpers (no crash on error)
// ================================================================

// User account
$user = db_row($pdo,"SELECT full_name,water_balance FROM users WHERE id=?",[$uid]);
$balance_raw = (float)($user['water_balance'] ?? 0);

// All-time consumption
$consumed_all = (float)db_val($pdo,
    "SELECT COALESCE(SUM(litres_used),0) FROM consumption_log WHERE user_id=?",[$uid]);

// Balance tied to consumption:
// water_balance holds total purchased. consumed_all is what consumption_log records.
// remaining = purchased balance - consumed. If ESP32 deducts balance directly,
// consumption_log may be 0 and balance_raw is already net — both cases handled.
$remaining = max(0, $balance_raw - $consumed_all);
// If your ESP32 deducts water_balance directly AND also writes consumption_log,
// switch to: $remaining = max(0, $balance_raw);

$total_purchased = (float)db_val($pdo,
    "SELECT COALESCE(SUM(litres),0) FROM ".( 
        // use billing if available, else transactions
        db_val($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing'")
        ? "billing" : "transactions"
    )." WHERE user_id=?",[$uid]);

// Today's consumption
$today_litres = (float)db_val($pdo,
    "SELECT COALESCE(SUM(litres_used),0) FROM consumption_log WHERE user_id=? AND DATE(consumed_at)=CURDATE()",[$uid]);
$today_cost = round($today_litres * $RATE, 2);

// Last 14 days daily usage (for trend chart + prediction)
// Primary: consumption_log. Fallback: sensor_readings flow for user's zone
$daily_14 = db_rows($pdo,
    "SELECT DATE(consumed_at) AS d, SUM(litres_used) AS total
     FROM consumption_log WHERE user_id=? AND consumed_at >= DATE_SUB(NOW(),INTERVAL 14 DAY)
     GROUP BY d ORDER BY d ASC",[$uid]);

// Fill missing days with 0 so chart has 14 points
$daily_map = [];
for ($i=13;$i>=0;$i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $daily_map[$day] = 0;
}
foreach ($daily_14 as $r) $daily_map[$r['d']] = (float)$r['total'];

// ── Fallback: if all zeros, use sensor flow data for user's zone ──
$all_zero = (array_sum($daily_map) === 0.0);
if ($all_zero) {
    try {
        // Get user's zone from their account or billing
        $user_zone = $pdo->prepare("
            SELECT wz.id FROM water_zones wz
            JOIN billing b ON b.zone_id=wz.id
            WHERE b.user_id=? LIMIT 1
        ");
        $user_zone->execute([$uid]);
        $zone_row = $user_zone->fetch();
        if ($zone_row) {
            $flow_14 = db_rows($pdo,
                "SELECT DATE(recorded_at) AS d,
                        ROUND(AVG(flow_rate) * 60 * 24, 1) AS total
                 FROM sensor_readings
                 WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(),INTERVAL 14 DAY)
                 GROUP BY DATE(recorded_at) ORDER BY d ASC",
                [$zone_row['id']]);
            foreach ($flow_14 as $r) {
                if ((float)$r['total'] > 0) $daily_map[$r['d']] = (float)$r['total'];
            }
        }
    } catch(PDOException $e) {}
}
$chart_labels = array_map(fn($d)=>date('d M', strtotime($d)), array_keys($daily_map));
$chart_values = array_values($daily_map);

// Average daily (last 14 days with data only)
$nonzero = array_filter($chart_values, fn($v)=>$v>0);
$avg_daily = count($nonzero) ? array_sum($nonzero)/count($nonzero) : 0;

// ── USAGE PREDICTION (linear regression on last 14 days) ──────
// y = mx + b,  x = day index, y = litres
$n  = count($chart_values);
$xs = range(0, $n-1);
$sx = array_sum($xs);
$sy = array_sum($chart_values);
$sxy= 0; $sx2=0;
foreach ($xs as $i => $x) { $sxy += $x * $chart_values[$i]; $sx2 += $x*$x; }
$denom = ($n*$sx2 - $sx*$sx);
$m_reg = $denom!=0 ? ($n*$sxy - $sx*$sy)/$denom : 0;
$b_reg = ($sy - $m_reg*$sx)/$n;
// Predict next 7 days
$pred_values = [];
for ($i=0;$i<7;$i++) {
    $x = $n + $i;
    $pred_values[] = max(0, round($m_reg*$x + $b_reg, 1));
}
$pred_avg = array_sum($pred_values)/max(1,count($pred_values));

// Days of water remaining
$days_left = ($avg_daily > 0) ? (int)floor($remaining / $avg_daily) : null;
$pred_days_left = ($pred_avg > 0) ? (int)floor($remaining / $pred_avg) : null;

// ── ANOMALY DETECTION — IQR method on flow rate ───────────────
// Fetch last 48 flow readings from the user's zone
// Get user's assigned zone — fallback to Zone A if not assigned
$user_zone_id = db_val($pdo, "SELECT zone_id FROM users WHERE id=?", [$uid], 0);
$zone_row = db_row($pdo,
    "SELECT wz.id,wz.zone_name,wz.valve_status,
            sr.flow_rate,sr.pressure,sr.recorded_at
     FROM water_zones wz
     LEFT JOIN sensor_readings sr ON sr.id=(
         SELECT id FROM sensor_readings WHERE zone_id=wz.id ORDER BY recorded_at DESC LIMIT 1
     )
     WHERE wz.id = IF(? > 0, ?, (SELECT MIN(id) FROM water_zones))
     LIMIT 1",
    [$user_zone_id, $user_zone_id]);

$flow_readings = db_rows($pdo,
    "SELECT flow_rate FROM sensor_readings WHERE zone_id=?
     AND flow_rate IS NOT NULL AND recorded_at >= DATE_SUB(NOW(),INTERVAL 48 HOUR)
     ORDER BY recorded_at ASC",
    [$zone_row['id'] ?? 0]);

$anomaly_flag  = false;
$anomaly_label = '';
$iqr_upper     = null;
if (count($flow_readings) >= 4) {
    $flows = array_column($flow_readings,'flow_rate');
    sort($flows);
    $q1 = $flows[(int)floor(count($flows)*0.25)];
    $q3 = $flows[(int)floor(count($flows)*0.75)];
    $iqr = $q3 - $q1;
    $iqr_upper = $q3 + 1.5*$iqr;
    $cur_flow = (float)($zone_row['flow_rate'] ?? 0);
    if ($cur_flow > $iqr_upper && $iqr_upper > 0) {
        $anomaly_flag  = true;
        $anomaly_label = "⚠ Abnormal flow detected — possible leak (IQR threshold: ".round($iqr_upper,1)." L/min)";
    }
}

// ── SENSOR RELIABILITY SCORE ──────────────────────────────────
// Expected: 1 reading every 2 minutes = 720 per 24h
// Score = actual readings / expected × 100 (capped 100)
$readings_24h = (int)db_val($pdo,
    "SELECT COUNT(*) FROM sensor_readings WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)",
    [$zone_row['id'] ?? 0]);
$expected_24h    = 720;
$sensor_score    = min(100, round(($readings_24h / $expected_24h) * 100));
$sensor_label    = $sensor_score>=80?'Reliable':($sensor_score>=50?'Degraded':'Unreliable');
$sensor_color    = $sensor_score>=80?'#4ade80':($sensor_score>=50?'#fbbf24':'#f87171');

// ── EFFICIENCY = consumed / purchased × 100 ───────────────────
// Exactly as specified
$efficiency = ($total_purchased > 0)
    ? round(($consumed_all / $total_purchased) * 100, 1)
    : 0;

// Classification
if ($efficiency === 0.0)      $eff = ['label'=>'No Data',          'class'=>'eff-none',   'icon'=>'◌','detail'=>'No consumption recorded yet.'];
elseif ($efficiency > 105)    $eff = ['label'=>'Possible Leak',    'class'=>'eff-leak',   'icon'=>'⚠','detail'=>'More consumed than purchased — sensor drift or leak suspected.'];
elseif ($efficiency > 95)     $eff = ['label'=>'Abnormal Spike',   'class'=>'eff-spike',  'icon'=>'⚡','detail'=>'Usage is unusually high compared to purchases.'];
elseif ($efficiency >= 60)    $eff = ['label'=>'Normal Use',       'class'=>'eff-normal', 'icon'=>'✓','detail'=>'Consumption is within expected range.'];
elseif ($efficiency >= 30)    $eff = ['label'=>'Low Activity',     'class'=>'eff-low',    'icon'=>'↓','detail'=>'Usage is well below purchased volume.'];
else                          $eff = ['label'=>'Very Low / Idle',  'class'=>'eff-idle',   'icon'=>'○','detail'=>'Almost no usage recorded.'];

// Peak hour
$peak = db_row($pdo,
    "SELECT HOUR(consumed_at) hr, SUM(litres_used) total FROM consumption_log
     WHERE user_id=? GROUP BY hr ORDER BY total DESC LIMIT 1",[$uid]);
$peak_hour = $peak ? sprintf('%02d:00–%02d:00',(int)$peak['hr'],(int)$peak['hr']+1) : 'N/A';

// Billing records
$use_billing = (bool)db_val($pdo,
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='billing'");
$bill_rows = $use_billing
    ? db_rows($pdo,"SELECT invoice_no,litres,amount_kes,payment_method,mpesa_ref,status,paid_at FROM billing WHERE user_id=? ORDER BY paid_at DESC LIMIT 10",[$uid])
    : db_rows($pdo,"SELECT NULL AS invoice_no,litres,amount_kes,NULL AS payment_method,NULL AS mpesa_ref,'paid' AS status,created_at AS paid_at FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 10",[$uid]);

// Live sensor
$valve_on  = strtoupper($zone_row['valve_status'] ?? '') === 'OPEN';
$cur_flow  = (float)($zone_row['flow_rate'] ?? 0);
$cur_press = (float)($zone_row['pressure']  ?? 0);
$last_ping = $zone_row['recorded_at'] ?? null;
// Online = last sensor reading within last 2 minutes
// If no hardware yet, show dashboard load time as ping
$online    = $last_ping && (time()-strtotime($last_ping)) < 120;
$ping_display = $online ? date('d M H:i:s', strtotime($last_ping))
              : ($last_ping ? date('d M H:i', strtotime($last_ping)) : date('d M H:i:s'));
if (!$online)           $sys = ['Normal','#94a3b8'];
elseif ($cur_press<0.5) $sys = ['Low Pressure','#fbbf24'];
else                    $sys = ['Normal','#4ade80'];

// Gauge percent
$gauge_max = max($total_purchased, 1000);
$gauge_pct = min(100, ($remaining / $gauge_max) * 100);

// Notifications
$notifs = db_rows($pdo,
    "SELECT * FROM user_notifications WHERE (user_id=? OR user_id IS NULL) AND is_read=0 ORDER BY created_at DESC LIMIT 8",[$uid]);

// System alerts removed from user dashboard — admin only

// My reports
$em_sel = "message,created_at"
    .(has_col($pdo,'emergency_messages','severity')      ? ",severity"       : ",'warning' AS severity")
    .(has_col($pdo,'emergency_messages','issue_type')    ? ",issue_type"     : ",NULL AS issue_type")
    .(has_col($pdo,'emergency_messages','admin_response')? ",admin_response" : ",NULL AS admin_response")
    .(has_col($pdo,'emergency_messages','status')        ? ",status"         : ",'open' AS status");
$my_reports = db_rows($pdo,
    "SELECT $em_sel FROM emergency_messages WHERE user_id=? ORDER BY created_at DESC LIMIT 8",[$uid]);

// Gauge color
$gc = $gauge_pct>60?'#4ade80':($gauge_pct>30?'#fbbf24':'#f87171');

// SVG semicircle helpers
$R=80; $CX=100; $CY=95;
$ARC_LEN = M_PI * $R;
$filled  = ($gauge_pct/100) * $ARC_LEN;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Water Portal · <?= htmlspecialchars($name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,700;0,9..144,900;1,9..144,300&family=IBM+Plex+Mono:wght@400;500;600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080d14;
  --bg2:#0d1520;
  --card:#0f1c2e;
  --card2:#132038;
  --border:#1a2e48;
  --border2:#1f3655;
  --cyan:#00d4ff;
  --cyan2:#0099cc;
  --green:#00ff87;
  --yellow:#ffcc00;
  --red:#ff4757;
  --orange:#ff7043;
  --slate:#8ba5c0;
  --slate2:#506070;
  --text:#ddeeff;
  --text2:#8ba5c0;
  --text3:#3d5470;
  --mono:'IBM Plex Mono',monospace;
  --serif:'Fraunces',Georgia,serif;
  --body:'Manrope',sans-serif;
}
html{scroll-behavior:smooth}
body{
  font-family:var(--body);
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  background-image:
    radial-gradient(ellipse 120% 40% at 50% 0%,rgba(0,212,255,.04) 0%,transparent 60%),
    repeating-linear-gradient(0deg,transparent,transparent 59px,rgba(26,46,72,.3) 60px),
    repeating-linear-gradient(90deg,transparent,transparent 59px,rgba(26,46,72,.3) 60px);
}

/* ── NAV ─────────────────────────────────────────────── */
.nav{
  position:sticky;top:0;z-index:200;
  height:52px;
  background:rgba(8,13,20,.9);
  backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;
  padding:0 1.5rem;gap:12px;
}
.nav-brand{display:flex;align-items:center;gap:8px;margin-right:auto}
.nav-mark{
  width:28px;height:28px;border-radius:6px;
  background:linear-gradient(135deg,var(--cyan2),var(--cyan));
  display:grid;place-items:center;font-size:.75rem;
}
.nav-title{font-family:var(--serif);font-size:.92rem;font-weight:700;letter-spacing:-.01em;color:var(--text)}
.nav-title em{font-style:italic;color:var(--cyan)}
.nav-chip{
  display:flex;align-items:center;gap:6px;
  background:var(--card);border:1px solid var(--border);
  border-radius:7px;padding:4px 10px;font-size:.72rem;
}
.av{
  width:20px;height:20px;border-radius:50%;
  background:linear-gradient(135deg,var(--cyan2),var(--cyan));
  display:grid;place-items:center;font-size:.6rem;font-weight:700;color:#000;
}
.notif-btn{
  position:relative;
  background:var(--card);border:1px solid var(--border);
  border-radius:7px;padding:4px 10px;font-size:.72rem;
  text-decoration:none;color:var(--text2);cursor:pointer;
  transition:border-color .15s;
}
.notif-btn:hover{border-color:var(--cyan)}
.nbadge{
  position:absolute;top:-5px;right:-5px;
  background:var(--red);color:#fff;border-radius:10px;
  font-size:.55rem;font-weight:700;padding:1px 4px;
  font-family:var(--mono);
}
.nav-out{font-size:.72rem;color:var(--text3);text-decoration:none;padding:4px 9px;border-radius:6px;transition:.15s}
.nav-out:hover{color:var(--red);background:rgba(255,71,87,.08)}

/* ── PAGE ────────────────────────────────────────────── */
.page{max-width:1020px;margin:0 auto;padding:1.25rem 1rem 5rem}

/* ── FLASH ───────────────────────────────────────────── */
.flash{
  border-radius:10px;padding:11px 16px;
  margin-bottom:1rem;font-size:.8rem;font-weight:600;
  display:flex;align-items:center;gap:8px;
  animation:fadeSlide .3s ease;
}
.flash-ok {background:rgba(0,255,135,.07);border:1px solid rgba(0,255,135,.2);color:var(--green)}
.flash-err{background:rgba(255,71,87,.07);border:1px solid rgba(255,71,87,.2);color:var(--red)}
@keyframes fadeSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

/* ── ROW / GRID ──────────────────────────────────────── */
.row{display:grid;gap:1rem;margin-bottom:1rem}
.row-2{grid-template-columns:1fr 1fr}
.row-3{grid-template-columns:1fr 1fr 1fr}
.row-4{grid-template-columns:1fr 1fr 1fr 1fr}
@media(max-width:760px){.row-2,.row-3,.row-4{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.row-2,.row-3,.row-4{grid-template-columns:1fr}}

/* ── CARD ────────────────────────────────────────────── */
.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:14px;
  padding:1.1rem;
  transition:border-color .2s;
}
.card:hover{border-color:var(--border2)}
.ch{display:flex;align-items:center;justify-content:space-between;margin-bottom:.9rem}
.ct{font-family:var(--serif);font-size:.85rem;font-weight:700;display:flex;align-items:center;gap:6px}
.cs{font-size:.65rem;color:var(--text3);font-family:var(--mono)}

/* ── SECTION RULE ────────────────────────────────────── */
.srule{
  display:flex;align-items:center;gap:10px;
  margin:1.5rem 0 .75rem;
  font-family:var(--mono);font-size:.6rem;font-weight:600;
  text-transform:uppercase;letter-spacing:.14em;color:var(--text3);
}
.srule::after{content:'';flex:1;height:1px;background:var(--border)}

/* ══════════════════════════════════════════════════════
   BALANCE GAUGE HERO
══════════════════════════════════════════════════════ */
.gauge-hero{
  background:linear-gradient(160deg,#0a1628 0%,#0d1f3a 100%);
  border:1px solid rgba(0,212,255,.15);
  border-radius:18px;padding:1.5rem;
  margin-bottom:1rem;
  position:relative;overflow:hidden;
}
.gauge-hero::before{
  content:'';position:absolute;top:-80px;right:-80px;
  width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(0,212,255,.06) 0%,transparent 70%);
  pointer-events:none;
}
.gh-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem}
.gh-user .gu-label{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:3px;font-family:var(--mono)}
.gh-user .gu-name{font-family:var(--serif);font-size:1.15rem;font-weight:700}
.forecast-chip{text-align:right}
.fc-label{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:3px;font-family:var(--mono)}
.fc-val{font-family:var(--mono);font-size:1.4rem;font-weight:600}

/* SVG Gauge */
.gauge-wrap{display:flex;flex-direction:column;align-items:center}
.gauge-svg{width:220px;height:120px;overflow:visible}
.g-track{fill:none;stroke:#1a2e48;stroke-width:13;stroke-linecap:round}
.g-fill{fill:none;stroke-width:13;stroke-linecap:round;transition:stroke-dashoffset 1.4s cubic-bezier(.22,1,.36,1)}
.gauge-legend{display:flex;justify-content:space-between;width:220px;margin-top:-6px}
.gauge-legend span{font-family:var(--mono);font-size:.55rem;color:var(--text3)}
.gauge-readout{text-align:center;margin-top:.5rem}
.gr-main{font-family:var(--serif);font-size:2.6rem;font-weight:900;line-height:1;letter-spacing:-.03em}
.gr-unit{font-size:.9rem;color:var(--text2);margin-left:4px;font-family:var(--mono)}
.gr-money{font-family:var(--mono);font-size:.75rem;color:var(--cyan);margin-top:4px}
.gr-money em{font-style:normal;color:var(--text2)}

.gh-stats{
  display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;
  margin-top:1.1rem;padding-top:1rem;
  border-top:1px solid rgba(26,46,72,.8);
}
.ghs{text-align:center}
.ghs .gl{font-family:var(--mono);font-size:.55rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:4px}
.ghs .gv{font-family:var(--mono);font-size:.92rem;font-weight:600}

/* ══════════════════════════════════════════════════════
   LIVE STATUS CARDS
══════════════════════════════════════════════════════ */
.live-val{font-family:var(--mono);font-size:1.35rem;font-weight:600;line-height:1}
.live-sub{font-size:.68rem;color:var(--text3);margin-top:4px}
.valve-pill{
  display:inline-flex;align-items:center;gap:6px;
  padding:5px 13px;border-radius:20px;
  font-family:var(--mono);font-size:.72rem;font-weight:600;text-transform:uppercase;
}
.vp-open {background:rgba(0,255,135,.1);color:var(--green);border:1px solid rgba(0,255,135,.2)}
.vp-close{background:rgba(255,71,87,.1);color:var(--red);border:1px solid rgba(255,71,87,.2)}
.vdot{width:7px;height:7px;border-radius:50%}
.vdot-g{background:var(--green);animation:glow 2s infinite;box-shadow:0 0 6px var(--green)}
.vdot-r{background:var(--red)}
@keyframes glow{0%,100%{opacity:1}50%{opacity:.35}}
.sys-pip{display:flex;align-items:center;gap:7px}
.sp-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ══════════════════════════════════════════════════════
   STAT MINI CARDS
══════════════════════════════════════════════════════ */
.mini-card{
  background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.9rem;
}
.mc-l{font-family:var(--mono);font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:.35rem}
.mc-v{font-family:var(--mono);font-size:1.2rem;font-weight:600}
.mc-s{font-size:.67rem;color:var(--text3);margin-top:3px}

/* ══════════════════════════════════════════════════════
   TREND CHART (SVG bar chart)
══════════════════════════════════════════════════════ */
.chart-box{position:relative;height:120px;margin-bottom:.5rem}
.chart-svg{width:100%;height:100%}
.bar-label{font-family:var(--mono);font-size:8px;fill:var(--text3)}
.pred-label{font-family:var(--mono);font-size:7px;fill:var(--cyan);opacity:.7}
.axis-line{stroke:var(--border);stroke-width:1}
.chart-legend{display:flex;gap:1rem;justify-content:flex-end;margin-top:.4rem}
.cl-item{display:flex;align-items:center;gap:5px;font-size:.62rem;color:var(--text3);font-family:var(--mono)}
.cl-dot{width:8px;height:8px;border-radius:2px}

/* ══════════════════════════════════════════════════════
   EFFICIENCY
══════════════════════════════════════════════════════ */
.eff-big{font-family:var(--mono);font-size:2.2rem;font-weight:600;line-height:1}
.eff-bar-track{height:7px;background:var(--border);border-radius:99px;overflow:hidden;margin:.6rem 0}
.eff-bar-fill{height:100%;border-radius:99px;transition:width 1.2s ease}
.eff-badge{
  display:inline-flex;align-items:center;gap:6px;
  padding:4px 12px;border-radius:20px;
  font-family:var(--mono);font-size:.7rem;font-weight:600;
}
.eff-normal{background:rgba(0,255,135,.1);color:var(--green);border:1px solid rgba(0,255,135,.2)}
.eff-spike {background:rgba(255,204,0,.1);color:var(--yellow);border:1px solid rgba(255,204,0,.2)}
.eff-leak  {background:rgba(255,71,87,.1);color:var(--red);border:1px solid rgba(255,71,87,.2)}
.eff-low   {background:rgba(139,165,192,.1);color:var(--slate);border:1px solid rgba(139,165,192,.2)}
.eff-idle  {background:rgba(61,84,112,.15);color:var(--slate2);border:1px solid rgba(61,84,112,.3)}
.eff-none  {background:rgba(61,84,112,.1);color:var(--slate2);border:1px solid rgba(61,84,112,.2)}

/* Anomaly banner */
.anomaly-banner{
  background:rgba(255,71,87,.06);border:1px solid rgba(255,71,87,.25);
  border-left:3px solid var(--red);
  border-radius:10px;padding:.8rem 1rem;
  font-size:.78rem;color:var(--red);font-family:var(--mono);
  margin-bottom:1rem;display:flex;align-items:center;gap:10px;
}

/* Sensor reliability bar */
.rel-row{display:flex;align-items:center;gap:.75rem;margin-top:.5rem}
.rel-track{flex:1;height:5px;background:var(--border);border-radius:99px;overflow:hidden}
.rel-fill{height:100%;border-radius:99px;transition:width 1s ease}

/* ══════════════════════════════════════════════════════
   BUY WATER
══════════════════════════════════════════════════════ */
.pkg-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin-bottom:.9rem}
@media(max-width:520px){.pkg-grid{grid-template-columns:repeat(2,1fr)}}
.pkg{
  border:1px solid var(--border);border-radius:10px;
  padding:.65rem .4rem;text-align:center;cursor:pointer;
  background:var(--card2);transition:all .15s;font-family:var(--body);
}
.pkg:hover,.pkg.sel{border-color:var(--cyan);background:rgba(0,212,255,.06)}
.pkg-l{font-family:var(--mono);font-size:.95rem;font-weight:600;color:var(--cyan)}
.pkg-p{font-size:.62rem;color:var(--text3);margin-top:2px}

.fl{font-family:var(--mono);font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);display:block;margin-bottom:4px}
.fi{
  width:100%;padding:9px 11px;
  background:var(--card2);border:1px solid var(--border);
  border-radius:8px;color:var(--text);font-size:.85rem;font-family:var(--body);
  transition:border-color .2s;
}
.fi:focus{outline:none;border-color:var(--cyan);background:rgba(0,212,255,.03)}
.fi-row{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:.65rem}
@media(max-width:480px){.fi-row{grid-template-columns:1fr}}

.cost-strip{
  display:flex;justify-content:space-between;align-items:center;
  background:var(--card2);border:1px solid var(--border);
  border-radius:9px;padding:9px 13px;margin-bottom:.6rem;font-size:.8rem;
}
.cost-strip strong{font-family:var(--mono);font-size:1rem;color:var(--cyan)}
.cost-strip.green strong{color:var(--green)}

/* ══════════════════════════════════════════════════════
   BUTTONS
══════════════════════════════════════════════════════ */
.btn{
  width:100%;padding:10px;border:none;border-radius:9px;
  font-family:var(--body);font-size:.84rem;font-weight:700;
  cursor:pointer;transition:opacity .2s,transform .1s;letter-spacing:.01em;
}
.btn:active{transform:scale(.98)}
.btn:hover{opacity:.88}
.btn-cyan{background:linear-gradient(135deg,var(--cyan2),var(--cyan));color:#000}
.btn-red {background:linear-gradient(135deg,#c0392b,var(--red));color:#fff}

/* ══════════════════════════════════════════════════════
   BILLING TABLE
══════════════════════════════════════════════════════ */
.tbl{width:100%;border-collapse:collapse}
.tbl thead th{
  padding:6px 9px;text-align:left;
  font-family:var(--mono);font-size:.58rem;font-weight:600;
  text-transform:uppercase;letter-spacing:.08em;color:var(--text3);
  border-bottom:1px solid var(--border);
}
.tbl tbody td{
  padding:8px 9px;font-size:.78rem;
  border-bottom:1px solid rgba(26,46,72,.4);
}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:rgba(0,212,255,.02)}
.mono{font-family:var(--mono)}
.tc-cyan{color:var(--cyan)}
.tc-green{color:var(--green)}
.tag{display:inline-block;padding:2px 7px;border-radius:5px;font-family:var(--mono);font-size:.6rem;font-weight:600}
.tag-paid{background:rgba(0,255,135,.1);color:var(--green)}
.tag-pend{background:rgba(255,204,0,.1);color:var(--yellow)}
.tag-fail{background:rgba(255,71,87,.1);color:var(--red)}
.tag-mpesa{background:rgba(0,212,255,.08);color:var(--cyan)}
.tag-cash{background:rgba(139,165,192,.1);color:var(--slate)}

/* ══════════════════════════════════════════════════════
   ALERTS / NOTIFICATIONS
══════════════════════════════════════════════════════ */
.al{display:flex;flex-direction:column;gap:.45rem}
.ar{
  border-radius:9px;padding:.75rem .9rem;
  border-left:3px solid var(--border);
  background:var(--card2);
  display:flex;gap:9px;align-items:flex-start;
}
.ar.critical{border-left-color:var(--red);background:rgba(255,71,87,.04)}
.ar.high    {border-left-color:var(--orange);background:rgba(255,112,67,.04)}
.ar.medium,.ar.warning{border-left-color:var(--yellow);background:rgba(255,204,0,.04)}
.ar.low,.ar.info{border-left-color:var(--cyan);background:rgba(0,212,255,.04)}
.ar-bd{flex:1}
.ar-t{font-weight:600;font-size:.78rem;margin-bottom:2px}
.ar-m{font-size:.73rem;color:var(--text2);line-height:1.45}
.ar-w{font-family:var(--mono);font-size:.6rem;color:var(--text3);margin-top:4px}
.stag{padding:2px 7px;border-radius:4px;font-family:var(--mono);font-size:.58rem;font-weight:700;text-transform:uppercase}
.sc{background:rgba(255,71,87,.12);color:var(--red)}
.sh{background:rgba(255,112,67,.12);color:var(--orange)}
.sw,.sm{background:rgba(255,204,0,.12);color:var(--yellow)}
.si,.sl{background:rgba(0,212,255,.12);color:var(--cyan)}

/* Notif rows */
.nr{
  display:flex;justify-content:space-between;align-items:flex-start;gap:9px;
  background:var(--card2);border:1px solid var(--border);
  border-radius:9px;padding:.75rem .9rem;margin-bottom:.45rem;
}
.nr-t{font-weight:600;font-size:.78rem;margin-bottom:2px}
.nr-m{font-size:.72rem;color:var(--text2);line-height:1.4}
.nr-d{font-family:var(--mono);font-size:.6rem;color:var(--text3);margin-top:4px}
.mr-btn{
  background:none;border:1px solid var(--border);color:var(--text3);
  border-radius:6px;padding:3px 9px;font-size:.62rem;cursor:pointer;
  font-family:var(--mono);white-space:nowrap;transition:.15s;flex-shrink:0;
}
.mr-btn:hover{border-color:var(--cyan);color:var(--cyan)}

/* My reports */
.rrow{
  background:var(--card2);border:1px solid var(--border);border-radius:10px;
  padding:.8rem .9rem;margin-bottom:.45rem;
}
.rrow-meta{display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.35rem}
.rrow-msg{font-size:.76rem;color:var(--text2);line-height:1.45}
.rrow-resp{
  margin-top:.5rem;padding:.5rem .7rem;border-radius:8px;
  background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.15);
  font-size:.71rem;color:var(--cyan);
}
.rrow-resp::before{content:'↳ Admin: ';font-weight:700}
.rrow-date{font-family:var(--mono);font-size:.6rem;color:var(--text3);margin-top:.35rem}
.tag-open{background:rgba(255,204,0,.1);color:var(--yellow)}
.tag-prog{background:rgba(0,212,255,.1);color:var(--cyan)}
.tag-done{background:rgba(0,255,135,.1);color:var(--green)}

/* Report form */
.rf-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:.65rem}
@media(max-width:480px){.rf-grid{grid-template-columns:1fr}}
textarea.fi{resize:vertical;min-height:75px;margin-bottom:.65rem}

.empty{text-align:center;color:var(--text3);font-size:.75rem;padding:1.25rem 0;font-family:var(--mono)}
.tbl-wrap{overflow-x:auto}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nav-brand">
    <div class="nav-mark">💧</div>
    <div class="nav-title">SWDS <em>Meru</em></div>
  </div>
  <?php if (!empty($notifs)): ?>
  <a href="#notifications" class="notif-btn">
    🔔 Alerts <span class="nbadge"><?= count($notifs) ?></span>
  </a>
  <?php endif; ?>
  <div class="nav-chip">
    <div class="av"><?= strtoupper(substr($name,0,1)) ?></div>
    <?= htmlspecialchars($name) ?>
  </div>
  <a href="logout.php" class="nav-out">sign out →</a>
</nav>

<div class="page">

<?php if ($flash): ?>
<div class="flash flash-<?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     ANOMALY BANNER (shown if leak detected)
══════════════════════════════════════════════════ -->
<?php if ($anomaly_flag): ?>
<div class="anomaly-banner">
  ⚠&nbsp; <?= htmlspecialchars($anomaly_label) ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     ADMIN NOTIFICATIONS
══════════════════════════════════════════════════ -->
<?php if (!empty($notifs)): ?>
<div id="notifications" class="card" style="border-color:rgba(0,212,255,.2);margin-bottom:1rem">
  <div class="ch">
    <div class="ct">🔔 Messages from Authority</div>
    <span class="cs"><?= count($notifs) ?> unread</span>
  </div>
  <?php foreach ($notifs as $n): ?>
  <div class="nr">
    <div>
      <div class="nr-t"><?= htmlspecialchars($n['title']) ?></div>
      <div class="nr-m"><?= htmlspecialchars($n['body'] ?? $n['message'] ?? '') ?></div>
      <div class="nr-d"><?= date('d M Y, H:i',strtotime($n['created_at'])) ?></div>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="mark_read">
      <input type="hidden" name="nid" value="<?= $n['id'] ?>">
      <button class="mr-btn" type="submit">✓ Done</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     BALANCE GAUGE
══════════════════════════════════════════════════ -->
<div class="srule">Water Account</div>
<div class="gauge-hero">
  <div class="gh-top">
    <div class="gh-user">
      <div class="gu-label">Account Holder</div>
      <div class="gu-name"><?= htmlspecialchars($name) ?></div>
    </div>
    <?php
      $fc_color = $days_left===null?'var(--slate)':($days_left<3?'var(--red)':($days_left<7?'var(--yellow)':'var(--green)'));
    ?>
    <div class="forecast-chip">
      <div class="fc-label">Forecast Remaining</div>
      <div class="fc-val" style="color:<?= $fc_color ?>">
        <?= $days_left!==null ? $days_left.' days' : 'N/A' ?>
      </div>
    </div>
  </div>

  <div class="gauge-wrap">
    <?php
      $fill_len = round(($gauge_pct/100)*$ARC_LEN, 2);
    ?>
    <svg class="gauge-svg" viewBox="0 0 220 122" overflow="visible">
      <!-- Track -->
      <path d="M 20 100 A 80 80 0 0 1 200 100"
        fill="none" stroke="#1a2e48" stroke-width="13" stroke-linecap="round"/>
      <!-- Ticks -->
      <?php for ($t=0;$t<=100;$t+=25):
        $ang = M_PI - ($t/100)*M_PI;
        $x1=110+74*cos($ang); $y1=100-74*sin($ang);
        $x2=110+86*cos($ang); $y2=100-86*sin($ang);
      ?>
      <line x1="<?= round($x1,1) ?>" y1="<?= round($y1,1) ?>"
            x2="<?= round($x2,1) ?>" y2="<?= round($y2,1) ?>"
            stroke="#243352" stroke-width="1.5"/>
      <?php endfor; ?>
      <!-- Fill -->
      <path d="M 20 100 A 80 80 0 0 1 200 100"
        fill="none" stroke-linecap="round"
        stroke="<?= $gc ?>"
        stroke-width="13"
        stroke-dasharray="<?= $fill_len ?> <?= round($ARC_LEN,2) ?>"
        stroke-dashoffset="0"
        style="filter:drop-shadow(0 0 8px <?= $gc ?>88);transition:stroke-dasharray 1.4s ease"
      />
    </svg>
    <div class="gauge-legend">
      <span>0</span><span>25%</span><span>50%</span><span>75%</span><span>MAX</span>
    </div>
    <div class="gauge-readout">
      <div class="gr-main" style="color:<?= $gc ?>"><?= fmt_num($remaining) ?><span class="gr-unit">L</span></div>
      <div class="gr-money">≈ <em>KES</em> <?= fmt_num($remaining*$RATE,2) ?> current value</div>
    </div>
  </div>

  <div class="gh-stats">
    <div class="ghs">
      <div class="gl">Total Quota</div>
      <div class="gv" style="color:var(--cyan)"><?= fmt_num($balance_raw) ?> L</div>
    </div>
    <div class="ghs">
      <div class="gl">Consumed All-Time</div>
      <div class="gv" style="color:var(--text2)"><?= fmt_num($consumed_all) ?> L</div>
    </div>
    <div class="ghs">
      <div class="gl">Total Purchased</div>
      <div class="gv" style="color:var(--green)"><?= fmt_num($total_purchased) ?> L</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     LIVE STATUS
══════════════════════════════════════════════════ -->
<div class="srule">Live System Status</div>
<div class="row row-3">
  <!-- Valve -->
  <div class="card">
    <div class="ch"><div class="ct">🔌 Valve</div></div>
    <div class="valve-pill <?= $valve_on?'vp-open':'vp-close' ?>">
      <span class="vdot <?= $valve_on?'vdot-g':'vdot-r' ?>"></span>
      <?= $valve_on?'OPEN':'CLOSED' ?>
    </div>
    <div class="live-sub"><?= htmlspecialchars($zone_row['zone_name']??'—') ?></div>
  </div>
  <!-- Flow -->
  <div class="card">
    <div class="ch"><div class="ct">💧 Flow Rate</div></div>
    <div class="live-val" style="color:var(--cyan)"><?= $online?fmt_num($cur_flow,1):'—' ?><span style="font-size:.7rem;color:var(--text3)"> L/min</span></div>
    <div class="live-sub">Pressure: <?= $online?fmt_num($cur_press,2).' Bar':'—' ?></div>
  </div>
  <!-- System State -->
  <div class="card">
    <div class="ch"><div class="ct">📡 System</div></div>
    <div class="sys-pip">
      <span class="sp-dot" style="background:<?= $sys[1] ?>;box-shadow:0 0 6px <?= $sys[1] ?>88"></span>
      <span class="live-val" style="color:<?= $sys[1] ?>;font-size:1rem"><?= $sys[0] ?></span>
    </div>
    <div class="live-sub">Last ping: <?= $ping_display ?></div>
  </div>
</div>

<!-- Sensor Reliability -->
<div class="card" style="margin-bottom:1rem">
  <div class="ch">
    <div class="ct">📊 Sensor Reliability Score</div>
    <span class="cs" style="font-family:var(--mono)"><?= $readings_24h ?> / <?= $expected_24h ?> pings in 24h</span>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem">
    <span style="font-family:var(--mono);font-size:1.4rem;font-weight:600;color:<?= $sensor_color ?>"><?= $sensor_score ?>%</span>
    <span class="tag" style="background:<?= $sensor_color ?>18;color:<?= $sensor_color ?>;border:1px solid <?= $sensor_color ?>33;font-family:var(--mono);font-size:.65rem"><?= $sensor_label ?></span>
  </div>
  <div class="rel-row">
    <div class="rel-track">
      <div class="rel-fill" style="width:<?= $sensor_score ?>%;background:<?= $sensor_color ?>"></div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     USAGE & ANALYTICS
══════════════════════════════════════════════════ -->
<div class="srule">Usage & Analytics</div>
<div class="row row-4">
  <div class="mini-card">
    <div class="mc-l">Today</div>
    <div class="mc-v" style="color:var(--cyan)"><?= fmt_num($today_litres,1) ?> <span style="font-size:.65rem;color:var(--text3)">L</span></div>
    <div class="mc-s">KES <?= fmt_num($today_cost,2) ?></div>
  </div>
  <div class="mini-card">
    <div class="mc-l">Avg Daily</div>
    <div class="mc-v" style="color:var(--text)"><?= fmt_num($avg_daily,1) ?> <span style="font-size:.65rem;color:var(--text3)">L</span></div>
    <div class="mc-s">KES <?= fmt_num($avg_daily*$RATE,2) ?>/day</div>
  </div>
  <div class="mini-card">
    <div class="mc-l">Peak Hour</div>
    <div class="mc-v" style="color:var(--yellow);font-size:.85rem"><?= $peak_hour ?></div>
    <div class="mc-s">Highest usage</div>
  </div>
  <div class="mini-card">
    <div class="mc-l">Predicted Avg</div>
    <div class="mc-v" style="color:var(--green)"><?= fmt_num($pred_avg,1) ?> <span style="font-size:.65rem;color:var(--text3)">L</span></div>
    <div class="mc-s">Next 7 days</div>
  </div>
</div>

<!-- 14-day trend + 7-day prediction Chart.js -->
<div class="card" style="margin-bottom:1rem">
  <div class="ch">
    <div class="ct">📈 14-Day Usage + 7-Day Prediction</div>
    <span class="cs">Actual usage vs linear regression forecast · hover for values</span>
  </div>
  <div style="padding:.75rem 1rem 1rem">
    <canvas id="trend-chart" height="110"></canvas>
  </div>
  <div class="chart-legend" style="padding:0 1rem .75rem;display:flex;gap:1.2rem">
    <div class="cl-item"><div class="cl-dot" style="background:#00d4ff"></div>
      <span style="font-size:.72rem;color:var(--text2)">Actual (L/day)</span>
    </div>
    <div class="cl-item"><div class="cl-dot" style="background:#00ff87;opacity:.7"></div>
      <span style="font-size:.72rem;color:var(--text2)">Predicted (L/day)</span>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     EFFICIENCY + ANOMALY DETECTION
══════════════════════════════════════════════════ -->
<div class="row row-2">
  <!-- Efficiency -->
  <div class="card">
    <div class="ch">
      <div class="ct">⚡ Consumption Efficiency</div>
      <span class="cs">used ÷ purchased</span>
    </div>
    <?php
      $ec = $efficiency>105?'var(--red)':($efficiency>95?'var(--yellow)':'var(--green)');
    ?>
    <div class="eff-big" style="color:<?= $ec ?>"><?= fmt_num($efficiency,1) ?><span style="font-size:.8rem;color:var(--text3)">%</span></div>
    <div class="eff-bar-track">
      <div class="eff-bar-fill" style="width:<?= min(100,$efficiency) ?>%;background:<?= $ec ?>"></div>
    </div>
    <div><span class="eff-badge <?= $eff['class'] ?>"><?= $eff['icon'] ?> <?= $eff['label'] ?></span></div>
    <div style="font-size:.67rem;color:var(--text3);margin-top:.5rem;line-height:1.4"><?= $eff['detail'] ?></div>
    <div style="font-family:var(--mono);font-size:.62rem;color:var(--text3);margin-top:.4rem">
      <?= fmt_num($consumed_all) ?> L used &nbsp;/&nbsp; <?= fmt_num($total_purchased) ?> L purchased
    </div>
  </div>

  <!-- Anomaly detection -->
  <div class="card">
    <div class="ch">
      <div class="ct">🔍 Anomaly Detection</div>
      <span class="cs">IQR method · 48h window</span>
    </div>
    <?php if ($anomaly_flag): ?>
      <div style="display:flex;align-items:center;gap:7px;margin-bottom:.6rem">
        <span style="font-size:1.4rem">🚨</span>
        <span style="font-family:var(--mono);font-size:.78rem;font-weight:600;color:var(--red)">Anomaly Detected</span>
      </div>
      <div style="font-size:.73rem;color:var(--text2);line-height:1.5">
        Current flow <strong style="color:var(--red)"><?= fmt_num($cur_flow,1) ?> L/min</strong>
        exceeds IQR threshold of <strong><?= round($iqr_upper,1) ?> L/min</strong>.
        This may indicate a leak or valve malfunction.
      </div>
    <?php elseif (count($flow_readings) >= 4): ?>
      <div style="display:flex;align-items:center;gap:7px;margin-bottom:.6rem">
        <span style="font-size:1.4rem">✅</span>
        <span style="font-family:var(--mono);font-size:.78rem;font-weight:600;color:var(--green)">No Anomaly</span>
      </div>
      <div style="font-size:.73rem;color:var(--text2);line-height:1.5">
        Flow is within normal IQR bounds.
        Current: <strong style="color:var(--cyan)"><?= fmt_num($cur_flow,1) ?> L/min</strong>
        · Upper limit: <strong><?= round($iqr_upper??0,1) ?> L/min</strong>
      </div>
    <?php else: ?>
      <div style="font-size:.73rem;color:var(--text3);line-height:1.5;margin-top:.3rem">
        Not enough sensor data yet (need ≥4 readings in last 48h).
        Currently <?= count($flow_readings) ?> reading<?= count($flow_readings)!=1?'s':'' ?> available.
      </div>
    <?php endif; ?>
    <div style="font-family:var(--mono);font-size:.6rem;color:var(--text3);margin-top:.7rem">
      Based on <?= count($flow_readings) ?> readings over last 48 hours
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     BUY WATER
══════════════════════════════════════════════════ -->
<div class="srule">Purchase Water</div>
<div class="card">
  <div class="ch">
    <div class="ct">🛒 Buy Water</div>
    <span class="cs" style="color:var(--cyan)">KES <?= $RATE ?>/L</span>
  </div>

  <div class="pkg-grid">
    <?php foreach ([500,1000,2000,5000] as $p): ?>
    <button class="pkg" onclick="setPkg(<?= $p ?>)" type="button">
      <div class="pkg-l"><?= fmt_num($p) ?></div>
      <div style="font-size:.57rem;color:var(--text3);margin-top:1px">Litres</div>
      <div class="pkg-p">KES <?= fmt_num($p*$RATE,0) ?></div>
    </button>
    <?php endforeach; ?>
  </div>

  <form method="POST" id="buy-form">
    <input type="hidden" name="action" value="buy_water">
    <div class="fi-row">
      <div>
        <label class="fl">Litres to buy</label>
        <input type="number" name="litres" id="li" class="fi" min="100" step="100"
               placeholder="min 100 L" oninput="calc()" required>
      </div>
      <div>
        <label class="fl">Payment Method</label>
        <select name="method" id="pmeth" class="fi" onchange="toggleRef()">
          <option>M-Pesa</option>
          <option>Cash</option>
          <option>Bank</option>
          <option>Airtel Money</option>
        </select>
      </div>
    </div>
    <div id="mpesa-row" style="margin-bottom:.65rem">
      <label class="fl">M-Pesa / Ref Code (optional)</label>
      <input type="text" name="mpesa_ref" class="fi" placeholder="e.g. PGH7X2K1Q" maxlength="30">
    </div>
    <div class="cost-strip">
      <span>Cost:</span>
      <strong id="c-out">KES 0.00</strong>
    </div>
    <div class="cost-strip green" style="margin-bottom:.65rem">
      <span>Balance after:</span>
      <strong id="b-out">—</strong>
    </div>
    <button class="btn btn-cyan" type="submit">💧 Confirm Purchase</button>
  </form>
</div>

<!-- ══════════════════════════════════════════════════
     BILLING / TRANSACTION HISTORY
══════════════════════════════════════════════════ -->
<div class="srule">Billing History</div>
<div class="card">
  <div class="ch">
    <div class="ct">🧾 Invoices & Payments</div>
    <span class="cs"><?= count($bill_rows) ?> records</span>
  </div>
  <?php if (empty($bill_rows)): ?>
    <p class="empty">No billing records yet.</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Invoice</th>
          <th>Litres</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bill_rows as $b): ?>
      <tr>
        <td class="mono" style="color:var(--text3);font-size:.72rem"><?= date('d M Y',strtotime($b['paid_at']??$b['created_at'])) ?></td>
        <td class="mono" style="font-size:.68rem;color:var(--text3)"><?= $b['invoice_no']?htmlspecialchars($b['invoice_no']):'—' ?></td>
        <td class="mono tc-cyan">+<?= fmt_num((float)$b['litres'],0) ?> L</td>
        <td class="mono tc-green">KES <?= fmt_num((float)$b['amount_kes'],2) ?></td>
        <td><span class="tag <?= ($b['payment_method']??'') ==='Cash'?'tag-cash':'tag-mpesa' ?>"><?= htmlspecialchars($b['payment_method']??'—') ?></span></td>
        <td>
          <?php $st = $b['status']??'paid'; ?>
          <span class="tag tag-<?= $st ?: 'paid' ?>"><?= strtoupper($st) ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>



<!-- ══════════════════════════════════════════════════
     REPORT A PROBLEM
══════════════════════════════════════════════════ -->
<div class="srule">Report an Issue</div>
<div class="card">
  <div class="ch">
    <div class="ct" style="color:var(--red)">🚨 Report a Problem</div>
    <span class="cs">Stored in DB · Admin notified</span>
  </div>
  <?php
  // Load zones for dropdown
  $rpt_zones = [];
  try { $rpt_zones = $pdo->query("SELECT zone_name FROM water_zones ORDER BY zone_name")->fetchAll(); } catch(Exception $e) {}
  ?>
  <form method="POST">
    <input type="hidden" name="action" value="send_report">
    <div class="rf-grid">
      <div>
        <label class="fl">Issue Type</label>
        <select name="issue_type" class="fi">
          <option>No water supply</option>
          <option>Valve stuck / not opening</option>
          <option>Pipe burst or leak</option>
          <option>Low water pressure</option>
          <option>Dirty / discoloured water</option>
          <option>Meter malfunction</option>
          <option>Billing issue</option>
          <option>Other</option>
        </select>
      </div>
      <div>
        <label class="fl">Urgency</label>
        <select name="severity" class="fi">
          <option value="critical">🔴 Urgent</option>
          <option value="warning" selected>🟡 Moderate</option>
          <option value="info">🔵 Low priority</option>
        </select>
      </div>
    </div>

    <label class="fl">Your Zone / Area *</label>
    <select name="zone_name" class="fi" required>
      <option value="">— Select your zone —</option>
      <?php foreach ($rpt_zones as $z): ?>
      <option value="<?= htmlspecialchars($z['zone_name']) ?>"><?= htmlspecialchars($z['zone_name']) ?></option>
      <?php endforeach; ?>
      <option value="Other / Not listed">Other / Not listed</option>
    </select>

    <label class="fl">Describe the problem *</label>
    <textarea name="message" class="fi" rows="3"
      placeholder="E.g. No water since 6am, valve appears stuck..." required></textarea>

    <!-- GPS location capture with map preview -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <label class="fl" style="margin:0">📍 Your Location (optional)</label>
      <button type="button" onclick="captureGPS()" id="gps-btn"
        style="font-size:.72rem;color:var(--blue);background:none;border:none;cursor:pointer;
               text-decoration:underline;padding:0">📡 Use my GPS</button>
    </div>
    <input type="hidden" id="gps_lat" name="gps_lat">
    <input type="hidden" id="gps_lng" name="gps_lng">
    <div id="gps-status" style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem;min-height:16px"></div>
    <!-- Map preview — shown after GPS captured -->
    <div id="gps-map-wrap" style="display:none;margin-bottom:.85rem;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
        <iframe id="gps-map" width="100%" height="180" frameborder="0" style="display:block"></iframe>
        <div style="padding:6px 10px;background:rgba(14,165,233,.06);font-size:.7rem;color:var(--muted);
                    display:flex;justify-content:space-between;align-items:center">
            <span id="gps-coords-display"></span>
            <a id="gps-gmaps-link" href="#" target="_blank"
               style="color:var(--blue);text-decoration:none;font-size:.7rem">Open in Google Maps ↗</a>
        </div>
    </div>

    <button class="btn btn-red" type="submit">📤 Send Report to Admin</button>
  </form>
</div>

<script>
function captureGPS() {
    const btn    = document.getElementById('gps-btn');
    const status = document.getElementById('gps-status');
    if (!navigator.geolocation) {
        status.textContent = 'GPS not available on this device.';
        status.style.color = 'var(--red)'; return;
    }
    btn.textContent = '📡 Getting location…';
    btn.disabled = true;
    status.textContent = 'Requesting GPS…';
    status.style.color = 'var(--muted)';
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            const lat = pos.coords.latitude.toFixed(7);
            const lng = pos.coords.longitude.toFixed(7);
            const acc = Math.round(pos.coords.accuracy);
            document.getElementById('gps_lat').value = lat;
            document.getElementById('gps_lng').value = lng;

            // Show status
            status.textContent = '✅ Location captured — accuracy: ±' + acc + 'm';
            status.style.color = 'var(--green)';
            btn.textContent    = '✓ Location set';
            btn.disabled = false;

            // Show map preview using OpenStreetMap (no API key needed)
            const mapWrap = document.getElementById('gps-map-wrap');
            const mapFrame= document.getElementById('gps-map');
            const coordsEl= document.getElementById('gps-coords-display');
            const gmapLink = document.getElementById('gps-gmaps-link');

            mapFrame.src = `https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(lng)-.003},${parseFloat(lat)-.003},${parseFloat(lng)+.003},${parseFloat(lat)+.003}&layer=mapnik&marker=${lat},${lng}`;
            coordsEl.textContent = `📍 ${parseFloat(lat).toFixed(5)}, ${parseFloat(lng).toFixed(5)}`;
            gmapLink.href = `https://maps.google.com/?q=${lat},${lng}`;
            mapWrap.style.display = 'block';
        },
        function(err) {
            const msgs = {1:'Permission denied — please allow location access.',2:'Position unavailable.',3:'Timed out.'};
            status.textContent = '⚠️ ' + (msgs[err.code] || 'Could not get location.');
            status.style.color = '#fbbf24';
            btn.textContent = '📡 Try again';
            btn.disabled = false;
        },
        {enableHighAccuracy: true, timeout: 10000, maximumAge: 0}
    );
}
</script>

<!-- My reports with admin responses -->
<?php if (!empty($my_reports)): ?>
<div class="card" style="margin-bottom:1rem">
  <div class="ch">
    <div class="ct">📋 My Submitted Reports</div>
  </div>
  <?php foreach ($my_reports as $r):
    $sev=$r['severity']??'warning';
    $sta=$r['status']??'open';
  ?>
  <div class="rrow">
    <div class="rrow-meta">
      <?php if (!empty($r['issue_type'])): ?>
        <span class="tag" style="background:rgba(0,212,255,.08);color:var(--cyan);font-family:var(--mono);font-size:.6rem"><?= htmlspecialchars($r['issue_type']) ?></span>
      <?php endif; ?>
      <span class="stag s<?= substr($sev,0,1) ?>"><?= strtoupper($sev) ?></span>
      <span class="tag tag-<?= $sta==='open'?'open':($sta==='in_progress'?'prog':'done') ?>">
        <?= $sta==='in_progress'?'IN PROGRESS':strtoupper($sta) ?>
      </span>
    </div>
    <div class="rrow-msg"><?= htmlspecialchars($r['message']) ?></div>
    <?php if (!empty($r['admin_response'])): ?>
      <div class="rrow-resp"><?= htmlspecialchars($r['admin_response']) ?></div>
    <?php endif; ?>
    <div class="rrow-date"><?= date('d M Y \a\t H:i',strtotime($r['created_at'])) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /.page -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ── Constants ────────────────────────────────────────
const RATE = <?= $RATE ?>;
const REM  = <?= $remaining ?>;

// ── Buy Water Calculator ──────────────────────────────
function calc() {
  const l = parseFloat(document.getElementById('li').value) || 0;
  document.getElementById('c-out').textContent = 'KES ' + (l*RATE).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
  const nb = REM + l;
  document.getElementById('b-out').textContent = fmt(nb) + ' L  (KES ' + (nb*RATE).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ')';
}
function fmt(n){ return Math.round(n).toLocaleString(); }

function setPkg(l) {
  document.getElementById('li').value = l;
  calc();
  document.querySelectorAll('.pkg').forEach(b=>b.classList.remove('sel'));
  event.currentTarget.classList.add('sel');
}

function toggleRef() {
  const m = document.getElementById('pmeth').value;
  const r = document.getElementById('mpesa-row');
  r.style.display = ['M-Pesa','Airtel Money'].includes(m) ? '' : 'none';
}
toggleRef();


// ── Bar Chart (14-day actual + 7-day predicted) ───────
(function(){
  // ── Chart.js trend chart ─────────────────────────────────
  const actual = <?= json_encode(array_values($chart_values)) ?>;
  const preds  = <?= json_encode(array_values($pred_values))  ?>;
  const labels = <?= json_encode(array_values($chart_labels)) ?>;

  // Combined labels: actual dates + prediction dates
  const predLabels = [];
  for (let i = 1; i <= preds.length; i++) {
    const d = new Date(); d.setDate(d.getDate() + i);
    predLabels.push(d.toLocaleDateString('en-GB',{day:'2-digit',month:'short'}));
  }
  const allLabels = [...labels, ...predLabels];

  // Actual dataset: nulls for prediction days
  const actualFull = [...actual, ...Array(preds.length).fill(null)];
  // Predicted dataset: nulls for actual days, then predictions
  const predFull   = [...Array(actual.length).fill(null), ...preds];

  const ctx = document.getElementById('trend-chart');
  if (!ctx) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: allLabels,
      datasets: [{
        label: 'Actual Usage (L/day)',
        data: actualFull,
        backgroundColor: 'rgba(0,212,255,0.65)',
        borderColor: '#00d4ff',
        borderWidth: 1,
        borderRadius: 3,
        order: 2,
      },{
        label: 'Predicted (L/day)',
        data: predFull,
        backgroundColor: 'rgba(0,255,135,0.35)',
        borderColor: 'rgba(0,255,135,0.7)',
        borderWidth: 1,
        borderRadius: 3,
        borderDash: [4,3],
        order: 2,
      },{
        label: 'Trend line',
        data: [...actual.map((v,i)=> {
          // simple moving average for trend line
          const slice = actual.slice(Math.max(0,i-2), i+3);
          return slice.reduce((a,b)=>a+b,0)/slice.length;
        }), ...Array(preds.length).fill(null)],
        type: 'line',
        borderColor: 'rgba(0,212,255,0.4)',
        borderWidth: 1.5,
        borderDash: [3,3],
        pointRadius: 0,
        fill: false,
        tension: 0.4,
        order: 1,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: { 
            color: '#64748b', 
            boxWidth: 10, 
            padding: 12,
            font: { size: 10 }
          }
        },
        tooltip: {
          backgroundColor: '#0a1932',
          borderColor: 'rgba(0,212,255,.3)',
          borderWidth: 1,
          titleColor: '#e2e8f0',
          bodyColor: '#94a3b8',
          padding: 10,
          callbacks: {
            label: ctx => {
              const v = ctx.parsed.y;
              if (v === null || v === undefined) return null;
              return ` ${ctx.dataset.label}: ${v.toLocaleString()} L`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { color: 'rgba(30,58,95,.35)', drawTicks: false },
          ticks: { 
            color: '#475569', 
            font: { size: 9 },
            maxRotation: 45,
            autoSkip: true,
            maxTicksLimit: 10
          }
        },
        y: {
          grid: { color: 'rgba(30,58,95,.35)' },
          ticks: { 
            color: '#475569', 
            font: { size: 9 },
            callback: v => v >= 1000 ? (v/1000).toFixed(1)+'k' : v
          },
          title: {
            display: true,
            text: 'Litres / day',
            color: '#475569',
            font: { size: 9 }
          }
        }
      }
    }
  });
})();

// Auto-refresh every 60s
setTimeout(()=>location.reload(), 60000);
</script>

</body>
</html>