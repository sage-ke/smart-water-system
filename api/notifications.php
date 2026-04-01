<?php
// ================================================================
//  api/notifications.php  ·  SWDS Meru
//  ----------------------------------------------------------------
//  Handles all user notification operations.
//
//  ROUTES:
//
//  POST /api/notifications.php
//    Send a notification. Admin/operator only.
//    Body:
//      { "title": "...", "message": "...", "type": "info",
//        "user_id": 5 }        ← omit user_id to broadcast to all
//    Response: { status, notification_id, recipients }
//
//  GET /api/notifications.php
//    Fetch notifications for the authenticated resident (viewer).
//    Query params:
//      ?unread_only=1          ← only unread (default: all)
//      ?limit=20               ← max results (default 20, max 100)
//    Response: { status, notifications: [...], unread_count }
//
//  POST /api/notifications.php   (mark read)
//    Body: { "action": "mark_read", "id": 7 }
//    Body: { "action": "mark_all_read" }
//    Response: { status, updated }
//
//  GET /api/notifications.php?admin=1
//    Admin/operator: list all recent notifications sent.
//    Response: { status, notifications: [...] }
// ================================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

// ================================================================
//  POST — send or mark-read
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data   = body();
    $action = trim($data['action'] ?? 'send');

    // ── MARK READ ─────────────────────────────────────────────
    if (in_array($action, ['mark_read', 'mark_all_read'], true)) {

        $user = auth_session(['admin', 'operator', 'viewer']);

        if ($action === 'mark_all_read') {
            $upd = $pdo->prepare("
                UPDATE user_notifications
                SET is_read = 1
                WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
            ");
            $upd->execute([$user['id']]);
            api_ok(['updated' => $upd->rowCount()]);
        }

        // mark_read: single id
        $nid = (int)($data['id'] ?? 0);
        if (!$nid) api_err('id is required for mark_read', 422);

        $upd = $pdo->prepare("
            UPDATE user_notifications
            SET is_read = 1
            WHERE id = ? AND (user_id = ? OR user_id IS NULL)
        ");
        $upd->execute([$nid, $user['id']]);
        api_ok(['updated' => $upd->rowCount()]);
    }

    // ── SEND NOTIFICATION ─────────────────────────────────────
    // Admin or operator only
    $admin = auth_session(['admin', 'operator']);

    need($data, 'title', 'message');

    $title   = trim(substr($data['title'],   0, 150));
    $message = trim($data['message']);
    $type    = in_array($data['type'] ?? '', ['info','warning','critical','maintenance'], true)
               ? $data['type'] : 'info';

    // user_id = null means broadcast; otherwise target a specific resident
    $target_user_id = isset($data['user_id']) ? (int)$data['user_id'] : null;

    // Verify target user exists if specified
    if ($target_user_id !== null) {
        $exists = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='viewer'");
        $exists->execute([$target_user_id]);
        if (!$exists->fetch()) api_err("Resident user_id $target_user_id not found", 404);
    }

    try {
        $ins = $pdo->prepare("
            INSERT INTO user_notifications (user_id, title, message, type, sent_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$target_user_id, $title, $message, $type, $admin['id']]);
        $notif_id = (int)$pdo->lastInsertId();

        // Count recipients
        if ($target_user_id === null) {
            $recipients = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='viewer'")->fetchColumn();
        } else {
            $recipients = 1;
        }

    } catch (\PDOException $e) {
        api_err('Could not send notification: ' . $e->getMessage(), 500);
    }

    api_ok([
        'notification_id' => $notif_id,
        'type'            => $type,
        'broadcast'       => $target_user_id === null,
        'recipients'      => $recipients,
        'message'         => $target_user_id === null
            ? "Notification broadcast to $recipients residents."
            : "Notification sent to user $target_user_id.",
    ], 201);
}

// ================================================================
//  GET — fetch notifications
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // ── Admin list view ───────────────────────────────────────
    if (isset($_GET['admin'])) {
        $admin = auth_session(['admin', 'operator']);
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $rows = $pdo->prepare("
            SELECT n.*,
                   u_target.full_name AS target_name,
                   u_sender.full_name AS sender_name
            FROM user_notifications n
            LEFT JOIN users u_target ON u_target.id = n.user_id
            LEFT JOIN users u_sender ON u_sender.id = n.sent_by
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $rows->execute([$limit]);
        $notifs = $rows->fetchAll();

        api_ok(['notifications' => $notifs, 'count' => count($notifs)]);
    }

    // ── Resident: fetch own notifications ─────────────────────
    $user        = auth_session(['admin', 'operator', 'viewer']);
    $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] !== '0';
    $limit       = min(100, max(1, (int)($_GET['limit'] ?? 20)));

    $where = $unread_only ? 'AND n.is_read = 0' : '';

    $rows = $pdo->prepare("
        SELECT n.id, n.title, n.message, n.type, n.is_read, n.created_at
        FROM user_notifications n
        WHERE (n.user_id = ? OR n.user_id IS NULL)
          $where
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $rows->execute([$user['id'], $limit]);
    $notifs = $rows->fetchAll();

    // Unread count (always return regardless of filter)
    $unread_count = (int)$pdo->prepare("
        SELECT COUNT(*) FROM user_notifications
        WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
    ")->execute([$user['id']])
        ? $pdo->query("
            SELECT COUNT(*) FROM user_notifications
            WHERE (user_id = {$user['id']} OR user_id IS NULL) AND is_read = 0
          ")->fetchColumn()
        : 0;

    // Cleaner:
    $uc = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE (user_id=? OR user_id IS NULL) AND is_read=0");
    $uc->execute([$user['id']]);
    $unread_count = (int)$uc->fetchColumn();

    api_ok([
        'notifications' => $notifs,
        'count'         => count($notifs),
        'unread_count'  => $unread_count,
    ]);
}

api_err('Method not allowed', 405);