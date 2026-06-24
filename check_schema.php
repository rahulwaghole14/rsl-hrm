<?php
require 'config/db.php';
$stmt = $pdo->query('SHOW COLUMNS FROM tasks');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
