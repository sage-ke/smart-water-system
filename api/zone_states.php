<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$api_key = trim($_GET['api_key'] ?? '');
if (!$api_key) {
    echo json_encode(['status'=>'error','message'=>'api_key required']);
    exit;
}

$dev = $pdo->prepare("SELECT id FROM hardware_devices WHERE api_key=? LIMIT 1");
$dev->execute([$api_key]);
if (!$dev->fetch()) {
    echo json_encode(['status'=>'error','message'=>'Invalid api_key']);
    exit;
}

$zones = $pdo->query("
    SELECT wz.id, wz.zone_name, wz.valve_status,
           sr.flow_rate, sr.water_level
    FROM water_zones wz
    LEFT JOIN sensor_readings sr ON sr.id = (
        SELECT id FROM sensor_readings
        WHERE zone_id = wz.id
        ORDER BY recorded_at DESC LIMIT 1
    )
    ORDER BY wz.id
")->fetchAll();

echo json_encode(['status'=>'ok','zones'=>$zones]);