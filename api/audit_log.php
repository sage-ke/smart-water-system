<?php
// ================================================================
//  audit_log.php  ·  SWDS Meru  v3
//  ----------------------------------------------------------------
//  Standalone audit log viewer.  Admin / operator only.
//
//  FEATURES:
//    · Filter by: action category, user, result, date range
//    · Full-text search across action, entity_label, user_name, reason
//    · Paginated (50 rows/page)
//    · CSV export of current filter
//    · Color-coded by result (ok / denied / error)
//    · Valve actions have "Before → After" value diff shown inline
//    · Auto-refreshes newest entries every 30 s without page reload
// ================================================================

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api/auth.php';

// ── Auth + RBAC ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role      = $_SESSION['user_role'] ?? 'viewer';
$user_name = $_SESSION['user_name'] ?? '';
$user_id   = (int)$_SESSION['user_id'];

if (!can_do($pdo, $role, 'audit.view')) {
    header('Location: dashboard.php'); exit;
}

// ── CSV export (before any output) ────────────────────────────
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Filter params ─────────────────────────────────────────────
$f_category = trim($_GET['cat']    ?? '');        // valve|auth|alerts|settings
$f_result   = trim($_GET['result'] ?? '');        // ok|denied|error
$f_user     = trim($_GET['user']   ?? '');        // partial match
$f_from     = trim($_GET['from']   ?? '');        // date YYYY-MM-DD
$f_to       = trim($_GET['to']     ?? '');
$f_search   = trim($_GET['q']      ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = $export_csv ? 9999 : 50;

// ── Build WHERE clause ─────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($f_category) {
    $where[]  = 'al.action LIKE ?';
    $params[] = $f_category . '.%';
}
if ($f_result) {
    $where[]  = 'al.result = ?';
    $params[] = $f_result;
}
if ($f_user) {
    $where[]  = 'al.user_name LIKE ?';
    $params[] = '%' . $f_user . '%';
}
if ($f_from) {
    $where[]  = 'DATE(al.created_at) >= ?';
    $params[] = $f_from;
}
if ($f_to) {
    $where[]  = 'DATE(al.created_at) <= ?';
    $params[] = $f_to;
}
if ($f_search) {
    $where[]  = '(al.action LIKE ? OR al.entity_label LIKE ? OR al.user_name LIKE ? OR al.reason LIKE ? OR al.detail LIKE ?)';
    $s = '%' . $f_search . '%';
    array_push($params, $s, $s, $s, $s, $s);
}

$where_sql = implode(' AND ', $where);

// ── Total count ───────────────────────────────────────────────
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log al WHERE $where_sql");
$count_stmt->execute($params);
$total      = (int)$count_stmt->fetchColumn();
$total_pages= $export_csv ? 1 : (int)ceil($total / $per_page);
$offset     = ($page - 1) * $per_page;

// ── Fetch rows ────────────────────────────────────────────────
$rows_stmt = $pdo->prepare("
    SELECT al.id, al.created_at, al.action, al.result,
           al.user_name, al.user_role, al.user_id,
           al.entity_type, al.entity_label,
           al.old_value, al.new_value,
           al.reason, al.detail,
           al.ip_address
    FROM   audit_log al
    WHERE  $where_sql
    ORDER  BY al.created_at DESC
    LIMIT  $per_page OFFSET $offset
");
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── CSV export ────────────────────────────────────────────────
if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Timestamp','Action','Result','User','Role',
                   'Entity Type','Entity','Old Value','New Value','Reason','IP']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['action'], $r['result'],
            $r['user_name'], $r['user_role'],
            $r['entity_type'], $r['entity_label'],
            $r['old_value'], $r['new_value'],
            $r['reason'], $r['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Distinct users for filter dropdown ─────────────────────────
$all_users = $pdo->query(
    "SELECT DISTINCT user_name FROM audit_log WHERE user_name IS NOT NULL ORDER BY user_name"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Stats summary ─────────────────────────────────────────────
$stats = $pdo->query("
    SELECT
        SUM(result='ok')     AS ok_count,
        SUM(result='denied') AS denied_count,
        SUM(result='error')  AS error_count,
        SUM(action LIKE 'valve.%') AS valve_count,
        SUM(action LIKE 'auth.%')  AS auth_count,
        COUNT(*) AS total
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
")->fetch();

// ── Query string builder for pagination links ──────────────────
function qs(array $override = []): string {
    $p = array_merge([
        'cat'    => $_GET['cat']    ?? '',
        'result' => $_GET['result'] ?? '',
        'user'   => $_GET['user']   ?? '',
        'from'   => $_GET['from']   ?? '',
        'to'     => $_GET['to']     ?? '',
        'q'      => $_GET['q']      ?? '',
        'page'   => $_GET['page']   ?? 1,
    ], $override);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Audit Log · SWDS Meru</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080d14;--card:#0f1c2e;--card2:#0d1929;--card3:#132038;
  --b1:#1a2e48;--b2:#1f3655;
  --cyan:#00d4ff;--green:#00ff87;--yellow:#ffcc00;--red:#ff4757;--purple:#a78bfa;
  --text:#ddeeff;--t2:#8ba5c0;--t3:#3d5470;
  --mono:'IBM Plex Mono',monospace;--sans:'Outfit',sans-serif;
}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;padding:1.25rem 1.25rem 4rem}

/* ── Topbar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem}
.topbar h1{font-size:1.15rem;font-weight:800;display:flex;align-items:center;gap:10px}
.topbar-right{display:flex;gap:.6rem;align-items:center}
.back{font-size:.75rem;color:var(--t3);text-decoration:none;padding:5px 12px;border:1px solid var(--b1);border-radius:7px;transition:.15s}
.back:hover{color:var(--cyan);border-color:var(--cyan)}
.export-btn{font-size:.72rem;color:var(--green);text-decoration:none;padding:5px 12px;border:1px solid rgba(0,255,135,.3);border-radius:7px;transition:.15s}
.export-btn:hover{background:rgba(0,255,135,.08)}

/* ── Stats row ── */
.stats-row{display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap}
.stat-card{background:var(--card);border:1px solid var(--b1);border-radius:11px;padding:.7rem 1.1rem;flex:1;min-width:120px}
.stat-label{font-family:var(--mono);font-size:.52rem;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:4px}
.stat-val{font-family:var(--mono);font-size:1.35rem;font-weight:700}

/* ── Filters ── */
.filter-bar{background:var(--card);border:1px solid var(--b1);border-radius:12px;
            padding:1rem 1.1rem;margin-bottom:1.25rem}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.65rem}
.filter-grid select,.filter-grid input{
  padding:7px 10px;background:var(--card2);border:1px solid var(--b1);
  border-radius:8px;color:var(--text);font-family:var(--sans);font-size:.8rem;width:100%;
}
.filter-grid select:focus,.filter-grid input:focus{outline:none;border-color:var(--cyan)}
.filter-grid option{background:var(--card2)}
.filter-actions{display:flex;gap:.5rem;margin-top:.7rem}
.fbtn{padding:7px 16px;border-radius:8px;font-size:.78rem;font-weight:700;
      cursor:pointer;border:1px solid;font-family:var(--sans);transition:.15s}
.fbtn-apply{background:rgba(0,212,255,.12);border-color:rgba(0,212,255,.3);color:var(--cyan)}
.fbtn-apply:hover{background:rgba(0,212,255,.2)}
.fbtn-reset{background:transparent;border-color:var(--b1);color:var(--t3)}
.fbtn-reset:hover{border-color:var(--t2);color:var(--t2)}

/* ── Section head ── */
.sec-head{font-family:var(--mono);font-size:.56rem;text-transform:uppercase;letter-spacing:.14em;
          color:var(--t3);display:flex;align-items:center;gap:8px;margin-bottom:.85rem}
.sec-head::after{content:'';flex:1;height:1px;background:var(--b1)}
.sec-head .count{color:var(--cyan);margin-left:4px}

/* ── Table ── */
.tbl-wrap{overflow-x:auto;border:1px solid var(--b1);border-radius:12px}
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:7px 10px;text-align:left;font-family:var(--mono);font-size:.52rem;
        text-transform:uppercase;letter-spacing:.08em;color:var(--t3);
        background:var(--card2);border-bottom:1px solid var(--b1);white-space:nowrap}
.tbl th:first-child{border-radius:12px 0 0 0}
.tbl th:last-child{border-radius:0 12px 0 0}
.tbl td{padding:7px 10px;font-size:.76rem;border-bottom:1px solid rgba(26,46,72,.4);vertical-align:top}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:rgba(0,212,255,.025)}
.mono{font-family:var(--mono)}
.truncate{max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.trunc-sm{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Result badges ── */
.rbadge{display:inline-block;padding:2px 8px;border-radius:5px;font-family:var(--mono);font-size:.58rem;font-weight:700}
.r-ok    {background:rgba(0,255,135,.1);color:var(--green);border:1px solid rgba(0,255,135,.2)}
.r-denied{background:rgba(255,204,0,.1);color:var(--yellow);border:1px solid rgba(255,204,0,.2)}
.r-error {background:rgba(255,71,87,.1);color:var(--red);border:1px solid rgba(255,71,87,.2)}

/* ── Action category colors ── */
.ac-valve  {color:var(--cyan)}
.ac-auth   {color:var(--purple)}
.ac-alerts {color:var(--yellow)}
.ac-settings{color:var(--t2)}
.ac-other  {color:var(--t3)}

/* ── Diff viewer (old→new) ── */
.diff-wrap{font-family:var(--mono);font-size:.6rem;line-height:1.5}
.diff-key{color:var(--t3)}
.diff-old{color:var(--red);text-decoration:line-through}
.diff-new{color:var(--green)}
.diff-toggle{cursor:pointer;color:var(--t3);font-size:.58rem;text-decoration:underline dotted}
.diff-toggle:hover{color:var(--cyan)}

/* ── Pagination ── */
.pager{display:flex;gap:.4rem;justify-content:center;margin-top:1.25rem;flex-wrap:wrap}
.pager a,.pager span{
  padding:5px 11px;border-radius:7px;font-family:var(--mono);font-size:.7rem;
  border:1px solid var(--b1);color:var(--t2);text-decoration:none;transition:.15s;
}
.pager a:hover{border-color:var(--cyan);color:var(--cyan)}
.pager .cur{background:rgba(0,212,255,.12);border-color:rgba(0,212,255,.3);color:var(--cyan)}
.pager .disabled{opacity:.3;pointer-events:none}

/* ── Live update bar ── */
#live-bar{font-family:var(--mono);font-size:.6rem;color:var(--t3);
          margin-bottom:.75rem;display:flex;align-items:center;gap:10px}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--green);
          animation:glow 2s infinite;box-shadow:0 0 5px var(--green)}
@keyframes glow{0%,100%{opacity:1}50%{opacity:.25}}
.new-rows{color:var(--cyan);cursor:pointer;text-decoration:underline dotted}

@media(max-width:640px){
  .stats-row{gap:.5rem}
  .stat-card{min-width:100px;padding:.55rem .8rem}
  .stat-val{font-size:1.1rem}
}
</style>
</head>
<body>

<div class="topbar">
  <h1>📋 Audit Log</h1>
  <div class="topbar-right">
    <a href="valve_control.php" class="back">← Valve Control</a>
    <a href="<?= qs(['export'=>'csv','page'=>'']) ?>" class="export-btn">↓ CSV</a>
  </div>
</div>

<!-- ── 24-h Stats ── -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-label">Total (24h)</div>
    <div class="stat-val" style="color:var(--text)"><?= number_format((int)$stats['total']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Successful</div>
    <div class="stat-val" style="color:var(--green)"><?= number_format((int)$stats['ok_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Denied</div>
    <div class="stat-val" style="color:var(--yellow)"><?= number_format((int)$stats['denied_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Errors</div>
    <div class="stat-val" style="color:var(--red)"><?= number_format((int)$stats['error_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Valve Actions</div>
    <div class="stat-val" style="color:var(--cyan)"><?= number_format((int)$stats['valve_count']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Auth Events</div>
    <div class="stat-val" style="color:var(--purple)"><?= number_format((int)$stats['auth_count']) ?></div>
  </div>
</div>

<!-- ── Filters ── -->
<div class="filter-bar">
  <form method="get" id="filter-form">
    <div class="filter-grid">

      <div>
        <select name="cat" title="Category">
          <option value="">All categories</option>
          <option value="valve"    <?= $f_category==='valve'?'selected':'' ?>>🔧 Valve</option>
          <option value="auth"     <?= $f_category==='auth'?'selected':'' ?>>🔑 Auth</option>
          <option value="alerts"   <?= $f_category==='alerts'?'selected':'' ?>>🔔 Alerts</option>
          <option value="settings" <?= $f_category==='settings'?'selected':'' ?>>⚙️ Settings</option>
        </select>
      </div>

      <div>
        <select name="result" title="Result">
          <option value="">All results</option>
          <option value="ok"     <?= $f_result==='ok'?'selected':'' ?>>✓ OK</option>
          <option value="denied" <?= $f_result==='denied'?'selected':'' ?>>⚠ Denied</option>
          <option value="error"  <?= $f_result==='error'?'selected':'' ?>>✗ Error</option>
        </select>
      </div>

      <div>
        <select name="user" title="User">
          <option value="">All users</option>
          <?php foreach ($all_users as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $f_user===$u?'selected':'' ?>>
              <?= htmlspecialchars($u) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>" title="From date">
      </div>
      <div>
        <input type="date" name="to"   value="<?= htmlspecialchars($f_to) ?>"   title="To date">
      </div>
      <div>
        <input type="text" name="q" value="<?= htmlspecialchars($f_search) ?>"
               placeholder="Search action, user, entity…">
      </div>

    </div>
    <div class="filter-actions">
      <button type="submit" class="fbtn fbtn-apply">Apply Filters</button>
      <a href="audit_log.php" class="fbtn fbtn-reset" style="text-decoration:none">Reset</a>
    </div>
  </form>
</div>

<!-- ── Live update indicator ── -->
<div id="live-bar">
  <span class="live-dot"></span>
  <span>Auto-refresh every 30s</span>
  <span id="new-count" style="display:none" class="new-rows" onclick="location.reload()">
    New entries — click to refresh
  </span>
</div>

<!-- ── Table ── -->
<div class="sec-head">
  Audit Records
  <span class="count"><?= number_format($total) ?> total</span>
  <?php if ($total_pages > 1): ?>
    · page <?= $page ?> of <?= $total_pages ?>
  <?php endif; ?>
</div>

<div class="tbl-wrap">
<table class="tbl">
  <thead>
    <tr>
      <th>#</th>
      <th>Timestamp</th>
      <th>Action</th>
      <th>Entity</th>
      <th>User</th>
      <th>Role</th>
      <th>Before → After</th>
      <th>Reason</th>
      <th>Result</th>
      <th>IP</th>
    </tr>
  </thead>
  <tbody id="audit-tbody">
  <?php foreach ($rows as $row):
    // Action colour class
    $cat = explode('.', $row['action'])[0] ?? '';
    $ac  = match($cat){ 'valve'=>'ac-valve','auth'=>'ac-auth','alerts'=>'ac-alerts','settings'=>'ac-settings',default=>'ac-other' };

    // Result badge
    $rcls = match($row['result']){ 'ok'=>'r-ok','denied'=>'r-denied',default=>'r-error' };

    // Diff old→new
    $old = $row['old_value'] ? json_decode($row['old_value'], true) : [];
    $new = $row['new_value'] ? json_decode($row['new_value'], true) : [];
    $diff_html = '';
    if ($old || $new) {
        $keys = array_unique(array_merge(array_keys($old ?? []), array_keys($new ?? [])));
        foreach ($keys as $k) {
            $ov = $old[$k] ?? '—'; $nv = $new[$k] ?? '—';
            if ($ov !== $nv) {
                $diff_html .= '<span class="diff-key">'  . htmlspecialchars($k)         . ': </span>'
                            . '<span class="diff-old">'  . htmlspecialchars((string)$ov) . '</span>'
                            . ' → '
                            . '<span class="diff-new">'  . htmlspecialchars((string)$nv) . '</span><br>';
            }
        }
    }
  ?>
  <tr data-id="<?= $row['id'] ?>">
    <td class="mono" style="font-size:.6rem;color:var(--t3)"><?= $row['id'] ?></td>
    <td class="mono" style="font-size:.64rem;color:var(--t3);white-space:nowrap">
      <?= date('d M Y', strtotime($row['created_at'])) ?><br>
      <span style="color:var(--t2)"><?= date('H:i:s', strtotime($row['created_at'])) ?></span>
    </td>
    <td class="mono <?= $ac ?>" style="font-size:.7rem"><?= htmlspecialchars($row['action']) ?></td>
    <td class="truncate" style="color:var(--t2)">
      <?php if ($row['entity_type']): ?>
        <span style="font-size:.58rem;color:var(--t3)"><?= htmlspecialchars($row['entity_type']) ?>:</span><br>
      <?php endif; ?>
      <?= htmlspecialchars($row['entity_label'] ?? '—') ?>
    </td>
    <td style="white-space:nowrap">
      <?= htmlspecialchars($row['user_name'] ?? 'System') ?>
      <?php if ($row['user_id']): ?>
        <span style="font-size:.58rem;color:var(--t3)"> #<?= $row['user_id'] ?></span>
      <?php endif; ?>
    </td>
    <td class="mono" style="font-size:.65rem;color:var(--t3)"><?= htmlspecialchars($row['user_role'] ?? '—') ?></td>
    <td class="diff-wrap">
      <?php if ($diff_html): ?>
        <?= $diff_html ?>
      <?php elseif ($row['detail']): ?>
        <span style="color:var(--t3);font-size:.65rem"><?= htmlspecialchars(mb_strimwidth($row['detail'],0,60,'…')) ?></span>
      <?php else: ?>
        <span style="color:var(--t3)">—</span>
      <?php endif; ?>
    </td>
    <td class="trunc-sm" style="color:var(--t3);font-size:.72rem">
      <?= htmlspecialchars(mb_strimwidth($row['reason'] ?? '—', 0, 45, '…')) ?>
    </td>
    <td><span class="rbadge <?= $rcls ?>"><?= strtoupper($row['result']) ?></span></td>
    <td class="mono" style="font-size:.6rem;color:var(--t3)"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($rows)): ?>
  <tr>
    <td colspan="10" style="text-align:center;padding:2rem;color:var(--t3);font-family:var(--mono);font-size:.72rem">
      No audit records match your filters.
    </td>
  </tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<!-- ── Pagination ── -->
<?php if ($total_pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?>
    <a href="<?= qs(['page'=>1]) ?>">«</a>
    <a href="<?= qs(['page'=>$page-1]) ?>">‹ Prev</a>
  <?php else: ?>
    <span class="disabled">«</span>
    <span class="disabled">‹ Prev</span>
  <?php endif; ?>

  <?php
  $start = max(1, $page - 3);
  $end   = min($total_pages, $page + 3);
  for ($p = $start; $p <= $end; $p++):
  ?>
    <?php if ($p === $page): ?>
      <span class="cur"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= qs(['page'=>$p]) ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($page < $total_pages): ?>
    <a href="<?= qs(['page'=>$page+1]) ?>">Next ›</a>
    <a href="<?= qs(['page'=>$total_pages]) ?>">»</a>
  <?php else: ?>
    <span class="disabled">Next ›</span>
    <span class="disabled">»</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// ── Poll for new entries without refreshing the page ──────────
const LATEST_ID = <?= !empty($rows) ? (int)$rows[0]['id'] : 0 ?>;
const POLL_URL  = 'audit_log.php?<?= http_build_query(array_filter(['cat'=>$f_category,'result'=>$f_result,'user'=>$f_user,'q'=>$f_search],fn($v)=>$v!=='')) ?>&_ajax=1&since=';

let _knownLatest = LATEST_ID;

async function checkNew() {
    try {
        const r = await fetch(POLL_URL + _knownLatest, { credentials: 'same-origin' });
        if (!r.ok) return;
        const d = await r.json();
        if (d.count > 0) {
            document.getElementById('new-count').style.display = 'inline';
        }
    } catch(e) {}
}

// AJAX mode: return JSON count of new rows
<?php if (isset($_GET['_ajax']) && isset($_GET['since'])): ?>
// Handled server-side below
<?php endif; ?>

setInterval(checkNew, 30000);
</script>

<?php
// AJAX sub-request: just return count of rows newer than 'since'
if (isset($_GET['_ajax']) && isset($_GET['since'])) {
    $since = (int)$_GET['since'];
    $cnt   = (int)$pdo->prepare(
        "SELECT COUNT(*) FROM audit_log WHERE id > ? AND $where_sql"
    )->execute(array_merge([$since], $params))
        ? (int)$pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE id > $since AND $where_sql"
          )->fetchColumn()
        : 0;
    // Clear existing output and return JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['count' => $cnt]);
    exit;
}
?>

</body>
</html>