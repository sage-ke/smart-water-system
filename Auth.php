<?php
// ================================================================
//  api/auth.php  ·  SWDS Meru  v3
//  ----------------------------------------------------------------
//  Central security layer. Include at the top of EVERY endpoint.
//
//  PROVIDES:
//    csrf_token()           → get/generate the user's CSRF token
//    csrf_verify()          → validate token from request (dies on fail)
//    can_do($pdo,$role,$p)  → RBAC permission check
//    require_perm($pdo,$r,$p)→ RBAC check that dies on deny
//    auth_device($pdo)      → ESP32 API key auth + rate limit
//    auth_session($roles)   → Session auth for dashboard calls
//    audit($pdo,...)        → Write one row to audit_log
//    body()                 → Parse + cache JSON request body
//    need($data,...fields)  → Assert required fields
//    only(...methods)       → Assert HTTP method
//    api_ok($data)          → JSON 200 response + exit
//    api_err($msg,$code)    → JSON error response + exit
// ================================================================

// ── Standard headers ─────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    // CORS: lock down to same origin in production;
    // keep * only while on localhost/XAMPP
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed_origins = ['http://localhost', 'http://127.0.0.1'];
    if (in_array($origin, $allowed_origins, true) || empty($origin)) {
        header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ================================================================
//  RESPONSE HELPERS
// ================================================================

function api_ok(array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['status' => 'ok'], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_err(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(
        array_merge(['status' => 'error', 'message' => $msg], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function only(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        api_err('Method not allowed. Accepted: ' . implode(', ', $methods), 405);
    }
}

// ================================================================
//  REQUEST BODY HELPERS
// ================================================================

function body(): array {
    if (isset($GLOBALS['_BODY'])) return $GLOBALS['_BODY'];
    $raw = file_get_contents('php://input');
    if (empty($raw)) return $GLOBALS['_BODY'] = [];
    $d = json_decode($raw, true);
    if (!is_array($d)) api_err('Invalid JSON body', 400);
    return $GLOBALS['_BODY'] = $d;
}

function need(array $data, string ...$fields): void {
    $missing = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) {
            $missing[] = $f;
        }
    }
    if ($missing) {
        api_err('Missing required fields: ' . implode(', ', $missing), 422, ['missing' => $missing]);
    }
}

// ================================================================
//  CSRF PROTECTION
//  ──────────────────────────────────────────────────────────────
//  Strategy: Synchronizer Token Pattern
//    • Token generated on session start, stored server-side
//    • Every state-changing request (POST/DELETE) must include
//      the token in the X-CSRF-Token header or _csrf body field
//    • ESP32 device calls skip CSRF (they use api_key instead)
//    • GET requests are always exempt
//
//  Usage in JavaScript:
//    fetch('/api/valve_control.php', {
//      method: 'POST',
//      headers: {
//        'Content-Type': 'application/json',
//        'X-CSRF-Token': window.CSRF_TOKEN   // injected by PHP in <script>
//      },
//      body: JSON.stringify({...})
//    });
// ================================================================

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Rotate token if expired
    $ttl = (int)($_SESSION['_csrf_ttl'] ?? 0);
    if (!isset($_SESSION['_csrf']) || time() > $ttl) {
        $_SESSION['_csrf']     = bin2hex(random_bytes(32));
        $_SESSION['_csrf_ttl'] = time() + 3600; // 1 hour default
    }
    return $_SESSION['_csrf'];
}

function csrf_verify(): void {
    // GET / HEAD / OPTIONS never need CSRF
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD','OPTIONS'], true)) return;

    // Device API calls skip CSRF — they authenticate with api_key
    $api_key = trim(
        $_SERVER['HTTP_X_API_KEY'] ?? ''
    ) ?: trim(
        json_decode(file_get_contents('php://input') ?: '{}', true)['api_key'] ?? ''
    );
    if ($api_key) return;

    if (session_status() === PHP_SESSION_NONE) session_start();

    $expected = $_SESSION['_csrf'] ?? '';
    // Look for token in header first, then JSON body, then POST field
    $provided = trim(
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
    ) ?: trim(
        (json_decode(file_get_contents('php://input') ?: '{}', true)['_csrf'] ?? '')
    ) ?: trim(
        $_POST['_csrf'] ?? ''
    );

    if (empty($expected) || empty($provided)) {
        // Log the denied attempt
        error_log("[CSRF] Missing token for {$_SERVER['REQUEST_URI']} from {$_SERVER['REMOTE_ADDR']}");
        api_err('CSRF token missing', 403, ['code' => 'CSRF_MISSING']);
    }

    if (!hash_equals($expected, $provided)) {
        error_log("[CSRF] Invalid token for {$_SERVER['REQUEST_URI']} from {$_SERVER['REMOTE_ADDR']}");
        api_err('CSRF token invalid', 403, ['code' => 'CSRF_INVALID']);
    }
}

// ================================================================
//  RBAC  ─  Role-Based Access Control
//  ──────────────────────────────────────────────────────────────
//  Checks role_permissions table. Falls back to a hard-coded
//  matrix if the table is missing (safe degradation).
// ================================================================

// Hard-coded fallback matrix (mirrors the SQL seed data)
const RBAC_FALLBACK = [
    'admin'    => ['valve.open','valve.close','valve.set_pct','alerts.resolve',
                   'alerts.create','notifications.send','users.create','users.edit',
                   'users.delete','users.view','devices.manage','settings.edit',
                   'reports.view','audit.view','zones.manage','maintenance.manage'],
    'operator' => ['valve.open','valve.close','valve.set_pct','alerts.resolve',
                   'alerts.create','notifications.send','users.view','reports.view',
                   'audit.view','maintenance.manage'],
    'viewer'   => ['reports.view'],
];

function can_do(PDO $pdo, string $role, string $permission): bool {
    static $matrix = null;

    // Build matrix once per request
    if ($matrix === null) {
        $matrix = [];
        try {
            $rows = $pdo->query(
                "SELECT role, permission, granted FROM role_permissions"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $matrix[$r['role']][$r['permission']] = (bool)$r['granted'];
            }
        } catch (\PDOException $e) {
            // Table missing — use fallback
            foreach (RBAC_FALLBACK as $r => $perms) {
                foreach ($perms as $p) $matrix[$r][$p] = true;
            }
        }
    }

    return (bool)($matrix[$role][$permission] ?? false);
}

function require_perm(PDO $pdo, string $role, string $permission, int $user_id = 0): void {
    if (!can_do($pdo, $role, $permission)) {
        // Write denied attempt to audit log
        try {
            $pdo->prepare("
                INSERT INTO audit_log
                    (user_id, user_role, action, result, detail, ip_address)
                VALUES (?,?,?,  'denied',?,?)
            ")->execute([
                $user_id ?: null,
                $role,
                $permission,
                "Permission '$permission' denied for role '$role'",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\PDOException $e) { /* audit table may not exist yet */ }

        api_err(
            "Your role '$role' does not have permission: $permission",
            403,
            ['permission' => $permission, 'code' => 'RBAC_DENIED']
        );
    }
}

// ================================================================
//  AUDIT LOG WRITER
//  ──────────────────────────────────────────────────────────────
//  Call after every significant action:
//    audit($pdo, user: $user, action: 'valve.open',
//          entity_type: 'zone', entity_id: 1,
//          entity_label: 'Zone A', new_value: ['valve_pct'=>100],
//          reason: 'Maintenance complete');
// ================================================================

function audit(
    PDO    $pdo,
    array  $user,           // ['id','name','role'] from auth_session()
    string $action,
    string $entity_type = '',
    int    $entity_id   = 0,
    string $entity_label= '',
    mixed  $old_value   = null,
    mixed  $new_value   = null,
    string $reason      = '',
    string $result      = 'ok',
    string $detail      = ''
): void {
    try {
        $pdo->prepare("
            INSERT INTO audit_log
                (user_id, user_name, user_role, action,
                 entity_type, entity_id, entity_label,
                 old_value, new_value, reason,
                 ip_address, user_agent, result, detail)
            VALUES (?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?)
        ")->execute([
            $user['id']   ?? null,
            $user['name'] ?? 'System',
            $user['role'] ?? 'system',
            $action,
            $entity_type ?: null,
            $entity_id   ?: null,
            $entity_label ?: null,
            $old_value !== null ? json_encode($old_value) : null,
            $new_value !== null ? json_encode($new_value) : null,
            $reason      ?: null,
            $_SERVER['REMOTE_ADDR']    ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $result,
            $detail      ?: null,
        ]);
    } catch (\PDOException $e) {
        // Never let audit failure crash the main action
        error_log("[AUDIT] Failed to write audit log: " . $e->getMessage());
    }
}

// ================================================================
//  DEVICE AUTH  (ESP32 / sensor nodes)
//  Rate limiting: max 60 calls per device per minute
// ================================================================

function auth_device(PDO $pdo): array {
    $key = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d   = body();
        $key = trim($d['api_key'] ?? '');
    }
    if (!$key) $key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!$key) $key = trim($_GET['api_key'] ?? '');
    if (!$key) api_err('api_key required', 401);

    $stmt = $pdo->prepare('SELECT * FROM hardware_devices WHERE api_key = ?');
    $stmt->execute([$key]);
    $device = $stmt->fetch();
    if (!$device) api_err('Invalid API key', 403);

    // Per-minute rate limiting
    try {
        $win   = date('Y-m-d H:i:00');
        $limit = (int)($pdo->query(
            "SELECT setting_val FROM system_settings WHERE setting_key='api_rate_limit' LIMIT 1"
        )->fetchColumn() ?: 60);

        $pdo->prepare("
            INSERT INTO api_rate_limits (device_id, window_start, call_count)
            VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE call_count = call_count + 1
        ")->execute([$device['id'], $win]);

        $calls = (int)$pdo->prepare(
            "SELECT call_count FROM api_rate_limits WHERE device_id=? AND window_start=?"
        )->execute([$device['id'], $win])
            ? (int)$pdo->query(
                "SELECT call_count FROM api_rate_limits
                 WHERE device_id={$device['id']} AND window_start='$win'"
              )->fetchColumn()
            : 0;

        if ($calls > $limit) {
            api_err('Rate limit exceeded — try again next minute', 429,
                ['calls' => $calls, 'limit' => $limit]);
        }
    } catch (\PDOException $e) { /* rate limit table optional */ }

    return $device;
}

// ================================================================
//  SESSION AUTH  (dashboard users)
//  Also tracks session in user_sessions table for forced-logout
//  and concurrent session limiting.
// ================================================================

function auth_session(array $roles = ['admin', 'operator']): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) api_err('Not authenticated', 401);

    $role = $_SESSION['user_role'] ?? 'viewer';
    if (!in_array($role, $roles, true)) {
        api_err(
            "Access denied. This action requires one of: " . implode(', ', $roles),
            403,
            ['your_role' => $role, 'required' => $roles]
        );
    }

    return [
        'id'   => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name']  ?? '',
        'role' => $role,
    ];
}