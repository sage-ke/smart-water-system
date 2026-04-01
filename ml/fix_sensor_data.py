# fix_sensor_data.py
# Run this once to create the correct sensor_data.php
# Run: python fix_sensor_data.py

import os

path = r"C:\xampp_new\htdocs\smart_water\api\sensor_data.php"

content = """<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { $data = $_GET; }
$zone_id = (int)($data['zone_id'] ?? 0);
$flow_rate = (float)($data['flow_rate'] ?? 0);
$total_litres = (float)($data['total_litres'] ?? 0);
$water_level = (float)($data['water_level'] ?? 0);
$pressure = (float)($data['pressure'] ?? 0);
$valve_pct = (int)($data['valve_pct'] ?? 0);
if (!$zone_id) {
    echo json_encode(array('status'=>'error','message'=>'Missing zone_id'));
    exit;
}
try {
    $z = $pdo->prepare("SELECT id, zone_name FROM water_zones WHERE id=? LIMIT 1");
    $z->execute(array($zone_id));
    $zr = $z->fetch();
} catch(PDOException $e) {
    echo json_encode(array('status'=>'error','message'=>$e->getMessage()));
    exit;
}
if (!$zr) {
    echo json_encode(array('status'=>'error','message'=>'Zone not found'));
    exit;
}
try {
    $ins = $pdo->prepare("INSERT INTO sensor_readings (zone_id, flow_rate, total_litres, water_level, pressure, valve_open_pct, recorded_at) VALUES (?,?,?,?,?,?,NOW())");
    $ins->execute(array($zone_id, $flow_rate, $total_litres, $water_level, $pressure, $valve_pct));
    $rid = (int)$pdo->lastInsertId();
} catch(PDOException $e) {
    echo json_encode(array('status'=>'error','message'=>'DB insert failed: '.$e->getMessage()));
    exit;
}
try {
    $pdo->prepare("UPDATE water_zones SET current_flow=?, water_level=?, last_reading_at=NOW() WHERE id=?")->execute(array($flow_rate, $water_level, $zone_id));
} catch(PDOException $e) {}
echo json_encode(array('status'=>'ok','reading_id'=>$rid,'zone'=>$zr['zone_name'],'alert'=>false,'timestamp'=>date('Y-m-d H:i:s')));
"""

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Done! File written to: " + path)
print("Now verify: http://localhost/smart_water/api/sensor_data.php?zone_id=1&flow_rate=45&water_level=87")