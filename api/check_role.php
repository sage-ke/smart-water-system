<?php
/*
 * api/check_role.php
 * Called by login.php via fetch() when user finishes typing their email.
 * Returns role and destination dashboard for that email.
 * Does NOT expose password or id - only role + full_name.
 */
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../db.php';

$email = trim($_GET['email'] ?? '');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['found'=>false]);
    exit;
}
$s = $pdo->prepare("SELECT full_name, role FROM users WHERE email = ? LIMIT 1");
$s->execute([$email]);
$u = $s->fetch();
if (!$u) { echo json_encode(['found'=>false]); exit; }

$dest = in_array($u['role'],['admin','operator']) ? 'Admin Dashboard' : 'Resident Portal';
echo json_encode([
    'found' => true,
    'name'  => $u['full_name'],
    'role'  => $u['role'],
    'dest'  => $dest,
]);