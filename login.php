<?php
/*
 * ============================================================
 *  login.php — User Login Page
 * ============================================================
 *  When the user submits the form:
 *  1. Look up the email in the database
 *  2. Use password_verify() to compare the entered password
 *     with the stored hashed password
 *  3. If valid → create a SESSION and redirect to dashboard
 *  4. If invalid → show an error message
 *
 *  SESSIONS explained:
 *  PHP sessions store information on the SERVER linked to a
 *  unique session ID stored in the user's browser cookie.
 *  This is how the site "remembers" who is logged in.
 * ============================================================
 */

session_start();
ob_start(); // prevent stray output before headers
require_once __DIR__ . '/db.php';

// If already logged in AND this is not a POST (new login attempt),
// redirect to appropriate dashboard — but allow POST to override
// so switching accounts always works
if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $r = $_SESSION['user_role'] ?? 'viewer';
    header("Location: " . (in_array($r, ['admin','operator']) ? 'dashboard.php' : 'user_dashboard.php'));
    exit;
}

$error = '';

// Pick up any success message left by register.php
$success = $_SESSION['success_msg'] ?? '';
unset($_SESSION['success_msg']); // Remove it after reading (show only once)

// ============================================================
//  Process the login form submission
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please fill in both email and password.";
    } else {
        // Look up the user by their email address
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(); // Returns the user row as an array, or false if not found

        if ($user && password_verify($password, $user['password'])) {
            /*
             * password_verify($entered, $hashed) — checks if the plain text
             * password matches the bcrypt hash stored in the database.
             * Returns TRUE if they match, FALSE if not.
             */

            // ✅ Login successful — clear any old session first, then save new one
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true); // prevent session fixation attacks
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email']= $user['email'];
            $_SESSION['user_role'] = $user['role'];

            // Redirect based on role
            if (in_array($user['role'] ?? 'user', ['admin','operator'])) {
                header("Location: dashboard.php");
            } else {
                header("Location: user_dashboard.php");
            }
            exit;

        } else {
            // ❌ Wrong email or password
            $error = "Invalid email or password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Smart Water Distribution System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:    #0ea5e9;
            --teal:    #06b6d4;
            --dark:    #0a1628;
            --card:    #0f2040;
            --border:  #1e3a5f;
            --text:    #e2eaf4;
            --muted:   #7a9bba;
            --error:   #f87171;
            --success: #34d399;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--dark);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background-image:
                radial-gradient(ellipse at 10% 20%, rgba(14,165,233,0.12) 0%, transparent 50%),
                radial-gradient(ellipse at 90% 80%, rgba(6,182,212,0.10) 0%, transparent 50%);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }

        .brand {
            display: flex; align-items: center; gap: 12px; margin-bottom: 2rem;
        }
        .brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border-radius: 12px; display: grid; place-items: center; font-size: 1.4rem;
        }
        .brand-text h1 { font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 700; }
        .brand-text p { font-size: 0.75rem; color: var(--muted); }

        h2 { font-family: 'Syne', sans-serif; font-size: 1.6rem; font-weight: 800; margin-bottom: 0.4rem; }
        .subtitle { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.8rem; }

        .alert {
            border-radius: 10px; padding: 12px 16px; margin-bottom: 1.5rem; font-size: 0.875rem;
        }
        .alert-error   { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: var(--error); }
        .alert-success { background: rgba(52,211,153,0.1);  border: 1px solid rgba(52,211,153,0.3);  color: var(--success); }

        label {
            display: block; font-size: 0.82rem; font-weight: 500; color: var(--muted);
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;
        }
        input[type="email"], input[type="password"] {
            width: 100%; padding: 12px 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 10px; color: var(--text);
            font-size: 0.95rem; font-family: 'DM Sans', sans-serif;
            transition: border-color 0.2s; margin-bottom: 1.2rem;
        }
        input:focus { outline: none; border-color: var(--blue); background: rgba(14,165,233,0.05); }

        .btn {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border: none; border-radius: 10px; color: #fff;
            font-size: 1rem; font-weight: 600; font-family: 'Syne', sans-serif;
            cursor: pointer; transition: opacity 0.2s; margin-top: 0.5rem;
        }
        .btn:hover { opacity: 0.9; }

        .footer-link { text-align: center; margin-top: 1.5rem; font-size: 0.88rem; color: var(--muted); }
        .footer-link a { color: var(--blue); text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
<div class="card">

    <div class="brand">
        <div class="brand-icon">💧</div>
        <div class="brand-text">
            <h1>SWDS Meru</h1>
            <p>Smart Water Distribution System</p>
        </div>
    </div>

    <h2>Welcome Back</h2>
    <p class="subtitle">Sign in to monitor your water network</p>

    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form action="" method="post">

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="Your password" required>

        <button type="submit" class="btn">Sign In →</button>
    </form>
    <div style="text-align:center;margin-top:1rem;font-size:.82rem">
        <a href="forgot_password.php" style="color:#0ea5e9;text-decoration:none">🔑 Forgot your password?</a>
    </div>

    <div class="footer-link">
        Don't have an account? <a href="register.php">Register here</a>
    </div>

</div>
</body>
</html>