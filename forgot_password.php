<?php
/*
 * forgot_password.php — SWDS Meru
 * Password reset flow:
 *   Step 1 — User enters email → token generated → shown on screen (localhost)
 *   Step 2 — User clicks reset link → enters new password
 */
session_start();
require_once __DIR__ . '/db.php';

$step    = isset($_GET['token']) ? 2 : 1;
$token   = trim($_GET['token'] ?? '');
$msg     = '';
$msg_type= 'error';

// ── Ensure reset_tokens column exists ────────────────────────
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL");
} catch(PDOException $e) {}

// ── Step 1: Email submitted ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $msg = 'Please enter your email address.';
    } else {
        $user = $pdo->prepare("SELECT id, full_name FROM users WHERE email=?");
        $user->execute([$email]);
        $user = $user->fetch();

        if ($user) {
            $tok = bin2hex(random_bytes(32));
            // Use MySQL time to avoid PHP/MySQL timezone mismatch
            $pdo->prepare("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
                ->execute([$tok, $user['id']]);

            $reset_url = "http://{$_SERVER['HTTP_HOST']}/smart_water/forgot_password.php?token=$tok";
            $msg      = "success|{$user['full_name']}|$reset_url";

            // Try to send reset email automatically
            require_once __DIR__ . '/api/mailer.php';
            $mail_result = send_password_reset_email($pdo, $email, $user['full_name'], $reset_url);
            $email_sent  = $mail_result['sent'] ?? false;
        } else {
            // Don't reveal if email exists — security best practice
            $msg = "success_generic";
        }
    }
}

// ── Step 2: New password submitted ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 6) {
        $msg = 'Password must be at least 6 characters.';
    } elseif ($pass1 !== $pass2) {
        $msg = 'Passwords do not match.';
    } else {
        $user = $pdo->prepare("SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW()");
        $user->execute([$token]);
        $user = $user->fetch();

        if (!$user) {
            $msg = 'This reset link has expired or is invalid. Please request a new one.';
        } else {
            $hash = password_hash($pass1, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
                ->execute([$hash, $user['id']]);
            $msg      = 'done';
            $msg_type = 'success';
        }
    }
}

// Validate token for step 2 display
$token_valid = false;
$token_user  = null;
if ($step === 2 && $token) {
    $t = $pdo->prepare("SELECT full_name FROM users WHERE reset_token=? AND reset_expires > NOW()");
    $t->execute([$token]);
    $token_user = $t->fetch();
    $token_valid = (bool)$token_user;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password — SWDS Meru</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#060f1e;display:flex;align-items:center;justify-content:center;
     font-family:'DM Sans',sans-serif;color:#e2e8f0;padding:1rem}
.card{background:rgba(10,25,50,.8);border:1px solid rgba(14,165,233,.2);border-radius:20px;
      padding:2.5rem;width:100%;max-width:420px;backdrop-filter:blur(20px)}
.logo{text-align:center;margin-bottom:2rem}
.logo h1{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;
          background:linear-gradient(135deg,#0ea5e9,#06b6d4);-webkit-background-clip:text;
          -webkit-text-fill-color:transparent}
.logo p{font-size:.78rem;color:#64748b;margin-top:4px}
label{display:block;font-size:.78rem;color:#94a3b8;margin-bottom:6px;font-weight:500}
input{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(30,58,95,.6);
      border-radius:10px;padding:11px 14px;color:#e2e8f0;font-family:'DM Sans',sans-serif;
      font-size:.9rem;outline:none;transition:border-color .2s;margin-bottom:1rem}
input:focus{border-color:rgba(14,165,233,.5)}
button{width:100%;padding:12px;background:linear-gradient(135deg,#0ea5e9,#06b6d4);
       border:none;border-radius:10px;color:#fff;font-weight:700;font-size:.95rem;
       cursor:pointer;font-family:'DM Sans',sans-serif;margin-top:.5rem}
button:hover{opacity:.9}
.alert{padding:12px 16px;border-radius:10px;font-size:.83rem;margin-bottom:1.2rem;line-height:1.5}
.alert.error{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171}
.alert.success{background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:#34d399}
.alert.info{background:rgba(14,165,233,.1);border:1px solid rgba(14,165,233,.3);color:#38bdf8}
.back{text-align:center;margin-top:1.5rem;font-size:.82rem;color:#64748b}
.back a{color:#0ea5e9;text-decoration:none}
.reset-link{word-break:break-all;background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.2);
            border-radius:8px;padding:10px 12px;font-size:.75rem;color:#38bdf8;margin-top:8px;display:block}
.expired{text-align:center;padding:1rem 0}
.expired h3{color:#f87171;font-family:'Syne',sans-serif;margin-bottom:.5rem}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>💧 SWDS Meru</h1>
        <p>Smart Water Distribution System</p>
    </div>

    <?php
    // ── STEP 2: Token expired or invalid ─────────────────────
    if ($step === 2 && !$token_valid && $msg !== 'done'):
    ?>
    <div class="expired">
        <h3>⏰ Link Expired</h3>
        <p style="color:#94a3b8;font-size:.85rem">This reset link has expired or already been used.</p>
        <a href="forgot_password.php" style="display:inline-block;margin-top:1rem;color:#0ea5e9;font-size:.85rem">
            ← Request a new link
        </a>
    </div>

    <?php
    // ── STEP 2: Password successfully reset ───────────────────
    elseif ($msg === 'done'):
    ?>
    <div class="alert success">
        ✅ Password reset successfully! You can now log in with your new password.
    </div>
    <div class="back"><a href="login.php">→ Go to Login</a></div>

    <?php
    // ── STEP 2: Set new password form ─────────────────────────
    elseif ($step === 2 && $token_valid):
    ?>
    <h2 style="font-family:'Syne',sans-serif;font-size:1.1rem;margin-bottom:.4rem">Set New Password</h2>
    <p style="font-size:.8rem;color:#64748b;margin-bottom:1.5rem">
        Hello <?= htmlspecialchars($token_user['full_name']) ?>, enter your new password below.
    </p>

    <?php if($msg && $msg !== 'done'): ?>
    <div class="alert error"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php?token=<?= htmlspecialchars($token) ?>">
        <label>New Password</label>
        <input type="password" name="password" placeholder="Min 6 characters" required>
        <label>Confirm Password</label>
        <input type="password" name="password2" placeholder="Repeat password" required>
        <button type="submit">🔒 Reset Password</button>
    </form>

    <?php
    // ── STEP 1: Email submitted — show result ─────────────────
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1):
        if (str_starts_with($msg, 'success|')):
            [,$name,$url] = explode('|', $msg, 3);
    ?>
    <div class="alert success">
        ✅ Reset link generated for <strong><?= htmlspecialchars($name) ?></strong>
    </div>
    <div style="font-size:.8rem;color:#94a3b8;margin-bottom:.5rem">
        📋 Your reset link (click or copy):
    </div>
    <a href="<?= htmlspecialchars($url) ?>" class="reset-link"><?= htmlspecialchars($url) ?></a>
    <div style="font-size:.73rem;color:#64748b;margin-top:.75rem">
        <?php if(isset($email_sent) && $email_sent): ?>
        ✉️ Reset link also sent to your email address.
        <?php else: ?>
        ⏰ This link expires in 1 hour. Configure SMTP in Settings to send reset links by email automatically.
        <?php endif; ?>
    </div>
    <div class="back" style="margin-top:1rem"><a href="login.php">← Back to Login</a></div>

    <?php else: ?>
    <div class="alert info">
        If that email is registered, a reset link has been sent. Check your inbox.<br>
        <small style="opacity:.7">(On localhost, re-enter your email to see the link)</small>
    </div>
    <div class="back"><a href="forgot_password.php">← Try again</a></div>
    <?php
        endif;
    // ── STEP 1: Show email form ───────────────────────────────
    else:
    ?>
    <h2 style="font-family:'Syne',sans-serif;font-size:1.1rem;margin-bottom:.4rem">Forgot Password?</h2>
    <p style="font-size:.8rem;color:#64748b;margin-bottom:1.5rem">
        Enter your registered email and we'll generate a reset link.
    </p>

    <?php if($msg && !str_starts_with($msg,'success')): ?>
    <div class="alert error"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="your@email.com" required autofocus>
        <button type="submit">🔑 Generate Reset Link</button>
    </form>
    <?php endif; ?>

    <div class="back"><a href="login.php">← Back to Login</a></div>
</div>
</body>
</html>