<?php
/*
 * ============================================================
 *  register.php — New User Registration Page
 * ============================================================
 *  This file does TWO things:
 *  1. Shows the registration FORM (when the page is opened)
 *  2. PROCESSES the form data (when the user clicks "Register")
 *
 *  PHP knows which to do by checking $_SERVER['REQUEST_METHOD']:
 *    - 'GET'  = user just opened the page → show the form
 *    - 'POST' = user submitted the form  → process the data
 * ============================================================
 */

// Start a session (needed to pass success/error messages between pages)
session_start();

// Load the database connection ($pdo is now available)
require_once __DIR__ . '/db.php';

$errors = [];     // Array to collect validation error messages
$success = '';    // Success message string

// ============================================================
//  Only run this block when the form is submitted (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Step 1: Get & clean the submitted form values ---
    // trim() removes extra spaces the user may have typed accidentally
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = trim($_POST['password']  ?? '');
    $confirm   = trim($_POST['confirm']   ?? '');

    // --- Step 2: Validate the inputs ---

    // Full name must not be empty
    if (empty($full_name)) {
        $errors[] = "Full name is required.";
    }

    // Email must not be empty and must look like a real email
    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // FILTER_VALIDATE_EMAIL is a built-in PHP function that checks email format
        $errors[] = "Please enter a valid email address.";
    }

    // Password rules
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        // strlen() counts the number of characters
        $errors[] = "Password must be at least 6 characters long.";
    }

    // Passwords must match
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    // --- Step 3: Check if email already exists in database ---
    if (empty($errors)) {
        // Prepared statement: the ? is a placeholder — PDO fills it safely
        // This prevents SQL Injection attacks
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            // fetch() returns data if a row was found = email already taken
            $errors[] = "An account with this email already exists.";
        }
    }

    // --- Step 4: If no errors, save the user to the database ---
    if (empty($errors)) {

        // All new registrations are residents by default
        // Admin can change roles via the Users page in the dashboard
        $role    = 'user'; // all new registrations are regular users
        $zone_id = (int)($_POST['zone_id'] ?? 0) ?: null;

        // IMPORTANT: NEVER save plain-text passwords!
        // password_hash() scrambles the password securely using bcrypt
        // Even if someone steals the database, they can't read the passwords
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Insert the new user into the 'users' table
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, role, zone_id) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$full_name, $email, $hashed_password, $role, $zone_id]);

        // Redirect to login page with a success message stored in session
        $_SESSION['success_msg'] = "✅ Registration successful! Please log in.";
        header("Location: login.php");
        exit; // Always exit after header redirect!
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Smart Water Distribution System</title>
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
            max-width: 460px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2rem;
        }
        .brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border-radius: 12px;
            display: grid; place-items: center;
            font-size: 1.4rem;
        }
        .brand-text h1 {
            font-family: 'Syne', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }
        .brand-text p { font-size: 0.75rem; color: var(--muted); }

        h2 {
            font-family: 'Syne', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 0.4rem;
        }
        .subtitle { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.8rem; }

        /* Error messages */
        .error-box {
            background: rgba(248,113,113,0.1);
            border: 1px solid rgba(248,113,113,0.3);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 1.5rem;
        }
        .error-box p { color: var(--error); font-size: 0.875rem; margin-bottom: 4px; }
        .error-box p:last-child { margin-bottom: 0; }

        label {
            display: block;
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.95rem;
            font-family: 'DM Sans', sans-serif;
            transition: border-color 0.2s;
            margin-bottom: 1.2rem;
        }
        input:focus {
            outline: none;
            border-color: var(--blue);
            background: rgba(14,165,233,0.05);
        }

        .btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Syne', sans-serif;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
            margin-top: 0.5rem;
        }
        .btn:hover { opacity: 0.9; }
        .btn:active { transform: scale(0.99); }

        .footer-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.88rem;
            color: var(--muted);
        }
        .footer-link a { color: var(--blue); text-decoration: none; font-weight: 500; }
        .footer-link a:hover { text-decoration: underline; }
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

    <h2>Create Account</h2>
    <p class="subtitle">Register to access the water management portal</p>

    <?php if (!empty($errors)): ?>
    <div class="error-box">
        <?php foreach ($errors as $err): ?>
            <p>⚠️ <?= htmlspecialchars($err) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!--
        action=""   = submit to the same file (register.php handles itself)
        method="post" = send form data securely in the request body (not in the URL)
    -->
    <form action="" method="post" novalidate>

        <label for="zone_id">Your Water Zone *</label>
        <select id="zone_id" name="zone_id" required
            style="width:100%;padding:12px 16px;border:1.5px solid #e2e8f0;border-radius:10px;
                   font-size:1rem;background:#fff;margin-bottom:1rem">
            <option value="">— Select your zone —</option>
            <?php
            try {
                $zones_reg = $pdo->query("SELECT id, zone_name FROM water_zones ORDER BY zone_name")->fetchAll();
                foreach ($zones_reg as $zr) {
                    echo "<option value='{$zr['id']}'>" . htmlspecialchars($zr['zone_name']) . "</option>";
                }
            } catch(Exception $e) {}
            ?>
        </select>

        <label for="full_name">Full Name</label>
        <!-- htmlspecialchars() prevents XSS attacks by escaping special characters -->
        <input type="text" id="full_name" name="full_name"
               placeholder="e.g. John Kamau"
               value="<?= htmlspecialchars($full_name ?? '') ?>" required>

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               placeholder="you@example.com"
               value="<?= htmlspecialchars($email ?? '') ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="At least 6 characters" required>

        <label for="confirm">Confirm Password</label>
        <input type="password" id="confirm" name="confirm"
               placeholder="Repeat your password" required>

        <div style="background:rgba(52,211,153,.05);border:1px solid rgba(52,211,153,.2);border-radius:10px;padding:10px 14px;margin-bottom:1rem;font-size:.82rem;color:#34d399">
            ✅ This creates a <strong>resident account</strong> — you can view your water usage, billing, and submit reports.
        </div>

        <button type="submit" class="btn">Create My Account →</button>
    </form>

    <div class="footer-link">
        Already have an account? <a href="login.php">Sign In</a>
    </div>

</div>
</body>
</html>