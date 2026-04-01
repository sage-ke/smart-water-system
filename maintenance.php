<?php
/*
 * maintenance.php — SWDS Meru
 * Maintenance Job Tracker — admin / operator only
 * ============================================================
 * Tracks all scheduled and completed maintenance work:
 *  - Pipe repairs, valve replacements, sensor calibrations
 *  - Filter changes, pump servicing, tank cleaning
 * Linked to zones and hardware devices.
 * ============================================================
 */
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) { header('Location: dashboard.php'); exit; }

$user_id    = (int)$_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'maintenance';
$page_title   = 'Maintenance';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// Ensure maintenance_logs table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    zone_id         INT DEFAULT NULL,
    device_id       INT DEFAULT NULL,
    job_type        ENUM('pipe_repair','valve_replacement','sensor_calibration',
                         'filter_change','pump_service','tank_cleaning',
                         'meter_replacement','leak_repair','routine_check','other') DEFAULT 'routine_check',
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    status          ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
    priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
    scheduled_date  DATE,
    completed_date  DATE,
    technician_name VARCHAR(100),
    cost_ksh        DECIMAL(10,2) DEFAULT 0,
    notes           TEXT,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_zone   (zone_id),
    INDEX idx_date   (scheduled_date),
    FOREIGN KEY (zone_id) REFERENCES water_zones(id) ON DELETE SET NULL
)");

// Upgrade existing table — add missing columns if not present
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN priority ENUM('low','medium','high','critical') DEFAULT 'medium'"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN job_type ENUM('pipe_repair','valve_replacement','sensor_calibration','filter_change','pump_service','tank_cleaning','meter_replacement','leak_repair','routine_check','other') DEFAULT 'routine_check'"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN technician_name VARCHAR(100)"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN cost_ksh DECIMAL(10,2) DEFAULT 0"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN completed_date DATE"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN notes TEXT"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN created_by INT"); } catch(\PDOException $e) {}
try { $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN device_id INT DEFAULT NULL"); } catch(\PDOException $e) {}

$msg = ''; $err = '';

// ── ADD JOB ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add') {
    $zone_id   = (int)($_POST['zone_id']   ?? 0) ?: null;
    $title     = trim($_POST['title']      ?? '');
    $type      = $_POST['job_type']        ?? 'routine_check';
    $priority  = $_POST['priority']        ?? 'medium';
    $sched     = $_POST['scheduled_date']  ?? date('Y-m-d');
    $tech      = trim($_POST['technician_name'] ?? '');
    $desc      = trim($_POST['description']     ?? '');
    $cost      = (float)($_POST['cost_ksh']     ?? 0);

    if ($title) {
        $pdo->prepare("INSERT INTO maintenance_logs
            (zone_id,job_type,title,description,priority,scheduled_date,technician_name,cost_ksh,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,'scheduled',?)")
        ->execute([$zone_id,$type,$title,$desc,$priority,$sched,$tech,$cost,$user_id]);
        $msg = "Maintenance job \"$title\" scheduled successfully.";
    } else { $err = 'Job title is required.'; }
}

// ── UPDATE STATUS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update') {
    $id     = (int)($_POST['job_id']   ?? 0);
    $status = $_POST['new_status']     ?? '';
    $notes  = trim($_POST['notes']     ?? '');
    $cost   = (float)($_POST['cost_ksh'] ?? 0);
    $comp   = in_array($status,['completed','cancelled']) ? date('Y-m-d') : null;
    if ($id) {
        $pdo->prepare("UPDATE maintenance_logs SET status=?, notes=?, cost_ksh=?,
                       completed_date=? WHERE id=?")
            ->execute([$status,$notes,$cost,$comp,$id]);
        $msg = "Job #$id updated to: $status";
    }
}

// ── DELETE ────────────────────────────────────────────────────
if (isset($_GET['delete']) && $user_role === 'admin') {
    $pdo->prepare("DELETE FROM maintenance_logs WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: maintenance.php?deleted=1'); exit;
}
if (isset($_GET['deleted'])) $msg = 'Job record deleted.';

// ── LOAD DATA ─────────────────────────────────────────────────
$f_status   = $_GET['status']   ?? '';
$f_priority = $_GET['priority'] ?? '';
$f_zone     = (int)($_GET['zone_id'] ?? 0);

$where = '1=1'; $params = [];
if ($f_status)   { $where .= ' AND ml.status=?';          $params[] = $f_status; }
if ($f_priority) { $where .= ' AND ml.priority=?';         $params[] = $f_priority; }
if ($f_zone)     { $where .= ' AND ml.zone_id=?';          $params[] = $f_zone; }

$jobs = $pdo->prepare("
    SELECT ml.*, wz.zone_name
    FROM maintenance_logs ml
    LEFT JOIN water_zones wz ON wz.id=ml.zone_id
    WHERE $where
    ORDER BY
        CASE ml.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
        ml.scheduled_date ASC
    LIMIT 200
");
$jobs->execute($params);
$jobs = $jobs->fetchAll();

// Stats
$stats = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='scheduled')  scheduled,
    SUM(status='in_progress') in_progress,
    SUM(status='completed')  completed,
    SUM(priority='critical' AND status NOT IN ('completed','cancelled')) critical_open,
    SUM(status='completed') / NULLIF(COUNT(*),0) * 100 completion_rate,
    SUM(cost_ksh) total_cost
FROM maintenance_logs")->fetch();

// Upcoming (next 7 days)
$upcoming = $pdo->query("
    SELECT ml.*, wz.zone_name FROM maintenance_logs ml
    LEFT JOIN water_zones wz ON wz.id=ml.zone_id
    WHERE ml.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
      AND ml.status = 'scheduled'
    ORDER BY ml.scheduled_date ASC LIMIT 10
")->fetchAll();

$zones   = $pdo->query("SELECT id,zone_name FROM water_zones ORDER BY zone_name")->fetchAll();
$devices = $pdo->query("SELECT id,device_name,device_code FROM hardware_devices ORDER BY device_name")->fetchAll();

require_once __DIR__ . '/sidebar.php';
?>
<style>
.kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem}
.kpi-c{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.9rem 1.1rem}
.kpi-lbl{font-size:.63rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
.kpi-val{font-size:1.5rem;font-weight:800}
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.25rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end}
.filter-bar select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:7px 10px;font-size:.82rem}
.filter-bar select:focus{outline:none;border-color:var(--blue)}
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{padding:8px 10px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;background:rgba(255,255,255,.02);white-space:nowrap}
.tbl td{padding:8px 10px;border-top:1px solid rgba(30,58,95,.4);vertical-align:top}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.pil{padding:2px 8px;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.p-sched  {background:rgba(14,165,233,.12);color:#0ea5e9;border:1px solid rgba(14,165,233,.25)}
.p-inprog {background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.p-done   {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}
.p-cancel {background:rgba(122,155,186,.1);color:#7a9bba;border:1px solid rgba(122,155,186,.2)}
.pr-crit  {background:rgba(248,113,113,.2);color:#f87171;border:1px solid rgba(248,113,113,.35)}
.pr-high  {background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.3)}
.pr-med   {background:rgba(251,191,36,.1);color:#fbbf24;border:1px solid rgba(251,191,36,.2)}
.pr-low   {background:rgba(122,155,186,.1);color:#7a9bba;border:1px solid rgba(122,155,186,.2)}
.sec{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;margin:1.5rem 0 .85rem;display:flex;align-items:center;gap:8px}
.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.add-btn{padding:9px 20px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;border-radius:9px;color:#fff;font-size:.85rem;font-weight:700;cursor:pointer}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#0f2040;border:1px solid var(--border);border-radius:16px;padding:1.5rem;width:100%;max-width:520px;max-height:90vh;overflow-y:auto}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.85rem}
label{font-size:.75rem;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase}
.fi{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 11px;font-size:.88rem;font-family:'DM Sans',sans-serif}
.fi:focus{outline:none;border-color:var(--blue)}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">🔧 Maintenance</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:3px">Schedule jobs · Track progress · Log costs · Zone-linked records</p>
  </div>
  <button class="add-btn" onclick="document.getElementById('addModal').classList.add('open')">+ Schedule Job</button>
</div>

<?php if($msg):?><div style="padding:11px 16px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--green);border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">✓ <?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div style="padding:11px 16px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">⚠️ <?=htmlspecialchars($err)?></div><?php endif;?>

<!-- KPI cards -->
<div class="kpi">
<?php $kc=[
  ['Total Jobs',$stats['total']??0,'#0ea5e9'],
  ['Scheduled',$stats['scheduled']??0,'#06b6d4'],
  ['In Progress',$stats['in_progress']??0,'#fbbf24'],
  ['Completed',$stats['completed']??0,'#34d399'],
  ['Critical Open',$stats['critical_open']??0,'#f87171'],
  ['Completion %',round($stats['completion_rate']??0,1).'%','#a78bfa'],
  ['Total Cost','KSh '.number_format($stats['total_cost']??0,0),'#7a9bba'],
];
foreach($kc as[$l,$v,$c]):?>
<div class="kpi-c"><div class="kpi-lbl"><?=$l?></div><div class="kpi-val" style="color:<?=$c?>;font-size:<?=is_numeric(str_replace(['%','KSh ',','],'',$v))?'1.4':'1.05'?>rem"><?=$v?></div></div>
<?php endforeach;?>
</div>

<!-- Upcoming this week -->
<?php if(!empty($upcoming)):?>
<div style="background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.2);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.25rem">
  <div style="font-size:.75rem;font-weight:700;color:var(--yellow);text-transform:uppercase;margin-bottom:.6rem">📅 Upcoming this week (<?=count($upcoming)?> jobs)</div>
  <div style="display:flex;flex-wrap:wrap;gap:.6rem">
    <?php foreach($upcoming as $u):?>
    <div style="background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.5rem .85rem;font-size:.78rem">
      <span style="color:var(--yellow);font-weight:700"><?=date('d M',strtotime($u['scheduled_date']))?></span>
      <span style="margin-left:.5rem"><?=htmlspecialchars($u['title'])?></span>
      <?php if($u['zone_name']):?><span style="color:var(--muted);margin-left:.4rem">(<?=htmlspecialchars($u['zone_name'])?>)</span><?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>

<!-- Filters -->
<form method="get">
<div class="filter-bar">
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach(['scheduled','in_progress','completed','cancelled'] as $s):?>
      <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
      <?php endforeach;?>
    </select></div>
  <div><label>Priority</label>
    <select name="priority">
      <option value="">All</option>
      <?php foreach(['critical','high','medium','low'] as $p):?>
      <option value="<?=$p?>" <?=$f_priority===$p?'selected':''?>><?=ucfirst($p)?></option>
      <?php endforeach;?>
    </select></div>
  <div><label>Zone</label>
    <select name="zone_id">
      <option value="">All Zones</option>
      <?php foreach($zones as $z):?>
      <option value="<?=$z['id']?>" <?=$f_zone===(int)$z['id']?'selected':''?>><?=htmlspecialchars($z['zone_name'])?></option>
      <?php endforeach;?>
    </select></div>
  <button type="submit" style="padding:7px 16px;background:var(--blue);border:none;border-radius:8px;color:#fff;font-size:.82rem;font-weight:600;cursor:pointer">Filter</button>
  <a href="maintenance.php" style="color:var(--muted);font-size:.8rem;align-self:center">Reset</a>
</div>
</form>

<!-- Jobs table -->
<div class="sec">🔧 Jobs (<?=count($jobs)?> shown)</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem">
<div style="overflow-x:auto">
<table class="tbl">
  <thead><tr>
    <th>#</th><th>Job</th><th>Zone</th><th>Type</th><th>Priority</th>
    <th>Scheduled</th><th>Technician</th><th>Cost (KSh)</th><th>Status</th><th>Action</th>
  </tr></thead>
  <tbody>
  <?php if(empty($jobs)):?>
  <tr><td colspan="10" style="text-align:center;padding:2.5rem;color:var(--muted)">No maintenance jobs found. Click + Schedule Job to add one.</td></tr>
  <?php endif;?>
  <?php foreach($jobs as $j):
    $sc_map=['scheduled'=>'p-sched','in_progress'=>'p-inprog','completed'=>'p-done','cancelled'=>'p-cancel'];
    $pr_map=['critical'=>'pr-crit','high'=>'pr-high','medium'=>'pr-med','low'=>'pr-low'];
    $overdue = $j['status']==='scheduled' && $j['scheduled_date'] < date('Y-m-d');
  ?>
  <tr>
    <td style="color:var(--muted)">#<?=$j['id']?></td>
    <td>
      <div style="font-weight:600;<?=$overdue?'color:var(--red)':''?>"><?=htmlspecialchars($j['title'])?></div>
      <?php if($j['description']):?><div style="font-size:.72rem;color:var(--muted);margin-top:2px"><?=htmlspecialchars(mb_strimwidth($j['description'],0,70,'…'))?></div><?php endif;?>
      <?php if($overdue):?><div style="font-size:.7rem;color:var(--red);margin-top:2px">⚠ OVERDUE</div><?php endif;?>
    </td>
    <td style="font-size:.82rem"><?=htmlspecialchars($j['zone_name']??'—')?></td>
    <td style="font-size:.75rem;color:var(--muted)"><?=htmlspecialchars(str_replace('_',' ',ucfirst($j['job_type'])))?></td>
    <td><span class="pil <?=$pr_map[$j['priority']]??'pr-med'?>"><?=strtoupper($j['priority'])?></span></td>
    <td style="font-size:.78rem;white-space:nowrap;color:<?=$overdue?'var(--red)':'var(--muted)'?>"><?=date('d M Y',strtotime($j['scheduled_date']))?></td>
    <td style="font-size:.78rem"><?=htmlspecialchars($j['technician_name']??'—')?></td>
    <td style="font-size:.8rem;color:var(--blue)"><?=$j['cost_ksh']>0?number_format($j['cost_ksh'],0):'—'?></td>
    <td><span class="pil <?=$sc_map[$j['status']]??'p-sched'?>"><?=strtoupper(str_replace('_',' ',$j['status']))?></span></td>
    <td style="white-space:nowrap">
      <button class="pil p-sched" style="cursor:pointer;border:none;font-size:.68rem;padding:3px 9px"
              onclick="openUpdate(<?=$j['id']?>,'<?=$j['status']?>','<?=htmlspecialchars(addslashes($j['notes']??''))?>',<?=$j['cost_ksh']?>)">
        Update
      </button>
      <?php if($user_role==='admin'):?>
      <a href="?delete=<?=$j['id']?>" onclick="return confirm('Delete this job?')"
         style="color:var(--red);font-size:.75rem;text-decoration:none;margin-left:6px">🗑</a>
      <?php endif;?>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>
</div>

<!-- ADD JOB MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:1.1rem">🔧 Schedule Maintenance Job</h3>
    <form method="post">
      <input type="hidden" name="_action" value="add">
      <div style="margin-bottom:.85rem">
        <label>Job Title *</label>
        <input type="text" name="title" class="fi" placeholder="e.g. Replace Zone A main valve" required>
      </div>
      <div class="form-row">
        <div><label>Job Type</label>
          <select name="job_type" class="fi">
            <?php foreach(['pipe_repair','valve_replacement','sensor_calibration','filter_change',
                           'pump_service','tank_cleaning','meter_replacement','leak_repair','routine_check','other'] as $t):?>
            <option value="<?=$t?>"><?=ucfirst(str_replace('_',' ',$t))?></option>
            <?php endforeach;?>
          </select></div>
        <div><label>Priority</label>
          <select name="priority" class="fi">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select></div>
      </div>
      <div class="form-row">
        <div><label>Zone</label>
          <select name="zone_id" class="fi">
            <option value="">— All / General —</option>
            <?php foreach($zones as $z):?><option value="<?=$z['id']?>"><?=htmlspecialchars($z['zone_name'])?></option><?php endforeach;?>
          </select></div>
        <div><label>Scheduled Date</label>
          <input type="date" name="scheduled_date" class="fi" value="<?=date('Y-m-d')?>"></div>
      </div>
      <div class="form-row">
        <div><label>Technician Name</label>
          <input type="text" name="technician_name" class="fi" placeholder="e.g. James Mwangi"></div>
        <div><label>Estimated Cost (KSh)</label>
          <input type="number" name="cost_ksh" class="fi" min="0" step="0.01" value="0"></div>
      </div>
      <div style="margin-bottom:1rem">
        <label>Description</label>
        <textarea name="description" class="fi" rows="3" placeholder="Details about the work to be done…"></textarea>
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('open')"
                style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 16px;border-radius:8px;cursor:pointer">Cancel</button>
        <button type="submit" class="add-btn">Schedule Job</button>
      </div>
    </form>
  </div>
</div>

<!-- UPDATE STATUS MODAL -->
<div class="modal-overlay" id="updModal">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:1.1rem">Update Job Status</h3>
    <form method="post">
      <input type="hidden" name="_action" value="update">
      <input type="hidden" name="job_id" id="upd-id">
      <div style="margin-bottom:.85rem">
        <label>New Status</label>
        <select name="new_status" id="upd-status" class="fi">
          <option value="scheduled">Scheduled</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div style="margin-bottom:.85rem">
        <label>Actual Cost (KSh)</label>
        <input type="number" name="cost_ksh" id="upd-cost" class="fi" min="0" step="0.01">
      </div>
      <div style="margin-bottom:1rem">
        <label>Completion Notes</label>
        <textarea name="notes" id="upd-notes" class="fi" rows="3"></textarea>
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('updModal').classList.remove('open')"
                style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 16px;border-radius:8px;cursor:pointer">Cancel</button>
        <button type="submit" class="add-btn">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUpdate(id, status, notes, cost) {
    document.getElementById('upd-id').value     = id;
    document.getElementById('upd-status').value = status;
    document.getElementById('upd-notes').value  = notes;
    document.getElementById('upd-cost').value   = cost;
    document.getElementById('updModal').classList.add('open');
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target===m) m.classList.remove('open'); });
});
</script>
</main></body></html>