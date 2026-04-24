<?php
require_once 'config/db.php';

try {
    $pdo->exec("ALTER TABLE events MODIFY COLUMN type ENUM('holiday', 'event', 'half_day') NOT NULL DEFAULT 'event'");
    echo "Database updated successfully! 'half_day' type is now active.";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
}
?>
