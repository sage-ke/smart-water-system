<?php
/*
 * hardware.php — SWDS Meru
 * Hardware Device Management — admin / operator only
 * ============================================================
 *  - View all IoT devices (sensor nodes, valves, pumps, gateways)
 *  - Register new devices (auto-generates API key for firmware)
 *  - Send commands: set_valve, set_pump, reboot, calibrate
 *  - Command log with status tracking
 *  - Auto-marks devices offline after 10 minutes no heartbeat
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
$current_page = 'hardware';
$page_title   = 'Hardware Devices';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

$msg = ''; $err = '';

// ── Send a command ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_command') {
    $device_id = (int)($_POST['device_id'] ?? 0);
    $cmd_type  = $_POST['cmd_type'] ?? '';
    $valve_pct = (int)($_POST['valve_pct'] ?? 100);
    $pump_on   = (int)($_POST['pump_on']   ?? 0);

    $payload = match($cmd_type) {
        'set_valve'  => json_encode(['valve_pct' => $valve_pct]),
        'set_pump'   => json_encode(['pump_on'   => $pump_on]),
        'reboot'     => json_encode(['confirm'   => true]),
        'calibrate'  => json_encode(['sensor'    => 'all']),
        default      => '{}'
    };

    if ($device_id && $cmd_type) {
        $pdo->prepare("INSERT INTO device_commands (device_id,command_type,payload,issued_by) VALUES (?,?,?,?)")
            ->execute([$device_id, $cmd_type, $payload, $user_id]);
        $msg = "Command queued. Device will receive it on next poll.";
    }
}

// ── Add device ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_device') {
    $zone_id  = (int)($_POST['zone_id']   ?? 0) ?: null;
    $name     = trim($_POST['device_name'] ?? '');
    $type     = $_POST['device_type']      ?? 'sensor_node';
    $code     = strtoupper(trim($_POST['device_code'] ?? ''));
    $api_key  = bin2hex(random_bytes(32));

    if ($name && $code) {
        try {
            $pdo->prepare("INSERT INTO hardware_devices (zone_id,device_name,device_type,device_code,api_key) VALUES (?,?,?,?,?)")
                ->execute([$zone_id, $name, $type, $code, $api_key]);
            $msg = "Device registered. API Key: <code style='color:#06b6d4;word-break:break-all'>$api_key</code> — save this now, it won't be shown again.";
        } catch (\PDOException $e) {
            $err = "Device code already exists. Please use a unique code.";
        }
    } else { $err = 'Device name and code are required.'; }
}

// ── Delete device ─────────────────────────────────────────────
if (isset($_GET['delete']) && $user_role === 'admin') {
    $pdo->prepare("DELETE FROM hardware_devices WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: hardware.php?deleted=1'); exit;
}
if (isset($_GET['deleted'])) $msg = 'Device removed.';

// ── Mark stale devices offline ────────────────────────────────
$pdo->exec("UPDATE hardware_devices SET is_online=0
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               OR last_seen IS NULL");

// ── Fetch all devices ─────────────────────────────────────────
$devices = $pdo->query("
    SELECT hd.*, wz.zone_name,
        (SELECT COUNT(*) FROM device_commands WHERE device_id=hd.id AND status='pending') AS pending_cmds,
        (SELECT COUNT(*) FROM sensor_readings  WHERE device_id=hd.id AND recorded_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)) AS readings_24h
    FROM hardware_devices hd
    LEFT JOIN water_zones wz ON wz.id=hd.zone_id
    ORDER BY hd.is_online DESC, hd.zone_id ASC
")->fetchAll();

$cmd_log = $pdo->query("
    SELECT dc.*, hd.device_name, hd.device_code, u.full_name AS issued_by_name
    FROM device_commands dc
    LEFT JOIN hardware_devices hd ON hd.id=dc.device_id
    LEFT JOIN users u ON u.id=dc.issued_by
    ORDER BY dc.issued_at DESC LIMIT 30
")->fetchAll();

$zones = $pdo->query("SELECT id,zone_name FROM water_zones ORDER BY zone_name")->fetchAll();

$online_count  = count(array_filter($devices, fn($d) => $d['is_online']));
$offline_count = count($devices) - $online_count;
$pending_total = array_sum(array_column($devices, 'pending_cmds'));

require_once __DIR__ . '/sidebar.php';
?>
<style>
.hw-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem}
.hw-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.9rem 1.1rem}
.hw-lbl{font-size:.63rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
.hw-val{font-size:1.5rem;font-weight:800}
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{padding:8px 10px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;background:rgba(255,255,255,.02);white-space:nowrap}
.tbl td{padding:8px 10px;border-top:1px solid rgba(30,58,95,.4);vertical-align:middle}
.tbl tr:hover td{background:rgba(255,255,255,.015)}
.pil{padding:2px 8px;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.p-online {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}
.p-offline{background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.25)}
.p-pending{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.p-sent   {background:rgba(14,165,233,.12);color:#0ea5e9;border:1px solid rgba(14,165,233,.25)}
.p-ack    {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}
.p-failed {background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.p-type   {background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid rgba(167,139,250,.25)}
.bat-track{width:52px;height:6px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden;display:inline-block;vertical-align:middle;margin-right:4px}
.bat-fill {height:100%;border-radius:99px}
.sec{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;margin:1.5rem 0 .85rem;display:flex;align-items:center;gap:8px}
.sec::after{content:'';flex:1;height:1px;background:var(--border)}
.add-btn{padding:9px 20px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;border-radius:9px;color:#fff;font-size:.85rem;font-weight:700;cursor:pointer}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#0f2040;border:1px solid var(--border);border-radius:16px;padding:1.5rem;width:100%;max-width:480px;max-height:90vh;overflow-y:auto}
label{font-size:.75rem;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.fi{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 11px;font-size:.88rem;font-family:'DM Sans',sans-serif;margin-bottom:.85rem}
.fi:focus{outline:none;border-color:var(--blue)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.online-dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex-shrink:0}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">📡 Hardware Devices</h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:3px">IoT sensors · Valve controllers · Pump controllers · Command log</p>
  </div>
  <button class="add-btn" onclick="document.getElementById('addModal').classList.add('open')">+ Register Device</button>
</div>

<?php if($msg):?><div style="padding:11px 16px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--green);border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">✓ <?=$msg?></div><?php endif;?>
<?php if($err):?><div style="padding:11px 16px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;border-radius:10px;margin-bottom:1.25rem;font-size:.85rem">⚠️ <?=htmlspecialchars($err)?></div><?php endif;?>

<!-- KPI cards -->
<div class="hw-kpi">
<?php $kc=[
  ['Total Devices', count($devices),  '#0ea5e9'],
  ['Online',        $online_count,    '#34d399'],
  ['Offline',       $offline_count,   '#f87171'],
  ['Pending Cmds',  $pending_total,   '#fbbf24'],
  ['Zones Covered', count(array_unique(array_filter(array_column($devices,'zone_id')))), '#a78bfa'],
];
foreach($kc as[$l,$v,$c]):?>
<div class="hw-card"><div class="hw-lbl"><?=$l?></div><div class="hw-val" style="color:<?=$c?>"><?=$v?></div></div>
<?php endforeach;?>
</div>

<!-- Devices table -->
<div class="sec">📡 All Registered Devices</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:1.5rem">
<div style="overflow-x:auto">
<table class="tbl">
  <thead><tr>
    <th>Status</th><th>Device</th><th>Zone</th><th>Type</th>
    <th>Battery</th><th>Signal</th><th>Last Seen</th><th>Readings 24h</th><th>Cmds</th><th>Actions</th>
  </tr></thead>
  <tbody>
  <?php if(empty($devices)):?>
  <tr><td colspan="10" style="text-align:center;padding:2.5rem;color:var(--muted)">
    No devices registered yet. Click + Register Device to add your first IoT device.
  </td></tr>
  <?php endif;?>
  <?php foreach($devices as $d):
    $bat=(int)$d['battery_pct'];
    $bat_c=$bat<20?'#f87171':($bat<50?'#fbbf24':'#34d399');
    $sig=(int)($d['signal_strength']??0);
    $sig_c=$sig<-80?'var(--red)':($sig<-65?'var(--yellow)':'var(--green)');
  ?>
  <tr>
    <td>
      <div style="display:flex;align-items:center;gap:6px">
        <span class="online-dot" style="background:<?=$d['is_online']?'#34d399':'#f87171'?>;box-shadow:0 0 5px <?=$d['is_online']?'#34d399':'#f87171'?>"></span>
        <span class="pil <?=$d['is_online']?'p-online':'p-offline'?>"><?=$d['is_online']?'Online':'Offline'?></span>
      </div>
    </td>
    <td>
      <div style="font-weight:600"><?=htmlspecialchars($d['device_name']??'—')?></div>
      <div style="font-size:.7rem;color:var(--muted);font-family:monospace"><?=htmlspecialchars($d['device_code']??'')?></div>
      <?php if(!empty($d['firmware_version'])):?><div style="font-size:.68rem;color:var(--muted)">FW: <?=htmlspecialchars($d['firmware_version'])?></div><?php endif;?>
    </td>
    <td style="font-size:.82rem"><?=htmlspecialchars($d['zone_name']??'—')?></td>
    <td><span class="pil p-type"><?=htmlspecialchars(str_replace('_',' ',$d['device_type']??''))?></span></td>
    <td>
      <div style="display:flex;align-items:center">
        <span class="bat-track"><span class="bat-fill" style="width:<?=$bat?>%;background:<?=$bat_c?>"></span></span>
        <span style="font-size:.78rem;color:<?=$bat_c?>"><?=$bat?>%</span>
      </div>
    </td>
    <td style="font-size:.8rem;color:<?=$sig_c?>"><?=$sig!==0?$sig.' dBm':'—'?></td>
    <td style="font-size:.75rem;color:var(--muted);white-space:nowrap">
      <?=$d['last_seen']?date('d M H:i',strtotime($d['last_seen'])):'Never'?>
    </td>
    <td style="text-align:center;font-size:.82rem;color:var(--blue)"><?=$d['readings_24h']?></td>
    <td>
      <?php if((int)$d['pending_cmds']>0):?>
        <span class="pil p-pending"><?=$d['pending_cmds']?> pending</span>
      <?php else:?>
        <span style="color:var(--muted);font-size:.72rem">—</span>
      <?php endif;?>
    </td>
    <td style="white-space:nowrap">
      <button onclick="openCmd(<?=$d['id']?>,'<?=htmlspecialchars(addslashes($d['device_name']??''))?>')"
              style="padding:4px 10px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:var(--blue);border-radius:6px;cursor:pointer;font-size:.72rem;font-weight:600">
        ⚡ Command
      </button>
      <?php if($user_role==='admin'):?>
      <a href="?delete=<?=$d['id']?>" onclick="return confirm('Remove this device?')"
         style="color:var(--red);text-decoration:none;font-size:.8rem;margin-left:6px">🗑</a>
      <?php endif;?>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>
</div>

<!-- Command log -->
<div class="sec">📬 Recent Command Log</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem">
<div style="overflow-x:auto">
<table class="tbl">
  <thead><tr><th>Device</th><th>Command</th><th>Payload</th><th>Status</th><th>Issued By</th><th>Time</th></tr></thead>
  <tbody>
  <?php if(empty($cmd_log)):?>
  <tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--muted)">No commands sent yet.</td></tr>
  <?php endif;?>
  <?php foreach($cmd_log as $c):
    $cs_map=['pending'=>'p-pending','sent'=>'p-sent','acknowledged'=>'p-ack','failed'=>'p-failed'];?>
  <tr>
    <td>
      <div style="font-size:.82rem;font-weight:600"><?=htmlspecialchars($c['device_name']??'—')?></div>
      <div style="font-size:.7rem;color:var(--muted);font-family:monospace"><?=htmlspecialchars($c['device_code']??'')?></div>
    </td>
    <td style="font-weight:600;font-size:.82rem"><?=htmlspecialchars(str_replace('_',' ',ucfirst($c['command_type'])))?></td>
    <td style="font-family:monospace;font-size:.75rem;color:var(--muted)"><?=htmlspecialchars($c['payload']??'{}')?></td>
    <td><span class="pil <?=$cs_map[$c['status']]??'p-pending'?>"><?=ucfirst($c['status'])?></span></td>
    <td style="font-size:.8rem;color:var(--muted)"><?=htmlspecialchars($c['issued_by_name']??'System')?></td>
    <td style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?=date('d M H:i',strtotime($c['issued_at']))?></td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>
</div>

<!-- SEND COMMAND MODAL -->
<div class="modal-overlay" id="cmdModal">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:1.1rem" id="cmdTitle">⚡ Send Command</h3>
    <form method="post" action="hardware.php">
      <input type="hidden" name="action" value="send_command">
      <input type="hidden" name="device_id" id="cmd_dev_id">
      <label>Command Type</label>
      <select name="cmd_type" class="fi" id="cmd_type_sel" onchange="toggleCmdFields()">
        <option value="set_valve">Set Valve Position</option>
        <option value="set_pump">Set Pump On/Off</option>
        <option value="reboot">Reboot Device</option>
        <option value="calibrate">Calibrate Sensors</option>
      </select>
      <div id="valve_field">
        <label>Valve Open % (0=closed · 100=fully open)</label>
        <input type="number" name="valve_pct" class="fi" min="0" max="100" value="100">
      </div>
      <div id="pump_field" style="display:none">
        <label>Pump State</label>
        <select name="pump_on" class="fi">
          <option value="1">Turn ON</option>
          <option value="0">Turn OFF</option>
        </select>
      </div>
      <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;padding:10px 12px;font-size:.78rem;color:var(--yellow);margin-bottom:1rem">
        ⚠ The device will receive this command on its next poll cycle.
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('cmdModal').classList.remove('open')"
                style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 16px;border-radius:8px;cursor:pointer">Cancel</button>
        <button type="submit" class="add-btn">Send Command</button>
      </div>
    </form>
  </div>
</div>

<!-- REGISTER DEVICE MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <h3 style="font-family:'Syne',sans-serif;margin-bottom:1.1rem">📡 Register New Device</h3>
    <form method="post" action="hardware.php">
      <input type="hidden" name="action" value="add_device">
      <label>Device Name *</label>
      <input type="text" name="device_name" class="fi" placeholder="e.g. Zone A Flow Sensor" required>
      <label>Device Code * (unique ID for firmware)</label>
      <input type="text" name="device_code" class="fi" placeholder="e.g. SWDS-Z1-SN02" required>
      <div class="form-row">
        <div>
          <label>Zone</label>
          <select name="zone_id" class="fi">
            <option value="">— None —</option>
            <?php foreach($zones as $z):?><option value="<?=$z['id']?>"><?=htmlspecialchars($z['zone_name'])?></option><?php endforeach;?>
          </select>
        </div>
        <div>
          <label>Device Type</label>
          <select name="device_type" class="fi">
            <option value="sensor_node">Sensor Node</option>
            <option value="valve_controller">Valve Controller</option>
            <option value="pump_controller">Pump Controller</option>
            <option value="gateway">Gateway</option>
          </select>
        </div>
      </div>
      <div style="background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.2);border-radius:8px;padding:10px 12px;font-size:.78rem;color:var(--blue);margin-bottom:1rem">
        💡 An API key will be auto-generated. Program it into your device firmware so it can securely POST sensor data to <code>api/ingest.php</code>.
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('addModal').classList.remove('open')"
                style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 16px;border-radius:8px;cursor:pointer">Cancel</button>
        <button type="submit" class="add-btn">Register Device</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCmd(id, name) {
    document.getElementById('cmd_dev_id').value = id;
    document.getElementById('cmdTitle').textContent = '⚡ Command: ' + name;
    document.getElementById('cmdModal').classList.add('open');
}
function toggleCmdFields() {
    const t = document.getElementById('cmd_type_sel').value;
    document.getElementById('valve_field').style.display = t === 'set_valve' ? 'block' : 'none';
    document.getElementById('pump_field').style.display  = t === 'set_pump'  ? 'block' : 'none';
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
</script>
</main></body></html>