<?php
require_once 'config/db.php';
$stmt = $pdo->query("SELECT name, role FROM users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . " (" . $row['role'] . ")\n";
}
