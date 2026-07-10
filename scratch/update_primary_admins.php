<?php
require_once 'config/db.php';
// Reset all
$pdo->exec("UPDATE users SET is_primary = 0");
// Set specific ones
$pdo->exec("UPDATE users SET is_primary = 1 WHERE email IN ('pawanepratik2001@gmail.com', 'rsl.pratik27@gmail.com')");

// Show results
$stmt = $pdo->query("SELECT id, name, role, email, is_primary FROM users WHERE role = 'admin'");
while ($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Name: {$row['name']} | Role: {$row['role']} | Email: {$row['email']} | IsPrimary: {$row['is_primary']}\n";
}
