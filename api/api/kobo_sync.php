<?php
/*
 * api/kobo_sync.php — KoBo Toolbox Live API Sync
 * ============================================================
 * Pulls submissions directly from KoBo servers.
 *
 * HOW KOBO API WORKS:
 *   1. You create a form on kf.kobotoolbox.org
 *   2. Field officers fill it on their phones (works offline)
 *   3. This script pulls new submissions via KoBo REST API
 *   4. Submissions are saved into your complaints table
 *
 * SETUP:
 *   1. Register at https://kf.kobotoolbox.org (free)
 *   2. Create a form with these fields (or similar):
 *      - reporter_name (Text)
 *      - reporter_phone (Text)
 *      - zone_name (Select One: Zone A, Zone B...)
 *      - issue_type (Select One: leak, no_water, contamination...)
 *      - description (Text Area)
 *      - gps (GPS widget — gives lat/lng automatically)
 *   3. Go to Account Settings → API Token → copy token
 *   4. Go to your form → Settings → copy the Asset UID
 *   5. Save both in Settings page (kobo_api_token, kobo_asset_uid)
 *
 * CALL THIS:
 *   - Manually: visit /api/kobo_sync.php?trigger=1 (admin only)
 *   - Via AJAX from kobo_importer.php sync button
 *   - Via Windows Task Scheduler (php kobo_sync.php CLI)
 * ============================================================
 */

// Allow CLI + admin browser
if (PHP_SAPI !== 'cli') {
    session_start();
    require_once __DIR__ . '/../db.php';
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) ||
        !in_array($_SESSION['user_role'] ?? '', ['admin','operator'])) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Admin only']);
        exit;
    }
} else {
    require_once __DIR__ . '/../db.php';
}

// ── Load KoBo settings ────────────────────────────────────────
function get_kobo_settings(PDO $pdo): array {
    $cfg = [
        'kobo_api_token'  => '',
        'kobo_asset_uid'  => '',
        'kobo_server'     => 'https://kf.kobotoolbox.org',
        'kobo_enabled'    => '0',
        'kobo_last_sync'  => '',
        'kobo_field_map'  => '',
    ];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_val FROM system_settings
            WHERE setting_key LIKE 'kobo_%'")->fetchAll();
        foreach ($rows as $r) $cfg[$r['setting_key']] = $r['setting_val'];
    } catch(PDOException $e) {}
    return $cfg;
}

// ── Save sync timestamp ───────────────────────────────────────
function save_last_sync(PDO $pdo, string $ts): void {
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_val)
            VALUES ('kobo_last_sync', ?) ON DUPLICATE KEY UPDATE setting_val=?")
            ->execute([$ts, $ts]);
    } catch(PDOException $e) {}
}

// ── Map KoBo field names to our DB columns ────────────────────
function map_kobo_submission(array $sub, array $field_map): array {
    // Default field mappings — KoBo form field names → our DB columns
    // Field map from settings overrides these (JSON: {"kobo_field":"db_column"})
    $defaults = [
        // reporter name variants
        'reporter_name'     => 'reporter_name',
        'name'              => 'reporter_name',
        'your_name'         => 'reporter_name',
        'full_name'         => 'reporter_name',
        'submitter_name'    => 'reporter_name',
        // phone variants
        'reporter_phone'    => 'reporter_phone',
        'phone'             => 'reporter_phone',
        'mobile'            => 'reporter_phone',
        'phone_number'      => 'reporter_phone',
        'contact'           => 'reporter_phone',
        // zone variants
        'zone_name'         => 'zone_name',
        'zone'              => 'zone_name',
        'water_zone'        => 'zone_name',
        'area'              => 'zone_name',
        'location'          => 'zone_name',
        // issue type variants
        'issue_type'        => 'issue_type',
        'issue'             => 'issue_type',
        'problem_type'      => 'issue_type',
        'complaint_type'    => 'issue_type',
        'type_of_problem'   => 'issue_type',
        // description variants
        'description'       => 'description',
        'details'           => 'description',
        'problem_description'=> 'description',
        'what_happened'     => 'description',
        'notes'             => 'description',
        'comment'           => 'description',
    ];

    // Merge custom field map from settings
    if ($field_map) {
        $custom = json_decode($field_map, true) ?: [];
        $defaults = array_merge($defaults, $custom);
    }

    $result = [];
    foreach ($sub as $key => $value) {
        $clean_key = strtolower(trim($key));
        // Strip group prefix (KoBo adds group/field format)
        $clean_key = preg_replace('/^.+\//', '', $clean_key);

        if (isset($defaults[$clean_key]) && !empty($value)) {
            $col = $defaults[$clean_key];
            // Don't overwrite if already set with better data
            if (!isset($result[$col]) || empty($result[$col])) {
                $result[$col] = trim($value);
            }
        }
    }

    // Handle KoBo GPS field (_geolocation array or string "lat lng")
    $gps_val = $sub['_geolocation'] ?? $sub['gps'] ?? $sub['location_gps'] ?? null;
    if ($gps_val) {
        if (is_array($gps_val) && count($gps_val) >= 2) {
            $result['gps_lat'] = $gps_val[0];
            $result['gps_lng'] = $gps_val[1];
        } elseif (is_string($gps_val)) {
            $parts = preg_split('/[\s,]+/', trim($gps_val));
            if (count($parts) >= 2 && is_numeric($parts[0])) {
                $result['gps_lat'] = $parts[0];
                $result['gps_lng'] = $parts[1];
            }
        }
    }

    // KoBo submission ID
    $result['kobo_id'] = $sub['_id'] ?? $sub['_uuid'] ?? null;

    // Submission date
    $result['submitted_at'] = $sub['_submission_time'] ?? null;

    return $result;
}

// ── Normalise issue_type ──────────────────────────────────────
function normalise_issue_type(string $raw): string {
    $map = [
        'leak'           => 'leak',
        'water leak'     => 'leak',
        'no water'       => 'no_water',
        'no_water'       => 'no_water',
        'no supply'      => 'no_water',
        'contamination'  => 'contamination',
        'dirty water'    => 'contamination',
        'pollution'      => 'contamination',
        'low pressure'   => 'low_pressure',
        'low_pressure'   => 'low_pressure',
        'meter fault'    => 'meter_fault',
        'meter_fault'    => 'meter_fault',
        'broken meter'   => 'meter_fault',
        'pipe burst'     => 'pipe_burst',
        'pipe_burst'     => 'pipe_burst',
        'burst pipe'     => 'pipe_burst',
    ];
    $lower = strtolower(trim($raw));
    return $map[$lower] ?? 'other';
}

// ════════════════════════════════════════════════════════════════
//  MAIN SYNC FUNCTION
// ════════════════════════════════════════════════════════════════
function kobo_sync(PDO $pdo, bool $verbose = false): array {
    $cfg = get_kobo_settings($pdo);

    if (!$cfg['kobo_enabled'] || $cfg['kobo_enabled'] === '0') {
        return ['status'=>'disabled','message'=>'KoBo sync is disabled in settings'];
    }
    if (!$cfg['kobo_api_token']) {
        return ['status'=>'error','message'=>'KoBo API token not configured. Go to Settings → KoBo Sync.'];
    }
    if (!$cfg['kobo_asset_uid']) {
        return ['status'=>'error','message'=>'KoBo Asset UID not configured. Go to Settings → KoBo Sync.'];
    }

    $server    = rtrim($cfg['kobo_server'] ?: 'https://kf.kobotoolbox.org', '/');
    $token     = $cfg['kobo_api_token'];
    $asset_uid = $cfg['kobo_asset_uid'];
    $last_sync = $cfg['kobo_last_sync'];

    // Build API URL — pull only new submissions since last sync
    $url = "{$server}/api/v2/assets/{$asset_uid}/data/?format=json&limit=500";
    if ($last_sync) {
        $url .= '&query={"_submission_time":{"$gt":"' . $last_sync . '"}}';
    }

    if ($verbose) echo "Fetching: $url\n";

    // Call KoBo API
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Token ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['status'=>'error','message'=>'Connection failed: ' . $curl_err];
    }
    if ($http_code === 401) {
        return ['status'=>'error','message'=>'Invalid API token — check KoBo settings'];
    }
    if ($http_code === 404) {
        return ['status'=>'error','message'=>'Form not found — check Asset UID in settings'];
    }
    if ($http_code !== 200) {
        return ['status'=>'error','message'=>"KoBo API returned HTTP $http_code"];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['results'])) {
        return ['status'=>'error','message'=>'Invalid response from KoBo API'];
    }

    $submissions = $data['results'];
    $total       = count($submissions);
    $imported    = 0;
    $skipped     = 0;
    $errors      = [];

    if ($verbose) echo "Found $total submission(s)\n";

    foreach ($submissions as $sub) {
        $mapped = map_kobo_submission($sub, $cfg['kobo_field_map'] ?? '');

        // Skip if no useful data
        if (empty($mapped['description']) && empty($mapped['issue_type'])) {
            $skipped++;
            continue;
        }

        // Normalise issue type
        $issue_type = normalise_issue_type($mapped['issue_type'] ?? 'other');

        // Match zone_id
        $zone_id = null;
        if (!empty($mapped['zone_name'])) {
            $zs = $pdo->prepare("SELECT id FROM water_zones WHERE zone_name LIKE ? LIMIT 1");
            $zs->execute(['%' . $mapped['zone_name'] . '%']);
            $zr = $zs->fetch();
            if ($zr) $zone_id = $zr['id'];
        }

        // Use submission time or now
        $created_at = !empty($mapped['submitted_at'])
            ? date('Y-m-d H:i:s', strtotime($mapped['submitted_at']))
            : date('Y-m-d H:i:s');

        try {
            $pdo->prepare("INSERT INTO complaints
                (reporter_name, reporter_phone, zone_name, zone_id, issue_type,
                 description, gps_lat, gps_lng, source, kobo_id, created_at, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'new')")
                ->execute([
                    $mapped['reporter_name']  ?? 'KoBo Submission',
                    $mapped['reporter_phone'] ?? '',
                    $mapped['zone_name']      ?? '',
                    $zone_id,
                    $issue_type,
                    $mapped['description']    ?? '',
                    isset($mapped['gps_lat']) && is_numeric($mapped['gps_lat'])
                        ? $mapped['gps_lat'] : null,
                    isset($mapped['gps_lng']) && is_numeric($mapped['gps_lng'])
                        ? $mapped['gps_lng'] : null,
                    'kobo',
                    $mapped['kobo_id'] ?? null,
                    $created_at,
                ]);
            $imported++;
            if ($verbose) echo "  + Imported: " . ($mapped['description'] ?? $mapped['kobo_id']) . "\n";
        } catch (PDOException $e) {
            // Duplicate kobo_id = already imported
            $skipped++;
            if ($verbose) echo "  - Skipped duplicate: " . ($mapped['kobo_id'] ?? '?') . "\n";
        }
    }

    // Log sync to kobo_sync_log
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->prepare("INSERT INTO kobo_sync_log
            (synced_at, total_fetched, imported, skipped, status, message)
            VALUES (?,?,?,?,?,?)")
            ->execute([
                $now, $total, $imported, $skipped,
                $imported > 0 || $total === 0 ? 'success' : 'partial',
                "Fetched $total, imported $imported, skipped $skipped"
            ]);
    } catch(PDOException $e) {
        // Create table if missing
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS kobo_sync_log (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                synced_at    DATETIME,
                total_fetched INT DEFAULT 0,
                imported     INT DEFAULT 0,
                skipped      INT DEFAULT 0,
                status       VARCHAR(20),
                message      TEXT,
                INDEX idx_time (synced_at)
            )");
            $pdo->prepare("INSERT INTO kobo_sync_log
                (synced_at, total_fetched, imported, skipped, status, message)
                VALUES (?,?,?,?,?,?)")
                ->execute([$now, $total, $imported, $skipped, 'success',
                           "Fetched $total, imported $imported, skipped $skipped"]);
        } catch(PDOException $e2) {}
    }

    // Save last sync time
    save_last_sync($pdo, $now);

    return [
        'status'   => 'success',
        'fetched'  => $total,
        'imported' => $imported,
        'skipped'  => $skipped,
        'message'  => "Sync complete — fetched $total, imported $imported new, $skipped skipped",
        'synced_at'=> $now,
        'has_more' => isset($data['next']) && $data['next'],
    ];
}

// ── CLI mode ─────────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    echo date('[Y-m-d H:i:s]') . " KoBo sync starting...\n";
    $result = kobo_sync($pdo, true);
    echo date('[Y-m-d H:i:s]') . " " . $result['message'] . "\n";
    exit($result['status'] === 'success' ? 0 : 1);
}

// ── Browser/AJAX mode ─────────────────────────────────────────
$result = kobo_sync($pdo);
echo json_encode($result);