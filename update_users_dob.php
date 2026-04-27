<?php
require_once 'config/db.php';
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN dob DATE NULL AFTER mob_no");
    echo "DOB column added successfully!";
} catch (PDOException $e) {
    echo "Error or Column already exists: " . $e->getMessage();
}
?>
