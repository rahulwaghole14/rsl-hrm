<?php
date_default_timezone_set('Asia/Kolkata');

// Database Configuration
$host = '127.0.0.1';
$port = '3306';
$db = 'company_calendar';

$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
     PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false,
];

$pdo = null;

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // Connection failed. UI will handle null $pdo.
     // error_log($e->getMessage());
}
?>