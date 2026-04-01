<?php
/*
 * settings.php — Account & System Settings
 */
session_start(); require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_name  = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];
$user_role  = $_SESSION['user_role'];
$current_page = 'settings';
$page_title   = 'Settings';

$total_alerts = $pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
$msg = ''; $msg_type = '';

// ── Update profile ────────────────────────────────────────────
// ── Save Email/SMTP settings ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_email') {
    $fields = ['email_enabled','email_from','email_from_name',
                'smtp_host','smtp_port','smtp_user','smtp_pass',
                'smtp_secure','admin_email'];
    foreach($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_val)
                VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
                ->execute([$key,$val,$val]);
        } catch(PDOException $e) {}
    }
    $email_saved = true;
}

// ── Load Email settings ────────────────────────────────────────
$email_cfg = ['email_enabled'=>'0','email_from'=>'','email_from_name'=>'SWDS Meru',
              'smtp_host'=>'','smtp_port'=>'587','smtp_user'=>'','smtp_pass'=>'',
              'smtp_secure'=>'tls','admin_email'=>''];
try {
    $rows = $pdo->query("SELECT setting_key,setting_val FROM system_settings
        WHERE setting_key LIKE 'email_%' OR setting_key LIKE 'smtp_%'
           OR setting_key='admin_email'")->fetchAll();
    foreach($rows as $r) $email_cfg[$r['setting_key']] = $r['setting_val'];
} catch(PDOException $e) {}

// ── Save SMS settings ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_sms') {
    $fields = ['sms_enabled','sms_api_key','sms_username','sms_phone','sms_sender'];
    foreach($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_val)
                VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
                ->execute([$key,$val,$val]);
        } catch(PDOException $e) {}
    }
    $sms_saved = true;
}

// ── Load SMS settings ─────────────────────────────────────────
$sms_cfg = ['sms_enabled'=>'0','sms_api_key'=>'','sms_username'=>'sandbox','sms_phone'=>'','sms_sender'=>'SWDS'];
try {
    $rows = $pdo->query("SELECT setting_key,setting_val FROM system_settings WHERE setting_key LIKE 'sms_%'")->fetchAll();
    foreach($rows as $r) $sms_cfg[$r['setting_key']] = $r['setting_val'];
} catch(PDOException $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_profile') {
    $fn = trim($_POST['full_name'] ?? '');
    $em = trim($_POST['email']     ?? '');
    if ($fn && $em) {
        // Check email not taken by another user
        $check = $pdo->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $check->execute([$em, $_SESSION['user_id']]);
        if ($check->fetch()) {
            $msg = "That email is already used by another account."; $msg_type = 'error';
        } else {
            $pdo->prepare("UPDATE users SET full_name=?, email=? WHERE id=?")
                ->execute([$fn, $em, $_SESSION['user_id']]);
            // Update session too
            $_SESSION['user_name']  = $fn;
            $_SESSION['user_email'] = $em;
            $user_name = $fn; $user_email = $em;
            $msg = "✅ Profile updated successfully."; $msg_type = 'success';
        }
    }
}

// ── Change password ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_password') {
    $old  = $_POST['old_password']     ?? '';
    $new  = $_POST['new_password']     ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    $user_row = $pdo->prepare("SELECT password FROM users WHERE id=?");
    $user_row->execute([$_SESSION['user_id']]);
    $user_row = $user_row->fetch();

    if (!password_verify($old, $user_row['password'])) {
        $msg = "Current password is incorrect."; $msg_type = 'error';
    } elseif (strlen($new) < 6) {
        $msg = "New password must be at least 6 characters."; $msg_type = 'error';
    } elseif ($new !== $conf) {
        $msg = "New passwords do not match."; $msg_type = 'error';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
        $msg = "✅ Password changed successfully."; $msg_type = 'success';
    }
}

require_once __DIR__ . '/sidebar.php';
?>

<?php if ($msg): ?>
    <div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="grid-2" style="margin-bottom:1.5rem;">

    <!-- Profile settings -->
    <div>
        <div class="section-title">👤 Profile Settings</div>
        <div class="card">
            <div class="card-body">
                <form method="post" action="settings.php">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?= htmlspecialchars($user_name) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($user_email) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user_role) ?>" disabled style="opacity:0.6">
                        <div style="font-size:0.75rem;color:var(--muted);margin-top:4px">Role can only be changed by an admin.</div>
                    </div>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change password -->
    <div>
        <div class="section-title">🔒 Change Password</div>
        <div class="card">
            <div class="card-body">
                <form method="post" action="settings.php">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- SMS Alert Settings -->
<div class="section-title" style="margin-top:1.5rem">📱 SMS Alert Settings — Africa's Talking</div>
<div class="card">
    <div class="card-body">
        <?php if(isset($sms_saved)): ?>
        <div style="background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);border-radius:8px;
             padding:10px 14px;color:#34d399;font-size:.85rem;margin-bottom:1rem">
            ✅ SMS settings saved successfully.
        </div>
        <?php endif; ?>

        <div style="font-size:.82rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.6">
            SMS alerts are sent for <strong style="color:#f87171">critical</strong> and
            <strong style="color:#fbbf24">high</strong> severity events only.
            Get a free account at <a href="https://africastalking.com" target="_blank"
            style="color:var(--blue)">africastalking.com</a> — use <em>sandbox</em>
            username for testing (no real SMS sent).
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_sms">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">
                        Enable SMS Alerts
                    </label>
                    <select name="sms_enabled" style="width:100%;background:rgba(255,255,255,.05);
                        border:1px solid var(--border);border-radius:8px;padding:9px 12px;
                        color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                        <option value="1" <?= $sms_cfg['sms_enabled']==='1'?'selected':'' ?>>✅ Enabled</option>
                        <option value="0" <?= $sms_cfg['sms_enabled']!=='1'?'selected':'' ?>>❌ Disabled</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">
                        Username (use <em>sandbox</em> for testing)
                    </label>
                    <input type="text" name="sms_username" value="<?= htmlspecialchars($sms_cfg['sms_username']) ?>"
                        placeholder="sandbox"
                        style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
                               border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">
                        API Key
                    </label>
                    <input type="text" name="sms_api_key" value="<?= htmlspecialchars($sms_cfg['sms_api_key']) ?>"
                        placeholder="Paste your Africa's Talking API key"
                        style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
                               border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">
                        Admin Phone Number
                    </label>
                    <input type="text" name="sms_phone" value="<?= htmlspecialchars($sms_cfg['sms_phone']) ?>"
                        placeholder="+254712345678"
                        style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
                               border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88mm">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">
                        Sender ID (optional)
                    </label>
                    <input type="text" name="sms_sender" value="<?= htmlspecialchars($sms_cfg['sms_sender']) ?>"
                        placeholder="SWDS"
                        style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
                               border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
            </div>
            <button type="submit" style="padding:9px 22px;background:linear-gradient(135deg,#0ea5e9,#06b6d4);
                border:none;border-radius:8px;color:#fff;font-weight:700;font-size:.88rem;cursor:pointer">
                💾 Save SMS Settings
            </button>
        </form>
    </div>
</div>

<!-- Email / SMTP Settings -->
<div class="section-title" style="margin-top:1.5rem">📧 Email & SMTP Settings</div>
<div class="card">
    <div class="card-body">
        <?php if(isset($email_saved)): ?>
        <div style="background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);border-radius:8px;
             padding:10px 14px;color:#34d399;font-size:.85rem;margin-bottom:1rem">
            ✅ Email settings saved successfully.
        </div>
        <?php endif; ?>

        <div style="font-size:.82rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.6">
            For <strong>localhost testing</strong>, use
            <a href="https://mailtrap.io" target="_blank" style="color:var(--blue)">Mailtrap.io</a>
            (free) — it catches all emails in a test inbox without sending real mail.
            For <strong>production</strong>, use Gmail SMTP with an App Password.
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_email">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">Enable Email Alerts</label>
                    <select name="email_enabled" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                        <option value="1" <?= $email_cfg['email_enabled']==='1'?'selected':'' ?>>✅ Enabled</option>
                        <option value="0" <?= $email_cfg['email_enabled']!=='1'?'selected':'' ?>>❌ Disabled</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">Admin Email (receives alerts)</label>
                    <input type="email" name="admin_email" value="<?= htmlspecialchars($email_cfg['admin_email']) ?>" placeholder="admin@meru.go.ke" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">From Email</label>
                    <input type="email" name="email_from" value="<?= htmlspecialchars($email_cfg['email_from']) ?>" placeholder="swds@meru.go.ke" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">From Name</label>
                    <input type="text" name="email_from_name" value="<?= htmlspecialchars($email_cfg['email_from_name']) ?>" placeholder="SWDS Meru" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>

                <div style="grid-column:1/-1"><hr style="border:none;border-top:1px solid var(--border);margin:.5rem 0">
                    <div style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem;font-weight:600">SMTP Configuration</div>
                </div>

                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($email_cfg['smtp_host']) ?>" placeholder="smtp.mailtrap.io or smtp.gmail.com" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?= htmlspecialchars($email_cfg['smtp_port']) ?>" placeholder="587" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">SMTP Username</label>
                    <input type="text" name="smtp_user" value="<?= htmlspecialchars($email_cfg['smtp_user']) ?>" placeholder="your@email.com" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">SMTP Password / App Password</label>
                    <input type="password" name="smtp_pass" value="<?= htmlspecialchars($email_cfg['smtp_pass']) ?>" placeholder="••••••••" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--muted);display:block;margin-bottom:5px">Encryption</label>
                    <select name="smtp_secure" style="width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:#e2e8f0;font-family:'DM Sans',sans-serif;font-size:.88rem">
                        <option value="tls" <?= $email_cfg['smtp_secure']==='tls'?'selected':'' ?>>TLS (port 587)</option>
                        <option value="ssl" <?= $email_cfg['smtp_secure']==='ssl'?'selected':'' ?>>SSL (port 465)</option>
                        <option value="none" <?= $email_cfg['smtp_secure']==='none'?'selected':'' ?>>None (port 25)</option>
                    </select>
                </div>
            </div>
            <button type="submit" style="padding:9px 22px;background:linear-gradient(135deg,#0ea5e9,#06b6d4);
                border:none;border-radius:8px;color:#fff;font-weight:700;font-size:.88rem;cursor:pointer">
                💾 Save Email Settings
            </button>
        </form>
    </div>
</div>

<!-- System Info Card -->
<div class="section-title">ℹ️ System Information</div>
<div class="card">
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem;">
            <?php
            $info = [
                ['label'=>'System Name',    'value'=>'Smart Water Distribution System', 'icon'=>'💧'],
                ['label'=>'Location',        'value'=>'Meru County, Kenya',              'icon'=>'📍'],
                ['label'=>'PHP Version',     'value'=>phpversion(),                       'icon'=>'🐘'],
                ['label'=>'Database',        'value'=>'MySQL (PDO) — meru',              'icon'=>'🗄️'],
                ['label'=>'Current User',    'value'=>$user_name.' ('.$user_role.')',     'icon'=>'👤'],
                ['label'=>'Server Date',     'value'=>date('d M Y'),                      'icon'=>'📅'],
            ];
            foreach($info as $item): ?>
            <div style="display:flex;align-items:flex-start;gap:12px;">
                <div style="font-size:1.4rem;margin-top:2px"><?= $item['icon'] ?></div>
                <div>
                    <div style="font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em"><?= $item['label'] ?></div>
                    <div style="font-size:0.9rem;font-weight:500;margin-top:2px"><?= htmlspecialchars($item['value']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</main></body></html>