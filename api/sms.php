<?php
/*
 * api/sms.php — SWDS Meru SMS Alerts via Africa's Talking
 * ============================================================
 * Sends SMS for critical events:
 *   - Critical alert created
 *   - Leak detected
 *   - Valve closed remotely
 *   - Low water balance
 *
 * SETUP:
 *   1. Register free account at africastalking.com
 *   2. Get API key from dashboard
 *   3. Add settings in system_settings via Settings page:
 *      sms_enabled   = 1
 *      sms_api_key   = your_api_key
 *      sms_username  = your_username (sandbox for testing)
 *      sms_phone     = +254XXXXXXXXX (admin phone)
 *      sms_sender    = SWDS (optional sender name)
 *
 * USAGE from other PHP files:
 *   require_once __DIR__ . '/../api/sms.php';   // or /api/sms.php
 *   send_sms_alert($pdo, "Low water in Zone A — level at 12%", 'critical');
 * ============================================================
 */

function get_sms_settings(PDO $pdo): array {
    $defaults = [
        'sms_enabled'  => '0',
        'sms_api_key'  => '',
        'sms_username' => 'sandbox',
        'sms_phone'    => '',
        'sms_sender'   => 'SWDS',
    ];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_val FROM system_settings
            WHERE setting_key LIKE 'sms_%'")->fetchAll();
        foreach ($rows as $r) {
            $defaults[$r['setting_key']] = $r['setting_val'];
        }
    } catch(PDOException $e) {}
    return $defaults;
}

function send_sms_alert(PDO $pdo, string $message, string $severity = 'high'): array {
    // Only send for high/critical
    if (!in_array($severity, ['high','critical'])) {
        return ['sent'=>false,'reason'=>'severity too low'];
    }

    $cfg = get_sms_settings($pdo);

    if (!$cfg['sms_enabled'] || $cfg['sms_enabled'] === '0') {
        return ['sent'=>false,'reason'=>'SMS disabled in settings'];
    }
    if (!$cfg['sms_api_key'] || !$cfg['sms_phone']) {
        return ['sent'=>false,'reason'=>'API key or phone not configured'];
    }

    // Prefix message with system name
    $full_msg = "SWDS Meru Alert:\n" . $message;

    // Africa's Talking API
    $url  = 'https://api.africastalking.com/version1/messaging';
    $data = http_build_query([
        'username' => $cfg['sms_username'],
        'to'       => $cfg['sms_phone'],
        'message'  => $full_msg,
        'from'     => $cfg['sms_sender'] ?: null,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'apiKey: ' . $cfg['sms_api_key'],
        ],
    ]);
    $response = curl_exec($ch);
    $http_code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    $result = [
        'sent'      => ($http_code === 201 || $http_code === 200),
        'http_code' => $http_code,
        'response'  => $response,
        'error'     => $err ?: null,
        'message'   => $full_msg,
        'phone'     => $cfg['sms_phone'],
    ];

    // Log to audit_log
    try {
        $pdo->prepare("INSERT INTO audit_log (action, user_name, entity_label, new_value, result)
            VALUES ('sms.alert','system',:msg,:resp,:res)")
            ->execute([
                'msg'  => $full_msg,
                'resp' => $response,
                'res'  => $result['sent'] ? 'sent' : 'failed',
            ]);
    } catch(PDOException $e) {}

    return $result;
}

// ── Auto-trigger: called when a new alert is created ─────────
function sms_on_new_alert(PDO $pdo, array $alert): void {
    $severity = $alert['severity'] ?? 'medium';
    if (!in_array($severity, ['high','critical'])) return;

    $zone = $alert['zone_name'] ?? ('Zone ID ' . ($alert['zone_id'] ?? '?'));
    $type = $alert['alert_type'] ?? 'Alert';
    $msg  = $alert['message'] ?? '';

    $sms_text = "[$severity] $type in $zone.\n$msg";
    send_sms_alert($pdo, $sms_text, $severity);
}