<?php
/*
 * alerts.php — System Alerts Management
 * View, create, and resolve alerts.
 */
session_start(); require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'alerts';
$page_title   = 'Alerts';

$total_alerts = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
$msg = ''; $msg_type = '';

// ── Resolve an alert ─────────────────────────────────────────
if (isset($_GET['resolve'])) {
    $pdo->prepare("UPDATE alerts SET is_resolved=1 WHERE id=?")->execute([(int)$_GET['resolve']]);
    header("Location: alerts.php?resolved=1"); exit;
}
if (isset($_GET['resolved'])) { $msg = "✅ Alert marked as resolved."; $msg_type = 'success'; }

// ── Delete an alert ──────────────────────────────────────────
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM alerts WHERE id=?")->execute([(int)$_GET['delete']]);
    header("Location: alerts.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) { $msg = "🗑️ Alert deleted."; $msg_type = 'success'; }

// ── Add new alert ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_alert') {
    $zone_id    = (int)($_POST['zone_id']    ?? 0) ?: null;
    $alert_type = trim($_POST['alert_type']  ?? '');
    $message    = trim($_POST['message']     ?? '');
    $severity   = $_POST['severity'] ?? 'medium';
    if ($alert_type && $message) {
        $pdo->prepare("INSERT INTO alerts (zone_id,alert_type,message,severity) VALUES (?,?,?,?)")
            ->execute([$zone_id,$alert_type,$message,$severity]);
        $msg = "✅ Alert created."; $msg_type = 'success';
    } else { $msg = "Alert type and message are required."; $msg_type = 'error'; }
}

// ── Fetch all alerts ─────────────────────────────────────────
$filter = $_GET['filter'] ?? 'open';
$where  = $filter==='all' ? '' : ($filter==='resolved' ? 'WHERE a.is_resolved=1' : 'WHERE a.is_resolved=0');
$alerts = $pdo->query("
    SELECT a.*, wz.zone_name FROM alerts a
    LEFT JOIN water_zones wz ON wz.id=a.zone_id
    $where ORDER BY a.created_at DESC
")->fetchAll();

$zones = $pdo->query("SELECT id,zone_name FROM water_zones ORDER BY zone_name")->fetchAll();

// Count stats
$all_count      = $pdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$open_count     = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
$high_count     = $pdo->query("SELECT COUNT(*) FROM alerts WHERE severity='high' AND is_resolved=0")->fetchColumn();
$resolved_count = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=1")->fetchColumn();

if (file_exists(__DIR__ . '/includes/sidebar.php')) {
    require_once __DIR__ . '/includes/sidebar.php';
} else {
    require_once __DIR__ . '/sidebar.php';
}
?>

<?php if ($msg): ?>
    <div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon">🚨</div><div class="stat-label">Total Alerts</div><div class="stat-value c-blue"><?= $all_count ?></div></div>
    <div class="stat-card"><div class="stat-icon">🔴</div><div class="stat-label">Open Alerts</div><div class="stat-value c-red"><?= $open_count ?></div></div>
    <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-label">High Severity</div><div class="stat-value c-yellow"><?= $high_count ?></div></div>
    <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-label">Resolved</div><div class="stat-value c-green"><?= $resolved_count ?></div></div>
</div>

<!-- Filter tabs + Add button -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;gap:6px;">
        <?php foreach(['open'=>'Open','resolved'=>'Resolved','all'=>'All'] as $f=>$label): ?>
        <a href="?filter=<?= $f ?>" style="padding:6px 14px;border-radius:8px;font-size:0.82rem;text-decoration:none;
            background:<?= $filter===$f ? 'var(--blue)':'var(--card)' ?>;
            color:<?= $filter===$f ? '#fff':'var(--muted)' ?>;
            border:1px solid <?= $filter===$f ? 'var(--blue)':'var(--border)' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
    <button class="btn-primary btn-sm" onclick="document.getElementById('addModal').classList.add('open')">+ New Alert</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Zone</th><th>Type</th><th>Message</th><th>Severity</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($alerts)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="icon">✅</div>No alerts <?= $filter==='open'?'— system running normally.':($filter==='resolved'?'resolved yet.':'recorded.') ?></div></td></tr>
            <?php else: ?>
                <?php
                $highlight_id = (int)($_GET['highlight'] ?? 0);
                foreach ($alerts as $a):
                    $is_hl = ($highlight_id && $a['id'] == $highlight_id);
                    $hl_style = $is_hl ? 'background:rgba(248,113,113,.12);outline:2px solid rgba(248,113,113,.5);border-radius:6px;' : '';
                ?>
                <tr id="alert-<?= $a['id'] ?>" style="<?= $hl_style ?>">
                    <td><?= htmlspecialchars($a['zone_name']??'—') ?></td>
                    <td style="font-weight:500"><?= htmlspecialchars($a['alert_type']) ?></td>
                    <td style="color:var(--muted);font-size:0.82rem;max-width:220px"><?= htmlspecialchars($a['message']) ?></td>
                    <td><span class="badge badge-<?= $a['severity'] ?>"><?= ucfirst($a['severity']) ?></span></td>
                    <td><span class="badge <?= $a['is_resolved']?'badge-resolved':'badge-open' ?>"><?= $a['is_resolved']?'Resolved':'Open' ?></span></td>
                    <td style="color:var(--muted);font-size:0.8rem"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                    <td style="white-space:nowrap">
                        <?php if (!$a['is_resolved']): ?>
                            <a href="?resolve=<?= $a['id'] ?>&filter=<?= $filter ?>" style="color:var(--green);text-decoration:none;font-size:0.8rem;margin-right:10px"
                               title="Mark resolved">✅ Resolve</a>
                        <?php endif; ?>
                        <a href="?delete=<?= $a['id'] ?>&filter=<?= $filter ?>"
                           onclick="return confirm('Delete this alert?')"
                           style="color:var(--red);text-decoration:none;font-size:0.8rem">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD ALERT MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title">🚨 Create New Alert</div>
        <form method="post" action="alerts.php">
            <input type="hidden" name="action" value="add_alert">
            <div class="form-group">
                <label class="form-label">Zone (optional)</label>
                <select name="zone_id" class="form-control">
                    <option value="">— General / No specific zone —</option>
                    <?php foreach($zones as $z): ?>
                        <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['zone_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Alert Type *</label>
                <input type="text" name="alert_type" class="form-control" placeholder="e.g. Leak Detected, Low Pressure" required>
            </div>
            <div class="form-group">
                <label class="form-label">Message *</label>
                <textarea name="message" class="form-control" rows="3" placeholder="Describe the alert..." required style="resize:vertical"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Severity</label>
                <select name="severity" class="form-control">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn-primary">Create Alert</button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-scroll to highlighted alert when coming from banner link
const hlId = new URLSearchParams(location.search).get('highlight');
if (hlId) {
    const el = document.getElementById('alert-' + hlId);
    if (el) {
        setTimeout(() => {
            el.scrollIntoView({behavior:'smooth', block:'center'});
        }, 300);
    }
}
</script>
</main></body></html>