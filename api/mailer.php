<?php
/*
 * api/mailer.php — SWDS Meru Email Notifications
 * ============================================================
 * Sends email alerts for:
 *   - Critical/high alerts
 *   - New device registered
 *   - Password reset links
 *   - Daily digest (optional)
 *
 * Uses PHP mail() for localhost (XAMPP) OR SMTP via settings.
 *
 * XAMPP SETUP (for localhost testing):
 *   1. Install hMailServer or use a Gmail SMTP relay
 *   2. OR use a free service like Mailtrap (mailtrap.io)
 *      — Mailtrap catches all emails in a test inbox, perfect for dev
 *
 * SETTINGS (stored in system_settings table):
 *   email_enabled   = 1
 *   email_from      = swds@meru.go.ke
 *   email_from_name = SWDS Meru
 *   smtp_host       = smtp.gmail.com  (or mailtrap host)
 *   smtp_port       = 587
 *   smtp_user       = your@gmail.com
 *   smtp_pass       = your_app_password
 *   smtp_secure     = tls
 *   admin_email     = admin@meru.go.ke
 *
 * USAGE:
 *   require_once __DIR__ . '/../api/mailer.php';
 *   send_email_alert($pdo, 'Critical leak in Zone A', 'critical');
 *   send_password_reset_email($pdo, $to_email, $name, $reset_url);
 * ============================================================
 */

function get_email_settings(PDO $pdo): array {
    $cfg = [
        'email_enabled'   => '0',
        'email_from'      => 'swds@meru.go.ke',
        'email_from_name' => 'SWDS Meru',
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_user'       => '',
        'smtp_pass'       => '',
        'smtp_secure'     => 'tls',
        'admin_email'     => '',
    ];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_val FROM system_settings
            WHERE setting_key LIKE 'email_%' OR setting_key LIKE 'smtp_%'
               OR setting_key = 'admin_email'")->fetchAll();
        foreach ($rows as $r) $cfg[$r['setting_key']] = $r['setting_val'];
    } catch(PDOException $e) {}
    return $cfg;
}

// ── Core send function (SMTP if configured, else PHP mail()) ─
function swds_send_mail(array $cfg, string $to, string $subject, string $html_body): array {
    if (!$cfg['admin_email'] && !$to) {
        return ['sent'=>false,'reason'=>'No recipient email configured'];
    }

    $recipient = $to ?: $cfg['admin_email'];
    $from      = $cfg['email_from']      ?: 'noreply@swds.local';
    $from_name = $cfg['email_from_name'] ?: 'SWDS Meru';

    // ── Try SMTP if configured ────────────────────────────────
    if ($cfg['smtp_host'] && $cfg['smtp_user'] && $cfg['smtp_pass']) {
        return swds_smtp_send($cfg, $recipient, $subject, $html_body, $from, $from_name);
    }

    // ── Fallback: PHP mail() ──────────────────────────────────
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $from_name <$from>\r\n";
    $headers .= "X-Mailer: SWDS-Meru/1.0\r\n";

    $sent = @mail($recipient, $subject, $html_body, $headers); // @ suppresses warning on localhost
    return ['sent'=>$sent, 'method'=>'php_mail', 'to'=>$recipient];
}

// ── SMTP send via fsockopen (no PHPMailer needed) ─────────────
function swds_smtp_send(array $cfg, string $to, string $subject,
                         string $html, string $from, string $from_name): array {
    $host    = $cfg['smtp_host'];
    $port    = (int)($cfg['smtp_port'] ?: 587);
    $user    = $cfg['smtp_user'];
    $pass    = $cfg['smtp_pass'];
    $secure  = $cfg['smtp_secure'] ?: 'tls';

    try {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);

        $prefix = ($secure === 'ssl') ? 'ssl://' : '';
        $sock   = stream_socket_client("{$prefix}{$host}:{$port}", $errno, $errstr, 15,
                    STREAM_CLIENT_CONNECT, $ctx);

        if (!$sock) throw new Exception("Connect failed: $errstr ($errno)");

        $read = function() use ($sock) {
            $line = ''; $r = '';
            while (!feof($sock)) {
                $r = fgets($sock, 256);
                $line .= $r;
                if (substr($r, 3, 1) === ' ') break;
            }
            return $line;
        };
        $write = fn($cmd) => fputs($sock, $cmd . "\r\n");

        $read(); // 220 greeting
        $write("EHLO swds.local");
        $ehlo = $read();

        // STARTTLS for port 587
        if ($secure === 'tls' && str_contains($ehlo, 'STARTTLS')) {
            $write("STARTTLS");
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO swds.local");
            $read();
        }

        // Auth
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $auth = $read();
        if (!str_starts_with($auth, '235')) {
            fclose($sock);
            return ['sent'=>false,'reason'=>'SMTP auth failed: '.$auth];
        }

        $write("MAIL FROM:<$from>");
        $read();
        $write("RCPT TO:<$to>");
        $read();
        $write("DATA");
        $read();

        $b64  = base64_encode($html);
        $msg  = "From: $from_name <$from>\r\n";
        $msg .= "To: $to\r\n";
        $msg .= "Subject: $subject\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n" . chunk_split($b64);
        $msg .= "\r\n.";
        $write($msg);
        $send = $read();

        $write("QUIT");
        fclose($sock);

        $ok = str_starts_with($send, '250');
        return ['sent'=>$ok, 'method'=>'smtp', 'to'=>$to, 'response'=>trim($send)];

    } catch(Exception $e) {
        return ['sent'=>false, 'reason'=>$e->getMessage()];
    }
}

// ── Build HTML email template ─────────────────────────────────
function swds_email_template(string $title, string $body_html,
                               string $color = '#0ea5e9'): string {
    return "<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr><td align='center' style='padding:30px 20px'>
<table width='580' cellpadding='0' cellspacing='0'
       style='background:#fff;border-radius:12px;overflow:hidden;
              box-shadow:0 2px 12px rgba(0,0,0,.08)'>
  <!-- Header -->
  <tr><td style='background:linear-gradient(135deg,{$color},#06b6d4);
               padding:24px 32px;text-align:center'>
    <div style='font-size:24px;margin-bottom:4px'>💧</div>
    <h1 style='margin:0;color:#fff;font-size:18px;font-weight:700'>SWDS Meru</h1>
    <p style='margin:4px 0 0;color:rgba(255,255,255,.8);font-size:12px'>
        Smart Water Distribution System — Meru County
    </p>
  </td></tr>
  <!-- Body -->
  <tr><td style='padding:28px 32px'>
    <h2 style='margin:0 0 16px;color:#1e293b;font-size:16px'>{$title}</h2>
    {$body_html}
    <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0'>
    <p style='margin:0;color:#94a3b8;font-size:11px'>
        SWDS Meru &nbsp;·&nbsp; " . date('d M Y H:i') . " &nbsp;·&nbsp;
        <a href='http://localhost/smart_water/dashboard.php'
           style='color:#0ea5e9'>Open Dashboard</a>
    </p>
  </td></tr>
</table>
</td></tr></table>
</body></html>";
}

// ════════════════════════════════════════════════════════════════
//  PUBLIC: Send alert email
// ════════════════════════════════════════════════════════════════
function send_email_alert(PDO $pdo, string $message, string $severity = 'high',
                           string $zone = ''): array {
    $cfg = get_email_settings($pdo);
    if (!$cfg['email_enabled'] || $cfg['email_enabled'] === '0') {
        return ['sent'=>false,'reason'=>'Email disabled in settings'];
    }
    if (!$cfg['admin_email']) {
        return ['sent'=>false,'reason'=>'Admin email not configured'];
    }
    if (!in_array($severity, ['high','critical'])) {
        return ['sent'=>false,'reason'=>'Severity too low for email'];
    }

    $color   = $severity === 'critical' ? '#ef4444' : '#f59e0b';
    $sev_uc  = strtoupper($severity);
    $zone_str= $zone ? " — <strong>$zone</strong>" : '';

    $body = "<div style='background:#fef2f2;border-left:4px solid {$color};
                  padding:14px 18px;border-radius:4px;margin-bottom:16px'>
        <strong style='color:{$color}'>[$sev_uc] Alert{$zone_str}</strong><br>
        <span style='color:#374151;font-size:14px;line-height:1.6'>" .
        nl2br(htmlspecialchars($message)) . "</span>
    </div>
    <p style='color:#64748b;font-size:13px'>
        Log in to your dashboard to investigate and resolve this alert.
    </p>
    <a href='http://localhost/smart_water/dashboard.php'
       style='display:inline-block;margin-top:8px;padding:10px 22px;
              background:{$color};color:#fff;border-radius:6px;
              text-decoration:none;font-size:13px;font-weight:600'>
        View Dashboard →
    </a>";

    $html    = swds_email_template("[$sev_uc] Alert Detected", $body, $color);
    $subject = "[SWDS Meru] [$sev_uc] " . substr($message, 0, 80);

    $result = swds_send_mail($cfg, $cfg['admin_email'], $subject, $html);

    // Log
    try {
        $pdo->prepare("INSERT INTO audit_log
            (action, user_name, entity_label, new_value, result)
            VALUES ('email.alert','system',:msg,:to,:res)")
            ->execute([
                'msg' => substr($message,0,200),
                'to'  => $cfg['admin_email'],
                'res' => $result['sent'] ? 'sent' : 'failed',
            ]);
    } catch(PDOException $e) {}

    return $result;
}

// ════════════════════════════════════════════════════════════════
//  PUBLIC: Send password reset email
// ════════════════════════════════════════════════════════════════
function send_password_reset_email(PDO $pdo, string $to_email,
                                    string $name, string $reset_url): array {
    $cfg = get_email_settings($pdo);

    $body = "<p style='color:#374151;font-size:14px;line-height:1.7'>
        Hello <strong>" . htmlspecialchars($name) . "</strong>,<br><br>
        We received a request to reset your SWDS Meru password.
        Click the button below to set a new password.
        This link expires in <strong>1 hour</strong>.
    </p>
    <a href='" . htmlspecialchars($reset_url) . "'
       style='display:inline-block;margin:16px 0;padding:12px 28px;
              background:#0ea5e9;color:#fff;border-radius:8px;
              text-decoration:none;font-size:14px;font-weight:700'>
        🔒 Reset My Password
    </a>
    <p style='color:#94a3b8;font-size:12px;margin-top:12px'>
        If you did not request a password reset, ignore this email —
        your password will not change.
    </p>
    <p style='color:#94a3b8;font-size:11px;word-break:break-all'>
        Link: " . htmlspecialchars($reset_url) . "
    </p>";

    $html    = swds_email_template('Password Reset Request', $body);
    $subject = '[SWDS Meru] Password Reset Request';

    return swds_send_mail($cfg, $to_email, $subject, $html);
}

// ════════════════════════════════════════════════════════════════
//  PUBLIC: Send daily digest (call from Task Scheduler)
// ════════════════════════════════════════════════════════════════
function send_daily_digest(PDO $pdo): array {
    $cfg = get_email_settings($pdo);
    if (!$cfg['email_enabled'] || !$cfg['admin_email']) {
        return ['sent'=>false,'reason'=>'Email not configured'];
    }

    // Gather stats
    try {
        $zones    = (int)$pdo->query("SELECT COUNT(*) FROM water_zones WHERE status='active'")->fetchColumn();
        $alerts   = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $anomalies= (int)$pdo->query("SELECT COUNT(*) FROM anomaly_log WHERE DATE(detected_at)=CURDATE()")->fetchColumn();
        $flow     = round((float)$pdo->query("SELECT AVG(flow_rate) FROM sensor_readings WHERE DATE(recorded_at)=CURDATE()")->fetchColumn(), 1);
        $payments = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM billing WHERE DATE(payment_date)=CURDATE()")->fetchColumn();
    } catch(PDOException $e) {
        return ['sent'=>false,'reason'=>'DB error: '.$e->getMessage()];
    }

    $body = "<table width='100%' cellpadding='8' cellspacing='0'
                    style='border-collapse:collapse;font-size:13px'>
        <tr style='background:#f8fafc'>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;color:#64748b'>Active Zones</td>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;font-weight:700;color:#0ea5e9'>{$zones}</td>
        </tr>
        <tr>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;color:#64748b'>Alerts Today</td>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;font-weight:700;color:" . ($alerts>0?'#ef4444':'#34d399') . "'>{$alerts}</td>
        </tr>
        <tr style='background:#f8fafc'>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;color:#64748b'>ML Anomalies Today</td>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;font-weight:700;color:" . ($anomalies>0?'#f59e0b':'#34d399') . "'>{$anomalies}</td>
        </tr>
        <tr>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;color:#64748b'>Avg Flow Rate</td>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;font-weight:700;color:#0ea5e9'>{$flow} L/min</td>
        </tr>
        <tr style='background:#f8fafc'>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;color:#64748b'>Revenue Today</td>
            <td style='border:1px solid #e2e8f0;padding:10px 14px;font-weight:700;color:#34d399'>KES " . number_format($payments,0) . "</td>
        </tr>
    </table>
    <a href='http://localhost/smart_water/dashboard.php'
       style='display:inline-block;margin-top:20px;padding:10px 22px;
              background:#0ea5e9;color:#fff;border-radius:6px;
              text-decoration:none;font-size:13px;font-weight:600'>
        Open Dashboard →
    </a>";

    $html    = swds_email_template('Daily System Digest — ' . date('d M Y'), $body);
    $subject = '[SWDS Meru] Daily Digest — ' . date('d M Y');

    return swds_send_mail($cfg, $cfg['admin_email'], $subject, $html);
}