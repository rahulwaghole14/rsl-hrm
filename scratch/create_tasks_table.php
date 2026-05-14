<?php
require 'config/db.php';
try {
    $sql = "CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        task_date DATE,
        task_name VARCHAR(255),
        task_description TEXT,
        hours_spent DECIMAL(4,2),
        status VARCHAR(50) DEFAULT 'Completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "TABLE_CREATED_SUCCESSFULLY";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
