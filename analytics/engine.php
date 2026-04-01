<?php

// ── IQR helper — top-level so it's never re-declared on refresh ──────────
if (!function_exists('iqr_bounds')) {
    function iqr_bounds(array $values): array {
        sort($values);
        $n = count($values);
        if ($n < 4) return ['low'=>-INF,'high'=>INF];
        $q1 = $values[(int)($n*0.25)];
        $q3 = $values[(int)($n*0.75)];
        $iqr = $q3 - $q1;
        return ['low'=>$q1-1.5*$iqr, 'high'=>$q3+1.5*$iqr];
    }
}

/*
 * analytics/engine.php — SWDS Meru Intelligence Engine v3
 * ============================================================
 * FUNCTIONS IN THIS FILE:
 *
 *  1. wma_forecast()       — Weighted Moving Average prediction
 *  2. linear_regression()  — Least-squares trend line (real math)
 *  3. detect_anomalies()   — Z-score + IQR + threshold rules
 *  4. detect_leak()        — Pressure-flow divergence algorithm
 *  5. calculate_wqi()      — WHO Water Quality Index
 *  6. historical_trends()  — 48h hourly data for charts
 *  7. save_predictions()   — Write forecast to predictions table
 *  8. save_anomalies()     — Write anomalies to anomalies table
 *  9. get_zone_statistics()— Statistical summary per zone
 * ============================================================
 */

require_once __DIR__ . '/../db.php';

// ============================================================
//  1. WEIGHTED MOVING AVERAGE — 14-day, recency-weighted
//     Returns 7-day forecast array
// ============================================================
function forecast_demand(int $zone_id, int $days_ahead = 7): array {
    global $pdo;
    $rows = $pdo->prepare("
        SELECT DATE(recorded_at) AS day,
               AVG(flow_rate) AS avg_flow, AVG(water_level) AS avg_level,
               AVG(pressure)  AS avg_pres, AVG(ph_level)    AS avg_ph,
               AVG(turbidity) AS avg_turb
        FROM sensor_readings WHERE zone_id=?
          AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY day ORDER BY day DESC LIMIT 14
    ");
    $rows->execute([$zone_id]); $rows = $rows->fetchAll();
    if (count($rows) < 3) return [];

    $n = count($rows); $weights = range(1,$n); $tw = array_sum($weights);
    $wf=$wl=$wp=$wph=$wt=0;
    foreach ($rows as $i=>$r) {
        $wi = $weights[$n-1-$i] / $tw;
        $wf   += $wi*(float)$r['avg_flow'];
        $wl   += $wi*(float)$r['avg_level'];
        $wp   += $wi*(float)$r['avg_pres'];
        $wph  += $wi*(float)$r['avg_ph'];
        $wt   += $wi*(float)$r['avg_turb'];
    }

    // Linear trend component: are values trending up or down?
    $flows = array_reverse(array_column($rows,'avg_flow'));
    $trend_slope = 0;
    if ($n >= 5) {
        $lr = least_squares($flows);
        $trend_slope = $lr['slope'];
    }

    $preds = [];
    for ($d=1; $d<=$days_ahead; $d++) {
        $date = date('Y-m-d', strtotime("+$d days"));
        $dow  = (int)date('N', strtotime($date));
        $adj  = $dow >= 6 ? 0.85 : 1.0;
        $trend_adj = $trend_slope * $d * 0.1; // gentle trend extrapolation
        $conf = max(40, 90 - ($d*6));
        $preds[] = [
            'date'       => $date,
            'flow'       => round(max(0, ($wf + $trend_adj) * $adj), 2),
            'level'      => round(min(100, $wl * $adj), 2),
            'pressure'   => round($wp * $adj, 2),
            'demand'     => round(max(0, ($wf + $trend_adj) * $adj * 1440), 0),
            'confidence' => $conf,
            'trend_slope'=> round($trend_slope, 4),
            'source'     => 'WMA+LR',
        ];
    }
    return $preds;
}

// ============================================================
//  2. LINEAR REGRESSION — least squares on an array of values
//     Returns: [slope, intercept, r_squared, trend_direction]
// ============================================================
function least_squares(array $y): array {
    $n = count($y);
    if ($n < 2) return ['slope'=>0,'intercept'=>0,'r_squared'=>0,'direction'=>'flat'];
    $x = range(0, $n-1);
    $sx = array_sum($x); $sy = array_sum($y);
    $sxy = $sxx = $syy = 0;
    foreach ($x as $i=>$xi) {
        $yi = (float)$y[$i];
        $sxy += $xi * $yi;
        $sxx += $xi * $xi;
        $syy += $yi * $yi;
    }
    $denom = ($n * $sxx - $sx * $sx);
    if ($denom == 0) return ['slope'=>0,'intercept'=>array_sum($y)/$n,'r_squared'=>0,'direction'=>'flat'];

    $slope     = ($n * $sxy - $sx * $sy) / $denom;
    $intercept = ($sy - $slope * $sx) / $n;

    // R² calculation
    $y_mean = $sy / $n; $ss_tot = $ss_res = 0;
    foreach ($y as $i=>$yi) {
        $yi=(float)$yi;
        $predicted = $slope * $i + $intercept;
        $ss_res += ($yi - $predicted) ** 2;
        $ss_tot += ($yi - $y_mean) ** 2;
    }
    $r2 = $ss_tot > 0 ? 1 - ($ss_res / $ss_tot) : 0;

    return [
        'slope'      => round($slope, 4),
        'intercept'  => round($intercept, 4),
        'r_squared'  => round(max(0, $r2), 4),
        'direction'  => $slope > 0.05 ? 'increasing' : ($slope < -0.05 ? 'decreasing' : 'flat'),
        'y_values'   => array_map(fn($i) => round($slope*$i+$intercept, 2), range(0,$n-1)),
    ];
}

// ============================================================
//  3. ANOMALY DETECTION — Three layers:
//     A) Z-score (>2.5σ from 7-day baseline)
//     B) IQR fence (< Q1-1.5×IQR or > Q3+1.5×IQR)
//     C) Hard threshold rules
// ============================================================
function detect_anomalies(int $zone_id, int $lookback_hours = 24): array {
    global $pdo;

    // Latest reading
    $latest = $pdo->prepare("
        SELECT * FROM sensor_readings
        WHERE zone_id=? ORDER BY recorded_at DESC LIMIT 1
    ");
    $latest->execute([$zone_id]);
    $r = $latest->fetch();
    if (!$r) return [];

    // 7-day baseline (excluding last 2 hours to avoid self-contamination)
    $baseline = $pdo->prepare("
        SELECT
            AVG(flow_rate)  mf, STDDEV(flow_rate)  sf,
            AVG(pressure)   mp, STDDEV(pressure)   sp,
            AVG(water_level)ml, STDDEV(water_level)sl,
            AVG(ph_level)   mph,STDDEV(ph_level)   sph,
            AVG(turbidity)  mt, STDDEV(turbidity)  st
        FROM sensor_readings WHERE zone_id=?
          AND recorded_at BETWEEN DATE_SUB(NOW(),INTERVAL 8 DAY)
                              AND DATE_SUB(NOW(),INTERVAL 2 HOUR)
    ");
    $baseline->execute([$zone_id]);
    $b = $baseline->fetch();

    // IQR data (last 7 days hourly)
    $iqr_data = $pdo->prepare("
        SELECT flow_rate, pressure, water_level, ph_level, turbidity
        FROM sensor_readings WHERE zone_id=?
          AND recorded_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
        ORDER BY recorded_at DESC LIMIT 500
    ");
    $iqr_data->execute([$zone_id]);
    $iqr_rows = $iqr_data->fetchAll();

    // iqr_bounds defined at top-level to prevent redeclaration on refresh

    // Get thresholds from settings
    $cfg = [];
    foreach ($pdo->query("SELECT setting_key,setting_val FROM system_settings") as $c)
        $cfg[$c['setting_key']] = $c['setting_val'];

    $anomalies = [];

    $checks = [
        ['flow_rate',   'flow_spike',    $r['flow_rate'],    $b['mf'],$b['sf'], array_column($iqr_rows,'flow_rate'),   2.5, (float)($cfg['alert_flow_min']??10)],
        ['pressure',    'pressure_drop', $r['pressure'],     $b['mp'],$b['sp'], array_column($iqr_rows,'pressure'),    2.5, (float)($cfg['alert_pressure_min']??2.5)],
        ['water_level', 'level_drop',    $r['water_level'],  $b['ml'],$b['sl'], array_column($iqr_rows,'water_level'), 2.0, (float)($cfg['alert_level_min']??20)],
        ['turbidity',   'quality_issue', $r['turbidity'],    $b['mt'],$b['st'], array_column($iqr_rows,'turbidity'),   2.5, (float)($cfg['alert_turbidity_max']??4)],
        ['ph_level',    'quality_issue', $r['ph_level'],     $b['mph'],$b['sph'],array_column($iqr_rows,'ph_level'), 2.5, null],
    ];

    foreach ($checks as [$field,$atype,$val,$mean,$std,$iqr_vals,$z_thresh,$hard_thresh]) {
        $val = (float)$val; $mean=(float)$mean; $std=(float)$std;
        $layer_triggered = false; $sources = [];

        // Layer A: Z-score
        if ($std > 0) {
            $z = abs(($val - $mean) / $std);
            if ($z > $z_thresh) { $layer_triggered=true; $sources[]="Z={$z}σ"; }
        }

        // Layer B: IQR fence
        $bounds = iqr_bounds(array_map('floatval',$iqr_vals));
        if ($val < $bounds['low'] || $val > $bounds['high']) {
            $layer_triggered=true; $sources[]='IQR fence';
        }

        // Layer C: Hard threshold
        if ($hard_thresh !== null) {
            if ($field === 'turbidity' && $val > $hard_thresh) { $layer_triggered=true; $sources[]='Threshold'; }
            if ($field === 'pressure'  && $val < $hard_thresh) { $layer_triggered=true; $sources[]='Threshold'; }
            if ($field === 'water_level'&&$val< $hard_thresh) { $layer_triggered=true; $sources[]='Threshold'; }
            if ($field === 'flow_rate' && $val < $hard_thresh && (int)$r['pump_status']===1) { $layer_triggered=true; $sources[]='Threshold'; }
        }
        if ($field === 'ph_level') {
            if ($val < (float)($cfg['alert_ph_min']??6.5) || $val > (float)($cfg['alert_ph_max']??8.5))
                { $layer_triggered=true; $sources[]='pH range'; }
        }

        if ($layer_triggered) {
            $dev = $mean > 0 ? round(abs($val-$mean)/$mean*100,1) : 0;
            $existing = array_column($anomalies,'anomaly_type');
            if (!in_array($atype, $existing)) {
                $anomalies[] = [
                    'anomaly_type'   => $atype,
                    'expected_value' => round($mean, 2),
                    'actual_value'   => round($val, 2),
                    'deviation_pct'  => $dev,
                    'severity_score' => min(1.0, round($dev/100 + count($sources)*0.1, 2)),
                    'detection_sources' => implode(', ',$sources),
                    'field'          => $field,
                ];
            }
        }
    }

    return $anomalies;
}

// ============================================================
//  4. LEAK DETECTION
//     Pressure-flow divergence: flow up + pressure down = leak
// ============================================================
function detect_leak_probability(int $zone_id): array {
    global $pdo;

    $rows = $pdo->prepare("
        SELECT flow_rate, pressure, water_level, recorded_at
        FROM sensor_readings
        WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(),INTERVAL 3 HOUR)
        ORDER BY recorded_at ASC
    ");
    $rows->execute([$zone_id]);
    $rows = $rows->fetchAll();

    if (count($rows) < 4) return ['probability'=>0,'status'=>'insufficient_data','indicators'=>[],'score_breakdown'=>[]];

    $n = count($rows); $half = (int)($n/2);
    $early = array_slice($rows,0,$half);
    $late  = array_slice($rows,$half);

    $ef = array_sum(array_column($early,'flow_rate'))/$half;
    $lf = array_sum(array_column($late,'flow_rate'))/($n-$half);
    $ep = array_sum(array_column($early,'pressure'))/$half;
    $lp = array_sum(array_column($late,'pressure'))/($n-$half);

    $score = 0; $indicators = []; $breakdown = [];

    // Signal 1: flow up AND pressure down simultaneously (strongest signal)
    if ($lf > $ef*1.10 && $lp < $ep*0.93) {
        $s1=50; $score+=$s1;
        $indicators[] = sprintf('Flow +%.1f%% while pressure -%.1f%%',($lf/$ef-1)*100,(1-$lp/$ep)*100);
        $breakdown['divergence'] = $s1;
    }

    // Signal 2: flow significantly above 7-day avg
    $avg7 = $pdo->prepare("SELECT AVG(flow_rate) FROM sensor_readings WHERE zone_id=? AND recorded_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $avg7->execute([$zone_id]); $hist=(float)$avg7->fetchColumn();
    if ($hist>0 && $lf>$hist*1.20) {
        $s2=25; $score+=$s2;
        $indicators[] = sprintf('Flow %.1f L/min = %.0f%% above 7-day avg %.1f',$lf,($lf/$hist-1)*100,$hist);
        $breakdown['above_average'] = $s2;
    }

    // Signal 3: sustained pressure decline (>60% of intervals drop)
    $pressures = array_map(fn($r)=>(float)$r['pressure'],$rows);
    $drops = 0;
    for ($i=1;$i<count($pressures);$i++) if ($pressures[$i]<$pressures[$i-1]) $drops++;
    if (count($pressures)>1) {
        $drop_rate = $drops/(count($pressures)-1);
        if ($drop_rate>0.60) {
            $s3=(int)($drop_rate*20); $score+=$s3;
            $indicators[] = sprintf('Pressure declining in %.0f%% of consecutive readings',$drop_rate*100);
            $breakdown['pressure_decline'] = $s3;
        }
    }

    // Signal 4: night-time high flow (leaks often worse at night)
    $hour = (int)date('H');
    if (($hour>=22||$hour<=5) && $lf>$ef*1.05) {
        $s4=10; $score+=$s4;
        $indicators[] = 'High flow detected during low-demand night hours';
        $breakdown['night_flow'] = $s4;
    }

    $prob = min(100,$score);
    return [
        'probability'    => $prob,
        'status'         => $prob>=75?'likely_leak':($prob>=45?'possible_leak':'normal'),
        'indicators'     => $indicators,
        'score_breakdown'=> $breakdown,
        'avg_flow_early' => round($ef,2),
        'avg_flow_late'  => round($lf,2),
        'avg_pres_early' => round($ep,2),
        'avg_pres_late'  => round($lp,2),
    ];
}

// ============================================================
//  5. WATER QUALITY INDEX (WHO standard)
//     Returns score 0-100 (100=perfect, <50=poor)
// ============================================================
function calculate_wqi(float $ph, float $turbidity, float $tds, float $temp): array {
    $score = 100;
    $flags = [];

    // pH: ideal 7.0-7.5, acceptable 6.5-8.5
    if ($ph<6.5||$ph>8.5)      { $score-=30; $flags[]="pH {$ph} out of safe range (6.5-8.5)"; }
    elseif($ph<7.0||$ph>8.0)   { $score-=10; $flags[]="pH {$ph} slightly off ideal (7.0-8.0)"; }

    // Turbidity: WHO limit 4 NTU, ideal <1 NTU
    if ($turbidity>8)           { $score-=35; $flags[]="Turbidity {$turbidity} NTU severely high (>8)"; }
    elseif ($turbidity>4)       { $score-=20; $flags[]="Turbidity {$turbidity} NTU above WHO limit (4 NTU)"; }
    elseif ($turbidity>1)       { $score-=5;  $flags[]="Turbidity {$turbidity} NTU above ideal (1 NTU)"; }

    // TDS: WHO guideline 300 mg/L, limit 600 mg/L
    if ($tds>600)               { $score-=25; $flags[]="TDS {$tds} mg/L above WHO limit (600)"; }
    elseif ($tds>300)           { $score-=10; $flags[]="TDS {$tds} mg/L above ideal (300 mg/L)"; }

    // Temperature: ideal 10-25°C
    if ($temp>30||$temp<5)      { $score-=15; $flags[]="Temperature {$temp}°C outside safe range"; }
    elseif ($temp>25||$temp<10) { $score-=5;  $flags[]="Temperature {$temp}°C outside ideal range"; }

    $score = max(0, $score);
    $grade = $score>=80?'Excellent':($score>=60?'Good':($score>=40?'Fair':($score>=20?'Poor':'Critical')));
    $color = $score>=80?'#34d399':($score>=60?'#0ea5e9':($score>=40?'#fbbf24':($score>=20?'#fb923c':'#f87171')));

    return ['score'=>$score,'grade'=>$grade,'color'=>$color,'flags'=>$flags];
}

// ============================================================
//  6. HISTORICAL TRENDS — 48h hourly chart data
//     Returns array of hourly averages for Chart.js
// ============================================================
function historical_trends(int $zone_id, int $hours = 48): array {
    global $pdo;

    $rows = $pdo->prepare("
        SELECT
            DATE_FORMAT(recorded_at,'%Y-%m-%d %H:00:00') AS hour_bucket,
            AVG(flow_rate)   AS flow,
            AVG(pressure)    AS pressure,
            AVG(water_level) AS level,
            AVG(ph_level)    AS ph,
            AVG(turbidity)   AS turbidity,
            AVG(tds_ppm)     AS tds,
            COUNT(*)         AS readings
        FROM sensor_readings
        WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY hour_bucket ORDER BY hour_bucket ASC
    ");
    $rows->execute([$zone_id, $hours]);
    $raw = $rows->fetchAll();

    $labels=$flow=$pressure=$level=$ph=$turbidity=$tds=[];
    foreach ($raw as $r) {
        $labels[]   = date('d/m H:i', strtotime($r['hour_bucket']));
        $flow[]     = round((float)$r['flow'],2);
        $pressure[] = round((float)$r['pressure'],2);
        $level[]    = round((float)$r['level'],2);
        $ph[]       = round((float)$r['ph'],2);
        $turbidity[]= round((float)$r['turbidity'],2);
        $tds[]      = round((float)$r['tds'],2);
    }

    // Regression trend lines for each metric
    return [
        'labels'       => $labels,
        'flow'         => $flow,
        'pressure'     => $pressure,
        'level'        => $level,
        'ph'           => $ph,
        'turbidity'    => $turbidity,
        'tds'          => $tds,
        'flow_trend'   => count($flow)>1    ? array_values(least_squares($flow)['y_values'])    : [],
        'pressure_trend'=>count($pressure)>1? array_values(least_squares($pressure)['y_values']): [],
        'level_trend'  => count($level)>1   ? array_values(least_squares($level)['y_values'])   : [],
        'hours'        => $hours,
        'data_points'  => count($raw),
    ];
}

// ============================================================
//  7. SAVE PREDICTIONS — Write WMA forecast to DB
// ============================================================
function save_predictions(int $zone_id): bool {
    global $pdo;
    $forecasts = forecast_demand($zone_id);
    if (empty($forecasts)) return false;
    foreach ($forecasts as $f) {
        $pdo->prepare("INSERT INTO predictions
            (zone_id,predict_date,predicted_flow,predicted_level,predicted_demand,confidence_pct)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                predicted_flow=VALUES(predicted_flow),
                predicted_level=VALUES(predicted_level),
                predicted_demand=VALUES(predicted_demand),
                confidence_pct=VALUES(confidence_pct),
                created_at=NOW()")
        ->execute([$zone_id,$f['date'],$f['flow'],$f['level'],$f['demand'],$f['confidence']]);
    }
    return true;
}

// ============================================================
//  8. SAVE ANOMALIES — Write detected anomalies to DB
// ============================================================
function save_anomalies(int $zone_id): int {
    global $pdo;
    $anomalies = detect_anomalies($zone_id);
    $saved = 0;
    // Get latest reading id
    $rid = $pdo->prepare("SELECT id FROM sensor_readings WHERE zone_id=? ORDER BY recorded_at DESC LIMIT 1");
    $rid->execute([$zone_id]);
    $reading_id = $rid->fetchColumn() ?: null;

    foreach ($anomalies as $a) {
        try {
            $pdo->prepare("INSERT INTO anomalies
                (zone_id,reading_id,anomaly_type,expected_value,actual_value,deviation_pct,severity_score)
                VALUES (?,?,?,?,?,?,?)")
            ->execute([$zone_id,$reading_id,$a['anomaly_type'],$a['expected_value'],
                       $a['actual_value'],$a['deviation_pct'],$a['severity_score']]);
            $saved++;
        } catch (\PDOException $e) {}
    }
    return $saved;
}

// ============================================================
//  9. ZONE STATISTICS — Statistical summary
// ============================================================
function get_zone_statistics(int $zone_id, int $days = 7): array {
    global $pdo;
    $s = $pdo->prepare("
        SELECT
            COUNT(*)           AS readings,
            ROUND(AVG(flow_rate),2)    AS avg_flow,
            ROUND(MAX(flow_rate),2)    AS max_flow,
            ROUND(MIN(flow_rate),2)    AS min_flow,
            ROUND(STDDEV(flow_rate),2) AS std_flow,
            ROUND(AVG(pressure),2)     AS avg_pressure,
            ROUND(AVG(water_level),2)  AS avg_level,
            ROUND(AVG(ph_level),2)     AS avg_ph,
            ROUND(AVG(turbidity),2)    AS avg_turbidity,
            ROUND(AVG(tds_ppm),2)      AS avg_tds,
            MAX(recorded_at)           AS last_reading
        FROM sensor_readings
        WHERE zone_id=? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $s->execute([$zone_id, $days]);
    return $s->fetch() ?: [];
}