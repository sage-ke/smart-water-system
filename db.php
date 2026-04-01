<?php
// ================================================================
//  db.php — Database Connection
//  Include at the top of EVERY PHP file that needs the database:
//      require_once __DIR__ . '/db.php';
//  Then use $pdo to run queries.
// ================================================================

$host    = 'localhost';   // Always localhost in XAMPP
$dbname  = 'meru_new';        // Your database name
$user    = 'root';        // Default XAMPP username
$pass    = '';            // Default XAMPP password (empty string)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}