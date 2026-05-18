<?php
require_once __DIR__ . '/../config/db.php';
$pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
echo "Column added.";
?>
