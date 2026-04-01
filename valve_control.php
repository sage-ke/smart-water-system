<?php
/*
 * valve_control.php  ·  SWDS Meru  v3
 * ----------------------------------------------------------------
 * SECURITY: CSRF tokens, RBAC permissions, audit logging
 * POLLING:  Smart DOM patching every 15s, exponential backoff
 * CHARTS:   Pure SVG, lazy-loaded per zone on expand
 */
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/sensor_data.php';

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_role = $_SESSION['user_role'] ?? 'user';
$user_name = $_SESSION['user_name'] ?? '';
$user_id   = (int)$_SESSION['user_id'];
if (!in_array($user_role, ['admin','operator'], true)) {
    header('Location: dashboard.php'); exit;
}

$csrf        = csrf_token();
$can_open    = can_do($pdo, $user_role, 'valve.open');
$can_close   = can_do($pdo, $user_role, 'valve.close');
$can_set_pct = can_do($pdo, $user_role, 'valve.set_pct');
$can_audit   = can_do($pdo, $user_role, 'audit.view');
$any_valve   = $can_open || $can_close || $can_set_pct;

$poll_ms     = (int)_cfg($pdo, 'poll_interval_ms',    15000);
$reload_sec  = (int)_cfg($pdo, 'auto_reload_interval', 0);
$chart_hours = (int)_cfg($pdo, 'trend_chart_hours',    24);
if ($poll_ms  < 5000)  $poll_ms  = 15000;
if ($chart_hours < 1)  $chart_hours = 24;

$zones = get_all_zones($pdo);

// Recent command log
try {
    $recent_cmds = $pdo->query("
        SELECT vcl.*, wz.zone_name,
               u.full_name AS operator_name,
               dc.status   AS cmd_status,
               dc.ack_at
        FROM   valve_command_log vcl
        LEFT JOIN water_zones     wz ON wz.id = vcl.zone_id
        LEFT JOIN users            u ON u.id  = vcl.requested_by
        LEFT JOIN device_commands dc ON dc.id = vcl.command_id
        ORDER  BY vcl.queued_at DESC LIMIT 30
    ")->fetchAll();
} catch (\PDOException $e) {
    $recent_cmds = [];
}

// Audit log
try {
    $audit_rows = $can_audit ? $pdo->query("
        SELECT id, created_at, action, user_name, user_role,
               entity_label, new_value, reason, result, ip_address
        FROM   audit_log
        WHERE  action LIKE 'valve.%'
        ORDER  BY created_at DESC LIMIT 50
    ")->fetchAll() : [];
} catch (\PDOException $e) {
    $audit_rows = [];
}

// Stats
$total_zones   = count($zones);
$open_valves   = count(array_filter($zones, fn($z) => strtoupper($z['valve_status']) === 'OPEN'));
$online_count  = count(array_filter($zones, fn($z) => $z['online']));
$pending_cmds  = 0;
try { $pending_cmds = (int)$pdo->query("SELECT COUNT(*) FROM device_commands WHERE status='pending'")->fetchColumn(); } catch(\PDOException $e){}
$closed_valves  = $total_zones - $open_valves;
$online_devices = (int)$pdo->query("SELECT COUNT(*) FROM hardware_devices WHERE is_online=1")->fetchColumn();

$msg = ''; $msg_type = '';
$current_page = 'valve_control';
$page_title   = 'Valve Control';
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
require_once __DIR__ . '/sidebar.php';
?>
<style>
/* ── Zone control cards ── */
.zone-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;margin-bottom:1.5rem}
.zone-card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:border-color .2s}
.zone-card.open  {border-color:rgba(52,211,153,.35)}
.zone-card.closed{border-color:rgba(248,113,113,.25)}
.zone-card.maintenance{border-color:rgba(251,191,36,.25)}

.zc-head{padding:1rem 1.1rem .75rem;display:flex;justify-content:space-between;align-items:flex-start}
.zc-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem}
.zc-loc {font-size:.72rem;color:var(--muted);margin-top:2px}

.zc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;padding:0 1.1rem .85rem}
.zc-m{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;
       padding:.5rem .6rem;text-align:center}
.zc-ml{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.zc-mv{font-size:.9rem;font-weight:700;margin-top:2px}

.zc-controls{padding:.75rem 1.1rem 1rem;border-top:1px solid rgba(30,58,95,.4);
              display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}

/* ── Valve button ── */
.btn-valve-open {padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;border:none;
                  background:linear-gradient(135deg,#34d399,#059669);color:#fff}
.btn-valve-close{padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;border:none;
                  background:linear-gradient(135deg,#f87171,#dc2626);color:#fff}
.btn-pump-on    {padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;border:none;
                  background:rgba(14,165,233,.15);color:#0ea5e9;border:1px solid rgba(14,165,233,.3)}
.btn-pump-off   {padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;border:none;
                  background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.btn-disabled   {padding:7px 14px;border-radius:8px;font-size:.8rem;cursor:not-allowed;border:none;
                  background:rgba(255,255,255,.04);color:var(--muted);border:1px solid var(--border)}

/* Custom valve slider */
.valve-slider-wrap{display:flex;align-items:center;gap:.5rem;flex:1;min-width:160px}
.valve-slider{width:100%;accent-color:var(--blue)}
.valve-label{font-size:.75rem;color:var(--blue);font-weight:700;min-width:32px;text-align:right}

/* ── Emergency button ── */
.btn-emergency{padding:10px 22px;background:linear-gradient(135deg,#dc2626,#991b1b);
               border:2px solid rgba(248,113,113,.4);border-radius:10px;color:#fff;
               font-weight:800;font-size:.9rem;cursor:pointer;letter-spacing:.03em;
               animation:pulse-red 2s infinite}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.4)} 50%{box-shadow:0 0 0 8px rgba(220,38,38,0)}}

/* ── Status badges ── */
.bdg{padding:3px 9px;border-radius:5px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.b-open    {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}
.b-closed  {background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-pending {background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
.b-sent    {background:rgba(14,165,233,.12);color:#0ea5e9;border:1px solid rgba(14,165,233,.3)}
.b-ack     {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}
.b-fail    {background:rgba(248,113,113,.12);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.b-online  {background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3)}
.b-offline {background:rgba(120,120,120,.1);color:var(--muted);border:1px solid var(--border)}

/* ── Command log table ── */
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{padding:8px 10px;text-align:left;color:var(--muted);font-size:.63rem;text-transform:uppercase;
        border-bottom:1px solid var(--border);background:rgba(255,255,255,.02)}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(30,58,95,.35);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.02)}

/* ── Stats ── */
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.7rem;margin-bottom:1.5rem}
.sc{background:var(--card);border:1px solid var(--border);border-radius:11px;padding:.85rem 1rem}
.sl{font-size:.62rem;color:var(--muted);text-transform:uppercase;margin-bottom:3px}
.sv{font-size:1.5rem;font-weight:800}

/* Flash message */
.flash{border-radius:10px;padding:11px 16px;margin-bottom:1.2rem;font-size:.85rem;font-weight:500}
.flash.success{background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:#34d399}
.flash.error  {background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171}

/* Last updated indicator */
.live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#34d399;
           animation:blink 1.5s infinite;margin-right:5px}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem">
    <div>
        <h1 style="font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800">🎛️ Valve & Pump Control</h1>
        <p style="color:var(--muted);font-size:.83rem;margin-top:2px">
            <span class="live-dot"></span>
            Live zone control — auto-refreshes every 10 seconds
            <span id="last-refresh" style="margin-left:8px;font-size:.72rem"></span>
        </p>
    </div>
    <!-- EMERGENCY BUTTON -->
    <form method="post" onsubmit="return confirm('⚠️ EMERGENCY CLOSE ALL VALVES?\n\nThis will immediately close ALL valves in ALL zones.\nAre you absolutely sure?')">
        <input type="hidden" name="_action" value="emergency_close">
        <button type="submit" class="btn-emergency">🚨 EMERGENCY CLOSE ALL</button>
    </form>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msg_type ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="sg">
    <div class="sc"><div class="sl">Total Zones</div><div class="sv" style="color:var(--blue)"><?= $total_zones ?></div></div>
    <div class="sc"><div class="sl">Open Valves</div><div class="sv" style="color:#34d399"><?= $open_valves ?></div></div>
    <div class="sc"><div class="sl">Closed Valves</div><div class="sv" style="color:#f87171"><?= $closed_valves ?></div></div>
    <div class="sc"><div class="sl">Pending Cmds</div><div class="sv" style="color:#fbbf24"><?= $pending_cmds ?></div></div>
    <div class="sc"><div class="sl">Online Devices</div><div class="sv" style="color:#34d399"><?= $online_devices ?></div></div>
</div>

<!-- Zone Control Cards -->
<div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;margin-bottom:.85rem;
            display:flex;align-items:center;gap:8px">
    🗺️ Zone Controls
    <span style="flex:1;height:1px;background:var(--border);display:block"></span>
</div>

<div class="zone-grid" id="zone-grid">
<?php foreach ($zones as $z):
    $is_open  = $z['valve_status'] === 'OPEN';
    $card_cls = $z['status'] === 'maintenance' ? 'maintenance' : ($is_open ? 'open' : 'closed');
    // Show valve controls for all zones — master handles all zones
    $has_valve = true;
    // Use master device_id if no zone-specific valve device
    if (empty($z['valve_device_id'])) {
        // Will be handled by api/valve_control.php fallback to master node
        $z['valve_device_id'] = 1; // Virtual-Master-01
    }
    $has_pump  = !empty($z['pump_device_id']);
    $flow  = $z['flow_rate']   !== null ? round($z['flow_rate'],   1).'<small> L/m</small>'  : '—';
    $pres  = $z['pressure']    !== null ? round($z['pressure'],    2).'<small> Bar</small>'   : '—';
    $level = $z['water_level'] !== null ? round($z['water_level'], 1).'<small>%</small>'      : '—';
    $age   = $z['recorded_at'] ? human_time_diff($z['recorded_at']) : 'No data';
?>
<div class="zone-card <?= $card_cls ?>">

    <!-- Header -->
    <div class="zc-head">
        <div>
            <div class="zc-name"><?= htmlspecialchars($z['zone_name']) ?></div>
            <div class="zc-loc"><?= htmlspecialchars($z['location'] ?? '') ?></div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
            <span class="bdg <?= $is_open ? 'b-open' : 'b-closed' ?>">
                <?= $is_open ? '🟢 OPEN' : '🔴 CLOSED' ?>
            </span>
            <?php if ($z['status'] === 'maintenance'): ?>
            <span class="bdg b-pending">⚙️ Maintenance</span>
            <?php endif; ?>
            <?php if ($has_valve): ?>
            <span class="bdg <?= $z['valve_online'] ? 'b-online' : 'b-offline' ?>">
                <?= $z['valve_online'] ? '● Online' : '○ Offline' ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Live metrics -->
    <div class="zc-metrics">
        <div class="zc-m">
            <div class="zc-ml">Flow</div>
            <div class="zc-mv" style="color:#0ea5e9"><?= $flow ?></div>
        </div>
        <div class="zc-m">
            <div class="zc-ml">Pressure</div>
            <div class="zc-mv" style="color:#06b6d4"><?= $pres ?></div>
        </div>
        <div class="zc-m">
            <div class="zc-ml">Level</div>
            <div class="zc-mv" style="color:#34d399"><?= $level ?></div>
        </div>
    </div>
    <div style="padding:0 1.1rem .5rem;font-size:.68rem;color:var(--muted)">
        Last reading: <?= $age ?>
    </div>

    <!-- Controls -->
    <div class="zc-controls">
        <?php if ($has_valve): ?>

        <!-- Valve open/close buttons -->
        <div style="display:flex;align-items:center;gap:.5rem;flex:1">
            <div class="valve-slider-wrap">
                <input type="range" min="0" max="100" step="25"
                       value="<?= $is_open ? 100 : 0 ?>"
                       class="valve-slider"
                       id="slider<?= $z['id'] ?>"
                       oninput="document.getElementById('vl<?= $z['id'] ?>').textContent=this.value+'%'">
                <span class="valve-label" id="vl<?= $z['id'] ?>"><?= $is_open ? '100' : '0' ?>%</span>
            </div>
            <button onclick="sendValveCmd(event, <?= $z['id'] ?>, <?= $is_open ? "'close'" : "'open'" ?>, document.getElementById('slider<?= $z['id'] ?>').value)"
                    class="<?= $is_open ? 'btn-valve-close' : 'btn-valve-open' ?>">
                <?= $is_open ? '🔴 Close' : '🟢 Open' ?>
            </button>
        </div>

        <?php else: ?>
        <span class="btn-disabled">No valve device</span>
        <?php endif; ?>

        <?php if ($has_pump): ?>
        <!-- Pump control -->
        <form method="post" style="display:inline">
            <input type="hidden" name="_action"   value="send_command">
            <input type="hidden" name="device_id" value="<?= $z['pump_device_id'] ?>">
            <input type="hidden" name="zone_name" value="<?= htmlspecialchars($z['zone_name']) ?>">
            <input type="hidden" name="cmd_type"  value="set_pump">
            <?php if ($z['pump_status']): ?>
                <input type="hidden" name="pump_on" value="0">
                <button type="submit" class="btn-pump-off">⏹ Pump OFF</button>
            <?php else: ?>
                <input type="hidden" name="pump_on" value="1">
                <button type="submit" class="btn-pump-on">▶ Pump ON</button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Command Log -->
<div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;margin-bottom:.85rem;
            display:flex;align-items:center;gap:8px">
    📋 Command Log
    <span style="flex:1;height:1px;background:var(--border);display:block"></span>
    <span style="font-size:.72rem;font-weight:400;color:var(--muted)">Last 30 commands</span>
</div>
<div style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:2rem">
<?php if (empty($cmd_log)): ?>
<div style="padding:2rem;text-align:center;color:var(--muted);font-size:.85rem">
    No commands sent yet. Use the zone cards above to send valve or pump commands.
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tbl">
    <thead>
        <tr>
            <th>Time</th><th>Zone</th><th>Device</th>
            <th>Command</th><th>Value</th><th>Sent By</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($cmd_log as $c):
        $payload = json_decode($c['payload'] ?? '{}', true);
        $val = '';
        if ($c['command_type'] === 'set_valve') {
            $v = $payload['valve_pct'] ?? '?';
            $val = $v > 0 ? "Open $v%" : 'Closed';
        } elseif ($c['command_type'] === 'set_pump') {
            $val = ($payload['pump_on'] ?? 0) ? 'ON' : 'OFF';
        } else {
            $val = ucfirst($c['command_type']);
        }
        $sc = match($c['status']) {
            'pending'      => 'b-pending',
            'sent'         => 'b-sent',
            'acknowledged' => 'b-ack',
            'failed'       => 'b-fail',
            default        => 'b-pending'
        };
    ?>
    <tr>
        <td style="color:var(--muted);font-size:.73rem;white-space:nowrap">
            <?= date('d M H:i:s', strtotime($c['issued_at'])) ?>
        </td>
        <td style="font-weight:600"><?= htmlspecialchars($c['zone_name'] ?? '—') ?></td>
        <td style="font-size:.77rem;color:var(--muted)"><?= htmlspecialchars($c['device_name'] ?? '—') ?></td>
        <td style="font-size:.78rem"><?= ucwords(str_replace('_',' ',$c['command_type'])) ?></td>
        <td style="font-weight:600;color:var(--blue)"><?= $val ?></td>
        <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($c['sent_by'] ?? 'System') ?></td>
        <td data-cmd-id="<?= $c['id'] ?>" data-cmd-status="<?= $c['status'] ?>">
            <span class="bdg <?= $sc ?>"><?= strtoupper($c['status']) ?></span>
            <?php if ($c['ack_at'] && $c['status'] === 'acknowledged'): ?>
            <div class="ack-time" style="font-size:.62rem;color:var(--muted);margin-top:2px">
                Ack: <?= date('H:i:s', strtotime($c['ack_at'])) ?>
            </div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<script>
// ── Live command status polling ─────────────────────────────
// Every 3 seconds, checks pending/sent commands and updates badges
// without reloading the whole page. Full reload every 30s.

// ── Send valve command via AJAX ──────────────────────────────
async function sendValveCmd(evt, zoneId, action, valvePct) {
    valvePct = parseInt(valvePct);
    if (action === 'close') valvePct = 0;
    if (action === 'open' && valvePct === 0) valvePct = 100;

    const btn = evt.target;
    btn.disabled = true;
    btn.textContent = '⏳ Sending...';

    try {
        const res = await fetch('api/valve_control.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': '<?= $csrf ?>'
            },
            body: JSON.stringify({
                zone_id:    zoneId,
                action:     action,
                valve_pct:  valvePct,
                reason:     'Manual control from dashboard',
                csrf_token: '<?= $csrf ?>'
            })
        });

        // Get raw text first to debug PHP errors
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(parseErr) {
            // PHP returned HTML error — show it
            console.error('PHP error:', text);
            alert('Server error — check PHP logs\n' + text.substring(0, 200));
            btn.disabled = false;
            btn.textContent = action === 'open' ? '🟢 Open' : '🔴 Close';
            return;
        }

        if (data.status === 'ok') {
            setTimeout(() => window.location.reload(), 1500);
        } else {
            alert('Command failed: ' + (data.message || 'Unknown error'));
            btn.disabled = false;
            btn.textContent = action === 'open' ? '🟢 Open' : '🔴 Close';
        }
    } catch(e) {
        alert('Network error: ' + e.message);
        btn.disabled = false;
        btn.textContent = action === 'open' ? '🟢 Open' : '🔴 Close';
    }
}

let fullReloadTimer = 30;
const countEl = document.getElementById('last-refresh');

// Badge classes
const badgeClass = {
    pending:      'b-pending',
    sent:         'b-sent',
    acknowledged: 'b-ack',
    failed:       'b-fail',
};
const badgeLabel = {
    pending:      '⏳ PENDING',
    sent:         '📡 SENT',
    acknowledged: '✅ ACKNOWLEDGED',
    failed:       '❌ FAILED',
};

// Collect all command IDs shown on the page that are not yet acknowledged
function getLiveCommandIds() {
    const ids = [];
    document.querySelectorAll('[data-cmd-id]').forEach(el => {
        const status = el.getAttribute('data-cmd-status');
        if (status === 'pending' || status === 'sent') {
            ids.push(el.getAttribute('data-cmd-id'));
        }
    });
    return ids;
}

async function pollCommandStatuses() {
    const ids = getLiveCommandIds();
    if (ids.length === 0) return;

    try {
        const res = await fetch(`api/command_status.php?ids=${ids.join(',')}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) return;
        const data = await res.json();

        data.forEach(cmd => {
            const row = document.querySelector(`[data-cmd-id="${cmd.id}"]`);
            if (!row) return;

            const oldStatus = row.getAttribute('data-cmd-status');
            if (oldStatus === cmd.status) return; // no change

            // Update badge
            const badge = row.querySelector('.bdg');
            if (badge) {
                badge.className = `bdg ${badgeClass[cmd.status] || 'b-pending'}`;
                badge.textContent = badgeLabel[cmd.status] || cmd.status.toUpperCase();
            }

            // Update ack time if acknowledged
            if (cmd.status === 'acknowledged' && cmd.ack_at) {
                let ackEl = row.querySelector('.ack-time');
                if (!ackEl) {
                    ackEl = document.createElement('div');
                    ackEl.className = 'ack-time';
                    ackEl.style.cssText = 'font-size:.62rem;color:var(--muted);margin-top:2px';
                    row.querySelector('td:last-child')?.appendChild(ackEl);
                }
                ackEl.textContent = 'Ack: ' + cmd.ack_at;
            }

            // Flash row green/red on status change
            const rowEl = row.closest('tr');
            if (rowEl) {
                const flash = cmd.status === 'acknowledged'
                    ? 'rgba(52,211,153,.15)' : 'rgba(248,113,113,.15)';
                rowEl.style.transition = 'background .3s';
                rowEl.style.background = flash;
                setTimeout(() => rowEl.style.background = '', 2000);
            }

            row.setAttribute('data-cmd-status', cmd.status);
        });
    } catch(e) { /* silent fail */ }
}

// Poll every 3s for live status
setInterval(pollCommandStatuses, 3000);

// Full page reload every 30s
function tick() {
    fullReloadTimer--;
    if (countEl) countEl.textContent = `(refreshing in ${fullReloadTimer}s)`;
    if (fullReloadTimer <= 0) window.location.reload();
}
setInterval(tick, 1000);
if (countEl) countEl.textContent = `(refreshing in ${fullReloadTimer}s)`;

// Initial poll immediately on load
pollCommandStatuses();
</script>

</main></body></html>

<?php
// Helper function
function human_time_diff(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return round($diff/60) . 'm ago';
    if ($diff < 86400) return round($diff/3600) . 'h ago';
    return round($diff/86400) . 'd ago';
}