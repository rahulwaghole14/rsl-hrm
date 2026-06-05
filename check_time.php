<?php
require 'config/db.php';
echo 'PHP Time: ' . date('Y-m-d H:i:s') . "\n";
$stmt = $pdo->query('SELECT NOW()');
echo 'MySQL NOW(): ' . $stmt->fetchColumn() . "\n";
