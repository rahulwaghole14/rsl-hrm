<?php
date_default_timezone_set('Asia/Kolkata');

// Database Configuration
// CHANGE THESE FOR LIVE SERVER
$host = 'localhost';
$db   = 'company_calendar';


$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
     PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = null; // Initialize to null

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // Connection failed. We will handle the null $pdo in the UI/functions.
     // error_log($e->getMessage()); 
}
?>