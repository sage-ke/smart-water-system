<?php
// ================================================================
//  logout.php  ·  SWDS Meru  v3
//  Clears server-side session record, audits the logout,
//  destroys PHP session, redirects to login.
// ================================================================
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    $uid   = (int)$_SESSION['user_id'];
    $email = $_SESSION['user_email'] ?? '';
    $role  = $_SESSION['user_role']  ?? '';
    $sid   = session_id();

    // Remove server-side session record
    try {
        $pdo->prepare("DELETE FROM user_sessions WHERE session_id=?")->execute([$sid]);
    } catch (\PDOException $e) {}

    // Audit the logout
    try {
        $pdo->prepare("
            INSERT INTO audit_log
                (user_id, user_name, user_role, action, entity_type,
                 entity_label, result, ip_address)
            VALUES (?,?,?,'auth.logout','auth',?,'ok',?)
        ")->execute([
            $uid, $email, $role, $email,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\PDOException $e) {}
}

// Destroy session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit;