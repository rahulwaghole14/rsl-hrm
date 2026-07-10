<?php
require_once 'config/db.php';
$stmt = $pdo->query("SELECT id, name, role, email, is_primary FROM users");
while ($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Name: {$row['name']} | Role: {$row['role']} | Email: {$row['email']} | IsPrimary: {$row['is_primary']}\n";
}
