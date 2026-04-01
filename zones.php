<?php
/*
 * zones.php — Water Distribution Zones
 * Shows all zones, lets you add/edit/delete zones.
 */
session_start(); require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'zones';
$page_title   = 'Zone Map';

// Count alerts for sidebar badge
$total_alerts = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

$msg = ''; $msg_type = '';

// ── Handle ADD zone ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')===('add_zone')) {
    $zone_name = trim($_POST['zone_name'] ?? '');
    $location  = trim($_POST['location']  ?? '');
    $status    = $_POST['status'] ?? 'active';
    if ($zone_name) {
        $pdo->prepare("INSERT INTO water_zones (zone_name,location,status) VALUES (?,?,?)")
            ->execute([$zone_name,$location,$status]);
        $msg = "✅ Zone '$zone_name' added successfully."; $msg_type = 'success';
    } else { $msg = "Zone name is required."; $msg_type = 'error'; }
}

// ── Handle DELETE zone ───────────────────────────────────────
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM water_zones WHERE id=?")->execute([(int)$_GET['delete']]);
    header("Location: zones.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) { $msg = "🗑️ Zone deleted."; $msg_type = 'success'; }

// ── Fetch all zones with latest reading ─────────────────────
$zones = $pdo->query("
    SELECT wz.*,
        sr.flow_rate, sr.pressure, sr.water_level, sr.temperature, sr.recorded_at
    FROM water_zones wz
    LEFT JOIN sensor_readings sr ON sr.id=(
        SELECT id FROM sensor_readings WHERE zone_id=wz.id ORDER BY recorded_at DESC LIMIT 1
    )
    ORDER BY wz.id ASC
")->fetchAll();

require_once __DIR__ . '/sidebar.php';
?>

<?php if ($msg): ?>
    <div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="stats-grid">
    <?php
    $counts = ['active'=>0,'inactive'=>0,'maintenance'=>0];
    foreach($zones as $z) $counts[$z['status']] = ($counts[$z['status']]??0)+1;
    ?>
    <div class="stat-card"><div class="stat-icon">🗺️</div><div class="stat-label">Total Zones</div><div class="stat-value c-blue"><?= count($zones) ?></div></div>
    <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-label">Active</div><div class="stat-value c-green"><?= $counts['active'] ?></div></div>
    <div class="stat-card"><div class="stat-icon">🔧</div><div class="stat-label">Maintenance</div><div class="stat-value c-yellow"><?= $counts['maintenance'] ?></div></div>
    <div class="stat-card"><div class="stat-icon">⛔</div><div class="stat-label">Inactive</div><div class="stat-value c-muted"><?= $counts['inactive'] ?></div></div>
</div>

<!-- Add Zone button -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div class="section-title" style="margin-bottom:0;">🗺️ All Distribution Zones</div>
    <button class="btn-primary btn-sm" onclick="document.getElementById('addModal').classList.add('open')">+ Add Zone</button>
</div>

<!-- Zones cards grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:2rem;">
    <?php foreach ($zones as $z): ?>
    <div class="card" style="border-top:3px solid <?= $z['status']==='active'?'var(--teal)':($z['status']==='maintenance'?'var(--yellow)':'var(--muted)') ?>">
        <div class="card-header">
            <div>
                <div style="font-family:'Syne',sans-serif;font-weight:700;"><?= htmlspecialchars($z['zone_name']) ?></div>
                <div style="font-size:0.75rem;color:var(--muted)">📍 <?= htmlspecialchars($z['location']) ?></div>
            </div>
            <span class="badge badge-<?= $z['status'] ?>"><?= ucfirst($z['status']) ?></span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1rem;">
                <div><div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Flow Rate</div>
                    <div style="font-size:1rem;font-weight:600"><?= $z['flow_rate']!==null?$z['flow_rate'].' L/min':'—' ?></div></div>
                <div><div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Pressure</div>
                    <div style="font-size:1rem;font-weight:600"><?= $z['pressure']!==null?$z['pressure'].' Bar':'—' ?></div></div>
                <div><div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Temperature</div>
                    <div style="font-size:1rem;font-weight:600"><?= $z['temperature']!==null?$z['temperature'].' °C':'—' ?></div></div>
                <div><div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Water Level</div>
                    <div style="font-size:1rem;font-weight:600"><?= $z['water_level']!==null?$z['water_level'].'%':'—' ?></div></div>
            </div>
            <?php $lvl=(float)($z['water_level']??0); $bc=$lvl<30?'low':($lvl<60?'medium':''); ?>
            <div class="bar-track"><div class="bar-fill <?= $bc ?>" style="width:<?= $lvl ?>%"></div></div>
            <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:var(--muted);margin-top:4px;">
                <span>Water Level</span><span><?= $z['water_level']??'N/A' ?>%</span>
            </div>
            <div style="margin-top:1rem;display:flex;justify-content:flex-end;gap:8px;">
                <a href="zones.php?delete=<?= $z['id'] ?>"
                   onclick="return confirm('Delete this zone?')"
                   class="btn-danger btn-sm">🗑️ Delete</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ADD ZONE MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title">➕ Add New Zone</div>
        <form method="post" action="zones.php">
            <input type="hidden" name="action" value="add_zone">
            <div class="form-group">
                <label class="form-label">Zone Name *</label>
                <input type="text" name="zone_name" class="form-control" placeholder="e.g. Zone E - Industrial" required>
            </div>
            <div class="form-group">
                <label class="form-label">Location / Area</label>
                <input type="text" name="location" class="form-control" placeholder="e.g. Industrial Area, Meru">
            </div>
            <div class="form-group">
                <label class="form-label">Initial Status</label>
                <select name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn-primary">Add Zone</button>
            </div>
        </form>
    </div>
</div>

</main></body></html>