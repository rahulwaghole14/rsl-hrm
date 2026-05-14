<?php
require 'config/db.php';
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'tasks'");
    if ($stmt->fetch()) {
        echo "TABLE_EXISTS\n";
        $desc = $pdo->query("DESCRIBE tasks");
        print_r($desc->fetchAll());
    } else {
        echo "TABLE_NOT_FOUND\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
