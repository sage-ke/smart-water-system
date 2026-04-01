<?php
/*
 * complaints.php - SWDS Meru
 * Public mode:  complaints.php?view=public  (no login - residents submit here)
 * Admin mode:   complaints.php              (requires admin/operator login)
 * CSV export:   complaints.php?export=csv
 */
session_start();
require_once __DIR__ . '/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_name VARCHAR(100),
    reporter_phone VARCHAR(30),
    zone_name VARCHAR(100),
    issue_type ENUM('leak','no_water','contamination','low_pressure','meter_fault','pipe_burst','other') DEFAULT 'other',
    description TEXT,
    gps_lat DECIMAL(10,7),
    gps_lng DECIMAL(10,7),
    status ENUM('new','acknowledged','in_progress','resolved','closed') DEFAULT 'new',
    assigned_to INT DEFAULT NULL,
    resolution_note TEXT,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status(status), INDEX idx_time(created_at)
)");

// Add every possible missing column — safe, silently skips if already exists
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN reporter_name VARCHAR(100)"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN reporter_phone VARCHAR(30)"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN zone_name VARCHAR(100)"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN zone_id INT DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN description TEXT"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN issue_type VARCHAR(50) DEFAULT 'other'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN gps_lat DECIMAL(10,7)"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN gps_lng DECIMAL(10,7)"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN assigned_to INT DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN resolution_note TEXT"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN resolved_at TIMESTAMP NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN source VARCHAR(20) DEFAULT 'web_form'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN kobo_id VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD COLUMN status VARCHAR(20) DEFAULT 'new'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE complaints ADD INDEX idx_zone (zone_name(50))"); } catch(PDOException $e) {}


$mode = $_GET['view'] ?? '';

/* ============================================================
   PUBLIC FORM
   ============================================================ */
if ($mode === 'public') {
    $msg = ''; $err = '';
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='submit') {
        $name  = trim($_POST['reporter_name'] ?? '');
        $phone = trim($_POST['reporter_phone'] ?? '');
        $zone  = trim($_POST['zone_name'] ?? '');
        $type  = $_POST['issue_type'] ?? 'other';
        $desc  = trim($_POST['description'] ?? '');
        $lat   = is_numeric($_POST['gps_lat']??'') ? (float)$_POST['gps_lat'] : null;
        $lng   = is_numeric($_POST['gps_lng']??'') ? (float)$_POST['gps_lng'] : null;
        $valid = ['leak','no_water','contamination','low_pressure','meter_fault','pipe_burst','other'];
        if (!in_array($type,$valid)) $type='other';
        if (!$name) $err='Please enter your name.';
        elseif(!$zone) $err='Please select your zone.';
        elseif(!$desc) $err='Please describe the issue.';
        else {
            $pdo->prepare("INSERT INTO complaints (reporter_name,reporter_phone,zone_name,issue_type,description,gps_lat,gps_lng) VALUES (?,?,?,?,?,?,?)")
                ->execute([$name,$phone,$zone,$type,$desc,$lat,$lng]);
            $rid = $pdo->lastInsertId();
            $msg = "Complaint submitted. Reference #$rid — we will follow up.";
        }
    }
    $zones = $pdo->query("SELECT zone_name FROM water_zones WHERE status='active' ORDER BY zone_name")->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Report Water Issue - SWDS Meru</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--blue:#0ea5e9;--teal:#06b6d4;--dark:#0a1628;--card:#0f2040;--border:#1e3a5f;--text:#e2eaf4;--muted:#7a9bba;--green:#34d399;--red:#f87171}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--text);min-height:100vh;padding:2rem 1rem;
     background-image:radial-gradient(ellipse at 20% 30%,rgba(14,165,233,.1) 0%,transparent 55%)}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:2rem;max-width:540px;margin:0 auto}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:1.75rem}
.bi{width:42px;height:42px;background:linear-gradient(135deg,var(--blue),var(--teal));border-radius:11px;display:grid;place-items:center;font-size:1.3rem}
h2{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;margin-bottom:.3rem}
.sub{color:var(--muted);font-size:.88rem;margin-bottom:1.5rem}
label{display:block;font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em}
input,select,textarea{width:100%;padding:10px 14px;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:9px;color:var(--text);font-size:.9rem;font-family:'DM Sans',sans-serif;margin-bottom:1rem}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue)}
select option{background:var(--dark)} textarea{resize:vertical;min-height:90px}
.btn{width:100%;padding:12px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;
     border-radius:10px;color:#fff;font-size:1rem;font-weight:700;font-family:'Syne',sans-serif;cursor:pointer}
.msg{padding:12px 16px;border-radius:10px;margin-bottom:1.25rem;font-size:.88rem}
.ok{background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:var(--green)}
.er{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:var(--red)}
.gpr{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.gpr input{margin-bottom:0}
</style></head><body>
<div class="card">
  <div class="brand"><div class="bi">&#x1f4a7;</div>
    <div><div style="font-family:'Syne',sans-serif;font-weight:700">SWDS Meru</div>
         <div style="font-size:.72rem;color:var(--muted)">Water Issue Report</div></div>
  </div>
  <h2>Report a Water Issue</h2>
  <p class="sub">Fill in the form below. We will follow up as soon as possible.</p>
  <?php if($msg): ?><div class="msg ok">&#x2705; <?=htmlspecialchars($msg)?></div>
    <a href="?view=public" style="color:var(--blue);font-size:.88rem">Submit another complaint</a>
  <?php elseif($err): ?><div class="msg er">&#x26a0;&#xfe0f; <?=htmlspecialchars($err)?></div>
  <?php endif; ?>
  <?php if(!$msg): ?>
  <form method="post">
    <input type="hidden" name="_action" value="submit">
    <label>Your Name *</label>
    <input type="text" name="reporter_name" placeholder="e.g. Jane Mwangi" value="<?=htmlspecialchars($_POST['reporter_name']??'')?>" required>
    <label>Phone Number (optional)</label>
    <input type="tel" name="reporter_phone" placeholder="+254 7xx xxx xxx" value="<?=htmlspecialchars($_POST['reporter_phone']??'')?>">
    <label>Your Zone / Area *</label>
    <select name="zone_name" required>
      <option value="">-- Select your zone --</option>
      <?php foreach($zones as $z): $zn=htmlspecialchars($z['zone_name']); $sel=($_POST['zone_name']??'')===$z['zone_name']?'selected':''; ?>
      <option value="<?=$zn?>" <?=$sel?>><?=$zn?></option>
      <?php endforeach; ?>
      <option value="Other">Other / Not listed</option>
    </select>
    <label>Issue Type *</label>
    <select name="issue_type">
      <option value="leak">Water Leak</option>
      <option value="no_water">No Water Supply</option>
      <option value="contamination">Water Contamination</option>
      <option value="low_pressure">Low Pressure</option>
      <option value="meter_fault">Meter Fault</option>
      <option value="pipe_burst">Pipe Burst</option>
      <option value="other">Other</option>
    </select>
    <label>Description *</label>
    <textarea name="description" placeholder="Describe the problem: when it started, what you observed..." required><?=htmlspecialchars($_POST['description']??'')?></textarea>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
      <label style="margin-bottom:0">GPS Location (optional)</label>
      <button type="button" onclick="getGPS()" style="font-size:.72rem;color:var(--blue);background:none;border:none;cursor:pointer;text-decoration:underline">Use my location</button>
    </div>
    <div class="gpr" style="margin-bottom:1rem">
      <input type="number" id="gl" name="gps_lat" placeholder="Latitude" step="any" value="<?=htmlspecialchars($_POST['gps_lat']??'')?>">
      <input type="number" id="gn" name="gps_lng" placeholder="Longitude" step="any" value="<?=htmlspecialchars($_POST['gps_lng']??'')?>">
    </div>
    <button type="submit" class="btn">Submit Complaint</button>
  </form>
  <?php endif; ?>
</div>
<script>
function getGPS(){
  if(!navigator.geolocation){alert('GPS not available');return;}
  navigator.geolocation.getCurrentPosition(p=>{
    document.getElementById('gl').value=p.coords.latitude.toFixed(7);
    document.getElementById('gn').value=p.coords.longitude.toFixed(7);
  },()=>alert('Could not get location'));
}
</script>
</body></html>
<?php exit; }

/* ============================================================
   ADMIN DASHBOARD
   ============================================================ */
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role']??'',['admin','operator'])) { header('Location: dashboard.php'); exit; }
// Staff are redirected to the full complaints hub
header('Location: kobo_importer.php'); exit;

$user_name=$_SESSION['user_name']; $user_email=$_SESSION['user_email'];
$user_role=$_SESSION['user_role']; $current_page='complaints';
$page_title='Field Complaints';
$total_alerts=(int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// Status update
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='update') {
    $id=(int)$_POST['cid']; $st=$_POST['status']??'new';
    $note=trim($_POST['note']??'');
    $valid=['new','acknowledged','in_progress','resolved','closed'];
    if(!in_array($st,$valid)) $st='new';
    $ra=in_array($st,['resolved','closed'])?date('Y-m-d H:i:s'):null;
    $pdo->prepare("UPDATE complaints SET status=?,resolution_note=?,assigned_to=?,resolved_at=? WHERE id=?")
        ->execute([$st,$note,(int)$_SESSION['user_id'],$ra,$id]);
    header('Location: complaints.php'); exit;
}

// CSV export (Excel-compatible with UTF-8 BOM)
if (isset($_GET['export']) && $_GET['export']==='csv') {
    $rows=$pdo->query("SELECT id,reporter_name,reporter_phone,zone_name,issue_type,description,gps_lat,gps_lng,status,resolution_note,created_at,resolved_at FROM complaints ORDER BY created_at DESC")->fetchAll();
    // Clear any buffered output first
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="complaints_'.date('Ymd').'.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $o=fopen('php://output','w');
    // UTF-8 BOM — makes Excel open it correctly without garbled characters
    fprintf($o, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($o,['ID','Name','Phone','Zone','Issue Type','Description','GPS Lat','GPS Lng','Status','Resolution Note','Submitted','Resolved']);
    foreach($rows as $r) fputcsv($o,[
        $r['id'],
        $r['reporter_name'] ?? '',
        $r['reporter_phone'] ?? '',
        $r['zone_name'] ?? '',
        ucwords(str_replace('_',' ',$r['issue_type'] ?? '')),
        $r['description'] ?? '',
        $r['gps_lat'] ?? '',
        $r['gps_lng'] ?? '',
        ucfirst($r['status'] ?? ''),
        $r['resolution_note'] ?? '',
        $r['created_at'] ?? '',
        $r['resolved_at'] ?? '',
    ]);
    fclose($o); exit;
}

$fs=$_GET['status']??'all'; $ft=$_GET['type']??'all';
$w=[];$p=[];
if($fs!=='all'){$w[]='c.status=?';$p[]=$fs;}
if($ft!=='all'){$w[]='c.issue_type=?';$p[]=$ft;}
$sq="SELECT c.*,u.full_name an FROM complaints c LEFT JOIN users u ON u.id=c.assigned_to".($w?' WHERE '.implode(' AND ',$w):'')." ORDER BY c.created_at DESC LIMIT 200";
$st2=$pdo->prepare($sq);$st2->execute($p);$complaints=$st2->fetchAll();
$stats=$pdo->query("SELECT COUNT(*) tot,SUM(status='new') nw,SUM(status='in_progress') ip,SUM(status='resolved') res,SUM(issue_type='leak') lk,SUM(issue_type='pipe_burst') pb,SUM(issue_type='contamination') co FROM complaints")->fetch();

require_once __DIR__ . '/sidebar.php';
?>
<style>
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.7rem;margin-bottom:1.5rem}
.sc{background:var(--card);border:1px solid var(--border);border-radius:11px;padding:.85rem 1rem}
.sl{font-size:.63rem;color:var(--muted);text-transform:uppercase;margin-bottom:4px}
.sv{font-size:1.4rem;font-weight:800}
.cc{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:1.5rem}
.tb{width:100%;border-collapse:collapse;font-size:.79rem}
.tb th{padding:8px 11px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;background:rgba(255,255,255,.025)}
.tb td{padding:8px 11px;border-top:1px solid rgba(30,58,95,.4);vertical-align:top}
.bdg{padding:2px 8px;border-radius:5px;font-size:.63rem;font-weight:700}
.ftab{padding:5px 13px;border-radius:7px;font-size:.79rem;font-weight:600;border:1px solid var(--border);color:var(--muted);text-decoration:none}
.ftab.on{border-color:var(--blue);color:var(--blue);background:rgba(14,165,233,.07)}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">&#x1f4cb; Field Complaints</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:2px">Resident-submitted issues · manage status · export CSV</p>
  </div>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="complaints.php?view=public" target="_blank"
       style="padding:8px 16px;border:1px solid var(--border);border-radius:9px;color:var(--muted);font-size:.82rem;font-weight:600;text-decoration:none">
       Public Form Link
    </a>
    <a href="complaints.php?export=csv"
       style="padding:8px 16px;background:linear-gradient(135deg,var(--blue),var(--teal));border-radius:9px;color:#fff;font-size:.82rem;font-weight:700;text-decoration:none">
       Export CSV
    </a>
  </div>
</div>

<div class="sg">
<?php foreach([['Total',$stats['tot'],'#0ea5e9'],['New',$stats['nw'],'#fbbf24'],['In Progress',$stats['ip'],'#06b6d4'],['Resolved',$stats['res'],'#34d399'],['Leaks',$stats['lk'],'#f87171'],['Bursts',$stats['pb'],'#f87171'],['Contamination',$stats['co'],'#fb923c']] as[$l,$v,$c]):?>
  <div class="sc"><div class="sl"><?=$l?></div><div class="sv" style="color:<?=$c?>"><?=$v?></div></div>
<?php endforeach;?>
</div>

<div style="padding:12px 16px;background:rgba(14,165,233,.07);border:1px solid rgba(14,165,233,.2);border-radius:11px;margin-bottom:1.25rem;font-size:.84rem">
  Share with residents:
  <code style="background:rgba(255,255,255,.07);padding:2px 8px;border-radius:5px;margin-left:6px;color:var(--blue)">
    <?php $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']?'https':'http').'://'.$_SERVER['HTTP_HOST'].str_replace('\\','/',dirname($_SERVER['PHP_SELF']));?>
    <?=$base?>/complaints.php?view=public
  </code>
</div>

<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
  <span style="color:var(--muted);font-size:.79rem;align-self:center">Status:</span>
  <?php foreach(['all'=>'All','new'=>'New','acknowledged'=>'Ack','in_progress'=>'In Progress','resolved'=>'Resolved'] as $k=>$v):?>
    <a href="?status=<?=$k?>&type=<?=$ft?>" class="ftab <?=$fs===$k?'on':''?>"><?=$v?></a>
  <?php endforeach;?>
  <span style="color:var(--muted);font-size:.79rem;align-self:center;margin-left:.5rem">Type:</span>
  <?php foreach(['all'=>'All','leak'=>'Leak','no_water'=>'No Water','contamination'=>'Contamination','pipe_burst'=>'Burst'] as $k=>$v):?>
    <a href="?status=<?=$fs?>&type=<?=$k?>" class="ftab <?=$ft===$k?'on':''?>"><?=$v?></a>
  <?php endforeach;?>
</div>

<div class="cc"><div style="overflow-x:auto">
<table class="tb">
  <thead><tr><th>#</th><th>Date</th><th>Reporter</th><th>Zone</th><th>Issue</th><th>Description</th><th>GPS</th><th>Status</th><th>Update</th></tr></thead>
  <tbody>
  <?php foreach($complaints as $c):
    $ic=['leak'=>'#f87171','no_water'=>'#fbbf24','contamination'=>'#a78bfa','low_pressure'=>'#0ea5e9','meter_fault'=>'#7a9bba','pipe_burst'=>'#f87171','other'=>'#7a9bba'];
    $tc=$ic[$c['issue_type']]??'#7a9bba';
    $sm=['new'=>'#fbbf24','acknowledged'=>'#0ea5e9','in_progress'=>'#06b6d4','resolved'=>'#34d399','closed'=>'#7a9bba'];
    $sc=$sm[$c['status']]??'#7a9bba';?>
  <tr>
    <td style="color:var(--muted);font-size:.7rem">#<?=$c['id']?></td>
    <td style="color:var(--muted);white-space:nowrap;font-size:.7rem"><?=date('d M y H:i',strtotime($c['created_at']))?></td>
    <td><div style="font-weight:600"><?=htmlspecialchars($c['reporter_name'])?></div>
        <?php if($c['reporter_phone']):?><div style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($c['reporter_phone'])?></div><?php endif;?></td>
    <td style="white-space:nowrap;font-size:.8rem"><?=htmlspecialchars($c['zone_name'])?></td>
    <td><span class="bdg" style="background:<?=$tc?>22;color:<?=$tc?>;border:1px solid <?=$tc?>44"><?=strtoupper(str_replace('_',' ',$c['issue_type']))?></span></td>
    <td style="max-width:180px;color:var(--muted);font-size:.75rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($c['description'])?></td>
    <td><?php if($c['gps_lat']&&$c['gps_lng']):?><a href="https://maps.google.com/?q=<?=$c['gps_lat']?>,<?=$c['gps_lng']?>" target="_blank" style="font-size:.72rem;color:var(--blue)">Map</a><?php else:?><span style="color:var(--muted);font-size:.7rem">—</span><?php endif;?></td>
    <td><span class="bdg" style="background:<?=$sc?>22;color:<?=$sc?>;border:1px solid <?=$sc?>44"><?=strtoupper(str_replace('_',' ',$c['status']))?></span>
        <?php if($c['an']):?><div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($c['an'])?></div><?php endif;?></td>
    <td>
      <form method="post" style="display:flex;flex-direction:column;gap:4px;min-width:140px">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="cid" value="<?=$c['id']?>">
        <select name="status" style="padding:4px 7px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:.71rem">
          <?php foreach(['new','acknowledged','in_progress','resolved','closed'] as $s):?>
            <option value="<?=$s?>" <?=$c['status']===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
          <?php endforeach;?>
        </select>
        <input type="text" name="note" placeholder="Note" value="<?=htmlspecialchars($c['resolution_note']??'')?>"
               style="padding:4px 7px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:.7rem">
        <button type="submit" style="padding:4px 9px;border-radius:6px;font-size:.71rem;font-weight:600;cursor:pointer;border:1px solid var(--blue);background:transparent;color:var(--blue)">Update</button>
      </form>
    </td>
  </tr>
  <?php endforeach;?>
  <?php if(empty($complaints)):?>
  <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--muted)">
    No complaints found. Share the <a href="complaints.php?view=public" style="color:var(--blue)">public form</a> with residents.
  </td></tr>
  <?php endif;?>
  </tbody>
</table>
</div></div>
</main></body></html>