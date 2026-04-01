<?php
/*
 * ============================================================
 *  kobo_importer.php  ·  SWDS Meru
 *  Complaints + Field Reports Hub — Admin / Operator
 * ============================================================
 *
 *  THREE WAYS REPORTS REACH THIS PAGE:
 *
 *  1. RESIDENT COMPLAINTS — complaints.php?view=public
 *     Residents submit a form (name, phone, zone, issue type,
 *     description, optional GPS). Stored in complaints table.
 *     This page shows all complaints, lets staff manage them.
 *
 *  2. CSV UPLOAD
 *     Download CSV from KoBo Toolbox or any field tool.
 *     Upload here → auto-parsed and stored in complaints table.
 *     Stored CSV copies saved in uploads/complaints_csv/.
 *
 *  3. CSV EXPORT
 *     ?export=csv — downloads ALL complaints as a CSV file.
 *
 *  FEATURES:
 *    · Status management: new → acknowledged → in_progress → resolved
 *    · Filter by status, zone, issue type, date range
 *    · Summary cards: total, new, in_progress, resolved today
 *    · Trend chart: complaints per day by issue type (Chart.js)
 *    · Zone breakdown bar chart
 *    · CSV export of filtered results
 *    · GPS link for geotagged reports
 * ============================================================
 */

session_start();
require_once __DIR__ . '/db.php';

// Auth guard — admin/operator only
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
    header('Location: dashboard.php'); exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email= $_SESSION['user_email'];
$user_role = $_SESSION['user_role'];
$current_page = 'kobo';
$page_title   = 'Field Reports & Complaints';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// Ensure complaints table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reporter_name   VARCHAR(100),
    reporter_phone  VARCHAR(30),
    zone_name       VARCHAR(100),
    zone_id         INT DEFAULT NULL,
    issue_type      ENUM('leak','no_water','contamination','low_pressure','meter_fault','pipe_burst','other') DEFAULT 'other',
    description     TEXT,
    gps_lat         DECIMAL(10,7),
    gps_lng         DECIMAL(10,7),
    status          ENUM('new','acknowledged','in_progress','resolved','closed') DEFAULT 'new',
    assigned_to     INT DEFAULT NULL,
    resolution_note TEXT,
    resolved_at     TIMESTAMP NULL,
    source          ENUM('web_form','csv_upload','api','kobo') DEFAULT 'web_form',
    kobo_id         VARCHAR(100) DEFAULT NULL UNIQUE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_zone   (zone_name),
    INDEX idx_time   (created_at)
)");

// Upgrade existing complaints table — add missing columns if not present
// Add every possible missing column — safe, silently skips if already exists
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN reporter_name VARCHAR(100)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN reporter_phone VARCHAR(30)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN zone_name VARCHAR(100)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN zone_id INT DEFAULT NULL"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN description TEXT"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN issue_type VARCHAR(50) DEFAULT 'other'"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN gps_lat DECIMAL(10,7)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN gps_lng DECIMAL(10,7)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN assigned_to INT DEFAULT NULL"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN resolution_note TEXT"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN resolved_at TIMESTAMP NULL"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN source VARCHAR(20) DEFAULT 'web_form'"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN kobo_id VARCHAR(100) DEFAULT NULL"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN status VARCHAR(20) DEFAULT 'new'"); } catch(\PDOException $e) {}
// Add index on zone_name if missing (ignore error if exists)
try { $pdo->exec("ALTER TABLE complaints ADD INDEX idx_zone (zone_name(50))"); } catch(\PDOException $e) {}

// Ensure upload directory exists
$upload_dir = __DIR__ . '/uploads/complaints_csv/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

$msg = ''; $err = '';

// ── CSV EXPORT ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = '1=1';
    $params = [];
    if (!empty($_GET['status']))     { $where .= ' AND status=?';     $params[] = $_GET['status']; }
    if (!empty($_GET['issue_type'])) { $where .= ' AND issue_type=?'; $params[] = $_GET['issue_type']; }
    if (!empty($_GET['zone_name']))  { $where .= ' AND zone_name LIKE ?'; $params[] = '%'.$_GET['zone_name'].'%'; }
    if (!empty($_GET['date_from']))  { $where .= ' AND DATE(created_at)>=?'; $params[] = $_GET['date_from']; }
    if (!empty($_GET['date_to']))    { $where .= ' AND DATE(created_at)<=?'; $params[] = $_GET['date_to']; }

    $s = $pdo->prepare("SELECT * FROM complaints WHERE $where ORDER BY created_at DESC");
    $s->execute($params);
    $rows = $s->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="complaints_export_'.date('Ymd_Hi').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Reporter Name','Phone','Zone','Issue Type','Description',
                   'GPS Lat','GPS Lng','Status','Assigned To','Resolution Note',
                   'Resolved At','Source','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'],$r['reporter_name'],$r['reporter_phone'],$r['zone_name'],
                       $r['issue_type'],$r['description'],$r['gps_lat'],$r['gps_lng'],
                       $r['status'],$r['assigned_to'],$r['resolution_note'],
                       $r['resolved_at'],$r['source'],$r['created_at']]);
    }
    fclose($out);
    exit;
}

// ── STATUS UPDATE ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_status') {
    $cid    = (int)$_POST['complaint_id'];
    $status = $_POST['new_status'] ?? '';
    $note   = trim($_POST['resolution_note'] ?? '');
    $valid  = ['new','acknowledged','in_progress','resolved','closed'];
    if ($cid > 0 && in_array($status, $valid)) {
        $resolved_at = in_array($status,['resolved','closed']) ? 'NOW()' : 'NULL';
        $pdo->prepare("UPDATE complaints SET status=?, resolution_note=?,
                       resolved_at=$resolved_at, assigned_to=? WHERE id=?")
            ->execute([$status, $note, $user_id, $cid]);
        $msg = "Complaint #$cid updated to: $status";
    }
}

// ── CSV UPLOAD & PARSE ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload_csv') {
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed. Please select a valid CSV file.';
    } elseif (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv','txt'])) {
        $err = 'Only .csv files are accepted.';
    } else {
        // Save the original CSV file
        $saved_name = 'upload_'.date('Ymd_His').'_'.preg_replace('/[^a-z0-9_.-]/i','_',$file['name']);
        move_uploaded_file($file['tmp_name'], $upload_dir . $saved_name);

        // Parse CSV
        $handle = fopen($upload_dir . $saved_name, 'r');
        $headers = null;
        $imported = 0; $skipped = 0;

        // Column name variants (KoBo exports use different names)
        $col_map = [
            'reporter_name'  => ['reporter_name','name','reporter','submitter_name','full_name','your_name'],
            'reporter_phone' => ['reporter_phone','phone','mobile','contact','phone_number'],
            'zone_name'      => ['zone_name','zone','water_zone','area','location','neighbourhood'],
            'issue_type'     => ['issue_type','issue','problem_type','complaint_type','type'],
            'description'    => ['description','details','problem_description','notes','comment','what_happened'],
            'gps_lat'        => ['gps_lat','latitude','lat','_geolocation_latitude'],
            'gps_lng'        => ['gps_lng','longitude','lng','lon','_geolocation_longitude'],
            'kobo_id'        => ['kobo_id','_id','_uuid','submission_id','_submission_time'],
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if (!$headers) {
                $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'],'_',$h))), $row);
                continue;
            }
            if (count($row) !== count($headers)) continue;
            $data = array_combine($headers, $row);

            // Map columns
            $mapped = [];
            foreach ($col_map as $field => $variants) {
                foreach ($variants as $v) {
                    if (isset($data[$v]) && trim($data[$v]) !== '') {
                        $mapped[$field] = trim($data[$v]);
                        break;
                    }
                }
            }

            if (empty($mapped['description']) && empty($mapped['issue_type'])) { $skipped++; continue; }

            // Normalise issue_type
            $it = strtolower($mapped['issue_type'] ?? 'other');
            $it_map = ['leak'=>'leak','no water'=>'no_water','no_water'=>'no_water',
                       'contamination'=>'contamination','pollution'=>'contamination',
                       'low pressure'=>'low_pressure','low_pressure'=>'low_pressure',
                       'meter'=>'meter_fault','meter_fault'=>'meter_fault',
                       'pipe burst'=>'pipe_burst','pipe_burst'=>'pipe_burst','burst'=>'pipe_burst'];
            $mapped['issue_type'] = $it_map[$it] ?? 'other';

            // Find zone_id if zone_name matches water_zones table
            $zone_id_match = null;
            if (!empty($mapped['zone_name'])) {
                $zs = $pdo->prepare("SELECT id FROM water_zones WHERE zone_name LIKE ? LIMIT 1");
                $zs->execute(['%'.$mapped['zone_name'].'%']);
                $zr = $zs->fetch();
                if ($zr) $zone_id_match = $zr['id'];
            }

            try {
                $pdo->prepare("INSERT INTO complaints
                    (reporter_name,reporter_phone,zone_name,zone_id,issue_type,description,
                     gps_lat,gps_lng,source,kobo_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $mapped['reporter_name']  ?? 'Unknown',
                    $mapped['reporter_phone'] ?? '',
                    $mapped['zone_name']      ?? '',
                    $zone_id_match,
                    $mapped['issue_type']     ?? 'other',
                    $mapped['description']    ?? '',
                    !empty($mapped['gps_lat']) && is_numeric($mapped['gps_lat']) ? $mapped['gps_lat'] : null,
                    !empty($mapped['gps_lng']) && is_numeric($mapped['gps_lng']) ? $mapped['gps_lng'] : null,
                    'csv_upload',
                    $mapped['kobo_id'] ?? null,
                ]);
                $imported++;
            } catch (\PDOException $e) {
                $skipped++; // duplicate kobo_id
            }
        }
        fclose($handle);
        $msg = "CSV imported: $imported records added, $skipped skipped (duplicates or empty rows). File saved as: $saved_name";
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────
$f_status = $_GET['status']     ?? '';
$f_type   = $_GET['issue_type'] ?? '';
$f_zone   = $_GET['zone_name']  ?? '';
$f_from   = $_GET['date_from']  ?? date('Y-m-d', strtotime('-30 days'));
$f_to     = $_GET['date_to']    ?? date('Y-m-d');

$where = "created_at BETWEEN ? AND DATE_ADD(?,INTERVAL 1 DAY)";
$params = [$f_from, $f_to];
if ($f_status) { $where .= ' AND status=?';     $params[] = $f_status; }
if ($f_type)   { $where .= ' AND issue_type=?'; $params[] = $f_type; }
if ($f_zone)   { $where .= ' AND zone_name LIKE ?'; $params[] = "%$f_zone%"; }

$complaints = $pdo->prepare("SELECT * FROM complaints WHERE $where ORDER BY created_at DESC LIMIT 500");
$complaints->execute($params);
$complaints = $complaints->fetchAll();

// Stats
$stats = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='new') new_count,
    SUM(status='in_progress') inprog,
    SUM(status='resolved' AND DATE(resolved_at)=CURDATE()) resolved_today,
    SUM(status='resolved') resolved_total
FROM complaints")->fetch();

// Trend data for charts (last 14 days by issue type)
$trend = $pdo->query("
    SELECT DATE(created_at) AS day, issue_type, COUNT(*) AS cnt
    FROM complaints
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY day, issue_type ORDER BY day ASC
")->fetchAll();

// Zone breakdown
$zones_chart = $pdo->query("
    SELECT zone_name, COUNT(*) AS cnt FROM complaints
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND zone_name != ''
    GROUP BY zone_name ORDER BY cnt DESC LIMIT 10
")->fetchAll();

// Saved CSV files list
$saved_csvs = glob($upload_dir . '*.csv') ?: [];
usort($saved_csvs, fn($a,$b) => filemtime($b)-filemtime($a));

require_once __DIR__ . '/sidebar.php';
?>

<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:1.5rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem}
.stat-label{font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.stat-val{font-size:1.6rem;font-weight:800}
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem;margin-bottom:1.25rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end}
.filter-bar label{font-size:.7rem;color:var(--muted);display:block;margin-bottom:3px;text-transform:uppercase}
.filter-bar select,.filter-bar input{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:7px 10px;font-size:.82rem;min-width:120px}
.filter-bar select:focus,.filter-bar input:focus{outline:none;border-color:var(--blue)}
.btn-sm{padding:7px 14px;border:none;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer}
.btn-blue{background:var(--blue);color:#fff}
.btn-green{background:var(--green);color:#0a1628}
.btn-export{background:rgba(52,211,153,.15);color:var(--green);border:1px solid rgba(52,211,153,.25);padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:600;text-decoration:none;display:inline-block}
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{padding:8px 10px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;background:rgba(255,255,255,.02);white-space:nowrap}
.tbl td{padding:8px 10px;border-top:1px solid rgba(30,58,95,.4);vertical-align:top}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.badge{padding:2px 8px;border-radius:5px;font-size:.65rem;font-weight:700}
.s-new       {background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.s-ack       {background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.s-inprog    {background:rgba(14,165,233,.15);color:var(--blue);border:1px solid rgba(14,165,233,.3)}
.s-resolved  {background:rgba(52,211,153,.15);color:var(--green);border:1px solid rgba(52,211,153,.3)}
.s-closed    {background:rgba(122,155,186,.1);color:var(--muted);border:1px solid rgba(122,155,186,.2)}
.sec{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin:1.5rem 0 .85rem;display:flex;align-items:center;gap:8px}
.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem}
@media(max-width:700px){.chart-grid{grid-template-columns:1fr}}
.chart-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.25rem}
.chart-title{font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.85rem}
.upload-box{background:var(--card);border:1px dashed var(--border);border-radius:14px;padding:1.5rem;margin-bottom:1.5rem}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#0f2040;border:1px solid var(--border);border-radius:16px;padding:1.5rem;width:100%;max-width:460px}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">📋 Field Reports & Complaints</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:3px">Resident complaints · CSV imports · Status management · CSV export</p>
  </div>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a href="complaints.php?view=public" target="_blank" class="btn-export" style="background:rgba(14,165,233,.15);color:var(--blue);border-color:rgba(14,165,233,.25)">🔗 Public Form</a>
    <a href="?export=csv&status=<?=urlencode($f_status)?>&issue_type=<?=urlencode($f_type)?>&zone_name=<?=urlencode($f_zone)?>&date_from=<?=$f_from?>&date_to=<?=$f_to?>" class="btn-export">⬇ Export CSV</a>
  </div>
</div>

<?php if($msg):?><div style="padding:11px 16px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--green);border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">✓ <?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div style="padding:11px 16px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">⚠️ <?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Stats -->
<div class="stat-grid">
<?php $sc=[
  ['Total Reports',$stats['total']??0,'#0ea5e9'],
  ['New / Unread',$stats['new_count']??0,'#f87171'],
  ['In Progress',$stats['inprog']??0,'#fbbf24'],
  ['Resolved Today',$stats['resolved_today']??0,'#34d399'],
  ['Total Resolved',$stats['resolved_total']??0,'#7a9bba'],
];
foreach($sc as[$l,$v,$c]):?>
<div class="stat-card"><div class="stat-label"><?=$l?></div><div class="stat-val" style="color:<?=$c?>"><?=$v?></div></div>
<?php endforeach;?>
</div>

<!-- Charts -->
<div class="chart-grid">
  <div class="chart-card">
    <div class="chart-title">📈 Complaints per day — last 14 days</div>
    <canvas id="trendChart" height="200"></canvas>
  </div>
  <div class="chart-card">
    <div class="chart-title">🗺️ By Zone — last 30 days</div>
    <canvas id="zoneChart" height="200"></canvas>
  </div>
</div>

<!-- Filters -->
<form method="get" action="">
<div class="filter-bar">
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach(['new','acknowledged','in_progress','resolved','closed'] as $s):?>
      <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
      <?php endforeach;?>
    </select></div>
  <div><label>Issue Type</label>
    <select name="issue_type">
      <option value="">All</option>
      <?php foreach(['leak','no_water','contamination','low_pressure','meter_fault','pipe_burst','other'] as $t):?>
      <option value="<?=$t?>" <?=$f_type===$t?'selected':''?>><?=ucfirst(str_replace('_',' ',$t))?></option>
      <?php endforeach;?>
    </select></div>
  <div><label>Zone</label><input name="zone_name" value="<?=htmlspecialchars($f_zone)?>" placeholder="search zone…"></div>
  <div><label>From</label><input type="date" name="date_from" value="<?=$f_from?>"></div>
  <div><label>To</label><input type="date" name="date_to" value="<?=$f_to?>"></div>
  <button type="submit" class="btn-sm btn-blue">Filter</button>
  <a href="?" style="color:var(--muted);font-size:.8rem;align-self:center">Reset</a>
</div>
</form>

<!-- CSV Upload -->
<div class="sec">📂 Import from CSV</div>
<div class="upload-box">
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end">
    <input type="hidden" name="_action" value="upload_csv">
    <div>
      <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">Upload CSV File</label>
      <input type="file" name="csv_file" accept=".csv,.txt" style="color:var(--text);font-size:.82rem">
    </div>
    <button type="submit" class="btn-sm btn-blue">⬆ Import CSV</button>
    <a href="complaints.php?view=public" target="_blank" style="color:var(--muted);font-size:.8rem;align-self:center">
      Or share the public form link →
    </a>
  </form>
  <p style="color:var(--muted);font-size:.75rem;margin-top:.75rem">
    <strong style="color:var(--text)">Accepted columns (any order):</strong>
    reporter_name, reporter_phone, zone_name, issue_type, description, gps_lat, gps_lng, kobo_id (optional unique ID).
    Column names are matched automatically — KoBo exports and manual CSVs both work.
    Duplicates are skipped automatically.
  </p>
  <?php if(!empty($saved_csvs)):?>
  <details style="margin-top:.75rem">
    <summary style="font-size:.78rem;color:var(--muted);cursor:pointer">📁 Previously uploaded files (<?=count($saved_csvs)?>)</summary>
    <div style="margin-top:.5rem;font-size:.75rem;color:var(--muted);line-height:2">
      <?php foreach(array_slice($saved_csvs,0,10) as $f):?>
        <?=basename($f)?> — <?=number_format(filesize($f)/1024,1)?> KB — <?=date('d M Y H:i',filemtime($f))?><br>
      <?php endforeach;?>
    </div>
  </details>
  <?php endif;?>
</div>

<!-- Complaints table -->
<div class="sec">📋 Complaints (<?=count($complaints)?> shown)</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem">
<div style="overflow-x:auto">
<table class="tbl">
  <thead><tr>
    <th>#</th><th>Reporter</th><th>Zone</th><th>Issue</th>
    <th>Description</th><th>GPS</th><th>Source</th>
    <th>Date</th><th>Status</th><th>Action</th>
  </tr></thead>
  <tbody>
  <?php if(empty($complaints)):?>
  <tr><td colspan="10" style="text-align:center;padding:2.5rem;color:var(--muted)">
    No complaints found for the selected filters.
  </td></tr>
  <?php endif;?>
  <?php foreach($complaints as $c):
    $sc_map=['new'=>'s-new','acknowledged'=>'s-ack','in_progress'=>'s-inprog','resolved'=>'s-resolved','closed'=>'s-closed'];
    $sc_class=$sc_map[$c['status']]??'s-new';
    $it_icons=['leak'=>'💧','no_water'=>'🚰','contamination'=>'⚠️','low_pressure'=>'📉','meter_fault'=>'🔧','pipe_burst'=>'💥','other'=>'❓'];
    $it_icon=$it_icons[$c['issue_type']]??'❓';
  ?>
  <tr>
    <td style="color:var(--muted)">#<?=$c['id']?></td>
    <td>
      <div style="font-weight:600"><?=htmlspecialchars($c['reporter_name'])?></div>
      <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($c['reporter_phone'])?></div>
    </td>
    <td><?=htmlspecialchars($c['zone_name'])?></td>
    <td style="white-space:nowrap"><?=$it_icon?> <?=htmlspecialchars(str_replace('_',' ',$c['issue_type']))?></td>
    <td style="max-width:250px;color:var(--muted);font-size:.77rem"><?=htmlspecialchars(mb_strimwidth($c['description'],0,100,'…'))?></td>
    <td>
      <?php if($c['gps_lat'] && $c['gps_lng']):?>
        <a href="https://maps.google.com/?q=<?=$c['gps_lat']?>,<?=$c['gps_lng']?>" target="_blank"
           style="color:var(--blue);font-size:.72rem">🗺️ Map</a>
      <?php else:?>
        <span style="color:var(--muted);font-size:.72rem">—</span>
      <?php endif;?>
    </td>
    <td style="font-size:.7rem;color:var(--muted)"><?=$c['source']?></td>
    <td style="white-space:nowrap;font-size:.75rem;color:var(--muted)"><?=date('d M y H:i',strtotime($c['created_at']))?></td>
    <td><span class="badge <?=$sc_class?>"><?=str_replace('_',' ',strtoupper($c['status']))?></span></td>
    <td>
      <button class="btn-sm" style="background:rgba(14,165,233,.15);color:var(--blue);border:1px solid rgba(14,165,233,.25);font-size:.7rem"
              onclick="openModal(<?=$c['id']?>,'<?=htmlspecialchars($c['status'])?>','<?=htmlspecialchars(addslashes($c['resolution_note']??''))?>')">
        Edit
      </button>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>
</div>

<!-- Status Edit Modal -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:1rem">Update Complaint Status</h3>
    <form method="post">
      <input type="hidden" name="_action" value="update_status">
      <input type="hidden" name="complaint_id" id="m-id">
      <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:4px">New Status</label>
      <select name="new_status" id="m-status" style="width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 12px;font-size:.88rem;margin-bottom:1rem">
        <?php foreach(['new','acknowledged','in_progress','resolved','closed'] as $s):?>
        <option value="<?=$s?>"><?=ucfirst(str_replace('_',' ',$s))?></option>
        <?php endforeach;?>
      </select>
      <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:4px">Resolution Note (optional)</label>
      <textarea name="resolution_note" id="m-note" rows="3" style="width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 12px;font-size:.85rem;resize:vertical;margin-bottom:1rem"></textarea>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" onclick="closeModal()" style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 16px;border-radius:8px;cursor:pointer">Cancel</button>
        <button type="submit" class="btn-sm btn-blue" style="padding:8px 18px">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Charts ─────────────────────────────────────────────────────
const trendRaw = <?=json_encode($trend)?>;
const zoneRaw  = <?=json_encode($zones_chart)?>;

// Build date labels for trend chart
const allDates = [...new Set(trendRaw.map(r => r.day))].sort();
const issueTypes = ['leak','no_water','contamination','low_pressure','meter_fault','pipe_burst','other'];
const colors = {'leak':'#0ea5e9','no_water':'#f87171','contamination':'#a78bfa',
                'low_pressure':'#fbbf24','meter_fault':'#fb923c','pipe_burst':'#f43f5e','other':'#7a9bba'};

const trendDatasets = issueTypes.map(type => ({
    label: type.replace('_',' '),
    data: allDates.map(d => {
        const r = trendRaw.find(x => x.day===d && x.issue_type===type);
        return r ? parseInt(r.cnt) : 0;
    }),
    backgroundColor: colors[type]+'66',
    borderColor: colors[type],
    borderWidth:1.5, fill:true, tension:.4,
}));

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels: allDates, datasets: trendDatasets },
    options: {
        responsive:true, maintainAspectRatio:true,
        plugins:{ legend:{ labels:{ color:'#7a9bba', boxWidth:10, font:{size:10} } } },
        scales:{
            x:{ ticks:{color:'#7a9bba',font:{size:10}}, grid:{color:'rgba(30,58,95,.4)'} },
            y:{ ticks:{color:'#7a9bba',font:{size:10}}, grid:{color:'rgba(30,58,95,.4)'}, beginAtZero:true }
        }
    }
});

new Chart(document.getElementById('zoneChart'), {
    type: 'bar',
    data: {
        labels: zoneRaw.map(r=>r.zone_name),
        datasets:[{ label:'Complaints', data:zoneRaw.map(r=>parseInt(r.cnt)),
            backgroundColor:'rgba(14,165,233,.5)', borderColor:'#0ea5e9', borderWidth:1.5 }]
    },
    options:{
        responsive:true, indexAxis:'y',
        plugins:{ legend:{ display:false } },
        scales:{
            x:{ ticks:{color:'#7a9bba',font:{size:10}}, grid:{color:'rgba(30,58,95,.4)'} },
            y:{ ticks:{color:'#7a9bba',font:{size:11}} }
        }
    }
});

// ── Modal ──────────────────────────────────────────────────────
function openModal(id, status, note) {
    document.getElementById('m-id').value = id;
    document.getElementById('m-status').value = status;
    document.getElementById('m-note').value = note;
    document.getElementById('modal').classList.add('open');
}
function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
document.getElementById('modal').addEventListener('click', function(e){
    if(e.target === this) closeModal();
});
</script>
</main></body></html>