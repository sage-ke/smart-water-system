<?php
session_start();
require_once __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if (!in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
    header("Location: dashboard.php"); exit;
}
$user_name=$_SESSION['user_name']; $user_email=$_SESSION['user_email'];
$user_role=$_SESSION['user_role']; $current_page='users'; $page_title='Users';
$total_alerts=(int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE is_resolved=0")->fetchColumn();
$msg=''; $msg_type='';

// ── Change OWN password ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_own_password') {
    $current=$_POST['current_password']??'';
    $new_pw=$_POST['new_password']??'';
    $confirm=$_POST['confirm_password']??'';
    $me=$pdo->prepare("SELECT * FROM users WHERE id=?");
    $me->execute([$_SESSION['user_id']]); $me=$me->fetch();
    if (!password_verify($current,$me['password'])) {
        $msg='❌ Current password is wrong.'; $msg_type='error';
    } elseif (strlen($new_pw)<6) {
        $msg='❌ New password must be at least 6 characters.'; $msg_type='error';
    } elseif ($new_pw!==$confirm) {
        $msg='❌ New passwords do not match.'; $msg_type='error';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([password_hash($new_pw,PASSWORD_BCRYPT),$_SESSION['user_id']]);
        $msg='✅ Password changed successfully.'; $msg_type='success';
    }
}

// ── Admin resets another user's password ─────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reset_password') {
    $id=(int)($_POST['user_id']??0);
    $new_pw=trim($_POST['new_password']??'');
    if ($id && strlen($new_pw)>=6) {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([password_hash($new_pw,PASSWORD_BCRYPT),$id]);
        $msg='✅ Password reset successfully.'; $msg_type='success';
    } else { $msg='❌ Password must be at least 6 characters.'; $msg_type='error'; }
}

// ── Change role ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_role') {
    $id=(int)($_POST['user_id']??0); $role=$_POST['role']??'viewer';
    if ($id!==(int)$_SESSION['user_id']) {
        $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$id]);

    // Assign zone to user
    } elseif ($action === 'assign_zone') {
        $id      = (int)($_POST['user_id'] ?? 0);
        $zone_id = (int)($_POST['zone_id'] ?? 0) ?: null;
        if ($id) {
            $pdo->prepare("UPDATE users SET zone_id=? WHERE id=?")->execute([$zone_id, $id]);
            $msg = "Zone assigned successfully.";
        }
        $msg='✅ Role updated.'; $msg_type='success';
    } else { $msg='You cannot change your own role.'; $msg_type='error'; }
}

// ── Delete user ───────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id=(int)$_GET['delete'];
    if ($del_id!==(int)$_SESSION['user_id']) {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
        header("Location: users.php?deleted=1"); exit;
    } else { $msg='You cannot delete your own account.'; $msg_type='error'; }
}
if (isset($_GET['deleted'])) { $msg='🗑️ User deleted.'; $msg_type='success'; }

// ── Add user ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_user') {
    $fn=trim($_POST['full_name']??''); $em=trim($_POST['email']??'');
    $pw=trim($_POST['password']??''); $rl=$_POST['role']??'viewer';
    if ($fn && $em && strlen($pw)>=6) {
        $check=$pdo->prepare("SELECT id FROM users WHERE email=?"); $check->execute([$em]);
        if ($check->fetch()) { $msg='Email already exists.'; $msg_type='error'; }
        else {
            $pdo->prepare("INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,?)")
                ->execute([$fn,$em,password_hash($pw,PASSWORD_BCRYPT),$rl]);
            $msg='✅ User added successfully.'; $msg_type='success';
        }
    } else { $msg='❌ All fields required. Password min 6 chars.'; $msg_type='error'; }
}

$users=$pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
$admin_count=count(array_filter($users,fn($u)=>$u['role']==='admin'));
$res_count=count(array_filter($users,fn($u)=>in_array($u['role'],['viewer','user'])));
require_once __DIR__ . '/sidebar.php';
?>
<style>
.pw-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center}
.pw-modal.open{display:flex}
.pw-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.75rem;width:100%;max-width:400px;position:relative}
.pw-box h3{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;margin-bottom:1.2rem}
.pw-close{position:absolute;top:12px;right:14px;cursor:pointer;color:var(--muted);font-size:1.2rem;background:none;border:none;padding:0}
.fi{width:100%;padding:10px 12px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:.88rem;margin-bottom:1rem}
.fi:focus{outline:none;border-color:var(--blue)}
.fl{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px}
.btn-pw{width:100%;padding:11px;background:linear-gradient(135deg,var(--blue),var(--teal));border:none;border-radius:9px;color:#fff;font-weight:700;cursor:pointer;font-size:.9rem}
.badge-admin{background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3);padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700}
.badge-operator{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3);padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700}
.badge-viewer,.badge-user{background:rgba(52,211,153,.1);color:#34d399;border:1px solid rgba(52,211,153,.25);padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700}
</style>

<?php if ($msg): ?>
<div class="alert-box alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-label">Total Users</div><div class="stat-value c-blue"><?= count($users) ?></div></div>
    <div class="stat-card"><div class="stat-icon">🛡️</div><div class="stat-label">Admins</div><div class="stat-value c-purple"><?= $admin_count ?></div></div>
    <div class="stat-card"><div class="stat-icon">👤</div><div class="stat-label">Residents</div><div class="stat-value c-green"><?= $res_count ?></div></div>
</div>

<!-- CHANGE OWN PASSWORD -->
<div class="section-title" style="margin-top:1.5rem">🔑 Change Your Password</div>
<div class="card"><div class="card-body">
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;align-items:end">
        <input type="hidden" name="action" value="change_own_password">
        <div class="form-group">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" placeholder="Your current password" required>
        </div>
        <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters" required>
        </div>
        <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required>
        </div>
        <div class="form-group">
            <button type="submit" class="btn-primary" style="width:100%;margin-top:1.4rem">Update Password</button>
        </div>
    </form>
</div></div>

<!-- ALL USERS TABLE -->
<div class="section-title" style="margin-top:1.5rem">👥 All Users</div>
<div class="card"><div class="table-wrap">
    <table>
        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td style="color:var(--muted)"><?= $u['id'] ?></td>
            <td style="font-weight:500">
                <?= htmlspecialchars($u['full_name']) ?>
                <?php if ($u['id']==$_SESSION['user_id']): ?><span style="font-size:.7rem;color:var(--blue);margin-left:6px">(You)</span><?php endif; ?>
            </td>
            <td style="color:var(--muted)"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
            <td style="font-size:.82rem;color:var(--muted)"><?= date('d M Y',strtotime($u['created_at'])) ?></td>
            <td style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if ($u['id']!=$_SESSION['user_id']): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <select name="role" class="form-control" style="padding:4px 8px;font-size:.78rem;width:auto" onchange="this.form.submit()">
                        <option value="user"  <?= in_array($u['role'],['viewer','user'])?'selected':'' ?>>User</option>
                        <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                    </select>
                </form>
                <button onclick="openReset(<?= $u['id'] ?>,'<?= htmlspecialchars(addslashes($u['full_name'])) ?>')"
                    style="background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.3);padding:4px 10px;border-radius:6px;font-size:.75rem;cursor:pointer">
                    🔑 Reset PW
                </button>
                <a href="?delete=<?= $u['id'] ?>" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($u['full_name'])) ?>?')"
                   style="color:var(--red);text-decoration:none;font-size:.82rem">🗑️</a>
            <?php else: ?><span style="color:var(--muted);font-size:.78rem">—</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<!-- ADD USER -->
<div class="section-title" style="margin-top:1.5rem">➕ Add New User</div>
<div class="card"><div class="card-body">
    <form method="post">
        <input type="hidden" name="action" value="add_user">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem">
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" placeholder="Full name" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email *</label>
                <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password *</label>
                <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required>
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-control">
                    <option value="viewer">Viewer (Resident)</option>
                    <option value="operator">Operator</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:.5rem">Add User</button>
    </form>
</div></div>

<!-- RESET PASSWORD MODAL -->
<div class="pw-modal" id="resetModal">
    <div class="pw-box">
        <button class="pw-close" onclick="closeReset()">✕</button>
        <h3>🔑 Reset Password — <span id="resetName" style="color:var(--blue)"></span></h3>
        <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">
            <label class="fl">New Password (min 6 characters)</label>
            <input type="password" name="new_password" class="fi" placeholder="Enter new password" required>
            <button type="submit" class="btn-pw">Set New Password</button>
        </form>
    </div>
</div>

<script>
function openReset(id,name){
    document.getElementById('resetUserId').value=id;
    document.getElementById('resetName').textContent=name;
    document.getElementById('resetModal').classList.add('open');
}
function closeReset(){document.getElementById('resetModal').classList.remove('open');}
document.getElementById('resetModal').addEventListener('click',function(e){if(e.target===this)closeReset();});
</script>
</main></body></html>