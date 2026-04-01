<?php
/*
 * api/auth.php — SWDS Meru
 * ============================================================
 * Provides:
 *   csrf_token()        — generate/retrieve CSRF token for session
 *   verify_csrf()       — validate X-CSRF-Token header on POST
 *   can_do($pdo,$role,$permission) — RBAC permission check
 *   _cfg($pdo,$key,$default)       — read system_settings value
 * ============================================================
 */

// ── CSRF ──────────────────────────────────────────────────────
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? '';
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ── RBAC permission map ───────────────────────────────────────
// Defines what each role can do. Extend as needed.
const ROLE_PERMISSIONS = [
    'admin' => [
        'valve.open', 'valve.close', 'valve.set_pct',
        'pump.on', 'pump.off',
        'audit.view', 'emergency.close_all',
        'users.manage', 'settings.edit',
    ],
    'operator' => [
        'valve.open', 'valve.close', 'valve.set_pct',
        'pump.on', 'pump.off',
        'audit.view',
    ],
    'viewer' => [],
    'user'   => [],
];

function can_do(PDO $pdo, string $role, string $permission): bool {
    $perms = ROLE_PERMISSIONS[$role] ?? [];
    return in_array($permission, $perms, true);
}

// ── System settings helper ────────────────────────────────────
function _cfg(PDO $pdo, string $key, mixed $default = null): mixed {
    try {
        $s = $pdo->prepare("SELECT setting_val FROM system_settings WHERE setting_key=? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v !== false ? $v : $default;
    } catch (\PDOException $e) {
        return $default;
    }
}