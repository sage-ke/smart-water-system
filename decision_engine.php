<?php
/*
 * ============================================================
 *  decision_making.php  ·  SWDS Meru
 *  Intelligent Decision Engine — Admin Only
 * ============================================================
 *
 *  9-STEP PIPELINE (runs per zone, per execution):
 *
 *  Step 1 — Get latest sensor data from sensor_readings
 *  Step 2 — Get expected flow from predictions (WMA fallback)
 *  Step 3 — Compare actual vs predicted (deviation %)
 *  Step 4 — Detect anomaly (Z-score + hard thresholds)
 *  Step 5 — Detect leak (flow up + pressure down trend)
 *  Step 6 — Trigger alert (de-duplicated, severity graded)
 *  Step 7 — Fail-safe check (close valve if critical)
 *  Step 8 — Issue smart valve command (0/25/50/75/100%)
 *  Step 9 — Log full decision to decision_log table
 *
 *  HOW TO RUN:
 *    Manual  : visit decision_making.php?run=1
 *    Auto    : Windows Task Scheduler every 5 min hitting that URL
 * ============================================================
 */

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) { header('Location: dashboard.php'); exit; }

$user_id      = (int)$_SESSION['user_id'];
$user_name    = $_SESSION['user_name'];
$user_email   = $_SESSION['user_email'];
$user_role    = $_SESSION['user_role'];
$current_page = 'decision';
$page_title   = 'Decision Engine';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// ── Create decision_log table if missing ─────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS decision_log (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    zone_id            INT,
    zone_name          VARCHAR(100),
    run_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actual_flow        DECIMAL(8,2),
    actual_pressure    DECIMAL(8,2),
    actual_level       DECIMAL(5,2),
    actual_ph          DECIMAL(4,2),
    actual_turbidity   DECIMAL(6,2),
    predicted_flow     DECIMAL(8,2),
    flow_deviation_pct DECIMAL(6,2),
    anomaly_detected   TINYINT(1) DEFAULT 0,
    anomaly_types      VARCHAR(255),
    leak_probability   TINYINT DEFAULT 0,
    leak_indicators    TEXT,
    alert_triggered    TINYINT(1) DEFAULT 0,
    alert_type         VARCHAR(100),
    alert_severity     VARCHAR(20),
    failsafe_triggered TINYINT(1) DEFAULT 0,
    failsafe_reason    VARCHAR(255),
    valve_command_pct  TINYINT DEFAULT 100,
    command_issued     TINYINT(1) DEFAULT 0,
    command_reason     VARCHAR(255),
    engine_version     VARCHAR(20) DEFAULT 'v2.0',
    notes              TEXT,
    INDEX idx_dl_zone (zone_id, run_at),
    INDEX idx_dl_time (run_at)
)");

// ── Load thresholds from system_settings ─────────────────────
$cfg = [];
foreach ($pdo->query("SELECT setting_key,setting_val FROM system_settings") as $r) {
    $cfg[$r['setting_key']] = $r['setting_val'];
}

// Safety thresholds
$T_PRESSURE_MIN    = (float)($cfg['alert_pressure_min']    ?? 2.5);
$T_PRESSURE_CRIT   = 1.5;
$T_LEVEL_MIN       = (float)($cfg['alert_level_min']       ?? 20.0);
$T_LEVEL_CRIT      = 10.0;
$T_PH_MIN          = (float)($cfg['alert_ph_min']          ?? 6.5);
$T_PH_MAX          = (float)($cfg['alert_ph_max']          ?? 8.5);
$T_PH_CRIT_LOW     = 6.0;
$T_PH_CRIT_HIGH    = 9.0;
$T_TURB_MAX        = (float)($cfg['alert_turbidity_max']   ?? 4.0);
$T_TURB_CRIT       = 8.0;
$T_FLOW_MIN        = (float)($cfg['alert_flow_min']        ?? 10.0);
$T_ZSCORE          = 2.5;
$T_DEV_PCT         = 25.0;
$T_LEAK_CRIT       = 75;
$T_LEAK_WARN       = 45;


// ================================================================
//  INTELLIGENCE FUNCTIONS
// ================================================================

// STEP 1: Latest sensor reading
function eng_get_sensor(PDO $pdo, int $zid): ?array {
    $s = $pdo->prepare("
        SELECT sr.*, hd.device_code, hd.is_online
        FROM sensor_readings sr
        LEFT JOIN hardware_devices hd ON hd.id = sr.device_id
        WHERE sr.zone_id = ?
        ORDER BY sr.recorded_at DESC LIMIT 1
    ");
    $s->execute([$zid]);
    return $s->fetch() ?: null;
}

// STEP 2: Expected flow — DB prediction or WMA fallback
function eng_get_prediction(PDO $pdo, int $zid): array {
    $s = $pdo->prepare("SELECT predicted_flow,predicted_level,confidence_pct FROM predictions
                        WHERE zone_id=? AND predict_date=CURDATE() ORDER BY created_at DESC LIMIT 1");
    $s->execute([$zid]);
    $p = $s->fetch();
    if ($p && (float)$p['predicted_flow'] > 0) {
        return ['flow'=>(float)$p['predicted_flow'],'level'=>(float)$p['predicted_level'],
                'confidence'=>(int)$p['confidence_pct'],'source'=>'saved_prediction'];
    }
    // WMA fallback
    $h = $pdo->prepare("SELECT DATE(recorded_at) AS d, AVG(flow_rate) AS f, AVG(water_level) AS l
                        FROM sensor_readings WHERE zone_id=? AND recorded_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
                        GROUP BY d ORDER BY d DESC LIMIT 14");
    $h->execute([$zid]);
    $rows = $h->fetchAll();
    if (count($rows) < 3) return ['flow'=>0,'level'=>0,'confidence'=>0,'source'=>'no_data'];
    $flows = array_column($rows,'f'); $levels = array_column($rows,'l');
    $n = count($flows); $w = range(1,$n); $tw = array_sum($w);
    $wf = $wl = 0;
    foreach ($flows as $i => $f) { $wf += ($w[$n-1-$i]*(float)$f)/$tw; $wl += ($w[$n-1-$i]*(float)$levels[$i])/$tw; }
    $adj = (int)date('N') >= 6 ? 0.85 : 1.0;
    return ['flow'=>round($wf*$adj,2),'level'=>round($wl*$adj,2),'confidence'=>65,'source'=>'wma_fallback'];
}

// STEP 3: Compare actual vs predicted
function eng_compare(float $actual, float $predicted): array {
    if ($predicted <= 0) return ['pct'=>0,'dir'=>'unknown','significant'=>false];
    $d = (($actual - $predicted) / $predicted) * 100;
    return ['pct'=>round(abs($d),2), 'dir'=>$d>=0?'over':'under', 'significant'=>abs($d)>=25.0];
}

// STEP 4: Anomaly detection — Z-score + threshold rules
function eng_detect_anomaly(PDO $pdo, int $zid, array $r, float $dev_pct, array $T): array {
    $found = [];
    // Layer A: Z-score baseline
    $b = $pdo->prepare("SELECT AVG(flow_rate) mf,STDDEV(flow_rate) sf,
        AVG(pressure) mp,STDDEV(pressure) sp,
        AVG(water_level) ml,STDDEV(water_level) sl,
        AVG(turbidity) mt,STDDEV(turbidity) st
        FROM sensor_readings WHERE zone_id=?
        AND recorded_at BETWEEN DATE_SUB(NOW(),INTERVAL 8 DAY) AND DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $b->execute([$zid]); $b = $b->fetch();
    $zChecks = [
        'flow_rate'   => ['flow_spike',   $b['mf'],$b['sf'],$r['flow_rate'],   $T['zscore']],
        'pressure'    => ['pressure_drop',$b['mp'],$b['sp'],$r['pressure'],    $T['zscore']],
        'water_level' => ['level_drop',   $b['ml'],$b['sl'],$r['water_level'], 2.0],
        'turbidity'   => ['quality_issue',$b['mt'],$b['st'],$r['turbidity'],   $T['zscore']],
    ];
    foreach ($zChecks as [$atype,$mean,$std,$val,$thr]) {
        if (!$std || $val===null || $mean===null) continue;
        $z = abs(((float)$val-(float)$mean)/(float)$std);
        if ($z > $thr) {
            $dev = $mean>0 ? round(abs($val-$mean)/$mean*100,1) : 0;
            $found[] = ['type'=>$atype,'expected'=>round((float)$mean,2),
                        'actual'=>round((float)$val,2),'dev'=>$dev,
                        'score'=>min(1.0,round($z/5,2)),'src'=>'zscore','z'=>round($z,2)];
        }
    }
    // Layer B: Hard threshold rules
    $rules = [
        [(float)$r['pressure']   < $T['pressure_min'],  'pressure_drop', $r['pressure'],   $T['pressure_min'],  0.6],
        [(float)$r['water_level']< $T['level_min'],     'level_drop',    $r['water_level'], $T['level_min'],     0.5],
        [(float)$r['ph_level']   < $T['ph_min'],        'quality_issue', $r['ph_level'],    $T['ph_min'],        0.7],
        [(float)$r['ph_level']   > $T['ph_max'],        'quality_issue', $r['ph_level'],    $T['ph_max'],        0.7],
        [(float)$r['turbidity']  > $T['turb_max'],      'quality_issue', $r['turbidity'],   $T['turb_max'],      0.65],
        [(float)$r['flow_rate']  < $T['flow_min'] && (int)$r['pump_status']===1,
                                                        'flow_spike',    $r['flow_rate'],   $T['flow_min'],      0.55],
    ];
    foreach ($rules as [$trig,$atype,$actual,$expected,$score]) {
        if (!$trig) continue;
        if (in_array($atype, array_column($found,'type'))) continue;
        $found[] = ['type'=>$atype,'expected'=>(float)$expected,'actual'=>(float)$actual,
                    'dev'=>$expected>0?round(abs($actual-$expected)/$expected*100,1):0,
                    'score'=>$score,'src'=>'threshold','z'=>0];
    }
    // Layer C: Prediction deviation
    if ($dev_pct >= 25.0 && !in_array('flow_spike',array_column($found,'type'))) {
        $found[] = ['type'=>'flow_spike','expected'=>0,'actual'=>(float)$r['flow_rate'],
                    'dev'=>$dev_pct,'score'=>min(1.0,$dev_pct/100),'src'=>'pred_dev','z'=>0];
    }
    return $found;
}

// STEP 5: Leak detection
function eng_detect_leak(PDO $pdo, int $zid, float $T_LEAK_WARN, float $T_LEAK_CRIT): array {
    $s = $pdo->prepare("SELECT flow_rate,pressure FROM sensor_readings
                        WHERE zone_id=? AND recorded_at>=DATE_SUB(NOW(),INTERVAL 2 HOUR) ORDER BY recorded_at ASC");
    $s->execute([$zid]); $rows = $s->fetchAll();
    if (count($rows) < 4) return ['prob'=>0,'status'=>'insufficient_data','indicators'=>[],'flow'=>0,'pressure'=>0];
    $n = count($rows); $half = (int)($n/2);
    $early = array_slice($rows,0,$half); $late = array_slice($rows,$half);
    $ef = array_sum(array_column($early,'flow_rate'))/$half;
    $lf = array_sum(array_column($late,'flow_rate'))/($n-$half);
    $ep = array_sum(array_column($early,'pressure'))/$half;
    $lp = array_sum(array_column($late,'pressure'))/($n-$half);
    $score = 0; $ind = [];
    if ($lf > $ef*1.10 && $lp < $ep*0.93) {
        $score += 50;
        $ind[] = sprintf('Flow rose %.1f%% (%.1f→%.1f L/min) while pressure fell %.1f%% (%.2f→%.2f Bar)',
            ($lf/$ef-1)*100,$ef,$lf,(1-$lp/$ep)*100,$ep,$lp);
    }
    $avg7 = $pdo->prepare("SELECT AVG(flow_rate) FROM sensor_readings WHERE zone_id=? AND recorded_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $avg7->execute([$zid]); $hist = (float)$avg7->fetchColumn();
    if ($hist > 0 && $lf > $hist*1.25) {
        $score += 25;
        $ind[] = sprintf('Flow %.1f L/min is %.0f%% above 7-day average (%.1f)',
            $lf,($lf/$hist-1)*100,$hist);
    }
    $pressures = array_column($rows,'pressure'); $drops = 0;
    for ($i=1;$i<count($pressures);$i++) if ((float)$pressures[$i]<(float)$pressures[$i-1]) $drops++;
    if (count($pressures)>1 && $drops/(count($pressures)-1) > 0.65) {
        $score += 20;
        $ind[] = sprintf('Pressure dropping in %.0f%% of consecutive readings',$drops/(count($pressures)-1)*100);
    }
    $prob = min(100,$score);
    return ['prob'=>$prob,'status'=>$prob>=$T_LEAK_CRIT?'likely_leak':($prob>=$T_LEAK_WARN?'possible_leak':'normal'),
            'indicators'=>$ind,'flow'=>round($lf,2),'pressure'=>round($lp,2)];
}

// STEP 6: Trigger alert (no duplicates)
function eng_alert(PDO $pdo, int $zid, ?int $did, string $type, string $msg, string $sev): bool {
    $c = $pdo->prepare("SELECT id FROM alerts WHERE zone_id=? AND alert_type=? AND is_resolved=0 LIMIT 1");
    $c->execute([$zid,$type]);
    if ($c->fetch()) return false;
    $pdo->prepare("INSERT INTO alerts (zone_id,device_id,alert_type,message,severity) VALUES (?,?,?,?,?)")
        ->execute([$zid,$did,$type,$msg,$sev]);
    return true;
}

// STEP 7: Fail-safe evaluation
function eng_failsafe(array $r, array $leak, array $T): array {
    $reasons = [];
    if ($leak['prob'] >= $T['leak_crit'])     $reasons[] = "Leak {$leak['prob']}% >= {$T['leak_crit']}%";
    if ((float)$r['pressure']   < $T['p_crit']) $reasons[] = "Pressure {$r['pressure']} Bar < {$T['p_crit']} Bar";
    if ((float)$r['water_level']< $T['l_crit']) $reasons[] = "Level {$r['water_level']}% < {$T['l_crit']}%";
    if ((float)$r['ph_level']   < $T['ph_crit_low'])  $reasons[] = "pH {$r['ph_level']} < {$T['ph_crit_low']}";
    if ((float)$r['ph_level']   > $T['ph_crit_high']) $reasons[] = "pH {$r['ph_level']} > {$T['ph_crit_high']}";
    if ((float)$r['turbidity']  > $T['turb_crit'])    $reasons[] = "Turbidity {$r['turbidity']} NTU > {$T['turb_crit']}";
    return ['triggered'=>!empty($reasons),'reasons'=>$reasons];
}

// STEP 8: Decide valve %
function eng_valve_decision(array $r, array $leak, array $fs, array $T): array {
    $cur = (int)($r['valve_open_pct'] ?? 100);
    if ($fs['triggered'])                          { $tgt=0;   $why='FAIL-SAFE: '.implode('; ',$fs['reasons']); }
    elseif ($leak['prob'] >= $T['leak_crit'])      { $tgt=0;   $why="Leak {$leak['prob']}% — closing valve"; }
    elseif ($leak['prob'] >= $T['leak_warn'])      { $tgt=25;  $why="Possible leak {$leak['prob']}% — throttle 25%"; }
    elseif ((float)$r['water_level'] < $T['l_min']){ $tgt=50;  $why="Low level {$r['water_level']}% — conserve 50%"; }
    elseif ((float)$r['pressure']    < $T['p_min'])  { $tgt=75;  $why="Low pressure {$r['pressure']} Bar — reduce 75%"; }
    else                                           { $tgt=100; $why='All parameters normal — full open'; }
    return [$tgt, $why, abs($tgt-$cur)>5];
}

// STEP 9: Log decision
function eng_log(PDO $pdo, array $d): void {
    $pdo->prepare("INSERT INTO decision_log
        (zone_id,zone_name,actual_flow,actual_pressure,actual_level,actual_ph,actual_turbidity,
         predicted_flow,flow_deviation_pct,anomaly_detected,anomaly_types,
         leak_probability,leak_indicators,alert_triggered,alert_type,alert_severity,
         failsafe_triggered,failsafe_reason,valve_command_pct,command_issued,command_reason,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $d['zone_id'],$d['zone_name'],
        $d['flow'],$d['pressure'],$d['level'],$d['ph'],$d['turb'],
        $d['pred_flow'],$d['dev_pct'],
        $d['anom_count']>0?1:0, implode(', ',$d['anom_types']),
        $d['leak_prob'], implode(' | ',$d['leak_inds']),
        $d['alert']?1:0, $d['alert_type'], $d['alert_sev'],
        $d['failsafe']?1:0, implode('; ',$d['fs_reasons']),
        $d['valve_pct'], $d['cmd']?1:0, $d['valve_why'],
        $d['notes'],
    ]);
}


// ================================================================
//  MAIN ENGINE RUNNER
// ================================================================
$run_results = []; $engine_ran = false; $engine_errors = [];

// Threshold array for passing to functions
$T = [
    'zscore'=>$T_ZSCORE,'pressure_min'=>$T_PRESSURE_MIN,'level_min'=>$T_LEVEL_MIN,
    'ph_min'=>$T_PH_MIN,'ph_max'=>$T_PH_MAX,'turb_max'=>$T_TURB_MAX,
    'flow_min'=>$T_FLOW_MIN,
    'p_crit'=>$T_PRESSURE_CRIT,'l_crit'=>$T_LEVEL_CRIT,
    'ph_crit_low'=>$T_PH_CRIT_LOW,'ph_crit_high'=>$T_PH_CRIT_HIGH,
    'turb_crit'=>$T_TURB_CRIT,'leak_crit'=>$T_LEAK_CRIT,'leak_warn'=>$T_LEAK_WARN,
    'p_min'=>$T_PRESSURE_MIN,'l_min'=>$T_LEVEL_MIN,
];

if (isset($_GET['run'])) {
    $engine_ran = true;

    // Get active zones joined to their valve controller device
    $zones = $pdo->query("
        SELECT wz.*, hd.id AS valve_device_id, hd.device_code, hd.is_online
        FROM water_zones wz
        LEFT JOIN hardware_devices hd ON hd.device_type='master_node' AND hd.id=(SELECT id FROM hardware_devices WHERE device_type='master_node' LIMIT 1)
        WHERE wz.status != 'maintenance'
        ORDER BY wz.id
    ")->fetchAll();

    foreach ($zones as $zone) {
        $zid   = (int)$zone['id'];
        $zname = $zone['zone_name'];
        $steps = [];

        $d = ['zone_id'=>$zid,'zone_name'=>$zname,
              'flow'=>0,'pressure'=>0,'level'=>0,'ph'=>0,'turb'=>0,
              'pred_flow'=>0,'dev_pct'=>0,
              'anom_count'=>0,'anom_types'=>[],
              'leak_prob'=>0,'leak_inds'=>[],
              'alert'=>false,'alert_type'=>'','alert_sev'=>'',
              'failsafe'=>false,'fs_reasons'=>[],
              'valve_pct'=>100,'cmd'=>false,'valve_why'=>'','notes'=>''];

        try {
            // ── Step 1 ──────────────────────────────────────
            $sensor = eng_get_sensor($pdo, $zid);
            if (!$sensor) {
                $d['notes'] = 'No sensor data';
                $steps[] = [1,'Get sensor data','SKIP','No readings in database for this zone'];
                eng_log($pdo,$d);
                $run_results[$zid] = ['zone'=>$zname,'steps'=>$steps,'status'=>'skipped'];
                continue;
            }
            $d['flow']=$sensor['flow_rate']; $d['pressure']=$sensor['pressure'];
            $d['level']=$sensor['water_level']; $d['ph']=$sensor['ph_level']; $d['turb']=$sensor['turbidity'];
            $steps[] = [1,'Get sensor data','OK',
                "Flow: {$sensor['flow_rate']} L/min | Pressure: {$sensor['pressure']} Bar | Level: {$sensor['water_level']}% | pH: {$sensor['ph_level']} | Turbidity: {$sensor['turbidity']} NTU"];

            // ── Step 2 ──────────────────────────────────────
            $pred = eng_get_prediction($pdo, $zid);
            $d['pred_flow'] = $pred['flow'];
            $steps[] = [2,'Get expected flow','OK',
                "Predicted: {$pred['flow']} L/min | Source: {$pred['source']} | Confidence: {$pred['confidence']}%"];

            // ── Step 3 ──────────────────────────────────────
            $cmp = eng_compare((float)$sensor['flow_rate'], $pred['flow']);
            $d['dev_pct'] = $cmp['pct'];
            $steps[] = [3,'Compare actual vs predicted', $cmp['significant']?'SIGNIFICANT':'NORMAL',
                "Actual: {$sensor['flow_rate']} | Expected: {$pred['flow']} | Deviation: {$cmp['pct']}% {$cmp['dir']}"];

            // ── Step 4 ──────────────────────────────────────
            $anoms = eng_detect_anomaly($pdo,$zid,$sensor,$cmp['pct'],$T);
            $d['anom_count'] = count($anoms);
            $d['anom_types'] = array_unique(array_column($anoms,'type'));
            // Save to anomalies table
            foreach ($anoms as $a) {
                try { $pdo->prepare("INSERT INTO anomalies (zone_id,device_id,reading_id,anomaly_type,expected_value,actual_value,deviation_pct,severity_score) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$zid,$sensor['device_id']??null,$sensor['id'],$a['type'],$a['expected'],$a['actual'],$a['dev'],$a['score']]); }
                catch(\PDOException $e) {}
            }
            $anom_detail = count($anoms)>0
                ? implode(', ',array_map(fn($a)=>strtoupper(str_replace('_',' ',$a['type']))." (dev={$a['dev']}%,src={$a['src']})", $anoms))
                : 'No anomalies detected';
            $steps[] = [4,'Detect anomaly', count($anoms)>0?'DETECTED ('.count($anoms).')':'NONE', $anom_detail];

            // ── Step 5 ──────────────────────────────────────
            $leak = eng_detect_leak($pdo,$zid,$T_LEAK_WARN,$T_LEAK_CRIT);
            $d['leak_prob'] = $leak['prob'];
            $d['leak_inds'] = $leak['indicators'];
            $leak_status = $leak['prob']>=$T_LEAK_CRIT?'LIKELY LEAK':($leak['prob']>=$T_LEAK_WARN?'POSSIBLE LEAK':'NO LEAK');
            $steps[] = [5,'Detect leak',$leak_status,
                "Probability: {$leak['prob']}% ({$leak['status']})" .
                (!empty($leak['indicators'])?' | '.implode(' | ',$leak['indicators']):'')];

            // ── Step 6 ──────────────────────────────────────
            $did = $sensor['device_id'] ?? null;
            $alert_fired = false; $alert_type = ''; $alert_sev = '';
            if ($leak['prob'] >= $T_LEAK_CRIT) {
                $alert_fired = eng_alert($pdo,$zid,$did,'Suspected Leak',
                    "Leak probability {$leak['prob']}%. ".implode('. ',$leak['indicators']),'critical');
                $alert_type='Suspected Leak'; $alert_sev='critical';
            } elseif (count($anoms)>0) {
                $worst = array_reduce($anoms,fn($c,$a)=>($a['score']>($c['score']??0))?$a:$c,null);
                $sev = $worst['score']>=0.7?'critical':($worst['score']>=0.5?'high':'medium');
                $alert_fired = eng_alert($pdo,$zid,$did,ucfirst(str_replace('_',' ',$worst['type'])),
                    "Anomaly: {$worst['type']}. Actual={$worst['actual']}, Expected={$worst['expected']}, Dev={$worst['dev']}%.",$sev);
                $alert_type = ucfirst(str_replace('_',' ',$worst['type'])); $alert_sev=$sev;
            } elseif ($leak['prob'] >= $T_LEAK_WARN) {
                $alert_fired = eng_alert($pdo,$zid,$did,'Possible Leak',"Possible leak {$leak['prob']}% probability.",'high');
                $alert_type='Possible Leak'; $alert_sev='high';
            }
            $d['alert']=$alert_fired; $d['alert_type']=$alert_type; $d['alert_sev']=$alert_sev;
            $steps[] = [6,'Trigger alert',$alert_fired?"ALERT: {$alert_type}":'NO ALERT',
                $alert_fired?"Created {$alert_sev} alert: {$alert_type}":'Conditions normal — no alert raised'];

            // ── Step 7 ──────────────────────────────────────
            $fs = eng_failsafe($sensor,$leak,$T);
            $d['failsafe']=$fs['triggered']; $d['fs_reasons']=$fs['reasons'];
            if ($fs['triggered']) {
                $pdo->prepare("UPDATE water_zones SET status='maintenance' WHERE id=?")->execute([$zid]);
                eng_alert($pdo,$zid,$did,'FAIL-SAFE Activated',
                    'Fail-safe triggered: '.implode('. ',$fs['reasons']),'critical');
            }
            $steps[] = [7,'Fail-safe check',$fs['triggered']?'FAIL-SAFE TRIGGERED':'NOT TRIGGERED',
                $fs['triggered']?'ACTIVATED: '.implode(' | ',$fs['reasons']):'No critical thresholds breached'];

            // ── Step 8 ──────────────────────────────────────
            $vdev = $zone['valve_device_id'] ?? null;
            [$vpct,$vwhy,$should_cmd] = eng_valve_decision($sensor,$leak,$fs,$T);
            $d['valve_pct']=$vpct; $d['valve_why']=$vwhy;
            if ($should_cmd && $vdev) {
                $master = $pdo->query("SELECT id FROM hardware_devices WHERE device_type='master_node' LIMIT 1")->fetch();
                $master_id = $master ? $master['id'] : 1;
                $pdo->prepare("INSERT INTO device_commands (device_id,command_type,payload,issued_by,status) VALUES (?,?,?,'pending')")
                    ->execute([$master_id, 'set_valve', json_encode(['valve_pct'=>$vpct,'zone_id'=>$zid])]);
                $d['cmd'] = true;
                $vstatus = $vpct===0?'CLOSED':'OPEN';
                $pdo->prepare("UPDATE water_zones SET valve_status=? WHERE id=?")->execute([$vstatus,$zid]);
            }
            $steps[] = [8,'Issue valve command',$d['cmd']?"ISSUED (valve={$vpct}%)":'NO CHANGE',
                "{$vwhy}".($d['cmd']?' | Command queued for device':' | Valve already at correct position')];

            // ── Step 9 ──────────────────────────────────────
            eng_log($pdo,$d);
            $steps[] = [9,'Log everything','LOGGED',"Decision record written for zone: {$zname}"];

            $overall = $fs['triggered']?'failsafe':($alert_fired?'alert':(count($anoms)>0?'anomaly':'normal'));
            $run_results[$zid] = ['zone'=>$zname,'steps'=>$steps,'status'=>$overall,'d'=>$d];

        } catch(\Throwable $e) {
            $engine_errors[] = "{$zname}: ".$e->getMessage();
            $steps[] = [0,'Engine error','ERROR',$e->getMessage()];
            $run_results[$zid] = ['zone'=>$zname,'steps'=>$steps,'status'=>'error'];
        }
    }
}

// Load history and stats
$log_history = $pdo->query("SELECT dl.* FROM decision_log dl ORDER BY dl.run_at DESC LIMIT 120")->fetchAll();
$log_stats   = $pdo->query("SELECT COUNT(*) total,SUM(anomaly_detected) anoms,SUM(alert_triggered) alerts_f,
    SUM(failsafe_triggered) failsafes,SUM(command_issued) cmds,MAX(run_at) last_run FROM decision_log")->fetch();

require_once __DIR__ . '/sidebar.php';

?>
<style>
.eng-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem}
.eng-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.9rem 1.1rem}
.eng-lbl{font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.eng-val{font-size:1.5rem;font-weight:800}
.zone-block{background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:1rem;overflow:hidden}
.zone-hdr{padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;cursor:pointer;flex-wrap:wrap;gap:.5rem}
.zone-hdr:hover{background:rgba(255,255,255,.02)}
.zone-body{padding:0 1.25rem 1.25rem;display:none}
.zone-body.open{display:block}
.st{width:100%;border-collapse:collapse;font-size:.79rem;margin-top:.75rem}
.st th{padding:7px 10px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;background:rgba(255,255,255,.02)}
.st td{padding:7px 10px;border-top:1px solid rgba(30,58,95,.4);vertical-align:top;line-height:1.5}
.badge{padding:2px 8px;border-radius:5px;font-size:.65rem;font-weight:700}
.b-ok  {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}
.b-warn{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.b-err {background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-crit{background:rgba(248,113,113,.25);color:#f87171;border:1px solid rgba(248,113,113,.5)}
.b-skip{background:rgba(122,155,186,.1);color:#7a9bba;border:1px solid rgba(122,155,186,.2)}
.run-btn{padding:10px 24px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;border-radius:10px;color:#fff;font-size:.95rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.sec{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin:1.5rem 0 .85rem;display:flex;align-items:center;gap:8px}
.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.lt{width:100%;border-collapse:collapse;font-size:.75rem}
.lt th{padding:6px 9px;text-align:left;color:var(--muted);font-size:.62rem;text-transform:uppercase;background:rgba(255,255,255,.02);white-space:nowrap}
.lt td{padding:6px 9px;border-top:1px solid rgba(30,58,95,.4)}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">⚙️ Decision Engine</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:3px">9-step AI pipeline · Anomaly · Leak · Fail-safe · Valve control · Full logging</p>
  </div>
  <a href="?run=1" class="run-btn">▶ Run Engine Now</a>
</div>

<!-- Stats -->
<div class="eng-grid">
<?php $sc=[['Total Runs',$log_stats['total']??0,'#0ea5e9'],['Anomalies',$log_stats['anoms']??0,'#fbbf24'],
            ['Alerts Fired',$log_stats['alerts_f']??0,'#f87171'],['Fail-safes',$log_stats['failsafes']??0,'#f87171'],
            ['Valve Cmds',$log_stats['cmds']??0,'#06b6d4'],
            ['Last Run',$log_stats['last_run']?date('d M H:i',strtotime($log_stats['last_run'])):'Never','#7a9bba']];
foreach($sc as[$l,$v,$c]):?>
<div class="eng-card"><div class="eng-lbl"><?=$l?></div>
  <div class="eng-val" style="color:<?=$c?>;font-size:<?=is_numeric($v)?'1.5':'1'?>rem"><?=$v?></div></div>
<?php endforeach;?>
</div>

<?php if(!empty($engine_errors)):?>
<div style="padding:12px 16px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">
  Engine errors: <?=htmlspecialchars(implode(' | ',$engine_errors))?>
</div>
<?php endif;?>

<!-- Run results -->
<?php if($engine_ran && !empty($run_results)):?>
<div class="sec">🔁 Run completed — <?=date('d M Y H:i:s')?></div>
<?php
$sb=['normal'=>['✅ NORMAL','b-ok'],'anomaly'=>['⚠️ ANOMALY','b-warn'],
     'alert' =>['🚨 ALERT', 'b-err'],'failsafe'=>['🔴 FAIL-SAFE','b-crit'],
     'error' =>['❌ ERROR',  'b-err'],'skipped'=>['⏭ SKIPPED',  'b-skip']];
foreach($run_results as $zid=>$res):
[$bt,$bc]=$sb[$res['status']]??['?','b-skip'];?>
<div class="zone-block" id="z<?=$zid?>">
  <div class="zone-hdr" onclick="tog('z<?=$zid?>')">
    <span style="font-family:'Syne',sans-serif;font-weight:700"><?=htmlspecialchars($res['zone'])?></span>
    <div style="display:flex;align-items:center;gap:.75rem">
      <span class="badge <?=$bc?>"><?=$bt?></span>
      <span style="color:var(--muted);font-size:.75rem">▾</span>
    </div>
  </div>
  <div class="zone-body" id="z<?=$zid?>-b">
    <table class="st">
      <thead><tr><th style="width:36px">Step</th><th style="width:170px">Action</th><th style="width:170px">Result</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach($res['steps'] as[$n,$action,$status,$detail]):
        $sc2=str_contains($status,'OK')||str_contains($status,'NONE')||str_contains($status,'NORMAL')||str_contains($status,'NO LEAK')||str_contains($status,'NOT TRIGGERED')||str_contains($status,'LOGGED')?'var(--green)'
           :(str_contains($status,'FAIL-SAFE')||str_contains($status,'ERROR')?'var(--red)'
           :(str_contains($status,'SIGNIFICANT')||str_contains($status,'DETECTED')||str_contains($status,'POSSIBLE')||str_contains($status,'ALERT')||str_contains($status,'ISSUED')?'var(--yellow)'
           :'var(--muted)'));?>
      <tr>
        <td style="color:var(--muted);font-weight:700;text-align:center;font-size:.75rem"><?=$n?></td>
        <td style="font-weight:600;font-size:.8rem"><?=htmlspecialchars($action)?></td>
        <td style="color:<?=$sc2?>;font-weight:700;font-size:.77rem"><?=htmlspecialchars($status)?></td>
        <td style="color:var(--muted);font-size:.76rem"><?=htmlspecialchars($detail)?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach;?>
<?php elseif(!$engine_ran):?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:3rem;text-align:center;margin-bottom:1.5rem">
  <div style="font-size:2.5rem;margin-bottom:1rem">⚙️</div>
  <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.5rem">Engine Ready</div>
  <p style="color:var(--muted);font-size:.88rem;margin-bottom:1.5rem;max-width:420px;margin-left:auto;margin-right:auto">
    Click Run Engine Now to process all active zones through the 9-step decision pipeline.
    Every run is logged to the table below.
  </p>
  <a href="?run=1" class="run-btn">▶ Run Engine Now</a>
</div>
<?php endif;?>

<!-- How it works -->
<div class="sec">📖 Pipeline Reference</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.25rem;margin-bottom:1.5rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.85rem;font-size:.8rem">
  <?php $how=[
    [1,'Get Sensor Data','Reads latest sensor_readings row per zone. If zone has no data it is skipped.','#0ea5e9'],
    [2,'Get Expected Flow','Checks predictions table for today. Falls back to Weighted Moving Average (14-day window, recency-weighted, weekend -15% adjustment) if none saved.','#06b6d4'],
    [3,'Compare Actual vs Predicted','Deviation % = |actual−predicted|/predicted×100. Over 25% = significant, feeds into anomaly Layer C.','#34d399'],
    [4,'Detect Anomaly','Layer A: Z-score (>2.5σ from 7-day baseline) for flow, pressure, level, turbidity. Layer B: Hard limits — pressure<2.5Bar, level<20%, pH 6.5–8.5, turbidity<4NTU. Layer C: prediction deviation >25%.','#fbbf24'],
    [5,'Detect Leak','Compares first vs second half of last 2 hours. Signals: (1) flow↑+pressure↓, (2) flow 25% above 7-day avg, (3) pressure dropping in 65%+ of consecutive readings. Score 0–100%.','#fb923c'],
    [6,'Trigger Alert','Inserts into alerts table only if no identical unresolved alert exists. Severity graded: critical(score≥0.7), high(≥0.5), medium otherwise.','#f87171'],
    [7,'Fail-Safe Check','Triggers if: leak≥75%, pressure<1.5Bar, level<10%, pH<6.0 or >9.0, turbidity>8NTU. Action: close valve, set zone to maintenance.','#f87171'],
    [8,'Issue Valve Command','Target: normal=100%, low level=50%, possible leak=25%, fail-safe/high leak=0%. Only writes to device_commands if target differs from current by >5%.','#a78bfa'],
    [9,'Log Everything','Writes full snapshot to decision_log: all sensor values, prediction, deviation, anomaly types, leak score, alert details, fail-safe status, valve command.','#7a9bba'],
  ];
  foreach($how as[$n,$title,$desc,$col]):?>
    <div style="padding:.8rem;background:rgba(255,255,255,.025);border-radius:10px;border-left:3px solid <?=$col?>">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
        <span style="background:<?=$col?>22;color:<?=$col?>;border:1px solid <?=$col?>44;padding:1px 7px;border-radius:4px;font-size:.63rem;font-weight:700">STEP <?=$n?></span>
        <span style="font-weight:700;color:var(--text);font-size:.8rem"><?=$title?></span>
      </div>
      <p style="color:var(--muted);line-height:1.55;margin:0;font-size:.76rem"><?=$desc?></p>
    </div>
  <?php endforeach;?>
  </div>
</div>

<!-- Log history -->
<div class="sec">🗂️ Decision Log (last 120 runs)</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem">
<div style="overflow-x:auto"><table class="lt">
  <thead><tr>
    <th>Run At</th><th>Zone</th><th>Flow</th><th>Press</th><th>Level</th>
    <th>Pred</th><th>Dev%</th><th>Anomaly</th><th>Leak%</th>
    <th>Alert</th><th>Fail-safe</th><th>Valve</th><th>Cmd</th>
  </tr></thead>
  <tbody>
  <?php foreach($log_history as $lr):
    $lk=(int)$lr['leak_probability'];
    $lc=$lk>=$T_LEAK_CRIT?'var(--red)':($lk>=$T_LEAK_WARN?'var(--yellow)':'var(--muted)');?>
  <tr>
    <td style="color:var(--muted);white-space:nowrap"><?=date('d M H:i',strtotime($lr['run_at']))?></td>
    <td style="font-weight:600;white-space:nowrap"><?=htmlspecialchars($lr['zone_name']??'—')?></td>
    <td><?=$lr['actual_flow']?><span style="color:var(--muted);font-size:.62rem"> L/m</span></td>
    <td><?=$lr['actual_pressure']?><span style="color:var(--muted);font-size:.62rem"> Bar</span></td>
    <td><?=$lr['actual_level']?><span style="color:var(--muted);font-size:.62rem">%</span></td>
    <td style="color:var(--blue)"><?=$lr['predicted_flow']?></td>
    <td style="color:<?=(float)$lr['flow_deviation_pct']>=25?'var(--yellow)':'var(--muted)'?>"><?=$lr['flow_deviation_pct']?>%</td>
    <td style="color:<?=$lr['anomaly_detected']?'var(--yellow)':'var(--muted)'?>"><?=$lr['anomaly_detected']?'⚠️ '.htmlspecialchars($lr['anomaly_types']):'—'?></td>
    <td style="color:<?=$lc?>;font-weight:<?=$lk>=$T_LEAK_WARN?700:400?>"><?=$lk?>%</td>
    <td style="color:<?=$lr['alert_triggered']?'var(--red)':'var(--muted)'?>;font-size:.72rem"><?=$lr['alert_triggered']?htmlspecialchars($lr['alert_type']):'—'?></td>
    <td style="color:<?=$lr['failsafe_triggered']?'var(--red)':'var(--muted)'?>"><?=$lr['failsafe_triggered']?'🔴 YES':'—'?></td>
    <td style="color:var(--blue);font-weight:700"><?=$lr['valve_command_pct']?>%</td>
    <td style="color:<?=$lr['command_issued']?'var(--green)':'var(--muted)'?>"><?=$lr['command_issued']?'✓':'-'?></td>
  </tr>
  <?php endforeach;?>
  <?php if(empty($log_history)):?>
  <tr><td colspan="13" style="text-align:center;padding:2.5rem;color:var(--muted)">No runs yet — click Run Engine Now above.</td></tr>
  <?php endif;?>
  </tbody>
</table></div>
</div>

<script>
function tog(id){const b=document.getElementById(id+'-b');if(b)b.classList.toggle('open');}
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.zone-block').forEach(el=>{
    const badge=el.querySelector('.badge');
    if(badge&&!badge.classList.contains('b-ok')&&!badge.classList.contains('b-skip'))
      document.getElementById(el.id+'-b')?.classList.add('open');
  });
});
</script>
</main></body></html>