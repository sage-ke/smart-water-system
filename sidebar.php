<?php
/*
 * sidebar.php — SWDS Meru
 * ---------------------------------------------------------------
 * FILE NAME MAPPING (your actual files → sidebar links):
 *
 *   YOUR FILE                  SIDEBAR SHOWS AS
 *   ─────────────────────────────────────────────
 *   sensor_data.php          → Sensor Data
 *   api/analyticsAI.php      → Analytics & AI     (in api/ subfolder)
 *   decision_engine.php      → Decision Engine
 *   api/kobo_importer.php    → Field Reports       (in api/ subfolder)
 *   hardware.php             → Hardware
 *   maintenance.php          → Maintenance
 *   reports.php              → Reports
 *   users.php                → Users
 *   settings.php             → Settings
 *   api/check_role.php       → called by login.php (in api/ subfolder)
 *
 * ---------------------------------------------------------------
 * Expects these variables set before require_once:
 *   $user_name, $user_email, $user_role, $total_alerts (int)
 *   $current_page — 'dashboard','zones','sensors','alerts',
 *                   'analytics','decision','kobo','hardware',
 *                   'maintenance','reports','users','settings'
 * ---------------------------------------------------------------
 */
$current_page = $current_page ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'SWDS Meru') ?> | Smart Water Distribution</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:    #0ea5e9;
            --teal:    #06b6d4;
            --green:   #34d399;
            --yellow:  #fbbf24;
            --red:     #f87171;
            --purple:  #a78bfa;
            --dark:    #0a1628;
            --sidebar: #0c1e3a;
            --card:    #0f2040;
            --border:  #1e3a5f;
            --text:    #e2eaf4;
            --muted:   #7a9bba;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--dark);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            background-image:
                radial-gradient(ellipse at 5% 10%, rgba(14,165,233,0.07) 0%, transparent 40%),
                radial-gradient(ellipse at 95% 90%, rgba(6,182,212,0.06) 0%, transparent 40%);
        }

        /* ======================== SIDEBAR ======================== */
        .sidebar {
            width: 240px; min-height: 100vh;
            background: var(--sidebar); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; padding: 1.5rem 0;
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
            overflow-y: auto;
        }
        .sidebar-brand {
            display: flex; align-items: center; gap: 10px;
            padding: 0 1.25rem 1.25rem;
            border-bottom: 1px solid var(--border); margin-bottom: 1.25rem;
            flex-shrink: 0;
        }
        .sidebar-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border-radius: 10px; display: grid; place-items: center;
            font-size: 1.2rem; flex-shrink: 0;
        }
        .sidebar-brand h1 { font-family:'Syne',sans-serif; font-size:0.9rem; font-weight:700; line-height:1.2; }
        .sidebar-brand p  { font-size:0.68rem; color:var(--muted); }

        .nav-label {
            padding: 0 1.25rem; font-size:0.68rem; font-weight:600;
            text-transform:uppercase; letter-spacing:0.1em;
            color:var(--muted); margin-bottom:0.4rem;
        }
        .nav-item {
            display:flex; align-items:center; gap:10px;
            padding: 9px 1.25rem; text-decoration:none;
            color:var(--muted); font-size:0.875rem;
            transition:all 0.15s; border-left:3px solid transparent;
            position: relative;
        }
        .nav-item:hover  { color:var(--text); background:rgba(14,165,233,0.07); border-left-color:rgba(14,165,233,0.4); }
        .nav-item.active { color:var(--blue); background:rgba(14,165,233,0.1);  border-left-color:var(--blue); font-weight:500; }
        .nav-icon  { font-size:1rem; width:18px; text-align:center; }
        .nav-badge {
            margin-left:auto; background:var(--red); color:#fff;
            border-radius:10px; padding:1px 7px; font-size:0.68rem; font-weight:600;
        }

        .sidebar-footer {
            margin-top:auto; padding:1rem 1.25rem; border-top:1px solid var(--border);
        }
        .user-avatar {
            width:36px; height:36px; border-radius:50%;
            background:linear-gradient(135deg,var(--blue),var(--teal));
            display:grid; place-items:center; font-size:1rem; flex-shrink:0;
        }
        .user-meta  { flex:1; min-width:0; }
        .user-row   { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .user-name  { font-weight:600; font-size:0.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .user-email { font-size:0.72rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .logout-btn {
            display:block; width:100%; padding:8px;
            background:rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.2);
            color:var(--red); border-radius:8px; text-align:center;
            text-decoration:none; font-size:0.83rem; font-weight:500; transition:background 0.2s;
        }
        .logout-btn:hover { background:rgba(248,113,113,0.2); }

        /* ======================== MAIN ======================== */
        .main { margin-left: 240px; flex: 1; padding: 2rem; min-height: 100vh; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:2rem; }
        .topbar h2 { font-family:'Syne',sans-serif; font-size:1.4rem; font-weight:800; }
        .date-badge {
            background:var(--card); border:1px solid var(--border);
            border-radius:8px; padding:7px 14px; font-size:0.78rem; color:var(--muted);
        }

        /* ======================== CARDS ======================== */
        .card { background:var(--card); border:1px solid var(--border); border-radius:16px; }
        .card-header {
            padding:1rem 1.25rem; border-bottom:1px solid var(--border);
            display:flex; justify-content:space-between; align-items:center;
        }
        .card-title { font-family:'Syne',sans-serif; font-weight:700; font-size:0.95rem; }
        .card-body  { padding:1.25rem; }

        /* ======================== STATS GRID ======================== */
        .stats-grid {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:1rem; margin-bottom:2rem;
        }
        .stat-card {
            background:var(--card); border:1px solid var(--border);
            border-radius:16px; padding:1.2rem; position:relative; overflow:hidden;
        }
        .stat-label { font-size:0.72rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; }
        .stat-value { font-family:'Syne',sans-serif; font-size:1.9rem; font-weight:800; }
        .stat-sub   { font-size:0.78rem; color:var(--muted); margin-top:4px; }
        .stat-icon  { position:absolute; top:1rem; right:1rem; font-size:1.6rem; opacity:0.3; }
        .c-blue   { color:var(--blue); }
        .c-teal   { color:var(--teal); }
        .c-green  { color:var(--green); }
        .c-yellow { color:var(--yellow); }
        .c-red    { color:var(--red); }
        .c-purple { color:var(--purple); }
        .c-muted  { color:var(--muted); }

        /* ======================== TABLES ======================== */
        table { width:100%; border-collapse:collapse; }
        thead th {
            padding:10px 1.25rem; text-align:left; font-size:0.72rem; font-weight:600;
            text-transform:uppercase; letter-spacing:0.06em; color:var(--muted);
            border-bottom:1px solid var(--border);
        }
        tbody td { padding:11px 1.25rem; font-size:0.88rem; border-bottom:1px solid rgba(30,58,95,0.5); }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover { background:rgba(255,255,255,0.02); }
        .table-wrap { overflow-x:auto; }

        /* ======================== BADGES ======================== */
        .badge {
            display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:0.7rem; font-weight:600; text-transform:uppercase;
        }
        .badge-active      { background:rgba(52,211,153,0.15);  color:var(--green); }
        .badge-inactive    { background:rgba(122,155,186,0.15); color:var(--muted); }
        .badge-maintenance { background:rgba(251,191,36,0.15);  color:var(--yellow); }
        .badge-high        { background:rgba(248,113,113,0.15); color:var(--red); }
        .badge-medium      { background:rgba(251,191,36,0.15);  color:var(--yellow); }
        .badge-low         { background:rgba(52,211,153,0.15);  color:var(--green); }
        .badge-critical    { background:rgba(248,113,113,0.25); color:var(--red); }
        .badge-resolved    { background:rgba(52,211,153,0.15);  color:var(--green); }
        .badge-open        { background:rgba(248,113,113,0.15); color:var(--red); }
        .badge-admin       { background:rgba(167,139,250,0.15); color:var(--purple); }
        .badge-operator    { background:rgba(251,191,36,0.15);  color:var(--yellow); }
        .badge-viewer      { background:rgba(52,211,153,0.15);  color:var(--green); }
        .badge-user        { background:rgba(14,165,233,0.15);  color:var(--blue); }
        .badge-pending     { background:rgba(14,165,233,0.15);  color:var(--blue); }
        .badge-sent        { background:rgba(6,182,212,0.15);   color:var(--teal); }
        .badge-failed      { background:rgba(248,113,113,0.15); color:var(--red); }

        /* ======================== FORM ======================== */
        .form-group { margin-bottom:1.25rem; }
        .form-label {
            display:block; font-size:0.78rem; font-weight:500; color:var(--muted);
            text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px;
        }
        .form-control {
            width:100%; padding:10px 14px;
            background:rgba(255,255,255,0.04); border:1px solid var(--border);
            border-radius:10px; color:var(--text); font-size:0.9rem;
            font-family:'DM Sans',sans-serif; transition:border-color 0.2s;
        }
        .form-control:focus { outline:none; border-color:var(--blue); background:rgba(14,165,233,0.05); }
        select.form-control option { background:var(--card); }
        .btn-primary {
            padding:10px 22px; background:linear-gradient(135deg,var(--blue),var(--teal));
            border:none; border-radius:10px; color:#fff; font-size:0.9rem; font-weight:600;
            font-family:'Syne',sans-serif; cursor:pointer; transition:opacity 0.2s;
        }
        .btn-primary:hover { opacity:0.9; }
        .btn-secondary {
            padding:9px 18px; background:rgba(255,255,255,0.06);
            border:1px solid var(--border); border-radius:10px;
            color:var(--muted); font-size:0.88rem; cursor:pointer;
        }
        .btn-danger {
            padding:8px 16px; background:rgba(248,113,113,0.15);
            border:1px solid rgba(248,113,113,0.3); border-radius:8px;
            color:var(--red); font-size:0.83rem; cursor:pointer;
        }
        .btn-danger:hover { background:rgba(248,113,113,0.25); }
        .btn-sm { padding:5px 12px; font-size:0.78rem; border-radius:7px; }

        /* Alert boxes */
        .alert-box     { border-radius:10px; padding:12px 16px; margin-bottom:1.25rem; font-size:0.875rem; }
        .alert-success { background:rgba(52,211,153,0.1);  border:1px solid rgba(52,211,153,0.3);  color:var(--green); }
        .alert-error   { background:rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.3); color:var(--red); }
        .alert-warning { background:rgba(251,191,36,0.1);  border:1px solid rgba(251,191,36,0.3);  color:var(--yellow); }

        /* Section title */
        .section-title {
            font-family:'Syne',sans-serif; font-size:1rem; font-weight:700;
            margin-bottom:1rem; display:flex; align-items:center; gap:8px;
        }
        .section-title::after { content:''; flex:1; height:1px; background:var(--border); }

        /* Progress bars */
        .bar-track { height:6px; background:rgba(255,255,255,0.08); border-radius:99px; overflow:hidden; }
        .bar-fill  { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--blue),var(--teal)); }
        .bar-fill.low    { background:var(--red); }
        .bar-fill.medium { background:var(--yellow); }

        /* Grid layouts */
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
        .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1.25rem; }
        @media(max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }

        /* Empty state */
        .empty-state { text-align:center; padding:3rem 1rem; color:var(--muted); }
        .empty-state .icon { font-size:2.5rem; margin-bottom:0.75rem; }

        /* Modal */
        .modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6);
            z-index:200; align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal {
            background:var(--card); border:1px solid var(--border); border-radius:18px;
            padding:1.75rem; width:100%; max-width:480px; max-height:90vh; overflow-y:auto;
        }
        .modal-title  { font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:800; margin-bottom:1.25rem; }
        .modal-footer { display:flex; gap:10px; margin-top:1.5rem; justify-content:flex-end; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-icon">💧</div>
        <div>
            <h1>SWDS Meru</h1>
            <p>Water Distribution</p>
        </div>
    </div>

    <?php
    $nav_role = $user_role ?? 'user';
    $is_admin = $nav_role === 'admin';
    $is_staff = in_array($nav_role, ['admin','operator']);

    // ── FILE NAME MAP ─────────────────────────────────────────
    // Maps each nav link to YOUR actual filename.
    // Edit these paths if you rename files in future.
    $f = [
        'dashboard'   => 'dashboard.php',
        'zones'       => 'zones.php',
        'sensors'     => 'sensor_data.php',       // YOUR filename
        'alerts'      => 'alerts.php',
        'analytics'   => 'analytics_ai.php',      // ROOT file (correct paths)
        'decision'    => 'decision_engine.php',    // YOUR filename
        'kobo'        => 'kobo_importer.php',      // ROOT file (correct paths)
        'hardware'    => 'hardware.php',
        'maintenance' => 'maintenance.php',
        'reports'     => 'reports.php',
        'users'       => 'users.php',
        'settings'    => 'settings.php',
    ];
    ?>

    <p class="nav-label">Main Menu</p>
    <a href="<?= $f['dashboard'] ?>"  class="nav-item <?= $current_page==='dashboard'  ? 'active':'' ?>"><span class="nav-icon">📊</span> Dashboard</a>
    <a href="<?= $f['zones'] ?>"      class="nav-item <?= $current_page==='zones'      ? 'active':'' ?>"><span class="nav-icon">🗺️</span> Zone Map</a>
    <a href="<?= $f['sensors'] ?>"    class="nav-item <?= $current_page==='sensors'    ? 'active':'' ?>"><span class="nav-icon">📡</span> Sensor Data</a>
    <a href="<?= $f['alerts'] ?>"     class="nav-item <?= $current_page==='alerts'     ? 'active':'' ?>">
        <span class="nav-icon">🚨</span> Alerts
        <?php if (($total_alerts ?? 0) > 0): ?>
            <span class="nav-badge"><?= $total_alerts ?></span>
        <?php endif; ?>
    </a>

    <?php if ($is_staff): ?>
    <p class="nav-label" style="margin-top:1rem;">Analytics</p>
    <a href="<?= $f['analytics'] ?>"  class="nav-item <?= $current_page==='analytics' ? 'active':'' ?>"><span class="nav-icon">🧠</span> Analytics & AI</a>
    <a href="<?= $f['decision'] ?>"   class="nav-item <?= $current_page==='decision'  ? 'active':'' ?>"><span class="nav-icon">⚙️</span> Decision Engine</a>
    <a href="prediction_log.php"   class="nav-item <?= $current_page==='prediction_log' ? 'active':'' ?>"><span class="nav-icon">📈</span> ML Predictions</a>

    <p class="nav-label" style="margin-top:1rem;">Operations</p>
    <a href="<?= $f['hardware'] ?>"    class="nav-item <?= $current_page==='hardware'    ? 'active':'' ?>"><span class="nav-icon">📡</span> Hardware</a>
    <a href="valve_control.php"           class="nav-item <?= $current_page==='valve_control' ? 'active':'' ?>"><span class="nav-icon">🎛️</span> Valve Control</a>
    <a href="<?= $f['maintenance'] ?>" class="nav-item <?= $current_page==='maintenance' ? 'active':'' ?>"><span class="nav-icon">🔧</span> Maintenance</a>
    <?php /* Field Reports hidden — enable when KoBo is configured
    <a href="<?= $f['kobo'] ?>"        class="nav-item <?= $current_page==='kobo'        ? 'active':'' ?>"><span class="nav-icon">📋</span> Field Reports</a>
    */ ?>
    <a href="user_reports.php"         class="nav-item <?= $current_page==='user_reports' ? 'active':'' ?>">
        <span class="nav-icon">📬</span> User Reports
        <?php
        try {
            $unread_c = (int)$pdo->query("SELECT COUNT(*) FROM emergency_messages WHERE is_read=0")->fetchColumn();
            if ($unread_c > 0) echo "<span class='nav-badge'>$unread_c</span>";
        } catch (Exception $e) {}
        ?>
    </a>
    <a href="<?= $f['reports'] ?>"     class="nav-item <?= $current_page==='reports'     ? 'active':'' ?>"><span class="nav-icon">📄</span> Reports</a>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <p class="nav-label" style="margin-top:1rem;">Admin</p>
    <a href="<?= $f['users'] ?>"    class="nav-item <?= $current_page==='users'    ? 'active':'' ?>"><span class="nav-icon">👥</span> Users</a>
    <a href="<?= $f['settings'] ?>" class="nav-item <?= $current_page==='settings' ? 'active':'' ?>"><span class="nav-icon">⚙️</span> Settings</a>
    <?php endif; ?>

    <div class="sidebar-footer">
        <div class="user-row">
            <div class="user-avatar">👤</div>
            <div class="user-meta">
                <div class="user-name"><?= htmlspecialchars($user_name ?? '') ?></div>
                <div class="user-email"><?= htmlspecialchars($user_email ?? '') ?></div>
                <?php
                $badge_style = match($nav_role) {
                    'admin'    => 'background:rgba(167,139,250,.2);color:#a78bfa;border:1px solid rgba(167,139,250,.3)',
                    'operator' => 'background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)',
                    default    => 'background:rgba(52,211,153,.15);color:#34d399;border:1px solid rgba(52,211,153,.3)',
                };
                ?>
                <span style="<?= $badge_style ?>;display:inline-block;padding:2px 8px;border-radius:4px;font-size:.65rem;font-weight:700;text-transform:uppercase;margin-top:3px">
                    <?= htmlspecialchars($nav_role) ?>
                </span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">🚪 Sign Out</a>
    </div>
</aside>

<main class="main">
<div class="topbar">
    <h2><?= htmlspecialchars($page_title ?? 'Dashboard') ?></h2>
    <div class="date-badge" id="live-date">—</div>
</div>
<script>
    function ud(){
        const n=new Date();
        document.getElementById('live-date').textContent=
            n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric'})
            +' • '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
    } ud(); setInterval(ud,1000);
</script>

<?php
// ── Alert banner: only show on non-alerts pages, links directly to specific alert ──
try {
    if (($current_page ?? '') !== 'alerts') {
        $crit_count = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0 AND severity='critical'")->fetchColumn();
        $high_count = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0 AND severity='high'")->fetchColumn();
        if ($crit_count > 0 || $high_count > 0):
            $top_alert = $pdo->query("
                SELECT a.id, a.alert_type, a.severity, a.message, a.created_at, wz.zone_name
                FROM alerts a
                LEFT JOIN water_zones wz ON wz.id = a.zone_id
                WHERE a.is_resolved = 0
                ORDER BY FIELD(a.severity,'critical','high','medium','low'), a.created_at DESC
                LIMIT 1
            ")->fetch();
            $is_crit      = $top_alert['severity'] === 'critical';
            $banner_bg    = $is_crit ? 'rgba(248,113,113,.1)'  : 'rgba(251,191,36,.08)';
            $banner_bdr   = $is_crit ? 'rgba(248,113,113,.35)' : 'rgba(251,191,36,.3)';
            $banner_color = $is_crit ? '#f87171' : '#fbbf24';
            $banner_icon  = $is_crit ? '🚨' : '⚠️';
            $total_unres  = $crit_count + $high_count;
            // Link goes directly to the specific alert anchor
            $alert_link   = 'alerts.php?highlight=' . $top_alert['id'] . '#alert-' . $top_alert['id'];
?>
<div style="background:<?= $banner_bg ?>;border:1px solid <?= $banner_bdr ?>;
            border-radius:11px;padding:10px 14px;margin-bottom:1.25rem;
            display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <span style="font-size:1.1rem;flex-shrink:0"><?= $banner_icon ?></span>
  <div style="flex:1;min-width:0">
    <div style="font-weight:700;font-size:.82rem;color:<?= $banner_color ?>">
      <?= $total_unres ?> active system alert<?= $total_unres > 1 ? 's' : '' ?>
      <?php if ($crit_count > 0): ?> — <strong><?= $crit_count ?> CRITICAL</strong><?php endif; ?>
    </div>
    <div style="font-size:.76rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
      <?= htmlspecialchars($top_alert['zone_name'] ?? 'System') ?> —
      <?= htmlspecialchars($top_alert['alert_type']) ?>
      · <?= date('H:i', strtotime($top_alert['created_at'])) ?>
    </div>
  </div>
  <a href="<?= $alert_link ?>"
     style="padding:5px 13px;border-radius:7px;font-size:.75rem;font-weight:700;
            border:1px solid <?= $banner_bdr ?>;color:<?= $banner_color ?>;
            text-decoration:none;white-space:nowrap;flex-shrink:0">
    See Alert →
  </a>
</div>
<?php endif; }
} catch (Exception $e) {} ?>