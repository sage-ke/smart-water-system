<?php
/*
 * api/update_device_status.php — SWDS Meru
 * Dedicated endpoint for device heartbeat/status updates.
 * Separates telemetry (sensor_data.php) from device status.
 *
 * POST: {"api_key":"...","zone_id":1,"online":true}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { $data = $_GET; }

$api_key = trim($data['api_key'] ?? '');
$zone_id = (int)($data['zone_id'] ?? 0);
$online  = (bool)($data['online'] ?? true);

if (!$api_key || !$zone_id) {
    echo json_encode(array('status'=>'error','message'=>'api_key and zone_id required'));
    exit;
}

try {
    $status = $online ? 1 : 0;
    $pdo->prepare("UPDATE hardware_devices
        SET is_online=?, last_seen=NOW()
        WHERE zone_id=?")
        ->execute(array($status, $zone_id));

    echo json_encode(array(
        'status'  => 'ok',
        'zone_id' => $zone_id,
        'online'  => $online,
    ));
} catch(PDOException $e) {
    error_log('[update_device_status] ' . $e->getMessage());
    echo json_encode(array('status'=>'error','message'=>$e->getMessage()));
}