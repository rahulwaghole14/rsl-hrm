<?php
date_default_timezone_set('Asia/Kolkata');

// Set session lifetime to 1 hour 30 minutes (5,400 seconds) if session is not already started
if (session_status() === PHP_SESSION_NONE) {
     ini_set('session.gc_maxlifetime', 5400);
     session_set_cookie_params([
          'lifetime' => 5400,
          'path' => '/',
          'secure' => false,      // Set to true if you are using HTTPS
          'httponly' => true,
          'samesite' => 'Lax'
     ]);
}

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
     // Auto-migrate schema: add is_primary if not exists
     try {
          $pdo->query("SELECT is_primary FROM users LIMIT 1");
     } catch (\Exception $e) {
          $pdo->exec("ALTER TABLE users ADD COLUMN is_primary TINYINT(1) DEFAULT 0");
          // Mark specific admins as the primary admins
          $pdo->exec("UPDATE users SET is_primary = 1 WHERE email IN ('pawanepratik2001@gmail.com', 'rsl.pratik27@gmail.com')");
     }
} catch (\PDOException $e) {
     // Connection failed. UI will handle null $pdo.
     // error_log($e->getMessage());
}
?>