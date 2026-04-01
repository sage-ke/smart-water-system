<?php
/*
 * user_reports.php  ·  SWDS Meru
 * ============================================================
 *  INBOX LOGIC:
 *    - Inbox  = open + in_progress  (active, needs attention)
 *    - Archive = resolved             (collapsed by default)
 *
 *  When admin updates a report to "resolved", it disappears
 *  from the inbox and moves to the Archive section.
 *  History is never deleted — just hidden until needed.
 *
 *  SYSTEM ALERTS:
 *    Critical/unresolved system alerts appear as a red banner
 *    at the top of this page and in the sidebar.
 * ============================================================
 */
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
    header('Location: dashboard.php'); exit;
}

$uid        = (int)$_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'user_reports';
$page_title   = 'User Reports';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();

// ── Ensure table + columns ──────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS emergency_messages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    message      TEXT NOT NULL,
    severity     VARCHAR(20)  DEFAULT 'warning',
    issue_type   VARCHAR(100) DEFAULT 'Other',
    status       VARCHAR(30)  DEFAULT 'open',
    admin_response TEXT,
    responded_by INT DEFAULT NULL,
    responded_at TIMESTAMP NULL,
    is_read      TINYINT(1) DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_em_user(user_id), INDEX idx_em_status(status)
)");
foreach ([
    "ALTER TABLE emergency_messages ADD COLUMN severity VARCHAR(20) DEFAULT 'warning'",
    "ALTER TABLE emergency_messages ADD COLUMN issue_type VARCHAR(100) DEFAULT 'Other'",
    "ALTER TABLE emergency_messages ADD COLUMN status VARCHAR(30) DEFAULT 'open'",
    "ALTER TABLE emergency_messages ADD COLUMN admin_response TEXT",
    "ALTER TABLE emergency_messages ADD COLUMN responded_by INT DEFAULT NULL",
    "ALTER TABLE emergency_messages ADD COLUMN responded_at TIMESTAMP NULL",
    "ALTER TABLE emergency_messages ADD COLUMN is_read TINYINT(1) DEFAULT 0",
    "ALTER TABLE emergency_messages ADD COLUMN zone_name VARCHAR(100)",
    "ALTER TABLE emergency_messages ADD COLUMN gps_lat DECIMAL(10,7)",
    "ALTER TABLE emergency_messages ADD COLUMN gps_lng DECIMAL(10,7)",
] as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

// ── CSV export ──────────────────────────────────────────────
if (isset($_GET['export'])) {
    $rows = $pdo->query("
        SELECT em.id, u.full_name, u.email,
               em.issue_type, em.severity, em.message,
               em.status, em.admin_response, em.created_at, em.responded_at
        FROM emergency_messages em
        LEFT JOIN users u ON u.id = em.user_id
        ORDER BY em.created_at DESC
    ")->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_reports_'.date('Y-m-d').'.csv"');
    $o = fopen('php://output','w');
    fputcsv($o,['ID','Resident','Email','Issue Type','Severity','Message','Status','Admin Reply','Submitted','Replied']);
    foreach ($rows as $r) fputcsv($o,[$r['id'],$r['full_name'],$r['email'],
        $r['issue_type'],$r['severity'],$r['message'],$r['status'],
        $r['admin_response'],$r['created_at'],$r['responded_at']]);
    fclose($o); exit;
}

// ── Handle reply / status update ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reply') {
    $mid    = (int)$_POST['msg_id'];
    $status = in_array($_POST['status']??'',['open','read','in_progress','resolved']) ? $_POST['status'] : 'open';
    $reply  = trim($_POST['admin_response'] ?? '');
    $now    = $reply ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("UPDATE emergency_messages
                   SET status=?, admin_response=?, responded_by=?,
                       responded_at=COALESCE(?,responded_at), is_read=1
                   WHERE id=?")
        ->execute([$status, $reply ?: null, $uid, $now, $mid]);

    // Notify resident if admin replied
    if ($reply) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT, title VARCHAR(200), body TEXT,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $res_uid = (int)$pdo->query("SELECT user_id FROM emergency_messages WHERE id=$mid")->fetchColumn();
            $pdo->prepare("INSERT INTO user_notifications (user_id,title,body) VALUES (?,?,?)")
                ->execute([$res_uid,'Admin replied to your report',$reply]);
        } catch (PDOException $e) {}
    }
    header('Location: user_reports.php'); exit;
}

// ── Mark all as read when admin opens page ──────────────────
$pdo->exec("UPDATE emergency_messages SET is_read=1 WHERE is_read=0");

// ── Load inbox (open + in_progress) — newest first ─────────
$inbox = $pdo->query("
    SELECT em.*, u.full_name, u.email,
           COALESCE(u2.full_name,'—') AS replied_by_name
    FROM emergency_messages em
    LEFT JOIN users u  ON u.id  = em.user_id
    LEFT JOIN users u2 ON u2.id = em.responded_by
    WHERE em.status IN ('open','in_progress','read')
    ORDER BY FIELD(em.status,'open','in_progress','read'), em.created_at DESC
    LIMIT 200
")->fetchAll();

// ── Load archive (resolved) — newest first ──────────────────
$archive = $pdo->query("
    SELECT em.*, u.full_name, u.email,
           COALESCE(u2.full_name,'—') AS replied_by_name
    FROM emergency_messages em
    LEFT JOIN users u  ON u.id  = em.user_id
    LEFT JOIN users u2 ON u2.id = em.responded_by
    WHERE em.status = 'resolved'
    ORDER BY em.responded_at DESC
    LIMIT 100
")->fetchAll();

// ── Stats ────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='open') AS open_count,
           SUM(status='in_progress') AS inprog,
           SUM(status='resolved') AS resolved,
           SUM(severity='critical') AS critical
    FROM emergency_messages
")->fetch();

// ── Critical system alerts (for banner) ─────────────────────
$crit_alerts = $pdo->query("
    SELECT a.*, wz.zone_name
    FROM alerts a
    LEFT JOIN water_zones wz ON wz.id = a.zone_id
    WHERE a.is_resolved = 0
    ORDER BY FIELD(a.severity,'critical','high','medium','low'), a.created_at DESC
    LIMIT 5
")->fetchAll();

// Try both sidebar locations for compatibility
if (file_exists(__DIR__ . '/includes/sidebar.php')) {
    require_once __DIR__ . '/includes/sidebar.php';
} else {
    require_once __DIR__ . '/sidebar.php';
}
?>

<style>
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.7rem;margin-bottom:1.5rem}
.sc{background:var(--card);border:1px solid var(--border);border-radius:11px;padding:.85rem 1rem}
.sl{font-size:.63rem;color:var(--muted);text-transform:uppercase;margin-bottom:4px}
.sv{font-size:1.45rem;font-weight:800}

/* ── System alert banner ── */
.sys-banner{border-radius:12px;padding:12px 16px;margin-bottom:1.25rem;
            display:flex;align-items:flex-start;gap:12px}
.sys-banner.critical{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35)}
.sys-banner.high    {background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3)}
.sys-icon{font-size:1.3rem;flex-shrink:0;line-height:1}
.sys-body{flex:1}
.sys-title{font-weight:700;font-size:.88rem;margin-bottom:4px}
.sys-list{font-size:.8rem;color:var(--muted);line-height:1.7}
.sys-link{color:var(--blue);text-decoration:none;font-size:.78rem;font-weight:600;margin-top:5px;display:inline-block}

/* ── Report cards ── */
.rep-card{background:var(--card);border:1px solid var(--border);border-radius:14px;
          margin-bottom:.85rem;overflow:hidden;transition:opacity .3s}
.rep-card.unread{border-color:rgba(14,165,233,.35)}
.rep-header{padding:.9rem 1.2rem;display:flex;align-items:flex-start;
            justify-content:space-between;gap:1rem;flex-wrap:wrap}
.rep-body{padding:0 1.2rem 1.2rem;border-top:1px solid rgba(30,58,95,.5)}
.rep-msg{background:rgba(255,255,255,.03);border-radius:9px;padding:.75rem 1rem;
         font-size:.88rem;line-height:1.6;margin:.85rem 0}
.rep-reply-box{background:rgba(52,211,153,.04);border:1px solid rgba(52,211,153,.2);
               border-radius:9px;padding:.75rem 1rem;margin-top:.5rem}
.rep-reply-label{font-size:.63rem;font-weight:700;text-transform:uppercase;
                 color:#34d399;margin-bottom:.4rem;letter-spacing:.06em}
.rep-form{display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-top:.85rem}
.rep-form select,.rep-form textarea{padding:7px 10px;background:rgba(255,255,255,.04);
  border:1px solid var(--border);border-radius:7px;color:var(--text);font-size:.8rem;
  font-family:inherit}
.rep-form select:focus,.rep-form textarea:focus{outline:none;border-color:var(--blue)}
.rep-form textarea{flex:1;min-width:200px;resize:vertical;min-height:52px}
.rep-submit{padding:7px 16px;border-radius:7px;font-size:.8rem;font-weight:700;
            cursor:pointer;border:none;
            background:linear-gradient(135deg,var(--blue),var(--teal));color:#fff}
.rep-resolve{padding:7px 14px;border-radius:7px;font-size:.8rem;font-weight:700;
             cursor:pointer;border:1px solid rgba(52,211,153,.4);
             background:rgba(52,211,153,.08);color:#34d399}
.rep-resolve:hover{background:rgba(52,211,153,.15)}

/* ── Badges ── */
.bdg{padding:2px 8px;border-radius:5px;font-size:.63rem;font-weight:700}
.b-open{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.b-read{background:rgba(122,155,186,.1);color:#7a9bba;border:1px solid rgba(122,155,186,.2)}
.b-inprog{background:rgba(14,165,233,.12);color:var(--blue);border:1px solid rgba(14,165,233,.3)}
.b-resolved{background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}
.b-crit{background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-warn{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.b-info{background:rgba(14,165,233,.1);color:var(--blue);border:1px solid rgba(14,165,233,.25)}

/* ── Archive toggle ── */
.arc-toggle{display:flex;align-items:center;gap:10px;cursor:pointer;
            padding:12px 16px;background:rgba(255,255,255,.02);
            border:1px solid var(--border);border-radius:12px;margin-bottom:1rem;
            user-select:none}
.arc-toggle:hover{background:rgba(255,255,255,.04)}
.arc-body{display:none}
.arc-body.open{display:block}

.unread-dot{display:inline-block;width:8px;height:8px;background:#0ea5e9;
            border-radius:50%;margin-right:5px;flex-shrink:0;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.empty-state{text-align:center;padding:3rem 1rem;color:var(--muted)}
</style>

<!-- Page header -->
<div style="display:flex;justify-content:space-between;align-items:center;
            margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
  <div>
    <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800">
      📬 User Reports Inbox
    </h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:2px">
      Problems submitted by residents · Resolved messages move to Archive
    </p>
  </div>
  <a href="user_reports.php?export=1"
     style="padding:8px 16px;background:linear-gradient(135deg,var(--blue),var(--teal));
            border-radius:9px;color:#fff;font-size:.82rem;font-weight:700;text-decoration:none">
    Export All CSV
  </a>
</div>

<!-- ── SYSTEM ALERT BANNER ── shows only when critical/high alerts exist ── -->
<?php if (!empty($crit_alerts)):
    $has_critical = array_filter($crit_alerts, fn($a) => $a['severity'] === 'critical');
    $banner_class = $has_critical ? 'critical' : 'high';
?>
<div class="sys-banner <?= $banner_class ?>">
  <div class="sys-icon"><?= $has_critical ? '🚨' : '⚠️' ?></div>
  <div class="sys-body">
    <div class="sys-title" style="color:<?= $has_critical ? '#f87171' : '#fbbf24' ?>">
      <?= count($crit_alerts) ?> unresolved system alert<?= count($crit_alerts) > 1 ? 's' : '' ?> detected
    </div>
    <div class="sys-list">
      <?php foreach ($crit_alerts as $al): ?>
        <div>
          <span style="color:<?= $al['severity']==='critical' ? '#f87171' : '#fbbf24' ?>;font-weight:700">
            [<?= strtoupper($al['severity']) ?>]
          </span>
          <?= htmlspecialchars($al['zone_name'] ?? 'System') ?> —
          <?= htmlspecialchars($al['alert_type']) ?>
          <span style="font-size:.72rem;color:var(--muted)">
            · <?= date('d M H:i', strtotime($al['created_at'])) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <a href="alerts.php" class="sys-link">→ View & resolve all alerts</a>
  </div>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="sg">
  <?php foreach([
    ['Inbox',     count($inbox),           '#0ea5e9'],
    ['Open',      $stats['open_count']??0, '#fbbf24'],
    ['In Progress',$stats['inprog']??0,    '#06b6d4'],
    ['Archived',  $stats['resolved']??0,   '#7a9bba'],
    ['Critical',  $stats['critical']??0,   '#f87171'],
    ['System Alerts', $total_alerts,       $total_alerts>0?'#f87171':'#34d399'],
  ] as [$l,$v,$c]): ?>
  <div class="sc">
    <div class="sl"><?= $l ?></div>
    <div class="sv" style="color:<?= $c ?>"><?= $v ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ════════════════════════════════════════════════════════════
     INBOX — open + in_progress + read
═════════════════════════════════════════════════════════════ -->
<?php if (empty($inbox)): ?>
<div class="rep-card">
  <div class="empty-state">
    <div style="font-size:2.5rem;margin-bottom:.75rem">✅</div>
    <div style="font-weight:700;font-size:1rem;margin-bottom:.4rem">Inbox is clear</div>
    <p style="font-size:.85rem">All reports have been resolved. New reports from residents will appear here.</p>
  </div>
</div>

<?php else: ?>

<?php
$sc_map = ['open'=>'b-open','read'=>'b-read','in_progress'=>'b-inprog','resolved'=>'b-resolved'];
$sv_map = ['critical'=>'b-crit','warning'=>'b-warn','info'=>'b-info'];

foreach ($inbox as $r):
    $sc = $sc_map[$r['status']] ?? 'b-open';
    $sv = $sv_map[$r['severity']] ?? 'b-warn';
    $is_new = ($r['status'] === 'open');
?>
<div class="rep-card <?= $is_new ? 'unread' : '' ?>" id="r<?= $r['id'] ?>">
  <!-- Clickable header — toggles detail panel -->
  <div class="rep-header" onclick="toggleCard(<?= $r['id'] ?>)" style="cursor:pointer">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <?php if ($is_new): ?><span class="unread-dot" title="New"></span><?php endif; ?>
      <div>
        <div style="font-weight:700;font-size:.92rem">
          <?= htmlspecialchars($r['full_name'] ?? 'Unknown User') ?>
          <span style="color:var(--muted);font-weight:400;font-size:.78rem;margin-left:4px">
            <?= htmlspecialchars($r['email'] ?? '') ?>
          </span>
        </div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap">
          <span>#<?= $r['id'] ?> · <?= date('d M Y H:i', strtotime($r['created_at'])) ?></span>
          <?php if (!empty($r['zone_name'])): ?>
          <span>📍 <?= htmlspecialchars($r['zone_name']) ?></span>
          <?php endif; ?>
          <!-- Message preview when collapsed -->
          <span class="msg-preview" id="prev-<?= $r['id'] ?>"
                style="color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars(substr($r['message'], 0, 80)) . (strlen($r['message']) > 80 ? '…' : '') ?>
          </span>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center">
      <span class="bdg <?= $sv ?>"><?= strtoupper($r['severity'] ?? 'warning') ?></span>
      <span class="bdg" style="background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border)">
        <?= htmlspecialchars($r['issue_type'] ?? 'Other') ?>
      </span>
      <span class="bdg <?= $sc ?>"><?= strtoupper(str_replace('_',' ',$r['status'] ?? 'open')) ?></span>
      <?php if (!empty($r['gps_lat'])): ?>
      <a href="https://maps.google.com/?q=<?= $r['gps_lat'] ?>,<?= $r['gps_lng'] ?>"
         target="_blank" onclick="event.stopPropagation()"
         style="color:var(--blue);font-size:.72rem;text-decoration:none">🗺️</a>
      <?php endif; ?>
      <span id="arr-<?= $r['id'] ?>" style="color:var(--muted);font-size:.8rem;margin-left:4px">▼</span>
    </div>
  </div>

  <!-- Detail panel — hidden by default, click header to open -->
  <div class="rep-body" id="body-<?= $r['id'] ?>" style="display:none">
    <div class="rep-msg"><?= nl2br(htmlspecialchars($r['message'])) ?></div>

    <?php if ($r['admin_response']): ?>
    <div class="rep-reply-box">
      <div class="rep-reply-label">✅ Your previous reply
        <span style="font-weight:400;color:var(--muted);font-size:.65rem">
          — <?= $r['responded_at'] ? date('d M Y H:i', strtotime($r['responded_at'])) : '' ?>
        </span>
      </div>
      <div style="font-size:.85rem;line-height:1.5"><?= nl2br(htmlspecialchars($r['admin_response'])) ?></div>
    </div>
    <?php endif; ?>

    <form method="post" style="margin-top:.85rem">
      <input type="hidden" name="_action" value="reply">
      <input type="hidden" name="msg_id" value="<?= $r['id'] ?>">
      <div class="rep-form">
        <div>
          <label style="font-size:.63rem;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Status</label>
          <select name="status">
            <?php foreach(['open'=>'Open','read'=>'Read','in_progress'=>'In Progress','resolved'=>'Resolved ✓'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($r['status']===$k)?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1">
          <label style="font-size:.63rem;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Reply to resident</label>
          <textarea name="admin_response"
            placeholder="Type a reply — resident sees this in their dashboard. To archive, set status to Resolved ✓"
            ><?= htmlspecialchars($r['admin_response'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px">
          <button type="submit" class="rep-submit">Save Reply</button>
          <button type="submit" class="rep-resolve"
            onclick="this.form.querySelector('[name=status]').value='resolved'"
            title="Mark resolved — moves to Archive">
            ✓ Resolve
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════════════
     ARCHIVE — resolved, collapsed by default
═════════════════════════════════════════════════════════════ -->
<div style="margin-top:1.75rem">
  <div class="arc-toggle" onclick="toggleArchive()">
    <span style="font-size:1rem">🗄️</span>
    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem">
      Archive — Resolved Reports
    </span>
    <span class="bdg b-resolved" style="margin-left:4px"><?= count($archive) ?></span>
    <span id="arc-arrow" style="margin-left:auto;color:var(--muted);font-size:.85rem">▼ show</span>
  </div>

  <div class="arc-body" id="archive-body">
    <?php if (empty($archive)): ?>
    <div style="text-align:center;padding:2rem;color:var(--muted);font-size:.85rem">
      No resolved reports yet.
    </div>
    <?php else: ?>
    <?php foreach ($archive as $r):
        $sv = $sv_map[$r['severity']] ?? 'b-warn';
    ?>
    <div class="rep-card" style="opacity:.75">
      <div class="rep-header">
        <div>
          <div style="font-weight:600;font-size:.88rem">
            <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?>
            <span style="color:var(--muted);font-weight:400;font-size:.75rem;margin-left:4px">
              #<?= $r['id'] ?> · <?= date('d M Y H:i', strtotime($r['created_at'])) ?>
            </span>
          </div>
        </div>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
          <span class="bdg <?= $sv ?>"><?= strtoupper($r['severity'] ?? '') ?></span>
          <span class="bdg b-resolved">RESOLVED</span>
          <?php if ($r['responded_at']): ?>
          <span style="font-size:.68rem;color:var(--muted);align-self:center">
            resolved <?= date('d M', strtotime($r['responded_at'])) ?>
            by <?= htmlspecialchars($r['replied_by_name']) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="rep-body">
        <div style="font-size:.83rem;color:var(--muted);line-height:1.5;padding:.5rem 0">
          <?= nl2br(htmlspecialchars($r['message'])) ?>
        </div>
        <?php if ($r['admin_response']): ?>
        <div class="rep-reply-box" style="margin-top:.5rem">
          <div class="rep-reply-label">Admin reply</div>
          <div style="font-size:.82rem;line-height:1.5"><?= nl2br(htmlspecialchars($r['admin_response'])) ?></div>
        </div>
        <?php endif; ?>
        <!-- Reopen button -->
        <form method="post" style="margin-top:.75rem">
          <input type="hidden" name="_action" value="reply">
          <input type="hidden" name="msg_id" value="<?= $r['id'] ?>">
          <input type="hidden" name="status" value="open">
          <input type="hidden" name="admin_response" value="<?= htmlspecialchars($r['admin_response'] ?? '') ?>">
          <button type="submit"
            style="padding:5px 13px;border-radius:7px;font-size:.75rem;font-weight:600;
                   cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--muted)">
            ↩ Reopen
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleCard(id) {
    const body  = document.getElementById('body-' + id);
    const arrow = document.getElementById('arr-' + id);
    const prev  = document.getElementById('prev-' + id);
    const open  = body.style.display === 'none';
    body.style.display  = open ? 'block' : 'none';
    arrow.textContent   = open ? '▲' : '▼';
    if (prev) prev.style.display = open ? 'none' : '';
    // Auto-open critical reports on page load handled separately
}

// Auto-expand CRITICAL open reports on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.rep-card.unread').forEach(function(card) {
        const id = card.id.replace('r','');
        const body = document.getElementById('body-' + id);
        const arrow = document.getElementById('arr-' + id);
        const prev  = document.getElementById('prev-' + id);
        if (body && card.querySelector('.b-crit')) {
            body.style.display = 'block';
            if (arrow) arrow.textContent = '▲';
            if (prev) prev.style.display = 'none';
        }
    });
});

function toggleArchive() {
    const body  = document.getElementById('archive-body');
    const arrow = document.getElementById('arc-arrow');
    const open  = body.classList.toggle('open');
    arrow.textContent = open ? '▲ hide' : '▼ show';
}
</script>

</main>
</body>
</html>